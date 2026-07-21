<?php
/**
 * Integration test: consume, cancel, and the TTL sweep — the end of a job's life
 * (issue #8, ADR-0004).
 *
 * This harness exercises the terminal lifecycle of an Extraction job end to end
 * against the live REST stack. It pins every acceptance criterion of issue #8:
 *  - AC1: POST /extractions/{id}/consume on a ready job deletes the sealed
 *    artifact and the job's own working directory and reports the job consumed.
 *  - AC2: consume on a job that is not ready (queued or running) is a 409, and
 *    the rejected job is left untouched.
 *  - AC3: DELETE /extractions/{id} cancels / cleans up a job — deleting its
 *    artifact and working directory — without ever firing the ready lifecycle
 *    action that is the audit record's trigger (ADR-0004/0006), so cancel
 *    produces no audit record; it cleans up regardless of the job's state.
 *  - AC4: a TTL sweep removes a never-consumed artifact and its working directory
 *    and marks the job expired, and the TTL is a Config knob — a large TTL leaves
 *    the aged job untouched, a small one sweeps it, while a fresh job survives.
 *  - AC5: only the owner may consume or cancel; a capable non-owner is refused
 *    403 and deletes nothing, and an unknown id is a 404 before ownership.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Config;
use Kntnt\Extractor\Extraction_Job;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Sweeper;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

// The consume/cancel routes and the TTL Sweeper are what this issue adds; without
// them there is nothing to exercise, so record the gap and stop this file cleanly
// (a red before green).
if ( ! class_exists( Sweeper::class ) || ! method_exists( \Kntnt\Extractor\Rest\Extractions_Controller::class, 'consume' ) || ! method_exists( \Kntnt\Extractor\Job_Store::class, 'purge' ) ) {
	kntnt_extractor_assert( false, 'The consume, cancel, and TTL-sweep machinery is available' );
	return;
}
kntnt_extractor_assert( true, 'The consume, cancel, and TTL-sweep machinery is available' );

// Recursively removes a directory tree so the suite leaves no working directory
// behind on the host.
$rmrf = static function ( string $dir ) use ( &$rmrf ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: [] as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			$rmrf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
};

// Dispatches POST /extractions with a JSON body through the live REST server.
$post_extractions = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $request );
};

// Dispatches GET /extractions/{id} through the live REST server.
$get_extraction = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) );
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret.
$tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( \Kntnt\Extractor\Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Dispatches POST /extractions/{id}/consume through the live REST server.
$consume = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/consume' ) );
};

// Dispatches DELETE /extractions/{id} through the live REST server.
$cancel = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/kntnt-extractor/v1/extractions/' . $id ) );
};

// The id the last POST /extractions handed back.
$id_of = static function ( WP_REST_Response $response ): string {
	$data = $response->get_data();
	return is_array( $data ) && is_string( $data['id'] ?? null ) ? $data['id'] : '';
};

// Reads a field out of a job's on-disk state file, or '' when it is unreadable.
$state_field = static function ( string $work, string $id, string $field ): string {
	$path = $work . '/' . $id . '/job.json';
	$state = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $state ) && is_string( $state[ $field ] ?? null ) ? $state[ $field ] : '';
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// The owning administrator holds both capabilities.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so a
// ready job's artifact stays web-reachable while the run owns all of its state
// and cleans it up afterwards.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-consume-' . bin2hex( random_bytes( 4 ) );
$downloads = $work . '-downloads';
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the several jobs this file needs at once can
// all be created (a ready job still occupies its slot until consumed or swept).
$force_max = static fn(): int => 50;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Short-circuit every loopback the code fires so a create's nudge never touches
// the real network; the ticks below drive the jobs to ready synchronously.
$intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// The caller submits a real ephemeral X25519 public key so the artifact actually
// seals and the job reaches ready (an invalid key would fail the build instead).
$keypair = sodium_crypto_box_keypair();
$public_key = sodium_crypto_box_publickey( $keypair );

// A small selection every install has — its options table and its bootstrap file —
// so each job seals and reaches ready quickly.
$selection = [
	'tables' => [ $wpdb->options ],
	'files' => [ 'wp-load.php' ],
	'public_key' => base64_encode( $public_key ),
];

// Drives a freshly-created job to ready with its own persisted tick secret.
$drive_to_ready = static function ( string $id ) use ( $work, $tick, $state_field ): void {
	$tick( $id, $state_field( $work, $id, 'tick_secret' ) );
};

// --- AC1: consume deletes the artifact and the working directory, marks consumed ---

wp_set_current_user( $owner->ID );
$c1_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $c1_id );
$c1_artifact = $downloads . '/' . $state_field( $work, $c1_id, 'artifact' );
$c1_dir = $work . '/' . $c1_id;
kntnt_extractor_assert( is_dir( $c1_dir ) && is_file( $c1_artifact ), 'A driven job has both a working directory and a sealed artifact before consume (precondition)' );

$c1_response = $consume( $c1_id );
kntnt_extractor_assert( $c1_response->get_status() === 200, 'POST /extractions/{id}/consume on a ready job is a 200 (AC1)' );
$c1_data = $c1_response->get_data();
kntnt_extractor_assert( is_array( $c1_data ) && ( $c1_data['state'] ?? null ) === 'consumed', 'Consume marks the job consumed (AC1)' );
kntnt_extractor_assert( ! is_file( $c1_artifact ), 'Consume deletes the sealed artifact (AC1)' );
kntnt_extractor_assert( ! is_dir( $c1_dir ), 'Consume deletes the job\'s working directory (AC1)' );
kntnt_extractor_assert( $get_extraction( $c1_id )->get_status() === 404, 'A consumed job is gone: a later poll is a 404 (AC1)' );

// The shared working directory and its hardening survive: only the one job's own
// directory was removed, never the tree above it.
kntnt_extractor_assert( is_dir( $work ) && is_file( $work . '/.htaccess' ), 'Consume removes only the job\'s own directory, never the shared working directory (AC1)' );

// --- AC2: consume on a job that is not ready is a 409 ---

$c2_id = $id_of( $post_extractions( $selection ) );
kntnt_extractor_assert( $consume( $c2_id )->get_status() === 409, 'Consume on a queued job is a 409 (AC2)' );
$c2_poll = $get_extraction( $c2_id )->get_data();
kntnt_extractor_assert( is_array( $c2_poll ) && ( $c2_poll['state'] ?? null ) === 'queued', 'A 409-rejected consume leaves the job queued and untouched (AC2)' );
kntnt_extractor_assert( is_dir( $work . '/' . $c2_id ), 'A 409-rejected consume deletes nothing (AC2)' );

// A running job is likewise not ready: consume is still a 409.
$store = new Job_Store( new Config() );
$store->save( $store->find( $c2_id )->with_state( Job_State::Running ) );
kntnt_extractor_assert( $consume( $c2_id )->get_status() === 409, 'Consume on a running job is a 409 (AC2)' );
kntnt_extractor_assert( is_dir( $work . '/' . $c2_id ), 'A 409-rejected consume of a running job deletes nothing (AC2)' );
$cancel( $c2_id );

// --- AC3: cancel cleans up without producing an audit record ---

$c3_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $c3_id );
$c3_artifact = $downloads . '/' . $state_field( $work, $c3_id, 'artifact' );
$c3_dir = $work . '/' . $c3_id;

// The audit record is written when a job reaches ready (ADR-0004/0006); watch that
// exact lifecycle action across the cancel and prove it never fires, so cancel adds
// no audit record.
$ready_fired = false;
$watch_ready = static function () use ( &$ready_fired ): void {
	$ready_fired = true;
};
add_action( 'kntnt_extractor_job_ready', $watch_ready );
$c3_response = $cancel( $c3_id );
remove_action( 'kntnt_extractor_job_ready', $watch_ready );

kntnt_extractor_assert( $c3_response->get_status() === 200, 'DELETE /extractions/{id} on a ready job is a 200 (AC3)' );
$c3_data = $c3_response->get_data();
kntnt_extractor_assert( is_array( $c3_data ) && ( $c3_data['state'] ?? null ) === 'cancelled', 'Cancel marks the job cancelled (AC3)' );
kntnt_extractor_assert( ! is_file( $c3_artifact ) && ! is_dir( $c3_dir ), 'Cancel deletes the artifact and the working directory (AC3)' );
kntnt_extractor_assert( ! $ready_fired, 'Cancel fires no ready lifecycle action, so it produces no audit record (AC3)' );
kntnt_extractor_assert( $get_extraction( $c3_id )->get_status() === 404, 'A cancelled job is gone: a later poll is a 404 (AC3)' );

// Cancel cleans up regardless of state: a queued job it never drove to ready is
// cancelled and removed just the same.
$c3q_id = $id_of( $post_extractions( $selection ) );
$c3q_response = $cancel( $c3q_id );
kntnt_extractor_assert( $c3q_response->get_status() === 200 && ( $c3q_response->get_data()['state'] ?? null ) === 'cancelled', 'Cancel cleans up a queued job too (AC3)' );
kntnt_extractor_assert( ! is_dir( $work . '/' . $c3q_id ), 'Cancel deletes a queued job\'s working directory (AC3)' );

// --- AC4: the TTL sweep removes a never-consumed artifact, marks it expired ---

// An aged, ready-but-never-consumed job: drive it to ready, then backdate its
// heartbeat far into the past so any short TTL counts it as expired.
$aged_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $aged_id );
$aged_artifact = $downloads . '/' . $state_field( $work, $aged_id, 'artifact' );
$aged_dir = $work . '/' . $aged_id;
kntnt_extractor_assert( is_file( $aged_artifact ) && is_dir( $aged_dir ), 'The aged job is ready with an artifact on disk before the sweep (precondition)' );
$aged_job = $store->find( $aged_id );
$store->save( new Extraction_Job( $aged_job->id, $aged_job->state, $aged_job->owner, $aged_job->public_key, $aged_job->tables, $aged_job->files, $aged_job->created_at, time() - 100000, $aged_job->tick_secret, $aged_job->artifact ) );

// A fresh, ready job whose recent heartbeat must survive any sweep alongside it.
$fresh_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $fresh_id );
$fresh_artifact = $downloads . '/' . $state_field( $work, $fresh_id, 'artifact' );
$fresh_dir = $work . '/' . $fresh_id;

// The TTL is a Config knob: a filter-supplied TTL larger than the aged job's age
// leaves it entirely unswept.
$ttl_value = 200000;
$force_ttl = static function () use ( &$ttl_value ): int {
	return $ttl_value;
};
add_filter( 'kntnt_extractor_config_ttl', $force_ttl );
$sweeper = new Sweeper( new Job_Store( new Config() ), new Config() );
$expired_large = array_map( static fn( Extraction_Job $job ): string => $job->id, $sweeper->sweep() );
kntnt_extractor_assert( ! in_array( $aged_id, $expired_large, true ) && is_dir( $aged_dir ), 'A TTL larger than the job\'s age leaves it unswept — the TTL is a Config knob (AC4)' );

// A filter-supplied TTL smaller than the aged job's age sweeps exactly it: its
// artifact and working directory are removed and it is marked expired.
$ttl_value = 50000;
$expired_small = $sweeper->sweep();
$expired_small_by_id = [];
foreach ( $expired_small as $job ) {
	$expired_small_by_id[ $job->id ] = $job->state;
}
kntnt_extractor_assert( array_key_exists( $aged_id, $expired_small_by_id ), 'A TTL smaller than the job\'s age sweeps the never-consumed job (AC4)' );
kntnt_extractor_assert( ( $expired_small_by_id[ $aged_id ] ?? null ) === Job_State::Expired, 'The swept job is marked expired (AC4)' );
kntnt_extractor_assert( ! is_file( $aged_artifact ), 'The sweep deletes the never-consumed artifact (AC4)' );
kntnt_extractor_assert( ! is_dir( $aged_dir ), 'The sweep deletes the job\'s working directory (AC4)' );
kntnt_extractor_assert( $get_extraction( $aged_id )->get_status() === 404, 'A swept job is gone: a later poll is a 404 (AC4)' );

// The fresh job is left untouched by the same sweep.
kntnt_extractor_assert( ! array_key_exists( $fresh_id, $expired_small_by_id ), 'The sweep does not expire a fresh, recently-updated job (AC4)' );
kntnt_extractor_assert( is_file( $fresh_artifact ) && is_dir( $fresh_dir ), 'The fresh job survives the sweep with its artifact intact (AC4)' );
remove_filter( 'kntnt_extractor_config_ttl', $force_ttl );
$cancel( $fresh_id );

// --- AC5: only the owner may consume or cancel ---

$o_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $o_id );
$o_artifact = $downloads . '/' . $state_field( $work, $o_id, 'artifact' );
$o_dir = $work . '/' . $o_id;

// A second administrator holds both capabilities through the administrator role,
// so it clears the capability gate yet must be refused the job it does not own.
$other = wp_insert_user( [ 'user_login' => 'kntnt_extractor_consume_other_' . bin2hex( random_bytes( 4 ) ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
wp_set_current_user( is_int( $other ) ? $other : 0 );
kntnt_extractor_assert( current_user_can( $operate ) && current_user_can( 'manage_options' ), 'The second administrator holds both capabilities (AC5)' );
kntnt_extractor_assert( $consume( $o_id )->get_status() === 403, 'A capable non-owner may not consume the job (403) (AC5)' );
kntnt_extractor_assert( $cancel( $o_id )->get_status() === 403, 'A capable non-owner may not cancel the job (403) (AC5)' );
kntnt_extractor_assert( is_file( $o_artifact ) && is_dir( $o_dir ), 'A rejected non-owner attempt deletes nothing (AC5)' );

// Existence is decided before ownership: an unknown id is a 404 even for the
// non-owner, never a 403 that would leak whether the job exists.
$unknown = str_repeat( '0', 32 );
kntnt_extractor_assert( $consume( $unknown )->get_status() === 404, 'Consume on an unknown id is a 404, existence before ownership (AC5)' );
kntnt_extractor_assert( $cancel( $unknown )->get_status() === 404, 'Cancel on an unknown id is a 404, existence before ownership (AC5)' );

// The capability gate still refuses an anonymous caller outright.
wp_set_current_user( 0 );
kntnt_extractor_assert( $consume( $o_id )->get_status() === 403, 'An anonymous consume is refused 403 by the capability gate (AC5)' );

// The owner still sees the intact, ready job after every rejected attempt.
wp_set_current_user( $owner->ID );
$o_poll = $get_extraction( $o_id )->get_data();
kntnt_extractor_assert( is_array( $o_poll ) && ( $o_poll['state'] ?? null ) === 'ready', 'The owner\'s job is still ready after the rejected non-owner attempts (AC5)' );

// The owner consumes its own job cleanly, closing out the run.
kntnt_extractor_assert( $consume( $o_id )->get_status() === 200, 'The owner consumes its own ready job (200) (AC5)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
$rmrf( $work );
$rmrf( $downloads );
wp_set_current_user( 0 );

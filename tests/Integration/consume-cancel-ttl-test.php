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
 *    artifact and working directory — without filing an audit record. A stand-in
 *    audit writer on the sanctioned ready trigger (ADR-0004/0006) is proven to
 *    record one entry when a job reaches ready, then shown not to grow across the
 *    cancel, so cancel produces no audit record; it cleans up regardless of state.
 *  - AC4: a TTL sweep removes a never-consumed artifact and its working directory
 *    and marks the job expired, and the TTL is a Config knob — a large TTL leaves
 *    the aged job untouched, a small one sweeps it, while a fresh job survives.
 *  - AC5: only the owner may consume or cancel; a capable non-owner is refused
 *    403 and deletes nothing, and an unknown id is a 404 before ownership.
 *  - AC6: the same sweep bounds the one terminal residue there is. A failed job
 *    keeps its record so a poll can still report the reason, and nothing else ever
 *    reclaims it; the sweep does, on the same two windows as any unfinished job
 *    and with no exemption of any kind (ADR-0024, and ADR-0015's addendum). Every
 *    shape a failure comes in is reclaimed alike: the floor stall, the opaque
 *    throw, and the stall that never shrank because its chunk spends no bound.
 *    Beside them sits the record an unsupported release wrote, which this release
 *    no longer deserialises at all: it is skipped quietly — a 404, not a throw —
 *    and the failures around it are still reclaimed by the very same sweep. That
 *    last property is what makes retiring the tolerance branches safe to ship,
 *    because it is what stops one stale file from breaking the sweep for the
 *    live jobs beside it.
 *  - AC8: cancel and consume both take the job's per-job tick lock before purging
 *    (ADR-0019). A lock held by a live tick refuses with 409 rather than a silent
 *    skip or a wait, and deletes nothing; the same call succeeds once the lock is
 *    released, which is the control proving the 409 was genuinely the lock.
 *  - AC9: a served artifact whose job record is gone (orphaned by exactly the race
 *    AC8 closes, or by a crash between an artifact's publish and its record
 *    settling) is reclaimed by the sweep once it has aged past its grace period —
 *    the TTL, reused rather than an invented constant — and is left untouched
 *    while still inside it, which is the assertion that matters most: it is what
 *    stops this fix from eating a live job's output.
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
	$path = $work . '/' . $id . '/state.json';
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

// Drives a freshly-created job to ready with its own persisted tick secret. The
// build is chunked (one bounded segment per tick, ADR-0007), so tick across chunks
// until it reaches ready, exactly as the loopback loop would.
$drive_to_ready = static function ( string $id ) use ( $work, $tick, $state_field ): void {
	$secret = $state_field( $work, $id, 'tick_secret' );
	$driven = 0;
	while ( $driven < 200 && $state_field( $work, $id, 'state' ) !== 'ready' ) {
		$tick( $id, $secret );
		$driven++;
	}
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

// Stand in for the ADR-0006 audit writer, which is not yet a subsystem: append a
// line to a real log file on kntnt_extractor_job_ready — the sanctioned trigger the
// audit record is filed on (ADR-0004) — and install it before the job is driven to
// ready. That gives the criterion a positive control: the probe is shown recording
// an entry when the audit-worthy event actually happens, so its later silence across
// the cancel is a discriminating result, not a structurally guaranteed one. Residual:
// until the real writer exists (a later issue) a cancel that filed a record by some
// path other than this trigger could not be caught here; this binds the sanctioned
// path, the only one that exists today.
$audit_log = $work . '-audit.log';
$audit_writer = static function () use ( $audit_log ): void {
	file_put_contents( $audit_log, "job-ready\n", FILE_APPEND );
};
$audit_entries = static function () use ( $audit_log ): int {
	return is_file( $audit_log ) ? count( file( $audit_log, FILE_IGNORE_NEW_LINES ) ?: [] ) : 0;
};
add_action( 'kntnt_extractor_job_ready', $audit_writer );

// Drive a fresh job to ready and confirm the probe filed exactly one audit record for
// it: without this positive control the later no-new-record check would hold vacuously.
$c3_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $c3_id );
$c3_artifact = $downloads . '/' . $state_field( $work, $c3_id, 'artifact' );
$c3_dir = $work . '/' . $c3_id;
kntnt_extractor_assert( $audit_entries() === 1, 'Reaching ready files exactly one audit record — the audit probe has discriminating power (AC3 precondition)' );

// Cancel the ready job while watching both the audit log and the ready action across
// the call, so a cancel that filed a record — by growing the log or by firing the
// ready transition — would be caught, not merely one guaranteed never to happen.
$audit_before_cancel = $audit_entries();
$ready_fired = false;
$watch_ready = static function () use ( &$ready_fired ): void {
	$ready_fired = true;
};
add_action( 'kntnt_extractor_job_ready', $watch_ready );
$c3_response = $cancel( $c3_id );
remove_action( 'kntnt_extractor_job_ready', $watch_ready );
remove_action( 'kntnt_extractor_job_ready', $audit_writer );

kntnt_extractor_assert( $c3_response->get_status() === 200, 'DELETE /extractions/{id} on a ready job is a 200 (AC3)' );
$c3_data = $c3_response->get_data();
kntnt_extractor_assert( is_array( $c3_data ) && ( $c3_data['state'] ?? null ) === 'cancelled', 'Cancel marks the job cancelled (AC3)' );
kntnt_extractor_assert( ! is_file( $c3_artifact ) && ! is_dir( $c3_dir ), 'Cancel deletes the artifact and the working directory (AC3)' );
kntnt_extractor_assert( $audit_entries() === $audit_before_cancel && ! $ready_fired, 'Cancel files no audit record: the audit log does not grow and no ready transition fires (AC3)' );
kntnt_extractor_assert( $get_extraction( $c3_id )->get_status() === 404, 'A cancelled job is gone: a later poll is a 404 (AC3)' );

// Cancel cleans up regardless of state: a queued job it never drove to ready is
// cancelled and removed just the same.
$c3q_id = $id_of( $post_extractions( $selection ) );
$c3q_response = $cancel( $c3q_id );
kntnt_extractor_assert( $c3q_response->get_status() === 200 && ( $c3q_response->get_data()['state'] ?? null ) === 'cancelled', 'Cancel cleans up a queued job too (AC3)' );
kntnt_extractor_assert( ! is_dir( $work . '/' . $c3q_id ), 'Cancel deletes a queued job\'s working directory (AC3)' );

// --- AC8: cancel and consume take the job's tick lock before purging (ADR-0019) ---

// A tick's lock file is per-open-file-description, not per-process: a second
// fopen() on the same path plus a non-blocking LOCK_EX genuinely fails within
// this one PHP process, exactly as a real second actor's separate process would
// fail against a live tick — so no second process is needed to demonstrate the
// refusal.
$lock_store = new Job_Store( new Config() );

$c8_cancel_id = $id_of( $post_extractions( $selection ) );
$c8_cancel_job = $lock_store->find( $c8_cancel_id );
kntnt_extractor_assert( $c8_cancel_job !== null, 'The cancel-lock demonstration job exists on disk (precondition)' );
$c8_cancel_lock = fopen( $lock_store->container_lock_path( $c8_cancel_job ), 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- holding the job's own tick lock in-process to demonstrate the refusal a live tick would cause.
kntnt_extractor_assert( $c8_cancel_lock !== false && flock( $c8_cancel_lock, LOCK_EX | LOCK_NB ), 'The demonstration holds the job\'s tick lock in-process (precondition)' );
$c8_cancel_locked_response = $cancel( $c8_cancel_id );
kntnt_extractor_assert( $c8_cancel_locked_response->get_status() === 409, 'Cancel on a job whose tick lock is held is a 409, not a silent skip and not a wait (AC8)' );
kntnt_extractor_assert( is_dir( $work . '/' . $c8_cancel_id ), 'A 409-refused cancel deletes nothing while the lock is held (AC8)' );

// The control: releasing the lock lets the identical cancel succeed, which is
// what proves the 409 above was genuinely caused by the held lock and not by
// some other, coincidental refusal.
fclose( $c8_cancel_lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releasing the in-process demonstration lock.
$c8_cancel_unlocked_response = $cancel( $c8_cancel_id );
kntnt_extractor_assert( $c8_cancel_unlocked_response->get_status() === 200, 'The same cancel succeeds once the lock is released (AC8 control)' );
kntnt_extractor_assert( ! is_dir( $work . '/' . $c8_cancel_id ), 'The retried cancel deletes the working directory once unlocked (AC8 control)' );

// Consume takes the same lock; a ready job's is exercised the same way.
$c8_consume_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $c8_consume_id );
$c8_consume_job = $lock_store->find( $c8_consume_id );
kntnt_extractor_assert( $c8_consume_job !== null && $c8_consume_job->state === Job_State::Ready, 'The consume-lock demonstration job is ready (precondition)' );
$c8_consume_lock = fopen( $lock_store->container_lock_path( $c8_consume_job ), 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- holding the job's own tick lock in-process to demonstrate the refusal a live tick would cause.
kntnt_extractor_assert( $c8_consume_lock !== false && flock( $c8_consume_lock, LOCK_EX | LOCK_NB ), 'The demonstration holds the ready job\'s tick lock in-process (precondition)' );
$c8_consume_locked_response = $consume( $c8_consume_id );
kntnt_extractor_assert( $c8_consume_locked_response->get_status() === 409, 'Consume on a job whose tick lock is held is a 409 (AC8)' );
fclose( $c8_consume_lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releasing the in-process demonstration lock.
kntnt_extractor_assert( $consume( $c8_consume_id )->get_status() === 200, 'The same consume succeeds once the lock is released (AC8 control)' );

// --- AC9: an orphaned artifact is reclaimed past its grace period, spared inside it (ADR-0019) ---

$orphan_ttl_value = 50000;
$force_orphan_ttl = static function () use ( &$orphan_ttl_value ): int {
	return $orphan_ttl_value;
};
add_filter( 'kntnt_extractor_config_ttl', $force_orphan_ttl );
$orphan_sweeper = new Sweeper( new Job_Store( new Config() ), new Config() );

// Publish a real artifact by driving a job to ready, then delete only its own
// working directory directly on disk — never through purge() — leaving the
// artifact behind with no job record naming it at all: the exact shape a cancel
// or consume landing mid-tick without this plan's lock (or any other crash
// between a publish and its record settling) can leave, and the shape
// {@see Job_Store::orphaned_artifacts()} must find.
$orphan_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $orphan_id );
$orphan_artifact = $downloads . '/' . $state_field( $work, $orphan_id, 'artifact' );
kntnt_extractor_assert( is_file( $orphan_artifact ), 'The orphan-reclamation job is ready with an artifact on disk (precondition)' );
$rmrf( $work . '/' . $orphan_id );
kntnt_extractor_assert( ! is_dir( $work . '/' . $orphan_id ) && is_file( $orphan_artifact ), 'The artifact survives with no job record naming it (precondition)' );

// Backdated past the grace period (the TTL), the sweep reclaims it.
touch( $orphan_artifact, time() - 100000 );
$orphan_sweeper->sweep();
kntnt_extractor_assert( ! is_file( $orphan_artifact ), 'An orphaned artifact past its grace period is reclaimed by the sweep (AC9)' );

// A second orphan, left with a fresh mtime, is inside the same grace period and
// must survive the identical sweep call — the assertion that stops this fix
// from eating a live job's output, and the one that matters most.
$fresh_orphan_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $fresh_orphan_id );
$fresh_orphan_artifact = $downloads . '/' . $state_field( $work, $fresh_orphan_id, 'artifact' );
$rmrf( $work . '/' . $fresh_orphan_id );
kntnt_extractor_assert( ! is_dir( $work . '/' . $fresh_orphan_id ) && is_file( $fresh_orphan_artifact ), 'The fresh-orphan artifact survives with no job record naming it (precondition)' );
touch( $fresh_orphan_artifact, time() );
$orphan_sweeper->sweep();
kntnt_extractor_assert( is_file( $fresh_orphan_artifact ), 'An orphaned artifact inside its grace period is NOT reclaimed (AC9)' );

remove_filter( 'kntnt_extractor_config_ttl', $force_orphan_ttl );
@unlink( $fresh_orphan_artifact );

// --- AC4: the TTL sweep removes a never-consumed artifact, marks it expired ---

// An aged, ready-but-never-consumed job: drive it to ready, then backdate its
// heartbeat far into the past so any short TTL counts it as expired.
$aged_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $aged_id );
$aged_artifact = $downloads . '/' . $state_field( $work, $aged_id, 'artifact' );
$aged_dir = $work . '/' . $aged_id;
kntnt_extractor_assert( is_file( $aged_artifact ) && is_dir( $aged_dir ), 'The aged job is ready with an artifact on disk before the sweep (precondition)' );
$aged_job = $store->find( $aged_id );
$store->save( new Extraction_Job( $aged_job->id, $aged_job->state, $aged_job->owner, $aged_job->public_key, $aged_job->tables, $aged_job->structure_only, $aged_job->files, $aged_job->created_at, time() - 100000, $aged_job->tick_secret, $aged_job->artifact ) );

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
$cancel( $fresh_id );

// --- AC6: every failed record is bounded by the same windows, with no exemption ---

// A failure keeps its record so a poll can still report it with its reason, and
// GET /extractions lists only non-terminal jobs — so before this, every failed run
// left a directory under uploads that nothing but uninstall ever reclaimed. The
// container is discarded at fail-time (ADR-0015), but job.json still holds the
// whole selection, which is megabytes on a large one, and nothing bounded the count.

// Rewrites a job's on-disk state file, so a record can be put in exactly the shape
// a given release would have left behind.
$restate = static function ( string $id, array $overrides, array $drop = [] ) use ( $work ): void {
	$path = $work . '/' . $id . '/state.json';
	$state = json_decode( (string) file_get_contents( $path ), true );
	$state = is_array( $state ) ? array_merge( $state, $overrides ) : $overrides;
	foreach ( $drop as $key ) {
		unset( $state[ $key ] );
	}
	file_put_contents( $path, (string) wp_json_encode( $state ) );
};

// The three failure shapes this release writes, all aged far past any short TTL: a
// stall it adapted its way to the floor over (a reason and shrunken budgets), an
// opaque throw (no reason at all), and a stall that never shrank because its chunk
// spends no bound — a structure-only table or the sealed index — which reaches
// `failed` with the budget keys present at zero.
$aged_stamp = [ 'state' => 'failed', 'updated_at' => time() - 100000, 'progressed_at' => time() - 100000 ];
$f_floor_id = $id_of( $post_extractions( $selection ) );
$restate( $f_floor_id, $aged_stamp + [ 'error' => 'The extraction stalled at the floor.', 'chunk_size' => 1 ] );
$f_opaque_id = $id_of( $post_extractions( $selection ) );
$restate( $f_opaque_id, $aged_stamp + [ 'error' => null ] );
$f_unshrinkable_id = $id_of( $post_extractions( $selection ) );
$restate( $f_unshrinkable_id, $aged_stamp + [ 'error' => 'The extraction stalled: 3 consecutive attempts.', 'chunk_size' => 0, 'table_chunk_bytes' => 0, 'table_chunk_rows' => 0 ] );

// Beside them, the shape 0.5.1 and earlier wrote: a diagnosed stall with no budget
// keys whatsoever. This release understands the current schema and no earlier one
// (ADR-0024), so that record is not a fourth kind of failure — it is not a readable
// job at all, and the assertions below are about it being ignored rather than swept.
$f_legacy_id = $id_of( $post_extractions( $selection ) );
$restate( $f_legacy_id, $aged_stamp + [ 'error' => 'The extraction stalled: 3 consecutive attempts.' ], [ 'chunk_size', 'table_chunk_bytes', 'table_chunk_rows' ] );
kntnt_extractor_assert( is_dir( $work . '/' . $f_floor_id ) && is_dir( $work . '/' . $f_opaque_id ) && is_dir( $work . '/' . $f_unshrinkable_id ) && is_dir( $work . '/' . $f_legacy_id ), 'All four failed records are on disk before the sweep (precondition)' );

// The unparseable record degrades quietly: skipped rather than thrown, so nothing
// that walks the store can be taken down by one stale file. Read through the store
// and through the poll, because those are the two ways anything ever reaches it.
$f_legacy_read = null;
$f_legacy_threw = false;
try {
	$f_legacy_read = $store->find( $f_legacy_id );
} catch ( Throwable $e ) {
	$f_legacy_threw = true;
}
kntnt_extractor_assert( ! $f_legacy_threw, 'Deserialising a record that predates the current schema does not throw (AC6)' );
kntnt_extractor_assert( $f_legacy_read === null, 'A record written before the current schema does not read back as a job (AC6)' );
kntnt_extractor_assert( $get_extraction( $f_legacy_id )->get_status() === 404, 'A record the plugin can no longer parse polls as no such job (AC6)' );

// The enumeration around it still completes: the same sweep that walks over the
// unreadable record reclaims all three failures beside it, on the ordinary windows.
$expired_failed = [];
foreach ( $sweeper->sweep() as $job ) {
	$expired_failed[ $job->id ] = $job->state;
}
kntnt_extractor_assert( array_key_exists( $f_floor_id, $expired_failed ) && ! is_dir( $work . '/' . $f_floor_id ), 'The sweep reclaims a floor-failure (AC6)' );
kntnt_extractor_assert( array_key_exists( $f_opaque_id, $expired_failed ) && ! is_dir( $work . '/' . $f_opaque_id ), 'The sweep reclaims an opaque failure (AC6)' );
kntnt_extractor_assert( array_key_exists( $f_unshrinkable_id, $expired_failed ) && ! is_dir( $work . '/' . $f_unshrinkable_id ), 'The sweep reclaims a stall that never shrank, its keys present at 0 (AC6)' );
kntnt_extractor_assert( ( $expired_failed[ $f_floor_id ] ?? null ) === Job_State::Expired, 'A reclaimed failure is recorded expired like any other swept job (AC6)' );
kntnt_extractor_assert( $get_extraction( $f_floor_id )->get_status() === 404, 'A reclaimed failure is gone: a later poll is a 404, as for any purged job (AC6)' );

// Nothing hunts down what an unsupported release left behind, deliberately: there
// is no migration and no cleanup routine, so the unreadable directory is simply
// never claimed. Asserted so the omission is a decision on record, not a gap.
kntnt_extractor_assert( is_dir( $work . '/' . $f_legacy_id ), 'An unreadable record is left where it lies rather than hunted down (AC6)' );

remove_filter( 'kntnt_extractor_config_ttl', $force_ttl );

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

// The gate still refuses an anonymous caller outright — 401, since the request
// resolved to no user at all (ADR-0012).
wp_set_current_user( 0 );
kntnt_extractor_assert( $consume( $o_id )->get_status() === 401, 'An anonymous consume is refused 401 by the authorization gate (AC5)' );

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
@unlink( $audit_log );
wp_set_current_user( 0 );

<?php
/**
 * Integration test: GET /extractions lists the caller's own non-terminal jobs (issue #17).
 *
 * The collection route `GET /kntnt-extractor/v1/extractions` (no id) is the
 * stranded-job recovery surface the cutover health check enumerates its live jobs
 * through: a job stranded by a crashed run — queued, running, or ready, still
 * holding the single global concurrency slot until the TTL sweep reclaims it
 * (ADR-0004) — can be found and cancelled instead of blocking the next `POST` for
 * up to the sweep window. It is gated by the same both-capabilities Authorizer as
 * every data endpoint (ADR-0002), owner-scoped, and deliberately narrow: it lists
 * only non-terminal jobs (queued | running | ready) and never a terminal one, and
 * it never carries a `download_url` — delivery stays the per-job poll's job.
 *
 * It pins every acceptance criterion of issue #17:
 *  - AC1: an authorized listing returns the caller's own non-terminal jobs, each
 *    with id, state, created_at, updated_at, and progress where the job has
 *    advanced (a queued job omits progress; a running one carries it).
 *  - AC2: a terminal job persisted on disk (here failed) is omitted.
 *  - AC3: a capable job owner never sees another user's job, and vice versa.
 *  - AC4: an anonymous or single-capability caller is refused 403.
 *  - AC5: the listing is { jobs: [] } when the caller has no live jobs.
 *  - AC6: after the caller cancels a listed job it drops from the listing, and
 *    after the caller consumes a listed (ready) job it drops too — both terminal
 *    ends of a job's life leave the slot-management listing.
 *  - AC7: the REST API version reports 2 (the coordinated cutover bump).
 *
 * A job that must be listed carrying progress — and the ready job the consume path
 * needs — is driven one bounded chunk per tick through the internal tick endpoint
 * (no real loopback or cron), exactly as the existing execution harness does, so
 * its listed state is a known point.
 *
 * @package Kntnt\Extractor
 * @since   0.1.1
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Config;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

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

// Dispatches the collection GET /extractions through the live REST server.
$get_extractions = static function (): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions' ) );
};

// Dispatches DELETE /extractions/{id} (the caller's cancel) through the server.
$cancel = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/kntnt-extractor/v1/extractions/' . $id ) );
};

// Dispatches POST /extractions/{id}/consume (the caller's confirmed download).
$consume = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/consume' ) );
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret; each call
// advances the build exactly one bounded chunk.
$tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Reads a job's persisted per-job tick secret from its on-disk state.
$secret_of = static function ( string $work, string $id ): string {
	$state = is_file( $work . '/' . $id . '/job.json' ) ? json_decode( (string) file_get_contents( $work . '/' . $id . '/job.json' ), true ) : null;
	return is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '';
};

// Drives a job all the way to ready, one bounded chunk per tick, so the consume
// path has a genuine ready artifact to confirm.
$drive_to_ready = static function ( string $id, string $secret ) use ( $tick ): void {
	for ( $i = 0; $i < 20; $i++ ) {
		$state = (string) ( $tick( $id, $secret )->get_data()['state'] ?? '' );
		if ( $state === 'ready' || $state === 'failed' ) {
			return;
		}
	}
};

// Extracts the { jobs: [...] } array of ids from a listing response body.
$listed_ids = static function ( WP_REST_Response $response ): array {
	$data = $response->get_data();
	$jobs = is_array( $data ) && is_array( $data['jobs'] ?? null ) ? $data['jobs'] : [];
	return array_map( static fn( $job ): string => is_array( $job ) && is_string( $job['id'] ?? null ) ? $job['id'] : '', $jobs );
};

// Finds one listed job entry by id, or null when the listing omits it.
$entry_for = static function ( WP_REST_Response $response, string $id ): ?array {
	$data = $response->get_data();
	$jobs = is_array( $data ) && is_array( $data['jobs'] ?? null ) ? $data['jobs'] : [];
	foreach ( $jobs as $job ) {
		if ( is_array( $job ) && ( $job['id'] ?? null ) === $id ) {
			return $job;
		}
	}
	return null;
};

$operate = 'kntnt_extractor_operate';

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// The owning administrator holds both capabilities; capture the id up front so the
// ownership checks stay unambiguous once a second admin exists.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so the
// run owns all of its state and cleans it up afterwards.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-list-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so every job this file needs can coexist; the
// listing surface is unrelated to the create ceiling.
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Short-circuit every loopback the code fires so a nudge never touches the real
// network — the build is driven deterministically by explicit tick calls instead.
$intercept = static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// A selection every install actually has: two core tables and two single-part
// core files, so a driven job advances through known progress values. The public
// key is a fresh ephemeral X25519 public half for each create.
$selection = static fn(): array => [
	'tables' => [ $GLOBALS['wpdb']->options, $GLOBALS['wpdb']->users ],
	'files' => [ 'wp-load.php', 'wp-settings.php' ],
	'public_key' => base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) ),
];

// --- AC4: neither an anonymous nor a single-capability caller may list ---

wp_set_current_user( 0 );
kntnt_extractor_assert( $get_extractions()->get_status() === 403, 'An anonymous caller is refused the listing (403) (AC4)' );

// The gate is composite, so Operate alone (without manage_options) never admits.
$single = wp_insert_user( [ 'user_login' => 'kntnt_list_single_cap', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
$single_user = get_user_by( 'id', is_int( $single ) ? $single : 0 );
$single_user->add_cap( $operate );
wp_set_current_user( $single_user->ID );
kntnt_extractor_assert( current_user_can( $operate ) && ! current_user_can( 'manage_options' ), 'The single-capability caller holds Operate but not manage_options' );
kntnt_extractor_assert( $get_extractions()->get_status() === 403, 'A single-capability caller is refused the listing (403) (AC4)' );

// The gate is composite in the other direction too, so manage_options alone
// (without Operate) never admits — pinning both single-capability directions
// keeps the composite Authorizer (ADR-0002) from drifting to a manage_options-only
// check that would still pass every other assertion here.
$manage_only = wp_insert_user( [ 'user_login' => 'kntnt_list_manage_cap', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
$manage_user = get_user_by( 'id', is_int( $manage_only ) ? $manage_only : 0 );
$manage_user->add_cap( 'manage_options' );
wp_set_current_user( $manage_user->ID );
kntnt_extractor_assert( current_user_can( 'manage_options' ) && ! current_user_can( $operate ), 'The single-capability caller holds manage_options but not Operate' );
kntnt_extractor_assert( $get_extractions()->get_status() === 403, 'A manage_options-only caller is refused the listing (403) (AC4)' );

// --- AC5: an authorized caller with no live jobs gets an empty listing ---

wp_set_current_user( $owner->ID );
$empty = $get_extractions();
kntnt_extractor_assert( $empty->get_status() === 200, 'An authorized listing responds 200 (AC5)' );
kntnt_extractor_assert( $empty->get_data() === [ 'jobs' => [] ], 'With no live jobs the listing is exactly { jobs: [] } (AC5)' );

// --- AC1: a queued job appears with the documented shape, no progress yet ---

$queued_id = (string) ( $post_extractions( $selection() )->get_data()['id'] ?? '' );
kntnt_extractor_assert( $queued_id !== '', 'The owner creates a job to list' );
$entry = $entry_for( $get_extractions(), $queued_id );
kntnt_extractor_assert( is_array( $entry ), 'A created job appears in the owner listing (AC1)' );
kntnt_extractor_assert( is_array( $entry ) && ( $entry['id'] ?? null ) === $queued_id, 'The listed job carries its id (AC1)' );
kntnt_extractor_assert( is_array( $entry ) && ( $entry['state'] ?? null ) === 'queued', 'The listed job carries its state (AC1)' );
kntnt_extractor_assert( is_array( $entry ) && is_int( $entry['created_at'] ?? null ) && $entry['created_at'] > 0, 'The listed job carries an integer created_at (AC1)' );
kntnt_extractor_assert( is_array( $entry ) && is_int( $entry['updated_at'] ?? null ) && $entry['updated_at'] > 0, 'The listed job carries an integer updated_at (AC1)' );
kntnt_extractor_assert( is_array( $entry ) && ! array_key_exists( 'progress', $entry ), 'A queued job that has not advanced omits progress (AC1)' );
kntnt_extractor_assert( is_array( $entry ) && ! array_key_exists( 'download_url', $entry ), 'The listing never carries a download_url — delivery stays the per-job poll' );

// --- AC1: a running job carries progress in the same shape as the poll ---

$running_id = (string) ( $post_extractions( $selection() )->get_data()['id'] ?? '' );
$tick( $running_id, $secret_of( $work, $running_id ) );
$running_entry = $entry_for( $get_extractions(), $running_id );
kntnt_extractor_assert( is_array( $running_entry ) && ( $running_entry['state'] ?? null ) === 'running', 'A driven job lists as running (AC1)' );
$progress = is_array( $running_entry ) && is_array( $running_entry['progress'] ?? null ) ? $running_entry['progress'] : null;
kntnt_extractor_assert( is_array( $progress ), 'A running job carries a progress object in the listing (AC1)' );
$progress_keys = is_array( $progress ) ? array_keys( $progress ) : [];
sort( $progress_keys );
kntnt_extractor_assert( $progress_keys === [ 'files_done', 'files_total', 'tables_done', 'tables_total' ], 'The listed progress is the same four-counter shape as the poll (AC1)' );
kntnt_extractor_assert( is_array( $progress ) && ( $progress['tables_total'] ?? null ) === 2 && ( $progress['files_total'] ?? null ) === 2, 'The listed progress reflects the selection sizes (AC1)' );

// --- AC2: a terminal job persisted on disk is omitted ---

// Seed a real failed job on disk through the store: create a queued job, then save
// it back in a terminal state. all() reads it, so the listing must filter it out.
$store = new Job_Store( new Config() );
$terminal_id = (string) ( $post_extractions( $selection() )->get_data()['id'] ?? '' );
$terminal_job = $store->find( $terminal_id );
kntnt_extractor_assert( $terminal_job !== null, 'The job seeded for the terminal case exists on disk' );
$store->save( $terminal_job->with_state( Job_State::Failed ) );
kntnt_extractor_assert( $store->find( $terminal_id )->state === Job_State::Failed, 'The seeded job is now persisted terminal (failed)' );
kntnt_extractor_assert( ! in_array( $terminal_id, $listed_ids( $get_extractions() ), true ), 'A terminal (failed) job is omitted from the listing (AC2)' );

// Seed a second terminal job in the expired state — the TTL-sweep end of a job's
// life — so a hand-rolled state list that omits Job_State::Expired cannot pass
// while an expired job pollutes the recovery listing the health check enumerates.
$expired_id = (string) ( $post_extractions( $selection() )->get_data()['id'] ?? '' );
$expired_job = $store->find( $expired_id );
kntnt_extractor_assert( $expired_job !== null, 'The job seeded for the expired case exists on disk' );
$store->save( $expired_job->with_state( Job_State::Expired ) );
kntnt_extractor_assert( $store->find( $expired_id )->state === Job_State::Expired, 'The seeded job is now persisted terminal (expired)' );
kntnt_extractor_assert( ! in_array( $expired_id, $listed_ids( $get_extractions() ), true ), 'A terminal (expired) job is omitted from the listing (AC2)' );

// --- AC3: a job owned by another user never appears in this caller's listing ---

$other_admin = wp_insert_user( [ 'user_login' => 'kntnt_list_other_admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
wp_set_current_user( is_int( $other_admin ) ? $other_admin : 0 );
kntnt_extractor_assert( current_user_can( $operate ) && current_user_can( 'manage_options' ), 'The second administrator holds both capabilities' );
$other_id = (string) ( $post_extractions( $selection() )->get_data()['id'] ?? '' );
$other_listing_ids = $listed_ids( $get_extractions() );
kntnt_extractor_assert( in_array( $other_id, $other_listing_ids, true ), 'The second admin sees its own live job' );
kntnt_extractor_assert( ! in_array( $queued_id, $other_listing_ids, true ) && ! in_array( $running_id, $other_listing_ids, true ), 'The second admin never sees the owner\'s jobs (AC3)' );

// And symmetrically: the owner never sees the second admin's job.
wp_set_current_user( $owner->ID );
kntnt_extractor_assert( ! in_array( $other_id, $listed_ids( $get_extractions() ), true ), 'The owner never sees the second admin\'s job (AC3)' );

// --- AC6 (cancel): after the owner cancels a listed job, it drops from the listing ---

kntnt_extractor_assert( in_array( $queued_id, $listed_ids( $get_extractions() ), true ), 'The job to cancel is listed before the cancel (AC6)' );
kntnt_extractor_assert( $cancel( $queued_id )->get_status() === 200, 'The owner cancels the listed job (200) (AC6)' );
kntnt_extractor_assert( ! in_array( $queued_id, $listed_ids( $get_extractions() ), true ), 'A cancelled job drops from the listing (AC6)' );

// --- AC6 (consume): after the owner consumes a listed ready job, it drops too ---

// Drive a fresh job all the way to ready — a ready job is non-terminal and still
// holds the slot, so it must list until the consume confirms its delivery.
$ready_id = (string) ( $post_extractions( $selection() )->get_data()['id'] ?? '' );
$drive_to_ready( $ready_id, $secret_of( $work, $ready_id ) );
$ready_entry = $entry_for( $get_extractions(), $ready_id );
kntnt_extractor_assert( is_array( $ready_entry ) && ( $ready_entry['state'] ?? null ) === 'ready', 'A ready job is listed before the consume (AC6)' );
kntnt_extractor_assert( is_array( $ready_entry ) && ! array_key_exists( 'download_url', $ready_entry ), 'Even a ready listed job carries no download_url (AC6)' );
kntnt_extractor_assert( $consume( $ready_id )->get_status() === 200, 'The owner consumes the listed ready job (200) (AC6)' );
kntnt_extractor_assert( ! in_array( $ready_id, $listed_ids( $get_extractions() ), true ), 'A consumed job drops from the listing (AC6)' );

// --- AC7: the REST API version reports 2 (the coordinated cutover bump) ---

$status = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/status' ) )->get_data();
kntnt_extractor_assert( is_array( $status ) && ( $status['api_version'] ?? null ) === 2, 'GET /status reports api_version 2 (AC7)' );

// Leave the suite state clean for later files, including the served downloads sibling.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

<?php
/**
 * Integration test: a job carries a bounded attempt log, distinct from the audit log.
 *
 * `GET /audit-log` records only the `kntnt_extractor_job_ready` transition — the
 * instant an artifact becomes downloadable (ADR-0004/0006). That is correct and
 * stays pinned by audit-log-test.php AC7. What was missing is a separate, small
 * surface that answers "what was attempted" for a long or stuck run.
 *
 * This file pins that surface (ADR-0016):
 *  - AC1: a tick records the chunk it began; `GET /extractions/{id}` reports
 *    those attempts (kind, name, offset, at) once any exist, and a queued poll
 *    omits the member.
 *  - AC2: the on-disk ring is last-N, not unbounded — more than N begins leave
 *    only the newest N.
 *  - AC3: `GET /audit-log` still has no attempt rows, and a job that fails
 *    still appends nothing there.
 *  - AC4: the persisted record holds no selected path, no restricted path, no
 *    SQL, and no Application Password — only `at`, `kind`, `n`, and `offset`.
 *  - AC5: the REST API version stays 6; the poll member is additive.
 *  - AC6: a schema-8 record written without the field still parses.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Audit_Log;
use Kntnt\Extractor\Config;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Extraction_Job;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Rest\Status_Controller;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Recursively removes a directory tree so the suite leaves no working directory
 * behind on the host.
 *
 * @param string $dir Directory to remove.
 * @return void
 */
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

/**
 * Dispatches POST /extractions with a JSON body through the live REST server.
 *
 * @param array<string, mixed> $body Body to send, JSON-encoded.
 * @return WP_REST_Response
 */
$post_extractions = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $request );
};

/**
 * Dispatches GET /extractions/{id} through the live REST server.
 *
 * @param string $id Job identifier.
 * @return array<string, mixed>
 */
$get_extraction = static function ( string $id ): array {
	$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) )->get_data();
	return is_array( $data ) ? $data : [];
};

/**
 * Dispatches POST /extractions/{id}/tick carrying the per-job secret.
 *
 * @param string $id     Job identifier.
 * @param string $secret Per-job tick secret.
 * @return WP_REST_Response
 */
$tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

/**
 * Dispatches GET /audit-log through the live REST server.
 *
 * @return array<string, mixed>
 */
$get_audit = static function (): array {
	$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/audit-log' ) )->get_data();
	return is_array( $data ) ? $data : [];
};

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-attempts-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

$intercept = static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

$public_key = base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) );

// Start the audit log from a clean slate so AC3's counts are this file's own.
$existing = get_option( Audit_Log::OPTION );
if ( is_string( $existing ) && is_file( $existing ) ) {
	unlink( $existing );
}
delete_option( Audit_Log::OPTION );

wp_set_current_user( $owner->ID );

// --- AC1: a tick records the chunk it began, and a queued poll omits the member ---

$created = $post_extractions( [
	'tables' => [ $wpdb->options ],
	'files' => [ 'wp-load.php' ],
	'public_key' => $public_key,
] )->get_data();
$id = is_array( $created ) && is_string( $created['id'] ?? null ) ? $created['id'] : '';
kntnt_extractor_assert( $id !== '', 'POST /extractions creates the job the attempt log is recorded on' );

$queued = $get_extraction( $id );
kntnt_extractor_assert( ( $queued['state'] ?? null ) === 'queued', 'The fresh job polls as queued' );
kntnt_extractor_assert( ! array_key_exists( 'attempts', $queued ), 'A queued poll omits attempts (AC1)' );

$state_file = $work . '/' . $id . '/state.json';
$state = json_decode( (string) file_get_contents( $state_file ), true );
$secret = is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '';

$tick( $id, $secret );
$after_first = $get_extraction( $id );
kntnt_extractor_assert( ( $after_first['state'] ?? null ) === 'running', 'The first tick leaves the job running (AC1)' );
kntnt_extractor_assert( is_array( $after_first['attempts'] ?? null ), 'A running poll returns the attempt log (AC1)' );
$first = is_array( $after_first['attempts'] ?? null ) ? $after_first['attempts'] : [];
kntnt_extractor_assert( count( $first ) === 1, 'The first tick records exactly one attempt (AC1)' );
kntnt_extractor_assert(
	( $first[0]['kind'] ?? null ) === 'table'
		&& ( $first[0]['name'] ?? null ) === $wpdb->options
		&& ( $first[0]['offset'] ?? null ) === 0
		&& isset( $first[0]['at'] ) && is_int( $first[0]['at'] ) && $first[0]['at'] > 0,
	'The first attempt names the opening table chunk (AC1)',
);

$tick( $id, $secret );
$after_second = $get_extraction( $id );
$second = is_array( $after_second['attempts'] ?? null ) ? $after_second['attempts'] : [];
kntnt_extractor_assert( count( $second ) === 2, 'A second tick appends a second attempt (AC1)' );
kntnt_extractor_assert(
	( $second[1]['kind'] ?? null ) === 'file'
		&& ( $second[1]['name'] ?? null ) === 'wp-load.php'
		&& ( $second[1]['offset'] ?? null ) === 0,
	'The second attempt names the file chunk (AC1)',
);

// --- AC4: the persisted ring is scalars only — no path, no SQL, no secret ------

$state_after = json_decode( (string) file_get_contents( $state_file ), true );
$log = is_array( $state_after ) && is_array( $state_after['attempt_log'] ?? null ) ? $state_after['attempt_log'] : [];
kntnt_extractor_assert( $log !== [], 'The state file persists the attempt log (AC4)' );
foreach ( $log as $entry ) {
	kntnt_extractor_assert(
		is_array( $entry )
			&& array_keys( $entry ) === [ 'at', 'kind', 'n', 'offset' ]
			&& is_int( $entry['at'] )
			&& is_string( $entry['kind'] )
			&& is_int( $entry['n'] )
			&& is_int( $entry['offset'] ),
		'Each persisted attempt is only at/kind/n/offset (AC4)',
	);
}
$state_raw = (string) file_get_contents( $state_file );
kntnt_extractor_assert(
	! str_contains( $state_raw, 'wp-load.php' )
		&& ! str_contains( $state_raw, 'wp-config.php' )
		&& ! str_contains( $state_raw, 'SELECT' )
		&& ! str_contains( $state_raw, 'Application Password' ),
	'The state file carries no selected path, restricted path, SQL, or Application Password (AC4)',
);

// --- AC2: the ring is last-N, not unbounded ------------------------------------

$store = new Job_Store( new Config() );
$job = $store->find( $id );
kntnt_extractor_assert( $job instanceof Extraction_Job, 'The job is still readable after the ticks (AC2)' );

$bound = Extraction_Job::ATTEMPT_LOG_BOUND;
$grown = $job;
for ( $i = 0; $i < $bound + 4; $i++ ) {
	$grown = $grown->with_attempt();
}
$store->save( $grown );

$trimmed_state = json_decode( (string) file_get_contents( $state_file ), true );
$trimmed_log = is_array( $trimmed_state ) && is_array( $trimmed_state['attempt_log'] ?? null ) ? $trimmed_state['attempt_log'] : [];
kntnt_extractor_assert(
	count( $trimmed_log ) === $bound,
	sprintf( 'More than %d begins leave only the newest %d (AC2)', $bound, $bound ),
);

$trimmed_poll = $get_extraction( $id );
$trimmed_attempts = is_array( $trimmed_poll['attempts'] ?? null ) ? $trimmed_poll['attempts'] : [];
kntnt_extractor_assert( count( $trimmed_attempts ) === $bound, 'The poll reports the same bounded ring (AC2)' );

// --- AC6: a schema-8 record written without the field still parses -------------

unset( $trimmed_state['attempt_log'] );
file_put_contents( $state_file, (string) wp_json_encode( $trimmed_state ) );
$reloaded = $store->find( $id );
kntnt_extractor_assert(
	$reloaded instanceof Extraction_Job && $reloaded->attempt_log === [],
	'A schema-8 record without attempt_log still parses as an empty log (AC6)',
);

// Restore a readable job so AC3 can fail it without a parse miss.
$store->save( $grown );

// --- AC3: the audit log is untouched by attempts, and a failure appends nothing ---

$before_audit = $get_audit();
$before_count = is_array( $before_audit['entries'] ?? null ) ? count( $before_audit['entries'] ) : 0;

$fail_created = $post_extractions( [
	'tables' => [ $wpdb->options, $wpdb->users ],
	'files' => [],
	'public_key' => $public_key,
] )->get_data();
$fail_id = is_array( $fail_created ) && is_string( $fail_created['id'] ?? null ) ? $fail_created['id'] : '';
$fail_state_file = $work . '/' . $fail_id . '/state.json';
$fail_state = json_decode( (string) file_get_contents( $fail_state_file ), true );
$fail_secret = is_array( $fail_state ) && is_string( $fail_state['tick_secret'] ?? null ) ? $fail_state['tick_secret'] : '';

// One real begin so the failed poll has something to show, then plant the floor
// so the next tick fails rather than adapting.
$tick( $fail_id, $fail_secret );
$fail_state = json_decode( (string) file_get_contents( $fail_state_file ), true );
if ( is_array( $fail_state ) ) {
	$fail_state['attempts'] = 3;
	$fail_state['table_chunk_bytes'] = 1;
	$fail_state['table_chunk_rows'] = 1;
	file_put_contents( $fail_state_file, (string) wp_json_encode( $fail_state ) );
}
$tick( $fail_id, $fail_secret );

$failed_poll = $get_extraction( $fail_id );
kntnt_extractor_assert( ( $failed_poll['state'] ?? null ) === 'failed', 'The planted floor stall reaches failed (AC3)' );
kntnt_extractor_assert( is_array( $failed_poll['attempts'] ?? null ) && $failed_poll['attempts'] !== [], 'A failed poll still carries the attempt log (AC3)' );

$after_audit = $get_audit();
$after_entries = is_array( $after_audit['entries'] ?? null ) ? $after_audit['entries'] : [];
kntnt_extractor_assert( count( $after_entries ) === $before_count, 'A failed extraction appends no audit entry (AC3)' );
foreach ( $after_entries as $entry ) {
	kntnt_extractor_assert(
		is_array( $entry ) && ! array_key_exists( 'attempts', $entry ) && ! array_key_exists( 'attempt_log', $entry ),
		'GET /audit-log entries carry no attempt rows (AC3)',
	);
}

// --- AC5: the REST contract stays at api_version 6 -----------------------------

$status = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . Status_Controller::REST_NAMESPACE . '/status' ) )->get_data();
kntnt_extractor_assert(
	is_array( $status ) && ( $status['api_version'] ?? null ) === 6,
	'GET /status reports api_version 6 (AC5)',
);

remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'pre_http_request', $intercept );
$rmrf( $work );

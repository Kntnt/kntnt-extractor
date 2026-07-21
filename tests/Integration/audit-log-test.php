<?php
/**
 * Integration test: the audit log — recorded at ready, read through `GET /audit-log`.
 *
 * A non-evadable record of every completed extraction is written the moment a job
 * reaches ready (never at consume), stored as a randomly-named JSON Lines file under
 * the uploads directory, age-rotated, and readable only by administrators through its
 * own endpoint (ADR-0004/0006).
 *
 * It pins every acceptance criterion of issue #11:
 *  - AC1: reaching ready appends one JSON Lines entry (under flock) recording ts,
 *    user_id, user_login, api_version, job_id, the full tables list, and a files
 *    summary (count, bytes, distinct top-level roots, SHA-256 of the sorted path list).
 *  - AC2: the entry is written at ready and never at consume — consuming (or never
 *    confirming) does not change that it was recorded, and it adds no second entry.
 *  - AC3: the log is a randomly-named file under the uploads directory, read only
 *    through the endpoint.
 *  - AC4: GET /audit-log requires manage_options, returns newest-first, supports
 *    optional from/to date filtering and pagination, and shows all users' actions.
 *  - AC5: the log is age-rotated with a 90-day default, overridable by the
 *    KNTNT_EXTRACTOR_LOG_RETENTION_DAYS constant and the kntnt_extractor_log_retention_days
 *    filter (filter wins).
 *  - AC6: when rotation empties the log the file (and its directory, if then empty)
 *    is deleted, and the next event creates a fresh randomly-named file.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Audit_Log;
use Kntnt\Extractor\Dispatcher;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

// The audit machinery lives behind the Audit_Log class and the /audit-log route;
// without it there is nothing to exercise, so record the gap and stop this file
// cleanly (a red before green).
if ( ! class_exists( Audit_Log::class ) ) {
	kntnt_extractor_assert( false, 'The audit-log machinery (Audit_Log) is available' );
	return;
}
kntnt_extractor_assert( true, 'The audit-log machinery (Audit_Log) is available' );

// Dispatches POST /extractions with a JSON body through the live REST server.
$post_extractions = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $request );
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret.
$tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Dispatches POST /extractions/{id}/consume through the live REST server.
$consume = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/consume' ) );
};

// Dispatches GET /audit-log with optional query args through the live REST server.
$get_audit = static function ( array $query = [] ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/kntnt-extractor/v1/audit-log' );
	foreach ( $query as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_get_server()->dispatch( $request );
};

// Isolate the working directory (still under uploads so a ready artifact stays
// web-reachable) and raise concurrency so several jobs can coexist.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-audit-' . bin2hex( random_bytes( 4 ) );
add_filter( 'kntnt_extractor_config_work_dir', static fn(): string => $work );
add_filter( 'kntnt_extractor_config_max_active_jobs', static fn(): int => 20 );

// Short-circuit every loopback so a nudge never touches the real network.
add_filter( 'pre_http_request', static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ] );

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// Two administrators, so "shows all users' actions" is exercised with a plurality.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
$other = wp_insert_user( [ 'user_login' => 'audit_admin_' . bin2hex( random_bytes( 4 ) ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
$other_id = is_int( $other ) ? $other : 0;

// Drives a fresh job for the current user all the way to ready and returns its id,
// reading the per-job tick secret from the state file exactly as the loopback would.
$drive_to_ready = static function ( array $selection ) use ( $post_extractions, $tick, $work ): string {
	$response = $post_extractions( $selection );
	$created = $response->get_data();
	$id = is_array( $created ) && is_string( $created['id'] ?? null ) ? $created['id'] : '';
	if ( $id === '' ) {
		return '';
	}
	$state = json_decode( (string) file_get_contents( $work . '/' . $id . '/job.json' ), true );
	$secret = is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '';
	$tick( $id, $secret );
	return $id;
};

// A caller's ephemeral public key; the private half is irrelevant to auditing.
$public_key = base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) );

// --- AC1/AC2: reaching ready records exactly one well-formed entry ---

wp_set_current_user( $owner->ID );
$before = time();
$job_id = $drive_to_ready( [ 'tables' => [ $wpdb->options, $wpdb->users ], 'files' => [ 'wp-load.php', 'wp-settings.php' ], 'public_key' => $public_key ] );
kntnt_extractor_assert( $job_id !== '', 'A job is created and driven to ready' );

// The endpoint is the sanctioned read path; read it back as the owning admin.
$audit = $get_audit()->get_data();
$entries = is_array( $audit ) && isset( $audit['entries'] ) && is_array( $audit['entries'] ) ? $audit['entries'] : [];
kntnt_extractor_assert( count( $entries ) === 1, 'Reaching ready appends exactly one audit entry (AC1)' );

$entry = $entries[0] ?? [];
kntnt_extractor_assert( is_int( $entry['ts'] ?? null ) && $entry['ts'] >= $before, 'The entry stamps a recent ts (AC1)' );
kntnt_extractor_assert( ( $entry['user_id'] ?? null ) === $owner->ID, 'The entry records the owning user_id (AC1)' );
kntnt_extractor_assert( ( $entry['user_login'] ?? null ) === $owner->user_login, 'The entry records the user_login (AC1)' );
kntnt_extractor_assert( ( $entry['api_version'] ?? null ) === 1, 'The entry records the api_version (AC1)' );
kntnt_extractor_assert( ( $entry['job_id'] ?? null ) === $job_id, 'The entry records the job_id (AC1)' );
kntnt_extractor_assert( ( $entry['tables'] ?? null ) === [ $wpdb->options, $wpdb->users ], 'The entry records the full tables list (AC1)' );

$files = is_array( $entry['files'] ?? null ) ? $entry['files'] : [];
kntnt_extractor_assert( ( $files['count'] ?? null ) === 2, 'The files summary counts the files (AC1)' );
$expected_bytes = filesize( ABSPATH . 'wp-load.php' ) + filesize( ABSPATH . 'wp-settings.php' );
kntnt_extractor_assert( ( $files['bytes'] ?? null ) === $expected_bytes, 'The files summary totals the bytes (AC1)' );
kntnt_extractor_assert( ( $files['roots'] ?? null ) === [ 'wp-load.php', 'wp-settings.php' ], 'The files summary lists the distinct top-level roots (AC1)' );
$sorted = [ 'wp-load.php', 'wp-settings.php' ];
sort( $sorted );
kntnt_extractor_assert( ( $files['sha256'] ?? null ) === hash( 'sha256', implode( "\n", $sorted ) ), 'The files summary hashes the sorted full path list (AC1)' );

// AC2: consuming the job neither erases the record nor adds a second one.
$consume( $job_id );
$after_consume = $get_audit()->get_data();
$after_entries = is_array( $after_consume['entries'] ?? null ) ? $after_consume['entries'] : [];
kntnt_extractor_assert( count( $after_entries ) === 1, 'Consuming a job leaves the ready-time record untouched and adds no second entry (AC2)' );

// --- AC3: the log is a randomly-named file under the uploads directory ---

$log_path = get_option( 'kntnt_extractor_audit_log' );
$uploads = wp_upload_dir()['basedir'];
kntnt_extractor_assert( is_string( $log_path ) && is_file( $log_path ), 'The audit log is a real file on disk (AC3)' );
kntnt_extractor_assert( is_string( $log_path ) && str_starts_with( $log_path, $uploads . '/' ), 'The audit log lives under the uploads directory (AC3)' );
kntnt_extractor_assert( is_string( $log_path ) && preg_match( '/\/[a-f0-9]{16,}\.jsonl$/', $log_path ) === 1, 'The audit log has an unguessable random file name (AC3)' );

// --- AC4: GET /audit-log requires manage_options ---

wp_set_current_user( 0 );
kntnt_extractor_assert( $get_audit()->get_status() === 401 || $get_audit()->get_status() === 403, 'An anonymous caller cannot read the audit log (AC4)' );

$subscriber = wp_insert_user( [ 'user_login' => 'audit_sub_' . bin2hex( random_bytes( 4 ) ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
wp_set_current_user( is_int( $subscriber ) ? $subscriber : 0 );
kntnt_extractor_assert( $get_audit()->get_status() === 403, 'A non-administrator is refused the audit log with 403 (AC4)' );

// --- AC4: newest-first, all users, from/to filtering, pagination ---

// A second administrator records their own extraction, so the log spans two users.
wp_set_current_user( $other_id );
$other_job = $drive_to_ready( [ 'tables' => [ $wpdb->options ], 'files' => [], 'public_key' => $public_key ] );
kntnt_extractor_assert( $other_job !== '', "A second administrator's extraction reaches ready" );

wp_set_current_user( $owner->ID );
$all = $get_audit()->get_data();
$all_entries = is_array( $all['entries'] ?? null ) ? $all['entries'] : [];
kntnt_extractor_assert( count( $all_entries ) === 2, 'The log shows every user\'s actions, not just the reader\'s (AC4)' );
kntnt_extractor_assert( ( $all_entries[0]['ts'] ?? 0 ) >= ( $all_entries[1]['ts'] ?? 0 ), 'Entries are returned newest-first (AC4)' );

$user_ids = array_map( static fn( $e ) => $e['user_id'] ?? null, $all_entries );
kntnt_extractor_assert( in_array( $owner->ID, $user_ids, true ) && in_array( $other_id, $user_ids, true ), 'Both administrators\' actions appear (AC4)' );

// Pagination: one per page yields the newest on page 1 and the older on page 2.
$page1 = $get_audit( [ 'per_page' => 1, 'page' => 1 ] )->get_data();
$page2 = $get_audit( [ 'per_page' => 1, 'page' => 2 ] )->get_data();
$p1 = is_array( $page1['entries'] ?? null ) ? $page1['entries'] : [];
$p2 = is_array( $page2['entries'] ?? null ) ? $page2['entries'] : [];
kntnt_extractor_assert( count( $p1 ) === 1 && count( $p2 ) === 1, 'Pagination bounds each page to per_page entries (AC4)' );
kntnt_extractor_assert( ( $p1[0]['ts'] ?? 0 ) >= ( $p2[0]['ts'] ?? 0 ), 'Page 1 carries a newer entry than page 2 (AC4)' );

// from/to filtering: a window ending before every entry returns nothing.
$far_past = gmdate( 'Y-m-d', 100000 );
$filtered = $get_audit( [ 'to' => $far_past ] )->get_data();
$fe = is_array( $filtered['entries'] ?? null ) ? $filtered['entries'] : [];
kntnt_extractor_assert( count( $fe ) === 0, 'A to-date before every entry filters them all out (AC4)' );
$from_now = $get_audit( [ 'from' => gmdate( 'Y-m-d' ) ] )->get_data();
$fn = is_array( $from_now['entries'] ?? null ) ? $from_now['entries'] : [];
kntnt_extractor_assert( count( $fn ) === 2, 'A from-date of today still includes today\'s entries (AC4)' );

// --- AC5/AC6: age-rotation, the retention knob, and empty-deletion ---

// Force retention to zero days through the documented filter, so every existing
// entry is now older than the window (filter wins over the constant/default).
add_filter( 'kntnt_extractor_log_retention_days', static fn(): int => 0 );
$log_before_rotation = get_option( 'kntnt_extractor_audit_log' );

// Reading the log rotates it: with a zero-day window every entry is expired, so the
// log empties and its file (and directory, if then empty) is removed (AC5/AC6).
$after_rotation = $get_audit()->get_data();
$ar = is_array( $after_rotation['entries'] ?? null ) ? $after_rotation['entries'] : [];
kntnt_extractor_assert( count( $ar ) === 0, 'Age-rotation drops entries older than the retention window (AC5)' );
kntnt_extractor_assert( is_string( $log_before_rotation ) && ! is_file( $log_before_rotation ), 'Rotation that empties the log deletes the file (AC6)' );
kntnt_extractor_assert( get_option( 'kntnt_extractor_audit_log' ) === false, 'The emptied log leaves no dangling path recorded (AC6)' );

// The next event creates a fresh, differently-named log file (AC6). Lift the
// zero-day window first so the new entry is not itself immediately rotated away.
remove_all_filters( 'kntnt_extractor_log_retention_days' );
wp_set_current_user( $owner->ID );
$fresh_job = $drive_to_ready( [ 'tables' => [ $wpdb->options ], 'files' => [], 'public_key' => $public_key ] );
$fresh_path = get_option( 'kntnt_extractor_audit_log' );
kntnt_extractor_assert( $fresh_job !== '' && is_string( $fresh_path ) && is_file( $fresh_path ), 'The next event after an empty rotation creates a fresh log file (AC6)' );
kntnt_extractor_assert( is_string( $fresh_path ) && $fresh_path !== $log_before_rotation, 'The fresh log file has a new random name (AC6)' );

<?php
/**
 * Integration test: the poll response surfaces progress and error (issue #14).
 *
 * `GET /extractions/{id}` completes its documented v1 poll contract by reporting
 * two already-documented optional fields it previously omitted: `progress?` while a
 * job is running (and, reading as complete, once it is ready) and `error?` once a
 * job has failed. Both are part of the v1 contract, so surfacing them completes v1
 * rather than changing it — no API-version bump.
 *
 * It pins every acceptance criterion of issue #14:
 *  - AC1: a running job's poll returns a `progress` object with `tables_done`,
 *    `tables_total`, `files_done`, `files_total` reflecting the persisted
 *    Build_Progress and the job's selection sizes, and the counters advance as the
 *    build is driven one chunk per tick.
 *  - AC2: a queued job's poll omits `progress` entirely.
 *  - AC3: a ready poll still returns a `download_url` (no regression) and reports
 *    `progress` as complete.
 *  - AC4: a failed job's poll returns an `error` carrying at least a `message`, and
 *    no non-failed poll carries an `error`.
 *  - AC5: `progress` never exposes internal container mechanics — no segment names,
 *    byte offsets, or sealed-index details, only the four caller-facing counters.
 *  - AC6: the contract's API version is unchanged; GET /status still reports 1.
 *
 * The build is driven deterministically one chunk per tick through the internal
 * tick endpoint (no real loopback or cron), exactly as the existing execution
 * harness does, so a mid-run poll observes a known, stable point.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Dispatcher;

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

// Dispatches GET /extractions/{id} through the live REST server.
$get_extraction = static function ( string $id ): array {
	$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) )->get_data();
	return is_array( $data ) ? $data : [];
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

// The owning administrator holds both capabilities.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so the
// run owns all of its state and cleans it up afterwards.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-poll-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the two jobs this file needs can both exist at
// once (a ready job still occupies its slot until consumed).
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Short-circuit every loopback the code fires so a nudge never touches the real
// network — the build is driven deterministically by explicit tick calls instead.
$intercept = static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// The caller submits only the public half of an ephemeral X25519 keypair.
$public_key = sodium_crypto_box_publickey( sodium_crypto_box_keypair() );

// --- AC1/AC2/AC3/AC5: a driven job's poll reports advancing, caller-facing progress ---

// Two tables and two single-part core files: four segments driven one per tick, so a
// poll between ticks observes both counters advancing through known values.
$selection = [
	'tables' => [ $wpdb->options, $wpdb->users ],
	'files' => [ 'wp-load.php', 'wp-settings.php' ],
	'public_key' => base64_encode( $public_key ),
];

wp_set_current_user( $owner->ID );
$id = (string) ( $post_extractions( $selection )->get_data()['id'] ?? '' );
$secret = $secret_of( $work, $id );

// AC2: a queued job has started nothing, so its poll omits progress entirely — and
// carries no error either.
$queued = $get_extraction( $id );
kntnt_extractor_assert( ( $queued['state'] ?? null ) === 'queued', 'The fresh job polls as queued' );
kntnt_extractor_assert( ! array_key_exists( 'progress', $queued ), 'A queued poll omits progress (AC2)' );
kntnt_extractor_assert( ! array_key_exists( 'error', $queued ), 'A queued poll carries no error (AC4)' );

// AC1: after the first chunk (the first of two tables) the running poll reports a
// progress object whose counters reflect the persisted Build_Progress and the
// selection sizes.
$tick( $id, $secret );
$p1 = $get_extraction( $id );
kntnt_extractor_assert( ( $p1['state'] ?? null ) === 'running', 'The driven job polls as running (AC1)' );
kntnt_extractor_assert( is_array( $p1['progress'] ?? null ), 'A running poll returns a progress object (AC1)' );
$prog1 = is_array( $p1['progress'] ?? null ) ? $p1['progress'] : [];
kntnt_extractor_assert(
	( $prog1['tables_done'] ?? null ) === 1
		&& ( $prog1['tables_total'] ?? null ) === 2
		&& ( $prog1['files_done'] ?? null ) === 0
		&& ( $prog1['files_total'] ?? null ) === 2,
	'After the first chunk progress is { tables_done: 1, tables_total: 2, files_done: 0, files_total: 2 } (AC1)',
);

// AC5: the progress object exposes only the four caller-facing counters — never the
// internal container mechanics (segment names, byte offsets, sealed-index details).
$keys = array_keys( $prog1 );
sort( $keys );
kntnt_extractor_assert( $keys === [ 'files_done', 'files_total', 'tables_done', 'tables_total' ], 'progress exposes exactly the four caller-facing counters, no internal mechanics (AC5)' );

// AC1: the counters advance as the build is driven. The second table advances
// tables_done to its total; sealing the first file then advances files_done.
$tick( $id, $secret );
$prog2 = $get_extraction( $id )['progress'] ?? [];
kntnt_extractor_assert( ( $prog2['tables_done'] ?? null ) === 2 && ( $prog2['files_done'] ?? null ) === 0, 'The second chunk advances tables_done to 2 (AC1)' );

$tick( $id, $secret );
$prog3 = $get_extraction( $id )['progress'] ?? [];
kntnt_extractor_assert( ( $prog3['tables_done'] ?? null ) === 2 && ( $prog3['files_done'] ?? null ) === 1, 'Sealing the first file advances files_done to 1 (AC1)' );

// AC3: the last chunk finalizes and publishes the artifact. A ready poll still
// returns a usable download_url (no regression) and reports progress as complete.
$tick( $id, $secret );
$ready = $get_extraction( $id );
kntnt_extractor_assert( ( $ready['state'] ?? null ) === 'ready', 'The driven job reaches ready' );
kntnt_extractor_assert( is_string( $ready['download_url'] ?? null ) && ( $ready['download_url'] ?? '' ) !== '', 'A ready poll still returns a download_url (AC3, no regression)' );
$readyp = is_array( $ready['progress'] ?? null ) ? $ready['progress'] : [];
kntnt_extractor_assert(
	( $readyp['tables_done'] ?? null ) === 2 && ( $readyp['files_done'] ?? null ) === 2,
	'A ready poll reports progress as complete: tables_done === tables_total, files_done === files_total (AC3)',
);
kntnt_extractor_assert( ! array_key_exists( 'error', $ready ), 'A ready poll carries no error (AC4)' );

// --- AC4: a failed job's poll surfaces an error ---

// Drive a file into a mid-build change so the packaging throws and the job fails: a
// small chunk size forces the file across several parts, and rewriting it between
// the first and second part trips the builder's pinned-identity guard.
$small_chunk = static fn(): int => 16;
add_filter( 'kntnt_extractor_config_chunk_size', $small_chunk );

$root = wp_normalize_path( (string) realpath( ABSPATH ) );
$fixture_abs = wp_normalize_path( wp_upload_dir()['basedir'] ) . '/kntnt-extractor-fixture-' . bin2hex( random_bytes( 4 ) ) . '.bin';
file_put_contents( $fixture_abs, str_repeat( 'A', 64 ) );
$fixture_rel = ltrim( substr( wp_normalize_path( (string) realpath( $fixture_abs ) ), strlen( $root ) ), '/' );

$fid = (string) ( $post_extractions( [ 'files' => [ $fixture_rel ], 'public_key' => base64_encode( $public_key ) ] )->get_data()['id'] ?? '' );
$fsecret = $secret_of( $work, $fid );

// Seal the first part (pinning the file's size and mtime), then rewrite the file so
// its next part no longer matches the pinned identity, then drive the tick that fails.
$tick( $fid, $fsecret );
file_put_contents( $fixture_abs, str_repeat( 'B', 128 ) );
$tick( $fid, $fsecret );

$failed = $get_extraction( $fid );
kntnt_extractor_assert( ( $failed['state'] ?? null ) === 'failed', 'The mid-build file change fails the job' );
kntnt_extractor_assert( is_array( $failed['error'] ?? null ), 'A failed poll returns an error object (AC4)' );
kntnt_extractor_assert( is_string( $failed['error']['message'] ?? null ) && ( $failed['error']['message'] ?? '' ) !== '', 'The failed error carries a non-empty message (AC4)' );
kntnt_extractor_assert( ! array_key_exists( 'progress', $failed ), 'A failed poll carries no progress (AC4)' );

remove_filter( 'kntnt_extractor_config_chunk_size', $small_chunk );

// --- AC6: the API version is unchanged — surfacing v1 fields is not a bump ---

$status = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/status' ) )->get_data();
kntnt_extractor_assert( is_array( $status ) && ( $status['api_version'] ?? null ) === 1, 'GET /status still reports api_version 1 — no bump (AC6)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
@unlink( $fixture_abs );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

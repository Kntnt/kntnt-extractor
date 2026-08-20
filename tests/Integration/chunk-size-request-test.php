<?php
/**
 * Integration test: `POST /extractions` accepts an optional `chunk_size` (issue #28).
 *
 * The file-part budget decides whether a multi-hour extraction survives, and the
 * value that survives is host-specific — `docs/measurements/2026-08-19-chunk-size-curve.md`
 * measures 256 KB packaging a 36 MB file in 85 s on one production host while 4 MiB
 * does not finish it in twelve minutes. Until now the only lever was a `wp-config.php`
 * constant or a filter, so choosing it meant editing code on production. This pins the
 * per-request member that replaces that.
 *
 * It pins every acceptance criterion of issue #28:
 *  - AC1: omitting the member packages at the Config knob's value, exactly as before.
 *  - AC2: an in-range member is what the build actually spends, observed as the
 *    artifact's part count rather than as a configured value — a stale opcode or a
 *    snippet loaded too late would show up in neither the config nor the response,
 *    but cannot fake the number of sealed parts.
 *  - AC3: the accepted range is the existing Config seam's own — an integer of at
 *    least one, with no ceiling, because `Artifact_Builder::configured()` clamps the
 *    knob to `max( 1, … )` and bounds it nowhere above. A value outside it is refused
 *    with the create path's existing malformed-member refusal, `422
 *    kntnt_extractor_malformed_body`, exactly as a non-boolean `strict` is.
 *
 * The `honours` entry AC4 asks for is asserted in `rest-status-test.php`, beside every
 * other behaviour name that list carries.
 *
 * @package Kntnt\Extractor
 * @since   0.6.1
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Dispatcher;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

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
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Reads a job's persisted state file into a decoded array, or null when absent.
$read_state = static function ( string $work, string $id ): ?array {
	$path = $work . '/' . $id . '/state.json';
	$decoded = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $decoded ) ? $decoded : null;
};

// Lifts the sealed index out of a finished container — the trailer's length prefix
// names it, so no segment has to be walked to find it.
$sealed_index_of = static function ( string $raw ): string {
	$trailer_at = strlen( $raw ) - 8;
	$index_len = (int) unpack( 'P', substr( $raw, $trailer_at, 8 ) )[1];
	return substr( $raw, $trailer_at - $index_len, $index_len );
};

// Unseals and splits the length-prefixed index back into its ordered names, which
// is where a part count is read: one entry per sealed segment, in index order.
$open_index = static function ( string $sealed_index, string $keypair ): ?array {
	$plain = sodium_crypto_box_seal_open( $sealed_index, $keypair );
	if ( $plain === false ) {
		return null;
	}
	$names = [];
	$offset = 0;
	while ( $offset < strlen( $plain ) ) {
		$len = (int) unpack( 'P', substr( $plain, $offset, 8 ) )[1];
		$offset += 8;
		$names[] = substr( $plain, $offset, $len );
		$offset += $len;
	}
	return $names;
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so the
// artifact stays web-reachable while the run owns all of its state and cleans up.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-chunk-' . bin2hex( random_bytes( 4 ) );
$downloads = $work . '-downloads';
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the several jobs this file needs can coexist.
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// The site's own configured file-part budget, standing in for the constant or
// filter that was the only lever before this. Every assertion below is about
// whether a request can depart from it without touching it.
$configured_chunk_size = 32;
$force_chunk = static fn(): int => $configured_chunk_size;
add_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );

// Short-circuit every loopback the driver fires, so a nudge never touches the
// real network; the ticks below drive each job synchronously across chunks.
$intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// A fixture whose length is a whole multiple of both budgets under test, so each
// expected part count is exact rather than rounded.
$fixture_bytes = str_repeat( 'kntnt-extractor-chunk-size-fixture--', 96 / 4 );
$fixture_bytes = substr( $fixture_bytes, 0, 96 );
$fixture_abs = wp_upload_dir()['basedir'] . '/kntnt-extractor-chunk-fixture-' . bin2hex( random_bytes( 4 ) ) . '.bin';
file_put_contents( $fixture_abs, $fixture_bytes );
$root = wp_normalize_path( (string) realpath( ABSPATH ) );
$fixture_rel = ltrim( substr( wp_normalize_path( (string) realpath( $fixture_abs ) ), strlen( $root ) ), '/' );

// A caller's ephemeral X25519 keypair; only the public half is submitted.
$keypair = sodium_crypto_box_keypair();
$public_key = base64_encode( sodium_crypto_box_publickey( $keypair ) );

// Drives a queued job to ready one bounded chunk per tick, bounded so a driver
// that never finishes fails the test rather than hangs.
$drive_to_ready = static function ( string $id, string $secret ) use ( $tick, $get_extraction ): void {
	$ticks = 0;
	while ( $ticks < 200 ) {
		$state = $get_extraction( $id )->get_data();
		if ( is_array( $state ) && ( $state['state'] ?? null ) === 'ready' ) {
			return;
		}
		$tick( $id, $secret );
		++$ticks;
	}
};

// Creates a files-only job, drives it to ready, and answers how many parts its
// published artifact actually holds — the index carries one entry per sealed
// segment, and with a single file selected every entry is one of its parts.
$parts_packaged = static function ( array $body ) use ( $post_extractions, $get_extraction, $read_state, $drive_to_ready, $sealed_index_of, $open_index, $work, $keypair, $fixture_rel ): int {
	$created = $post_extractions( $body )->get_data();
	$id = is_array( $created ) && is_string( $created['id'] ?? null ) ? $created['id'] : '';
	if ( $id === '' ) {
		return 0;
	}
	$state = $read_state( $work, $id );
	$drive_to_ready( $id, is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '' );

	$poll = $get_extraction( $id )->get_data();
	$download_url = is_array( $poll ) && is_string( $poll['download_url'] ?? null ) ? $poll['download_url'] : '';
	$uploads = wp_upload_dir();
	$artifact_path = $download_url !== '' ? rtrim( $uploads['basedir'], '/' ) . substr( $download_url, strlen( rtrim( $uploads['baseurl'], '/' ) ) ) : '';
	if ( $artifact_path === '' || ! is_file( $artifact_path ) ) {
		return 0;
	}
	$names = $open_index( $sealed_index_of( (string) file_get_contents( $artifact_path ) ), $keypair );

	return is_array( $names ) ? count( array_filter( $names, static fn( string $name ): bool => $name === $fixture_rel ) ) : 0;
};

wp_set_current_user( $owner->ID );

// --- AC1: omitting the member packages at the site's configured budget ---

$default_parts = $parts_packaged( [ 'files' => [ $fixture_rel ], 'public_key' => $public_key ] );
kntnt_extractor_assert( $default_parts === 3, 'Omitting chunk_size packages the file at the Config knob\'s budget, unchanged (AC1)' );

// --- AC2: an in-range member is the budget the build actually spends ---

$requested_chunk_size = 12;
$requested_parts = $parts_packaged( [ 'files' => [ $fixture_rel ], 'public_key' => $public_key, 'chunk_size' => $requested_chunk_size ] );
kntnt_extractor_assert( $requested_parts === 8, 'A requested chunk_size is what the build spends, read off the artifact\'s part count (AC2)' );
kntnt_extractor_assert( $requested_parts !== $default_parts, 'The requested budget departs from the configured one without the knob moving (AC2)' );

// A request may also ask for a budget larger than the site's, which is the whole
// point of a per-run lever: the value that survives is host-specific in both
// directions, and the endpoint is not a floor on the knob.
$whole_file_parts = $parts_packaged( [ 'files' => [ $fixture_rel ], 'public_key' => $public_key, 'chunk_size' => 96 ] );
kntnt_extractor_assert( $whole_file_parts === 1, 'A chunk_size at or above the file\'s size packages it as a single part (AC2)' );

// --- AC3: the range is the Config seam's own, and out of it is a malformed member ---

// The seam's floor is one: Artifact_Builder::configured() clamps to max( 1, … ),
// so zero and negatives are values it can never put in force.
$zero = $post_extractions( [ 'files' => [ $fixture_rel ], 'public_key' => $public_key, 'chunk_size' => 0 ] );
$zero_data = $zero->get_data();
kntnt_extractor_assert( $zero->get_status() === 422, 'A chunk_size of zero is below the seam\'s floor and is refused 422 (AC3)' );
kntnt_extractor_assert( is_array( $zero_data ) && ( $zero_data['code'] ?? null ) === 'kntnt_extractor_malformed_body', 'An out-of-range chunk_size is the create path\'s existing malformed-member refusal, not a new code (AC3)' );

$negative = $post_extractions( [ 'files' => [ $fixture_rel ], 'public_key' => $public_key, 'chunk_size' => -1 ] );
kntnt_extractor_assert( $negative->get_status() === 422, 'A negative chunk_size is refused 422 (AC3)' );

// The type discipline is the create path's existing one — a member arrives as its
// JSON type or it is malformed, exactly as a non-boolean `strict` is — so a
// fractional or string-wrapped number is refused rather than coerced.
$fractional = $post_extractions( [ 'files' => [ $fixture_rel ], 'public_key' => $public_key, 'chunk_size' => 16.5 ] );
kntnt_extractor_assert( $fractional->get_status() === 422, 'A non-integer chunk_size is refused 422 (AC3)' );

$stringly = $post_extractions( [ 'files' => [ $fixture_rel ], 'public_key' => $public_key, 'chunk_size' => '65536' ] );
kntnt_extractor_assert( $stringly->get_status() === 422, 'A string-wrapped chunk_size is refused 422 rather than coerced (AC3)' );

// The refusal is decided on the member itself, ahead of the public-key check the
// ladder puts after it — so a request wrong in both is told about the member.
$before_key = $post_extractions( [ 'files' => [ $fixture_rel ], 'public_key' => 'not-a-valid-key', 'chunk_size' => 0 ] );
kntnt_extractor_assert( $before_key->get_status() === 422, 'An out-of-range chunk_size is decided before the public-key check (AC3)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
@unlink( $fixture_abs );
$rmrf( $work );
$rmrf( $downloads );
wp_set_current_user( 0 );

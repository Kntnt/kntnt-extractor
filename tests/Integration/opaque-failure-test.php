<?php
/**
 * Integration test: a job killed by an unexpected throw records what threw
 * (issue #25), instead of polling as the generic fallback sentence.
 *
 * A production run failed at 97.8 % after six hours and the entire account of
 * why was `The extraction failed.` — not a message the plugin wrote, but
 * `Extractions_Controller::error_of()`'s fallback for a job whose `error` is
 * null, which is what an unexpected throw used to leave behind. The cause had
 * to be reconstructed afterwards from four converging facts rather than read
 * off the record (`docs/measurements/2026-08-18-production-run.md` §3).
 *
 * The fallback string itself is deliberately kept. After this change its
 * appearance is a diagnostic in its own right: a failure carrying a recorded
 * throwable is one PHP threw and the plugin caught, a failure still showing
 * the fallback is one where PHP died outright and no `catch` ever ran. The
 * assertions below are what keep those two distinguishable.
 *
 * It pins:
 *  - AC1: a job whose packaging throws reaches `failed`.
 *  - AC2 (regression guard): its poll's `error.message` is not the
 *    `The extraction failed.` fallback — the assertion that would have failed
 *    before this change, and the reason this file exists.
 *  - AC3: the message names the throwable's class and its own message text,
 *    and the chunk that was being packaged when it threw.
 *  - AC4: the message carries no stack trace and is bounded — a throwable
 *    whose own message runs to thousands of characters is recorded truncated,
 *    because the poll returns this field to any caller that can read the job.
 *  - AC5: the fallback still exists for the failure this change cannot reach.
 *
 * Two faults are planted, both through real code paths. The first is the
 * builder's own pinned-identity guard — a file rewritten between two of its
 * parts — which is a genuine `RuntimeException` from production code. The
 * second throws from the plugin's own `kntnt_extractor_config_chunk_size`
 * filter, which `Artifact_Builder::advance()` reads inside the driver's `try`:
 * that is the only way to put an arbitrarily long throwable message through
 * the real path, and it is also a realistic fault, since a site's mu-plugin
 * may hook that filter.
 *
 * @package Kntnt\Extractor
 * @since   0.6.1
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Dispatcher;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// The fallback `error_of()` returns for a job whose `error` is null. Spelled out
// here rather than read from the class, so the test pins the caller-visible
// string and not whatever the implementation happens to hold.
$fallback = 'The extraction failed.';

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
$post_extractions = static function ( array $body ): array {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	$data = rest_get_server()->dispatch( $request )->get_data();
	return is_array( $data ) ? $data : [];
};

// Dispatches GET /extractions/{id} through the live REST server.
$get_extraction = static function ( string $id ): array {
	$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) )->get_data();
	return is_array( $data ) ? $data : [];
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret; the suite
// pins the tick budget to zero, so each call advances exactly one chunk.
$tick = static function ( string $id, string $secret ): void {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	rest_get_server()->dispatch( $request );
};

// Reads a job's persisted per-job tick secret from its on-disk state.
$secret_of = static function ( string $work, string $id ): string {
	$path = $work . '/' . $id . '/state.json';
	$state = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '';
};

// Reads the poll contract's error.message, or the empty string when absent.
$message_of = static function ( array $poll ): string {
	$error = is_array( $poll['error'] ?? null ) ? $poll['error'] : [];
	return is_string( $error['message'] ?? null ) ? $error['message'] : '';
};

// Writes a throwaway file under uploads and returns its installation-root-relative
// path, which is the vocabulary a selection and the recorded reason both use.
$plant_file = static function ( string $contents ): array {
	$root = wp_normalize_path( (string) realpath( ABSPATH ) );
	$absolute = wp_normalize_path( wp_upload_dir()['basedir'] ) . '/kntnt-extractor-throw-' . bin2hex( random_bytes( 4 ) ) . '.bin';
	file_put_contents( $absolute, $contents );
	return [ $absolute, ltrim( substr( wp_normalize_path( (string) realpath( $absolute ) ), strlen( $root ) ), '/' ) ];
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( 'kntnt_extractor_operate' ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree, so this file owns every
// job record it creates and removes all of them again.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-throw-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the two failing jobs this file needs can
// both exist; a failed job frees its slot, but the ceiling is one by default.
$force_max = static fn(): int => 5;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Short-circuit every loopback the driver fires, so a nudge never touches the
// real network; the ticks below drive each job synchronously.
$intercept = static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// The caller submits only the public half of an ephemeral X25519 keypair.
$public_key = sodium_crypto_box_publickey( sodium_crypto_box_keypair() );

// --- AC1/AC2/AC3: a throw from production code is recorded, not swallowed ----

// Force a chunk size small enough that a 64-byte file needs several parts, so
// rewriting it between two of them trips the builder's pinned-identity guard.
$small_chunk = static fn(): int => 16;
add_filter( 'kntnt_extractor_config_chunk_size', $small_chunk );

[ $guard_abs, $guard_rel ] = $plant_file( str_repeat( 'A', 64 ) );

wp_set_current_user( $owner->ID );
$guard_id = (string) ( $post_extractions( [ 'files' => [ $guard_rel ], 'public_key' => base64_encode( $public_key ) ] )['id'] ?? '' );
$guard_secret = $secret_of( $work, $guard_id );

// Seal the first part, pinning the file's size and mtime, then rewrite the file
// so the next part no longer matches the pinned identity, then drive the tick
// that throws.
$tick( $guard_id, $guard_secret );
file_put_contents( $guard_abs, str_repeat( 'B', 128 ) );
$tick( $guard_id, $guard_secret );

$guard_poll = $get_extraction( $guard_id );
$guard_message = $message_of( $guard_poll );

remove_filter( 'kntnt_extractor_config_chunk_size', $small_chunk );

kntnt_extractor_assert(
	( $guard_poll['state'] ?? null ) === 'failed',
	'A job whose packaging throws reaches failed (AC1)'
);

kntnt_extractor_assert(
	$guard_message !== '' && ! str_contains( $guard_message, $fallback ),
	'REGRESSION GUARD: a thrown failure no longer polls as the generic "The extraction failed." fallback — before this change that sentence was the entire record of why (AC2)'
);

kntnt_extractor_assert(
	str_contains( $guard_message, 'RuntimeException' ),
	'The recorded reason names the throwable class (AC3)'
);

kntnt_extractor_assert(
	str_contains( $guard_message, 'A requested file changed while it was being packaged.' ),
	'The recorded reason carries the throwable\'s own message text (AC3)'
);

kntnt_extractor_assert(
	str_contains( $guard_message, 'Artifact_Builder.php' ) && preg_match( '/Artifact_Builder\.php[^0-9]{1,20}\d+/', $guard_message ) === 1,
	'The recorded reason names the file and line the throw came from (AC3)'
);

kntnt_extractor_assert(
	str_contains( $guard_message, $guard_rel ),
	'The recorded reason names the chunk that was being packaged when it threw (AC3)'
);

// --- AC4: the recorded reason is trace-free and bounded ---------------------

// A stack trace names every frame and argument on the way in, so it is exactly
// what must not travel to a caller over REST.
kntnt_extractor_assert(
	! str_contains( $guard_message, 'Stack trace' ) && ! str_contains( $guard_message, '#0 ' ) && ! str_contains( $guard_message, '#1 ' ),
	'The recorded reason carries no stack trace (AC4)'
);

// Plant a throw whose own message is far longer than anything worth returning,
// through the config filter Artifact_Builder::advance() reads inside the
// driver's try. A run of one character makes the truncation point observable.
[ $long_abs, $long_rel ] = $plant_file( str_repeat( 'C', 32 ) );

wp_set_current_user( $owner->ID );
$long_id = (string) ( $post_extractions( [ 'files' => [ $long_rel ], 'public_key' => base64_encode( $public_key ) ] )['id'] ?? '' );
$long_secret = $secret_of( $work, $long_id );

$marker = 'BOUNDMARK';
$thrower = static function () use ( $marker ): int {
	throw new RuntimeException( $marker . str_repeat( 'X', 4000 ) );
};
add_filter( 'kntnt_extractor_config_chunk_size', $thrower );
$tick( $long_id, $long_secret );
remove_filter( 'kntnt_extractor_config_chunk_size', $thrower );

$long_poll = $get_extraction( $long_id );
$long_message = $message_of( $long_poll );

kntnt_extractor_assert(
	( $long_poll['state'] ?? null ) === 'failed' && ! str_contains( $long_message, $fallback ),
	'A throw from anywhere inside the packaging is recorded, not only one from the builder\'s own guards (AC2)'
);

kntnt_extractor_assert(
	str_contains( $long_message, $marker . str_repeat( 'X', 100 ) ),
	'An over-long throwable message is recorded from its start, so the informative part survives (AC4)'
);

kntnt_extractor_assert(
	! str_contains( $long_message, str_repeat( 'X', 1000 ) ),
	'An over-long throwable message is truncated rather than recorded whole (AC4)'
);

kntnt_extractor_assert(
	mb_strlen( $long_message ) <= 1500,
	'The whole recorded reason stays within a bound a poll can safely return (AC4)'
);

// --- AC5: the fallback is kept, and now means something ---------------------

// Its appearance after this change is itself the diagnosis: PHP died outright
// — an OOM kill, a reaped worker — so no catch block ever ran to record a
// throwable. That case is not fixed here; it is only made distinguishable.
$controller = file_get_contents( dirname( __DIR__, 2 ) . '/classes/Rest/Extractions_Controller.php' );
kntnt_extractor_assert(
	is_string( $controller ) && str_contains( $controller, $fallback ),
	'The generic fallback is deliberately kept: a failure still showing it is one PHP died on rather than threw (AC5)'
);

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
@unlink( $guard_abs );
@unlink( $long_abs );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

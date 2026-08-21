<?php
/**
 * Integration test: `strict: false` reaches a file that vanishes DURING packaging.
 *
 * A manifest is a snapshot and a live site is not, and `strict: false` used to
 * close only the gap between the manifest walk and the `POST`. The much larger
 * gap — between the `POST` and the moment a file's chunk is actually packaged —
 * was outside its reach, so a file removed hours into a run still hard-failed the
 * whole job and discarded every table and file already packaged. That is what
 * ended a production run at 97.8 % (`docs/measurements/2026-08-18-production-run.md`
 * §3). This file pins the widened reach and, just as importantly, its edges:
 *
 *  - A1: a `strict: false` job whose file is deleted after the job was created,
 *    before its chunk is packaged, reaches `ready` rather than `failed`.
 *  - A2: the vanished path is reported in the poll's `skipped_files`, and the
 *    record on disk carries it — the skip survives the tick that found it.
 *  - A3: the artifact holds every other selected file whole. The skip removed one
 *    file's segments, not the tail of the run.
 *  - A4: the same deletion under `strict: true` still fails the job.
 *  - A5: a file that exists but cannot be read still fails, in both modes. The
 *    plan-005 guarantee is untouched: only a file that is *gone* is skippable.
 *  - A6: a file deleted between two of its parts fails rather than leaving a
 *    truncated file in the artifact, even under `strict: false`.
 *  - A7: a path that is out of bounds is never a skip, at packaging time as at
 *    create time.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Crypto\Sealed_Writer;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Rest\Status_Controller;

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
$get_extraction = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) );
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret.
$tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Reads a length-prefixed 64-bit-LE field at $offset, advancing it.
$read_length = static function ( string $raw, int &$offset ): int {
	$value = unpack( 'P', substr( $raw, $offset, 8 ) )[1];
	$offset += 8;
	return (int) $value;
};

// Parses a finished sealed container into its segment records and sealed index —
// the independent reader a downloading client would implement over the wire format.
$parse = static function ( string $raw ) use ( $read_length ): array {
	$magic = Sealed_Writer::MAGIC;
	$header_len = strlen( $magic ) + 1;
	$trailer_at = strlen( $raw ) - 8;
	$index_len = (int) unpack( 'P', substr( $raw, $trailer_at, 8 ) )[1];
	$sealed_index = substr( $raw, $trailer_at - $index_len, $index_len );
	$body_end = $trailer_at - $index_len;

	$records = [];
	$offset = $header_len;
	while ( $offset < $body_end ) {
		$sk_len = $read_length( $raw, $offset );
		$sealed_key = substr( $raw, $offset, $sk_len );
		$offset += $sk_len;
		$nonce = substr( $raw, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$offset += SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		$ct_len = $read_length( $raw, $offset );
		$ciphertext = substr( $raw, $offset, $ct_len );
		$offset += $ct_len;
		$records[] = [ 'sealed_key' => $sealed_key, 'nonce' => $nonce, 'ciphertext' => $ciphertext ];
	}

	return [ 'sealed_index' => $sealed_index, 'records' => $records ];
};

// Recovers a segment's plaintext with the caller's keypair, or false on failure.
$open_segment = static function ( array $record, string $keypair ): string|false {
	$key = sodium_crypto_box_seal_open( $record['sealed_key'], $keypair );
	if ( $key === false ) {
		return false;
	}
	return sodium_crypto_secretbox_open( $record['ciphertext'], $record['nonce'], $key );
};

// Unseals and splits the length-prefixed index back into its ordered names.
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
if ( ! get_role( 'administrator' )->has_cap( 'kntnt_extractor_operate' ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Isolate the working directory so this file's jobs cannot collide with another
// suite file's active job, and raise the ceiling so its several jobs coexist.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-vanish-' . bin2hex( random_bytes( 4 ) );
$downloads = $work . '-downloads';
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Force a tiny file-part budget, so a small fixture splits into several parts and
// a file can be made to vanish *between* two of its own parts (A6).
$chunk_size = 16;
$force_chunk = static fn(): int => $chunk_size;
add_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );

// Short-circuit every loopback the driver fires, so a nudge never touches the real
// network; the ticks below drive each job synchronously, one chunk at a time.
$intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

$root = wp_normalize_path( (string) realpath( ABSPATH ) );
$basedir = rtrim( wp_upload_dir()['basedir'], '/' );
$baseurl = rtrim( wp_upload_dir()['baseurl'], '/' );

// Reads a job's persisted state half into a decoded array, or null when absent.
$read_state = static function ( string $id ) use ( $work ): ?array {
	$path = $work . '/' . $id . '/state.json';
	$decoded = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $decoded ) ? $decoded : null;
};

// Reads a job's persisted selection half — the file the skipped list lives in.
$read_selection = static function ( string $id ) use ( $work ): ?array {
	$path = $work . '/' . $id . '/job.json';
	$decoded = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $decoded ) ? $decoded : null;
};

// Writes a fixture file inside the installation root and returns its bytes, its
// absolute path, and the installation-root-relative path a selection names it by.
$fixture = static function ( string $label, int $length ) use ( $basedir, $root ): array {
	$bytes = substr( str_repeat( strtoupper( $label ) . '0123456789', (int) ceil( $length / 11 ) + 1 ), 0, $length );
	$abs = $basedir . '/kntnt-extractor-vanish-' . $label . '-' . bin2hex( random_bytes( 4 ) ) . '.bin';
	file_put_contents( $abs, $bytes );
	$rel = ltrim( substr( wp_normalize_path( (string) realpath( $abs ) ), strlen( $root ) ), '/' );
	return [ 'bytes' => $bytes, 'abs' => $abs, 'rel' => $rel ];
};

// Creates a job from a selection and returns its id and tick secret.
$create = static function ( array $body ) use ( $post_extractions, $read_state, $owner ): array {
	wp_set_current_user( $owner->ID );
	$response = $post_extractions( $body );
	$data = $response->get_data();
	$id = is_array( $data ) && is_string( $data['id'] ?? null ) ? $data['id'] : '';
	$state = $id === '' ? null : $read_state( $id );
	return [
		'status' => $response->get_status(),
		'id' => $id,
		'secret' => is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '',
	];
};

// Drives a job with bounded ticks until it leaves queued/running, so a driver that
// never settles fails the test rather than hanging. Returns the state reached.
$drive = static function ( string $id, string $secret ) use ( $tick, $get_extraction ): string {
	for ( $i = 0; $i < 200; ++$i ) {
		$data = $get_extraction( $id )->get_data();
		$state = is_array( $data ) && is_string( $data['state'] ?? null ) ? $data['state'] : '';
		if ( $state !== 'queued' && $state !== 'running' ) {
			return $state;
		}
		$tick( $id, $secret );
	}
	return 'not-settled';
};

// Reads the published container of a ready job back as its ordered segment names
// and the plaintext concatenated per name.
$unseal = static function ( string $id ) use ( $get_extraction, $parse, $open_index, $open_segment, $basedir, $baseurl ): array {
	$poll = $get_extraction( $id )->get_data();
	$url = is_array( $poll ) && is_string( $poll['download_url'] ?? null ) ? $poll['download_url'] : '';
	$path = $url !== '' ? $basedir . substr( $url, strlen( $baseurl ) ) : '';
	$raw = $path !== '' && is_file( $path ) ? (string) file_get_contents( $path ) : '';
	if ( $raw === '' ) {
		return [ 'names' => [], 'content' => [] ];
	}
	$container = $parse( $raw );
	$names = $open_index( $container['sealed_index'], $GLOBALS['kntnt_extractor_vanish_keypair'] ) ?? [];
	$content = [];
	foreach ( $container['records'] as $i => $record ) {
		$plain = $open_segment( $record, $GLOBALS['kntnt_extractor_vanish_keypair'] );
		$name = $names[ $i ] ?? '';
		if ( is_string( $plain ) && $name !== '' ) {
			$content[ $name ] = ( $content[ $name ] ?? '' ) . $plain;
		}
	}
	return [ 'names' => $names, 'content' => $content ];
};

// A caller's ephemeral X25519 keypair; only the public half is ever submitted.
$GLOBALS['kntnt_extractor_vanish_keypair'] = sodium_crypto_box_keypair();
$public_key = base64_encode( sodium_crypto_box_publickey( $GLOBALS['kntnt_extractor_vanish_keypair'] ) );

// --- A1/A2/A3: a file deleted mid-run is skipped, and the rest still packages ---
//
// Three fixtures of three parts each, packaged in selection order. The middle one
// is deleted only after the first has been fully packaged, which is the production
// shape: the deletion lands in the middle of a run that has already done real work.

$alpha = $fixture( 'alpha', 3 * $chunk_size );
$victim = $fixture( 'victim', 3 * $chunk_size );
$omega = $fixture( 'omega', 3 * $chunk_size );

$mid = $create(
	[
		'files' => [ $alpha['rel'], $victim['rel'], $omega['rel'] ],
		'strict' => false,
		'public_key' => $public_key,
	]
);
kntnt_extractor_assert( $mid['status'] === 201, 'A strict: false selection of three live files is created (A1)' );

// Package the first file whole — three parts, one per tick — and confirm the run
// really is mid-selection before the deletion, rather than assuming it.
$tick( $mid['id'], $mid['secret'] );
$tick( $mid['id'], $mid['secret'] );
$tick( $mid['id'], $mid['secret'] );
$mid_progress = ( $read_state( $mid['id'] ) ?? [] )['progress'] ?? null;
kntnt_extractor_assert(
	is_array( $mid_progress ) && ( $mid_progress['file_index'] ?? null ) === 1 && ( $mid_progress['segment_count'] ?? null ) === 3,
	'The run has fully packaged the first file before anything is deleted (A1)'
);

// The deletion the whole plan is about: gone after the job was created, before its
// own chunk is packaged, on a live site that does not stop being live.
unlink( $victim['abs'] );

$mid_state = $drive( $mid['id'], $mid['secret'] );
kntnt_extractor_assert( $mid_state === 'ready', 'A file that vanishes mid-run is skipped rather than failing the job under strict: false (A1)' );

$mid_poll = $get_extraction( $mid['id'] )->get_data();
kntnt_extractor_assert(
	is_array( $mid_poll ) && ( $mid_poll['skipped_files'] ?? null ) === [ $victim['rel'] ],
	'The poll reports the mid-run vanished file in skipped_files (A2)'
);
kntnt_extractor_assert(
	( ( $read_selection( $mid['id'] ) ?? [] )['skipped_files'] ?? null ) === [ $victim['rel'] ],
	'The mid-run skip is persisted on the job record, so it survives the tick that found it (A2)'
);

$mid_artifact = $unseal( $mid['id'] );
kntnt_extractor_assert(
	( $mid_artifact['content'][ $alpha['rel'] ] ?? null ) === $alpha['bytes']
		&& ( $mid_artifact['content'][ $omega['rel'] ] ?? null ) === $omega['bytes'],
	'Every other selected file is packaged whole — the skip removed one file, not the tail of the run (A3)'
);
kntnt_extractor_assert(
	! in_array( $victim['rel'], $mid_artifact['names'], true ) && count( $mid_artifact['names'] ) === 6,
	'The skipped file contributes no segment at all to the artifact (A3)'
);

// --- A4: the same deletion under strict: true still fails the job ---

$strict_victim = $fixture( 'strictvictim', 2 * $chunk_size );
$strict_job = $create(
	[
		'files' => [ $strict_victim['rel'] ],
		'strict' => true,
		'public_key' => $public_key,
	]
);
kntnt_extractor_assert( $strict_job['status'] === 201, 'A strict: true selection of a live file is created (A4)' );
unlink( $strict_victim['abs'] );
kntnt_extractor_assert( $drive( $strict_job['id'], $strict_job['secret'] ) === 'failed', 'A file that vanishes mid-run still fails the job under strict: true (A4)' );
kntnt_extractor_assert(
	( ( $read_selection( $strict_job['id'] ) ?? [] )['skipped_files'] ?? null ) === null,
	'A strict: true job records no skip for the file that killed it (A4)'
);

// --- A5: a file that exists but cannot be read still fails, in both modes ---
//
// A directory resolves inside the root exactly as a file does, so it is never
// "gone" — it is present and unreadable, which is the case plan 005 made an error
// and which this change must leave an error whatever `strict` says.

$unreadable_abs = $basedir . '/kntnt-extractor-vanish-unreadable-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $unreadable_abs );
file_put_contents( $unreadable_abs . '/inside.txt', 'inside' );
$unreadable_rel = ltrim( substr( wp_normalize_path( (string) realpath( $unreadable_abs ) ), strlen( $root ) ), '/' );

foreach ( [ true, false ] as $strict_mode ) {
	$unreadable_job = $create(
		[
			'files' => [ $unreadable_rel ],
			'strict' => $strict_mode,
			'public_key' => $public_key,
		]
	);
	kntnt_extractor_assert(
		$unreadable_job['status'] === 201 && $drive( $unreadable_job['id'], $unreadable_job['secret'] ) === 'failed',
		sprintf( 'A path that exists but cannot be read still fails the job under strict: %s (A5)', $strict_mode ? 'true' : 'false' )
	);
	kntnt_extractor_assert(
		( ( $read_selection( $unreadable_job['id'] ) ?? [] )['skipped_files'] ?? null ) === null,
		sprintf( 'An unreadable-but-present path is never recorded as a skip under strict: %s (A5)', $strict_mode ? 'true' : 'false' )
	);
}

// --- A6: a file deleted between two of its own parts fails, never truncates ---
//
// Once a part is sealed, the file's earlier bytes are in the container and no
// reader of `docs/container-format.md` can tell a truncated file from a whole one.
// Skipping there would publish exactly that, so the job fails instead.

$partial = $fixture( 'partial', 3 * $chunk_size );
$partial_job = $create(
	[
		'files' => [ $partial['rel'] ],
		'strict' => false,
		'public_key' => $public_key,
	]
);
$tick( $partial_job['id'], $partial_job['secret'] );
$partial_progress = ( $read_state( $partial_job['id'] ) ?? [] )['progress'] ?? null;
kntnt_extractor_assert(
	is_array( $partial_progress ) && ( $partial_progress['file_offset'] ?? null ) === $chunk_size && ( $partial_progress['file_size'] ?? null ) === 3 * $chunk_size,
	'One part of the file is sealed and its identity pinned before it is deleted (A6)'
);
unlink( $partial['abs'] );
kntnt_extractor_assert(
	$drive( $partial_job['id'], $partial_job['secret'] ) === 'failed',
	'A file deleted between two of its parts fails the job even under strict: false (A6)'
);
kntnt_extractor_assert(
	( ( $read_selection( $partial_job['id'] ) ?? [] )['skipped_files'] ?? null ) === null,
	'A partially packaged file is never recorded as a skip — the artifact would hold it truncated (A6)'
);

// --- A7: an out-of-bounds path is never a skip at packaging time ---
//
// The create path can never let one through, so the record is edited directly:
// the packaging path must preserve the create path's own distinction between a
// file that is gone and a path that never was inside the root.

$bounds = $fixture( 'bounds', $chunk_size );
$bounds_job = $create(
	[
		'files' => [ $bounds['rel'] ],
		'strict' => false,
		'public_key' => $public_key,
	]
);
$bounds_selection = $read_selection( $bounds_job['id'] ) ?? [];
$bounds_selection['files'] = [ '../wp-load.php' ];
file_put_contents( $work . '/' . $bounds_job['id'] . '/job.json', (string) wp_json_encode( $bounds_selection ) );
kntnt_extractor_assert(
	$drive( $bounds_job['id'], $bounds_job['secret'] ) === 'failed',
	'A path that resolves outside the root fails the build under strict: false, never a skip (A7)'
);
kntnt_extractor_assert(
	( ( $read_selection( $bounds_job['id'] ) ?? [] )['skipped_files'] ?? null ) === null,
	'An out-of-bounds path is never recorded as a skipped file (A7)'
);

// --- The contract this widens announces itself, and moves no version ---

kntnt_extractor_assert( Status_Controller::API_VERSION === 7, 'Widening where an announced behaviour applies moves no API version' );
wp_set_current_user( $owner->ID );
$honours = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/status' ) )->get_data();
kntnt_extractor_assert(
	is_array( $honours ) && is_array( $honours['honours'] ?? null ) && in_array( 'packaging_skips', $honours['honours'], true ),
	'The build names the run-wide reach of strict: false in honours, so a caller need not infer it'
);

// Leave the suite state clean for later files.
@unlink( $alpha['abs'] );
@unlink( $omega['abs'] );
@unlink( $bounds['abs'] );
$rmrf( $unreadable_abs );
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
unset( $GLOBALS['kntnt_extractor_vanish_keypair'] );
$rmrf( $work );
$rmrf( $downloads );
wp_set_current_user( 0 );

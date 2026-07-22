<?php
/**
 * Integration test: file packaging in bounded parts + multi-chunk resumability.
 *
 * Drives the chunked, resumable extraction (ADR-0007/0009, issue #9) end to end
 * against the live REST server: a selection whose file is larger than one chunk
 * is packaged as several independently-sealed parts across several ticks, and the
 * job survives an interruption between ticks without redoing or corrupting a
 * completed segment.
 *
 * It pins every acceptance criterion of issue #9:
 *  - AC1: a requested file is packaged as bounded parts, each its own sealed
 *    segment, recorded in the sealed index by its installation-root-relative path,
 *    and the parts reassemble to the original bytes.
 *  - AC2: a selection larger than one chunk completes across multiple ticks and
 *    the finished container unseals to exactly the requested tables and files.
 *  - AC3: interrupting the job between ticks — including a crashed partial write —
 *    and ticking again resumes from persisted state without redoing or corrupting
 *    a completed segment.
 *  - AC4: the chunk size is a Config knob; forcing it small is what splits a small
 *    fixture into several parts, so this whole file is driven by that knob.
 *  - AC5: the caller's private key restores the table SQL and the file bytes with
 *    ordinary tools.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Crypto\Sealed_Writer;

global $wpdb;

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
	$header_ok = substr( $raw, 0, strlen( $magic ) ) === $magic;
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

	return [ 'header_ok' => $header_ok, 'sealed_index' => $sealed_index, 'records' => $records ];
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

// Reads a job's persisted state file into a decoded array, or null when absent.
$read_state = static function ( string $work, string $id ): ?array {
	$path = $work . '/' . $id . '/job.json';
	$decoded = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $decoded ) ? $decoded : null;
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so the
// artifact stays web-reachable while the run owns all of its state and cleans up.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-pack-' . bin2hex( random_bytes( 4 ) );
$downloads = $work . '-downloads';
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the several jobs this file needs can coexist.
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// AC4: force a tiny chunk size through the Config knob, so a small fixture file
// splits into several bounded parts and the whole run exercises multi-chunk paths.
$chunk_size = 16;
$force_chunk = static fn(): int => $chunk_size;
add_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );

// Short-circuit every loopback the driver fires, so a nudge never touches the
// real network; the ticks below drive each job synchronously across chunks.
$intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// A distinctive fixture file inside the installation root, longer than several
// chunks so it must be packaged as multiple parts. Its bytes are a recognisable
// pattern so a mis-ordered or duplicated part is detectable on reassembly.
$fixture_bytes = '';
for ( $i = 0; $i < 50; $i++ ) {
	$fixture_bytes .= chr( ord( 'A' ) + ( $i % 26 ) );
}
$fixture_abs = wp_upload_dir()['basedir'] . '/kntnt-extractor-fixture-' . bin2hex( random_bytes( 4 ) ) . '.bin';
file_put_contents( $fixture_abs, $fixture_bytes );
$root = wp_normalize_path( (string) realpath( ABSPATH ) );
$fixture_rel = ltrim( substr( wp_normalize_path( (string) realpath( $fixture_abs ) ), strlen( $root ) ), '/' );

// The number of bounded parts the fixture must split into under the forced chunk
// size — the property AC1/AC4 turn on: a whole-file segment would be exactly one.
$expected_parts = (int) ceil( strlen( $fixture_bytes ) / $chunk_size );

// A caller's ephemeral X25519 keypair; only the public half is submitted.
$keypair = sodium_crypto_box_keypair();
$public_key = sodium_crypto_box_publickey( $keypair );

// Seed a marker into the options table so AC5's table restore proves real data
// survives the seal, not merely that some ciphertext decodes.
$marker = 'PACKMARK-' . bin2hex( random_bytes( 16 ) );
update_option( 'kntnt_extractor_packaging_marker', $marker, false );

$selection = [
	'tables' => [ $wpdb->options ],
	'files' => [ $fixture_rel ],
	'public_key' => base64_encode( $public_key ),
];

// Drives a queued job to ready one bounded chunk per tick, bounded so a driver
// that never finishes fails the test rather than hangs. Returns the tick count.
$drive_to_ready = static function ( string $id, string $secret ) use ( $tick, $get_extraction ): int {
	$ticks = 0;
	while ( $ticks < 200 ) {
		$state = $get_extraction( $id )->get_data();
		if ( is_array( $state ) && ( $state['state'] ?? null ) === 'ready' ) {
			break;
		}
		$tick( $id, $secret );
		$ticks++;
	}
	return $ticks;
};

// --- Create the job and drive it to ready across many chunks (AC2) ---

wp_set_current_user( $owner->ID );
$response = $post_extractions( $selection );
kntnt_extractor_assert( $response->get_status() === 201, 'POST /extractions creates the multi-chunk job (201)' );
$created = $response->get_data();
$id = is_array( $created ) && is_string( $created['id'] ?? null ) ? $created['id'] : '';
kntnt_extractor_assert( $id !== '', 'The created job has an id' );

$state = $read_state( $work, $id );
$secret = is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '';

$ticks_used = $drive_to_ready( $id, $secret );
$ready_poll = $get_extraction( $id )->get_data();
kntnt_extractor_assert( is_array( $ready_poll ) && ( $ready_poll['state'] ?? null ) === 'ready', 'The job reaches ready after driving its chunks (AC2)' );

// A single-chunk one-shot would need one tick; a genuinely chunked build needs
// one tick per table plus one per file part. More ticks than resources is the
// observable proof the work spanned multiple ticks (AC2/AC4).
kntnt_extractor_assert( $ticks_used > 2, 'The selection completes across multiple ticks, not a single one (AC2/AC4)' );

// --- Read the finished artifact and unseal it (AC1/AC2/AC5) ---

$download_url = is_array( $ready_poll ) && is_string( $ready_poll['download_url'] ?? null ) ? $ready_poll['download_url'] : '';
$uploads = wp_upload_dir();
$basedir = rtrim( $uploads['basedir'], '/' );
$baseurl = rtrim( $uploads['baseurl'], '/' );
$artifact_path = $download_url !== '' ? $basedir . substr( $download_url, strlen( $baseurl ) ) : '';
kntnt_extractor_assert( $artifact_path !== '' && is_file( $artifact_path ), 'The ready job publishes a served artifact (AC2)' );
$raw = $artifact_path !== '' && is_file( $artifact_path ) ? (string) file_get_contents( $artifact_path ) : '';
kntnt_extractor_assert( str_starts_with( $raw, Sealed_Writer::MAGIC ), 'The published artifact is a sealed container (AC2)' );

$container = $parse( $raw );
$names = $open_index( $container['sealed_index'], $keypair );

// AC1/AC4: the file is recorded as several parts, each keyed by its relative path,
// while the table is a single segment — so the index is the table then N file parts.
$file_part_count = is_array( $names ) ? count( array_filter( $names, static fn( string $n ): bool => $n === $fixture_rel ) ) : 0;
kntnt_extractor_assert( $file_part_count === $expected_parts, 'The file is packaged as several bounded parts, each keyed by its relative path (AC1/AC4)' );
kntnt_extractor_assert( $file_part_count > 1, 'Forcing the chunk-size knob small splits the file into more than one part (AC4)' );

// AC2: the finished container unseals to exactly the requested tables and files —
// no more, no fewer — as a distinct set of names.
$distinct_names = is_array( $names ) ? array_values( array_unique( $names ) ) : [];
sort( $distinct_names );
$expected_names = [ $wpdb->options, $fixture_rel ];
sort( $expected_names );
kntnt_extractor_assert( $distinct_names === $expected_names, 'The container unseals to exactly the requested tables and files (AC2)' );

// AC1/AC5: the file's parts, decrypted in index order and concatenated, reassemble
// to the original bytes — the reassembly is the corruption/duplication check.
$reassembled = '';
$table_sql = '';
foreach ( $container['records'] as $i => $record ) {
	$plain = $open_segment( $record, $keypair );
	if ( $plain === false || ! is_array( $names ) || ! isset( $names[ $i ] ) ) {
		continue;
	}
	if ( $names[ $i ] === $fixture_rel ) {
		$reassembled .= $plain;
	} elseif ( $names[ $i ] === $wpdb->options ) {
		$table_sql = $plain;
	}
}
kntnt_extractor_assert( $reassembled === $fixture_bytes, 'The file parts reassemble to the original bytes keyed by relative path (AC1/AC5)' );

// AC5: the table segment restores as mysqldump-compatible SQL carrying the marker.
$is_mysqldump = str_contains( $table_sql, 'DROP TABLE IF EXISTS `' . $wpdb->options . '`' )
	&& str_contains( $table_sql, 'CREATE TABLE `' . $wpdb->options . '`' )
	&& str_contains( $table_sql, 'INSERT INTO `' . $wpdb->options . '`' );
kntnt_extractor_assert( $is_mysqldump && str_contains( $table_sql, $marker ), 'The table segment restores as mysqldump-compatible SQL with the seeded row (AC5)' );

// --- AC3: interrupt between ticks and resume without redoing or corrupting ---

wp_set_current_user( $owner->ID );
$r_response = $post_extractions( $selection );
$r_created = $r_response->get_data();
$r_id = is_array( $r_created ) && is_string( $r_created['id'] ?? null ) ? $r_created['id'] : '';
$r_state = $read_state( $work, $r_id );
$r_secret = is_array( $r_state ) && is_string( $r_state['tick_secret'] ?? null ) ? $r_state['tick_secret'] : '';

// Tick a bounded, partial number of times: one table segment plus one file part,
// deliberately short of completion.
$tick( $r_id, $r_secret );
$tick( $r_id, $r_secret );

// The job is still running and its build progress is durably persisted: the two
// completed segments are recorded, with a container-byte offset within the build.
$partial = $read_state( $work, $r_id );
$progress = is_array( $partial ) && is_array( $partial['progress'] ?? null ) ? $partial['progress'] : null;
kntnt_extractor_assert( is_array( $partial ) && ( $partial['state'] ?? null ) === 'running', 'A partially-driven job is still running between ticks (AC3)' );
kntnt_extractor_assert( $progress !== null, 'Build progress is persisted in the job record (AC3)' );
$segment_names = is_array( $progress ) && is_array( $progress['segment_names'] ?? null ) ? $progress['segment_names'] : [];
kntnt_extractor_assert( count( $segment_names ) === 2, 'Progress records exactly the two completed segments so far (AC3)' );
kntnt_extractor_assert( is_int( $progress['container_bytes'] ?? null ) && $progress['container_bytes'] > 0, 'Progress records the byte offset within the in-progress container (AC3)' );

// Simulate a crashed partial write: garbage appended past the committed offset
// after the last clean tick. A correct resume truncates it away rather than
// sealing it into a completed segment.
$build_glob = glob( $work . '/' . $r_id . '/*' );
$build_file = '';
foreach ( is_array( $build_glob ) ? $build_glob : [] as $candidate ) {
	if ( is_file( $candidate ) && str_ends_with( $candidate, '.building' ) ) {
		$build_file = $candidate;
	}
}
kntnt_extractor_assert( $build_file !== '' && is_file( $build_file ), 'The in-progress container lives in the per-job working directory, not the served downloads (AC3)' );

// Snapshot the committed prefix — the first container_bytes of the in-progress
// container, the two completed segments and the header. A true resume appends after
// this and never rewrites it, so the published artifact must begin with exactly
// these bytes; a rebuild-from-scratch reseals those segments under fresh random keys
// and nonces, changing the bytes, so this is a precise no-redo detector (AC3).
$committed_bytes = is_array( $progress ) && is_int( $progress['container_bytes'] ?? null ) ? $progress['container_bytes'] : 0;
$committed_prefix = $build_file !== '' && $committed_bytes > 0 ? substr( (string) file_get_contents( $build_file ), 0, $committed_bytes ) : '';
kntnt_extractor_assert( strlen( $committed_prefix ) === $committed_bytes && $committed_bytes > 0, 'The committed prefix of the in-progress container is captured before the interruption (AC3)' );

if ( $build_file !== '' ) {
	file_put_contents( $build_file, random_bytes( 32 ), FILE_APPEND );
}

// Resume and drive to completion; the crashed bytes must not corrupt the result.
$drive_to_ready( $r_id, $r_secret );
$r_ready = $get_extraction( $r_id )->get_data();
kntnt_extractor_assert( is_array( $r_ready ) && ( $r_ready['state'] ?? null ) === 'ready', 'An interrupted job resumes and reaches ready (AC3)' );

$r_url = is_array( $r_ready ) && is_string( $r_ready['download_url'] ?? null ) ? $r_ready['download_url'] : '';
$r_path = $r_url !== '' ? $basedir . substr( $r_url, strlen( $baseurl ) ) : '';
$r_raw = $r_path !== '' && is_file( $r_path ) ? (string) file_get_contents( $r_path ) : '';
$r_container = $parse( $r_raw );
$r_names = $open_index( $r_container['sealed_index'], $keypair );

// The resumed container holds exactly the right segment count — the two completed
// before the interruption were neither redone nor lost, and the crashed bytes were
// not sealed as an extra segment.
$expected_segments = 1 + $expected_parts;
kntnt_extractor_assert( is_array( $r_names ) && count( $r_names ) === $expected_segments && count( $r_container['records'] ) === $expected_segments, 'The resumed container has exactly the expected segments — none redone, duplicated, or corrupted (AC3)' );

// The published artifact begins with the exact committed prefix captured before the
// interruption: the completed segments were resumed, not resealed. Any redo would
// have changed these bytes (fresh keys and nonces), so this fails on a rebuild.
kntnt_extractor_assert( $committed_prefix !== '' && str_starts_with( $r_raw, $committed_prefix ), 'The resumed artifact keeps the exact committed prefix — completed segments are not redone or re-encrypted (AC3)' );

$r_reassembled = '';
foreach ( $r_container['records'] as $i => $record ) {
	$plain = $open_segment( $record, $keypair );
	if ( is_string( $plain ) && is_array( $r_names ) && ( $r_names[ $i ] ?? null ) === $fixture_rel ) {
		$r_reassembled .= $plain;
	}
}
kntnt_extractor_assert( $r_reassembled === $fixture_bytes, 'The resumed file reassembles to the original bytes despite the mid-build interruption (AC3)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
delete_option( 'kntnt_extractor_packaging_marker' );
@unlink( $fixture_abs );
$rmrf( $work );
$rmrf( $downloads );
wp_set_current_user( 0 );

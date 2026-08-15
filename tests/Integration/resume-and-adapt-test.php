<?php
/**
 * Integration test: a failed job resumes from persisted progress, and a stalled
 * chunk shrinks rather than killing the run.
 *
 * A job that has left `running` used to be unrecoverable: the next attempt was a
 * fresh `POST /extractions` from segment zero, so every crash cost the whole run.
 * The state needed to continue already existed — `Build_Progress`, the append-only
 * container, `Sealed_Writer`'s truncate-on-resume — and what was missing was
 * permission, plus a way to not walk straight back into the same wall. This file
 * drives both halves end to end against the live REST server.
 *
 * Which failures resume is narrower than "any diagnosed stall", and the distinction
 * is the point of half these cases. A stall this release meets never fails the job
 * while its budget can still shrink — it halves and carries on — so the only stall
 * that reaches `failed` here is one already at the floor, and re-driving that would
 * walk back into a wall already measured all the way down. What resume is actually
 * for is the record an EARLIER release left behind: stalled, failed at the first
 * wall, budgets never tried at anything smaller. That is a job stranded by an
 * upgrade, and it is recovered rather than restarted from segment zero. The absence
 * of the schema-8 budget keys is what identifies it, so the cases below write those
 * records the way 0.5.1 wrote them — without the keys — rather than with the keys
 * zeroed, which no release ever produced.
 *
 * It pins:
 *  - AC1: a pre-adaptation record marked `failed` after a diagnosed stall is
 *    re-driven from its persisted progress by a further tick. Garbage appended past
 *    `container_bytes` — the crash-mid-chunk shape — is truncated away, the
 *    committed prefix is kept, and the finished container reassembles without a
 *    duplicated segment.
 *  - AC2: a chunk begun repeatedly without finishing does not fail the job while
 *    its budget can still shrink. The file-part size is halved, the attempt
 *    counter is reset, and the job stays `running` and reaches `ready`.
 *  - AC3: a budget already at the floor of one byte still fails the job, with the
 *    stall reason naming the chunk. Adaptation is not an infinite retry — and
 *    neither is resume: a failure this release adapted its way into is never
 *    re-driven, whatever its budgets would still allow.
 *  - AC4: a job that failed opaquely (an unexpected throw, `error` null) is not
 *    resumed. Automatic resume of a permanent error would loop forever.
 *  - AC5: the watchdog restarts a pre-adaptation stall-failed job the same way it
 *    restarts a stale running one, and leaves an opaque failure alone.
 *  - AC6: a resume is declined when the concurrency ceiling has no room, because a
 *    failed job frees its slot and a create may already have taken it. The same
 *    record resumes once there is room, which is the control proving the refusal
 *    was the ceiling.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Artifact_Builder;
use Kntnt\Extractor\Config;
use Kntnt\Extractor\Crypto\Sealed_Writer;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Table_Dumper;
use Kntnt\Extractor\Watchdog;

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

// Parses a finished sealed container into its segment records and sealed index.
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
	$path = $work . '/' . $id . '/state.json';
	$decoded = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $decoded ) ? $decoded : null;
};

// Writes a decoded record back over a job's state file verbatim.
$write_state = static function ( string $work, string $id, array $data ): void {
	file_put_contents( $work . '/' . $id . '/state.json', (string) wp_json_encode( $data ) );
};

// Finds the in-progress container in a job directory.
$build_file_of = static function ( string $work, string $id ): string {
	foreach ( glob( $work . '/' . $id . '/*' ) ?: [] as $candidate ) {
		if ( is_file( $candidate ) && str_ends_with( $candidate, '.building' ) ) {
			return $candidate;
		}
	}
	return '';
};

// Finds the in-progress container's index sidecar in a job directory.
$sidecar_of = static function ( string $work, string $id ): string {
	foreach ( glob( $work . '/' . $id . '/*' ) ?: [] as $candidate ) {
		if ( is_file( $candidate ) && str_ends_with( $candidate, '.building.names' ) ) {
			return $candidate;
		}
	}
	return '';
};

// Resolves a ready job's published artifact to raw bytes.
$artifact_bytes = static function ( array $poll ): string {
	$url = is_string( $poll['download_url'] ?? null ) ? $poll['download_url'] : '';
	$uploads = wp_upload_dir();
	$basedir = rtrim( $uploads['basedir'], '/' );
	$baseurl = rtrim( $uploads['baseurl'], '/' );
	$path = $url !== '' ? $basedir . substr( $url, strlen( $baseurl ) ) : '';
	return $path !== '' && is_file( $path ) ? (string) file_get_contents( $path ) : '';
};

// Drives a job to ready one chunk per tick, bounded so a hang fails the test.
$drive_to_ready = static function ( string $id, string $secret ) use ( $tick, $get_extraction ): int {
	$ticks = 0;
	while ( $ticks < 200 ) {
		$state = $get_extraction( $id )->get_data();
		if ( is_array( $state ) && in_array( $state['state'] ?? null, [ 'ready', 'failed' ], true ) ) {
			break;
		}
		$tick( $id, $secret );
		++$ticks;
	}
	return $ticks;
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-resume-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the several jobs this file needs can coexist.
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Force a tiny chunk size so a small fixture splits into several parts.
$chunk_size = 64;
$force_chunk = static fn(): int => $chunk_size;
add_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );

// Short-circuit every loopback so a nudge never touches the real network.
$intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// A distinctive fixture longer than several of the forced chunks.
$fixture_bytes = '';
for ( $i = 0; $i < 200; $i++ ) {
	$fixture_bytes .= chr( ord( 'A' ) + ( $i % 26 ) );
}
$fixture_abs = wp_upload_dir()['basedir'] . '/kntnt-extractor-resume-' . bin2hex( random_bytes( 4 ) ) . '.bin';
file_put_contents( $fixture_abs, $fixture_bytes );
$root = wp_normalize_path( (string) realpath( ABSPATH ) );
$fixture_rel = ltrim( substr( wp_normalize_path( (string) realpath( $fixture_abs ) ), strlen( $root ) ), '/' );

$keypair = sodium_crypto_box_keypair();
$public_key = sodium_crypto_box_publickey( $keypair );
$selection = [
	'tables' => [ $wpdb->options ],
	'files' => [ $fixture_rel ],
	'public_key' => base64_encode( $public_key ),
];

// --- AC2: a stall shrinks the file chunk and keeps the job running ---

wp_set_current_user( $owner->ID );
$a_response = $post_extractions( $selection );
$a_id = is_array( $a_response->get_data() ) ? (string) ( $a_response->get_data()['id'] ?? '' ) : '';
$a_secret = (string) ( ( $read_state( $work, $a_id ) ?? [] )['tick_secret'] ?? '' );

// Seal the table so the next chunk is the file's first part — the production
// shape: the job dies at byte 0 of a file large enough to need a full-size part.
$tick( $a_id, $a_secret );
$a_state = $read_state( $work, $a_id ) ?? [];
kntnt_extractor_assert( ( $a_state['state'] ?? null ) === 'running', 'The job is running after its first (table) chunk (AC2)' );

// Plant the attempt count a chunk killed outside PHP leaves behind, then tick.
$a_state['attempts'] = 3;
$write_state( $work, $a_id, $a_state );
$tick( $a_id, $a_secret );
$a_adapted = $read_state( $work, $a_id ) ?? [];
kntnt_extractor_assert( ( $a_adapted['state'] ?? null ) === 'running', 'A stall whose budget can still shrink keeps the job running (AC2)' );
kntnt_extractor_assert( ( $a_adapted['attempts'] ?? null ) === 0, 'Adapting a stall resets the attempt counter (AC2)' );
kntnt_extractor_assert( ( $a_adapted['chunk_size'] ?? null ) === intdiv( $chunk_size, 2 ), 'A file-chunk stall halves the persisted file-part size (AC2)' );

$drive_to_ready( $a_id, $a_secret );
$a_ready = $get_extraction( $a_id )->get_data();
kntnt_extractor_assert( is_array( $a_ready ) && ( $a_ready['state'] ?? null ) === 'ready', 'An adapted job reaches ready (AC2)' );

$a_raw = $artifact_bytes( is_array( $a_ready ) ? $a_ready : [] );
$a_container = $parse( $a_raw );
$a_names = $open_index( $a_container['sealed_index'], $keypair );
$a_reassembled = '';
foreach ( $a_container['records'] as $i => $record ) {
	$plain = $open_segment( $record, $keypair );
	if ( is_string( $plain ) && is_array( $a_names ) && ( $a_names[ $i ] ?? null ) === $fixture_rel ) {
		$a_reassembled .= $plain;
	}
}
kntnt_extractor_assert( $a_reassembled === $fixture_bytes, 'The adapted file reassembles to the original bytes (AC2)' );

// --- AC1: a failed stall-job resumes, truncating an unacknowledged tail ---

wp_set_current_user( $owner->ID );
$r_response = $post_extractions( $selection );
$r_id = is_array( $r_response->get_data() ) ? (string) ( $r_response->get_data()['id'] ?? '' ) : '';
$r_secret = (string) ( ( $read_state( $work, $r_id ) ?? [] )['tick_secret'] ?? '' );

// Seal the table and the first file part so there is a committed prefix to keep.
$tick( $r_id, $r_secret );
$tick( $r_id, $r_secret );
$r_partial = $read_state( $work, $r_id ) ?? [];
$r_progress = is_array( $r_partial['progress'] ?? null ) ? $r_partial['progress'] : [];
$r_build = $build_file_of( $work, $r_id );
$committed_bytes = is_int( $r_progress['container_bytes'] ?? null ) ? $r_progress['container_bytes'] : 0;
$committed_prefix = $r_build !== '' && $committed_bytes > 0 ? substr( (string) file_get_contents( $r_build ), 0, $committed_bytes ) : '';
kntnt_extractor_assert( strlen( $committed_prefix ) === $committed_bytes && $committed_bytes > 0, 'The committed prefix is captured before the crash (AC1)' );

// Simulate a tick killed after appending bytes it never acknowledged, then drop the
// job to failed exactly as 0.5.1 did: a spent attempt counter, a recorded reason, and
// no budget keys at all, because that release had none to write. That is the record a
// stranded production run is found in after this plugin is upgraded over it.
if ( $r_build !== '' ) {
	file_put_contents( $r_build, random_bytes( 32 ), FILE_APPEND );
}
$r_partial['state'] = 'failed';
$r_partial['attempts'] = 3;
$r_partial['error'] = 'The extraction stalled: 3 consecutive attempts to package a file ended without advancing.';
unset( $r_partial['chunk_size'], $r_partial['table_chunk_bytes'], $r_partial['table_chunk_rows'] );
$write_state( $work, $r_id, $r_partial );

$tick( $r_id, $r_secret );
$r_resumed = $read_state( $work, $r_id ) ?? [];
kntnt_extractor_assert( ( $r_resumed['state'] ?? null ) !== 'failed', 'A further tick re-drives a stall-failed job instead of no-opping (AC1)' );
kntnt_extractor_assert( ( $r_resumed['attempts'] ?? null ) === 0, 'Resuming a failed job resets the spent attempt counter (AC1)' );

$drive_to_ready( $r_id, $r_secret );
$r_ready = $get_extraction( $r_id )->get_data();
kntnt_extractor_assert( is_array( $r_ready ) && ( $r_ready['state'] ?? null ) === 'ready', 'A resumed failed job reaches ready (AC1)' );

$r_raw = $artifact_bytes( is_array( $r_ready ) ? $r_ready : [] );
kntnt_extractor_assert( $committed_prefix !== '' && str_starts_with( $r_raw, $committed_prefix ), 'The resumed artifact keeps the exact committed prefix — the unacknowledged tail was truncated, not sealed (AC1)' );

$r_container = $parse( $r_raw );
$r_names = $open_index( $r_container['sealed_index'], $keypair );
$r_file_parts = is_array( $r_names ) ? count( array_filter( $r_names, static fn( string $n ): bool => $n === $fixture_rel ) ) : 0;
kntnt_extractor_assert( $r_file_parts >= 2, 'The resumed container still splits the file into parts (AC1)' );

$r_reassembled = '';
foreach ( $r_container['records'] as $i => $record ) {
	$plain = $open_segment( $record, $keypair );
	if ( is_string( $plain ) && is_array( $r_names ) && ( $r_names[ $i ] ?? null ) === $fixture_rel ) {
		$r_reassembled .= $plain;
	}
}
kntnt_extractor_assert( $r_reassembled === $fixture_bytes, 'The resumed file reassembles despite the crash mid-chunk (AC1)' );

// --- AC3: a budget already at one byte still fails the job ---

wp_set_current_user( $owner->ID );
$f_response = $post_extractions( $selection );
$f_id = is_array( $f_response->get_data() ) ? (string) ( $f_response->get_data()['id'] ?? '' ) : '';
$f_secret = (string) ( ( $read_state( $work, $f_id ) ?? [] )['tick_secret'] ?? '' );
$tick( $f_id, $f_secret );
$f_state = $read_state( $work, $f_id ) ?? [];
$f_state['attempts'] = 3;
$f_state['chunk_size'] = 1;
$write_state( $work, $f_id, $f_state );
$tick( $f_id, $f_secret );
$f_failed = $get_extraction( $f_id )->get_data();
kntnt_extractor_assert( is_array( $f_failed ) && ( $f_failed['state'] ?? null ) === 'failed', 'A stall at a one-byte budget fails the job rather than shrinking further (AC3)' );
$f_message = is_array( $f_failed ) && is_array( $f_failed['error'] ?? null ) ? (string) ( $f_failed['error']['message'] ?? '' ) : '';
kntnt_extractor_assert( $f_message !== '' && str_contains( $f_message, $fixture_rel ), 'The floor-stall reason names the file the build died on (AC3)' );
kntnt_extractor_assert( str_contains( $f_message, 'memory_limit' ) && str_contains( $f_message, 'max_execution_time' ), 'The floor-stall reason names the two host limits (AC3)' );

// A floor-failure this release wrote is never resumable, so the partial
// container is residue, not a resume input. The small record stays so this
// poll could still report failed.
kntnt_extractor_assert( $build_file_of( $work, $f_id ) === '', 'A floor-failed job discards its in-progress container (P3)' );
kntnt_extractor_assert( $sidecar_of( $work, $f_id ) === '', 'A floor-failed job discards the container index sidecar too (P3)' );
kntnt_extractor_assert( is_file( $work . '/' . $f_id . '/state.json' ), 'A floor-failed job keeps the small record a poll still reads (P3)' );

// A further tick must not revive a floor-failed job — that is the infinite-retry
// the floor exists to close.
$tick( $f_id, $f_secret );
$f_still = $get_extraction( $f_id )->get_data();
kntnt_extractor_assert( is_array( $f_still ) && ( $f_still['state'] ?? null ) === 'failed', 'A further tick leaves a floor-failed job failed (AC3)' );

// The floor is not the whole of it, and this is the case that says so. Give the same
// failed job budgets that HAVE been adapted and could still be halved several times
// over, so nothing about its size stops a resume. It must still stay failed: a stall
// this release recorded is one it already shrank its way into, and re-driving it
// re-runs a search whose every remaining step has been tried. Without this the resume
// predicate could be satisfied by any diagnosed stall, which is a promise the
// adaptation path makes it impossible to keep — and, being unreachable, one no other
// case in this file would notice being broken.
$f_adapted = $read_state( $work, $f_id ) ?? [];
$f_adapted['chunk_size'] = 65536;
$f_adapted['table_chunk_bytes'] = 65536;
$f_adapted['table_chunk_rows'] = 512;
$write_state( $work, $f_id, $f_adapted );
$tick( $f_id, $f_secret );
$f_adapted_after = $read_state( $work, $f_id ) ?? [];
kntnt_extractor_assert( ( $f_adapted_after['state'] ?? null ) === 'failed', 'A stall this release already adapted around is never re-driven, however much budget is left (AC3)' );

// --- AC4: an opaque throw-failure is not resumed ---

wp_set_current_user( $owner->ID );
$t_response = $post_extractions( $selection );
$t_id = is_array( $t_response->get_data() ) ? (string) ( $t_response->get_data()['id'] ?? '' ) : '';
$t_secret = (string) ( ( $read_state( $work, $t_id ) ?? [] )['tick_secret'] ?? '' );
$tick( $t_id, $t_secret );
$t_state = $read_state( $work, $t_id ) ?? [];
$t_state['state'] = 'failed';
$t_state['error'] = null;
$write_state( $work, $t_id, $t_state );
$tick( $t_id, $t_secret );
$t_after = $read_state( $work, $t_id ) ?? [];
kntnt_extractor_assert( ( $t_after['state'] ?? null ) === 'failed', 'An opaque failed job is not re-driven (AC4)' );

// --- AC5: the watchdog resumes a stall-failed job and ignores an opaque one ---

$store = new Job_Store( new Config() );
$dispatcher = new Dispatcher( $store, new Config(), new Artifact_Builder( new Table_Dumper(), new Config() ) );
$watchdog = new Watchdog( $store, $dispatcher );

wp_set_current_user( $owner->ID );
$w_response = $post_extractions( $selection );
$w_id = is_array( $w_response->get_data() ) ? (string) ( $w_response->get_data()['id'] ?? '' ) : '';
$tick( $w_id, (string) ( ( $read_state( $work, $w_id ) ?? [] )['tick_secret'] ?? '' ) );
$w_state = $read_state( $work, $w_id ) ?? [];
$w_state['state'] = 'failed';
$w_state['attempts'] = 3;
$w_state['error'] = 'The extraction stalled: 3 consecutive attempts ended without advancing.';
$w_state['updated_at'] = time() - 86400;
unset( $w_state['chunk_size'], $w_state['table_chunk_bytes'], $w_state['table_chunk_rows'] );
$write_state( $work, $w_id, $w_state );

$opaque_response = $post_extractions( $selection );
$opaque_id = is_array( $opaque_response->get_data() ) ? (string) ( $opaque_response->get_data()['id'] ?? '' ) : '';
$tick( $opaque_id, (string) ( ( $read_state( $work, $opaque_id ) ?? [] )['tick_secret'] ?? '' ) );
$opaque_state = $read_state( $work, $opaque_id ) ?? [];
$opaque_state['state'] = 'failed';
$opaque_state['error'] = null;
$opaque_state['updated_at'] = time() - 86400;
$write_state( $work, $opaque_id, $opaque_state );

$driven = $watchdog->patrol();
$driven_ids = array_map( static fn( $job ): string => $job->id, $driven );
kntnt_extractor_assert( in_array( $w_id, $driven_ids, true ), 'The watchdog restarts a stall-failed job (AC5)' );
kntnt_extractor_assert( ! in_array( $opaque_id, $driven_ids, true ), 'The watchdog leaves an opaque failed job alone (AC5)' );
kntnt_extractor_assert( ( ( $read_state( $work, $w_id ) ?? [] )['state'] ?? null ) !== 'failed', 'The stall-failed job the watchdog drove is no longer failed (AC5)' );
kntnt_extractor_assert( ( ( $read_state( $work, $opaque_id ) ?? [] )['state'] ?? null ) === 'failed', 'The opaque failed job is still failed after the patrol (AC5)' );

// --- AC6: a resume never takes a slot the concurrency ceiling has already given away ---

// A failed job is terminal and frees its slot, so a `POST /extractions` may have taken
// it in the meantime. Re-entering `running` occupies it again, and doing that past the
// ceiling would put two live builds on a site whose whole design says one. The same
// job is ticked twice against two different ceilings, so the second result is the
// control for the first: whatever refuses the resume under a full ceiling demonstrably
// is the ceiling, and not some other property of the record.
wp_set_current_user( $owner->ID );
$c_response = $post_extractions( $selection );
$c_id = is_array( $c_response->get_data() ) ? (string) ( $c_response->get_data()['id'] ?? '' ) : '';
$c_secret = (string) ( ( $read_state( $work, $c_id ) ?? [] )['tick_secret'] ?? '' );
$tick( $c_id, $c_secret );
$c_state = $read_state( $work, $c_id ) ?? [];
$c_state['state'] = 'failed';
$c_state['attempts'] = 3;
$c_state['error'] = 'The extraction stalled: 3 consecutive attempts to package a file ended without advancing.';
unset( $c_state['chunk_size'], $c_state['table_chunk_bytes'], $c_state['table_chunk_rows'] );
$write_state( $work, $c_id, $c_state );

// A live job now holds the only slot a ceiling of one allows.
$rival_response = $post_extractions( $selection );
$rival_id = is_array( $rival_response->get_data() ) ? (string) ( $rival_response->get_data()['id'] ?? '' ) : '';
$full = static fn(): int => 1;
add_filter( 'kntnt_extractor_config_max_active_jobs', $full, 20 );
$tick( $c_id, $c_secret );
kntnt_extractor_assert( ( ( $read_state( $work, $c_id ) ?? [] )['state'] ?? null ) === 'failed', 'A resume that would exceed the concurrency ceiling is declined (AC6)' );
kntnt_extractor_assert( ( ( $read_state( $work, $c_id ) ?? [] )['error'] ?? null ) !== null, 'A declined resume leaves the failure reason intact, so the job is exactly as it was found (AC6)' );

// Control: the same record, the same tick, a ceiling with room in it.
remove_filter( 'kntnt_extractor_config_max_active_jobs', $full, 20 );
$tick( $c_id, $c_secret );
kntnt_extractor_assert( ( ( $read_state( $work, $c_id ) ?? [] )['state'] ?? null ) !== 'failed', 'The same record resumes once the ceiling has room, so the refusal above was the ceiling (AC6)' );
kntnt_extractor_assert( $rival_id !== '' && ( ( $read_state( $work, $rival_id ) ?? [] )['state'] ?? null ) !== null, 'The rival job that held the slot is untouched by the declined resume (AC6)' );

// --- P3: a this-release stall that never adapts still discards staging ---

// A structure-only chunk spends no bound, so adapt() returns null with every
// budget still at zero. persist_failure is only reached by a failure this
// release just wrote, so it must reclaim anyway — the keep-path keyed on
// "diagnosed and unadapted" would leave residue GET /extractions cannot see.
wp_set_current_user( $owner->ID );
$so_response = $post_extractions(
	[
		'tables' => [ $wpdb->options ],
		'tables_structure_only' => [ $wpdb->users ],
		'public_key' => base64_encode( $public_key ),
	]
);
$so_id = is_array( $so_response->get_data() ) ? (string) ( $so_response->get_data()['id'] ?? '' ) : '';
$so_secret = (string) ( ( $read_state( $work, $so_id ) ?? [] )['tick_secret'] ?? '' );
$tick( $so_id, $so_secret );
kntnt_extractor_assert( $build_file_of( $work, $so_id ) !== '', 'The structure-only stall case has an in-progress container before the stall (P3)' );
kntnt_extractor_assert( $sidecar_of( $work, $so_id ) !== '', 'The structure-only stall case has an index sidecar before the stall (P3)' );
$so_state = $read_state( $work, $so_id ) ?? [];
$so_state['attempts'] = 3;
$write_state( $work, $so_id, $so_state );
$tick( $so_id, $so_secret );
$so_failed = $read_state( $work, $so_id ) ?? [];
kntnt_extractor_assert( ( $so_failed['state'] ?? null ) === 'failed', 'A structure-only stall fails the job (P3)' );
kntnt_extractor_assert( ( $so_failed['chunk_size'] ?? 0 ) === 0 && ( $so_failed['table_chunk_bytes'] ?? 0 ) === 0, 'The structure-only stall never adapted a budget (P3)' );
kntnt_extractor_assert( array_key_exists( 'chunk_size', $so_failed ) && array_key_exists( 'table_chunk_bytes', $so_failed ) && array_key_exists( 'table_chunk_rows', $so_failed ), 'A this-release stall still writes the schema-8 budget keys, even at zero (P3)' );
$so_job_failed = $store->find( $so_id );
kntnt_extractor_assert( $so_job_failed !== null && ! $so_job_failed->is_pre_adaptation_stall(), 'A this-release structure-only stall is not the pre-adaptation shape (P3)' );
kntnt_extractor_assert( $build_file_of( $work, $so_id ) === '', 'A this-release structure-only stall discards its container (P3)' );
kntnt_extractor_assert( $sidecar_of( $work, $so_id ) === '', 'A this-release structure-only stall discards its index sidecar (P3)' );
kntnt_extractor_assert( is_file( $work . '/' . $so_id . '/state.json' ), 'A this-release structure-only stall keeps the small record (P3)' );

// The reclaim resolves the container through the record's own artifact token, so a
// hand-edited token carrying a null byte would make realpath() raise inside the very
// path that is recording a failure. It must be nothing to remove instead, exactly as
// the artifact deletion treats it.
$so_hostile = $read_state( $work, $so_id ) ?? [];
$so_hostile['artifact'] = "kntnt\0extractor.sealed";
$write_state( $work, $so_id, $so_hostile );
$so_job = $store->find( $so_id );
kntnt_extractor_assert( $so_job !== null, 'A record carrying a hostile artifact token still reads back (P3 precondition)' );
if ( $so_job !== null ) {
	$store->reclaim_staging( $so_job );
}
kntnt_extractor_assert( is_file( $work . '/' . $so_id . '/state.json' ), 'A null-byte artifact token is nothing to reclaim rather than a throw (P3)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
@unlink( $fixture_abs );
$rmrf( $work );
wp_set_current_user( 0 );

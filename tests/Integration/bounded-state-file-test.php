<?php
/**
 * Integration test: the per-chunk state write is bounded, not sized by the selection.
 *
 * A real extraction of 49,116 files spent about 96 % of its per-chunk wall clock in
 * `Job_Store::save()`, and none of that time was doing anything: the job record
 * carried the whole file selection and a segment-name list that grew by one entry per
 * chunk, so every one of the two saves a chunk performs re-encoded and rewrote a
 * multi-megabyte document to move a handful of counters. The rate decayed measurably
 * as the name list grew — the signature of a cost proportional to a growing record —
 * and the run wrote on the order of half a terabyte of JSON to move 2.23 GB of files.
 *
 * The fix splits the record by what is BOUNDED rather than by what is mutable, since
 * unboundedness is the actual defect: the selection and the segment names are the only
 * two parts of a job that grow without limit, and neither one changes in a way the two
 * saves a chunk performs need to persist. The selection moves to `job.json`, written at
 * create and by nothing else except a tick that skipped a vanished file, through
 * `Job_Store::save_with_selection()` (ADR-0026); the line drawn here is unboundedness
 * and not immutability, which used to amount to the same thing and no longer does. The
 * segment names move to an append-only index sidecar beside the in-progress container,
 * owned by the writer that is the only thing that ever needed them; and `state.json` —
 * the only file `Job_Store::save()` rewrites — holds a fixed set of scalars whose size
 * is O(1) in the selection, forever.
 *
 * This file pins that contract, which is a performance property expressed as an
 * observable one — bytes on disk, not a stopwatch, so it holds on any host:
 *  - AC1 (the save is bounded): the file a save rewrites stays under a small fixed
 *    ceiling however large the selection is and however many segments are sealed, and
 *    it carries no path from the selection.
 *  - AC2 (a chunk that skips nothing never rewrites the selection): the file holding
 *    the selection is byte-identical before and after a build advances many chunks.
 *  - AC3 (the liveness counter survives): the poll's `progress.chunks_done` still
 *    counts every sealed segment, which is what a client's stall rule watches.
 *  - AC4 (the sealed index is exact): a build resumed across many ticks publishes an
 *    index naming every segment in write order, repeated names intact, so moving the
 *    names out of the record costs the artifact nothing.
 *  - AC5 (both anchors roll back together): a crash that leaves garbage past the
 *    committed offset in the container AND in the index sidecar is truncated away on
 *    resume, so the two never disagree about how far the build got.
 *  - AC6 (a build begun under schema 6 is skipped, not migrated): the migration that
 *    rebuilt the sidecar from a schema-6 record's in-record name list retired with the
 *    rest of ADR-0015's back-compatibility cluster (#52, ADR-0024). Such a record is
 *    no longer read at all, and what this pins is that it says so quietly — a 404,
 *    with the record left where it lies and the jobs beside it untouched — rather than
 *    resuming into a container whose index would name only the segments sealed after
 *    the upgrade. That silent, well-framed, mostly-nameless artifact is the failure
 *    the migration existed to rule out, and skipping the record rules it out too.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Crypto\Sealed_Writer;
use Kntnt\Extractor\Dispatcher;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Recursively removes a directory tree so the run leaves no working directory behind.
$bsf_rmrf = static function ( string $dir ) use ( &$bsf_rmrf ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: [] as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			$bsf_rmrf( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
};

// Dispatches POST /extractions with a JSON body through the live REST server.
$bsf_post = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $request );
};

// Dispatches GET /extractions/{id} through the live REST server.
$bsf_get = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) );
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret.
$bsf_tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Unseals and splits the length-prefixed index back into its ordered names.
$bsf_open_index = static function ( string $sealed_index, string $keypair ): ?array {
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

// Locates the sealed index at the tail of a finished container and unseals its names.
// A job that failed publishes nothing, so anything too short to be a container yields
// null rather than an unpack over empty bytes — a failing assertion here must report
// itself, not abort the file and take every later test with it.
$bsf_index_of = static function ( string $raw, string $keypair ) use ( $bsf_open_index ): ?array {
	$trailer_at = strlen( $raw ) - 8;
	if ( $trailer_at <= 0 ) {
		return null;
	}
	$index_len = (int) unpack( 'P', substr( $raw, $trailer_at, 8 ) )[1];
	if ( $index_len <= 0 || $index_len > $trailer_at ) {
		return null;
	}
	return $bsf_open_index( substr( $raw, $trailer_at - $index_len, $index_len ), $keypair );
};

// Finds the single file in a job's directory whose name ends with the given suffix.
$bsf_find_file = static function ( string $work, string $id, string $suffix ): string {
	foreach ( glob( $work . '/' . $id . '/*' ) ?: [] as $candidate ) {
		if ( is_file( $candidate ) && str_ends_with( $candidate, $suffix ) ) {
			return $candidate;
		}
	}
	return '';
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( 'kntnt_extractor_operate' ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$bsf_owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so the
// artifact stays web-reachable while the run owns all of its state and cleans up.
$bsf_work = wp_upload_dir()['basedir'] . '/kntnt-extractor-bounded-' . bin2hex( random_bytes( 4 ) );
$bsf_force_work = static fn(): string => $bsf_work;
add_filter( 'kntnt_extractor_config_work_dir', $bsf_force_work );

// Raise the concurrency ceiling so the two jobs this file needs can coexist.
$bsf_force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $bsf_force_max );

// Short-circuit every loopback the driver fires, so a nudge never touches the real
// network; the ticks below drive each job synchronously across chunks.
$bsf_intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $bsf_intercept, 10, 3 );

// A selection of many small files, each its own chunk under the default chunk size.
// The count is what makes this test discriminating: the selection must be far larger
// than the ceiling AC1 holds the per-save file to, so a record that still carried it
// could not possibly pass.
$bsf_fixture_dir = wp_upload_dir()['basedir'] . '/kntnt-extractor-bounded-fixture-' . bin2hex( random_bytes( 4 ) );
wp_mkdir_p( $bsf_fixture_dir );
$bsf_root = wp_normalize_path( (string) realpath( ABSPATH ) );
$bsf_files = [];
for ( $i = 0; $i < 40; $i++ ) {
	$abs = $bsf_fixture_dir . sprintf( '/bounded-state-fixture-%03d.bin', $i );
	file_put_contents( $abs, str_repeat( chr( ord( 'a' ) + ( $i % 26 ) ), 24 ) );
	$bsf_files[] = ltrim( substr( wp_normalize_path( (string) realpath( $abs ) ), strlen( $bsf_root ) ), '/' );
}

// A caller's ephemeral X25519 keypair; only the public half is submitted.
$bsf_keypair = sodium_crypto_box_keypair();
$bsf_selection = [
	'tables' => [ $wpdb->options ],
	'files' => $bsf_files,
	'public_key' => base64_encode( sodium_crypto_box_publickey( $bsf_keypair ) ),
];

// The number of segments the selection must seal: one for the table, one per file.
$bsf_expected_segments = 1 + count( $bsf_files );

// The ceiling AC1 holds the per-save file to. Generous enough that the record's fixed
// scalars — two 32-byte hex tokens, a base64 key, the counters, the last-N attempt
// log — never approach it, and far below what even this modest 40-path selection
// serialises to.
$bsf_ceiling = 2048;

// --- Create the job and drive it to ready, watching the two files as it goes ------

wp_set_current_user( $bsf_owner->ID );
$bsf_created = $bsf_post( $bsf_selection )->get_data();
$bsf_id = is_array( $bsf_created ) && is_string( $bsf_created['id'] ?? null ) ? $bsf_created['id'] : '';
kntnt_extractor_assert( $bsf_id !== '', 'POST /extractions creates the many-file job' );

$bsf_job_file = $bsf_work . '/' . $bsf_id . '/job.json';
$bsf_state_file = $bsf_work . '/' . $bsf_id . '/state.json';
kntnt_extractor_assert( is_file( $bsf_job_file ) && is_file( $bsf_state_file ), 'A created job writes its selection and its state as two separate files' );

$bsf_state = json_decode( (string) file_get_contents( $bsf_state_file ), true );
$bsf_secret = is_array( $bsf_state ) && is_string( $bsf_state['tick_secret'] ?? null ) ? $bsf_state['tick_secret'] : '';
kntnt_extractor_assert( $bsf_secret !== '', 'The per-job tick secret is persisted in the state file' );

// The selection is the unbounded half and must be nowhere near the per-save file:
// serialising it at create, or once per skip, is fine; serialising it per chunk is the
// defect.
kntnt_extractor_assert(
	filesize( $bsf_job_file ) > $bsf_ceiling,
	'The selection file is genuinely larger than the ceiling the state file is held to, so AC1 is a real constraint',
);

// Capture the selection file's exact bytes, so AC2 can prove no chunk rewrote it. No
// file in this fixture vanishes under the packaging, so nothing here reaches
// `save_with_selection()`, the one write that ever touches this half (ADR-0026).
$bsf_job_before = (string) file_get_contents( $bsf_job_file );

// Drive the job one bounded chunk per tick, recording the largest the per-save file
// ever grows to and the poll's chunk counter at every step.
$bsf_max_state_bytes = filesize( $bsf_state_file );
$bsf_counter_advanced = true;
$bsf_previous_chunks = 0;
$bsf_ticks = 0;
while ( $bsf_ticks < 200 ) {

	$bsf_poll = $bsf_get( $bsf_id )->get_data();
	if ( is_array( $bsf_poll ) && ( $bsf_poll['state'] ?? null ) === 'ready' ) {
		break;
	}

	$bsf_tick( $bsf_id, $bsf_secret );
	$bsf_ticks++;
	clearstatcache( true, $bsf_state_file );
	$bsf_max_state_bytes = max( $bsf_max_state_bytes, (int) filesize( $bsf_state_file ) );

	// AC3: the liveness counter moves on every chunk the build seals — the signal a
	// client's stall rule reads to tell a slow job from a wedged one.
	$bsf_progress = $bsf_get( $bsf_id )->get_data();
	$bsf_chunks = is_array( $bsf_progress ) && is_array( $bsf_progress['progress'] ?? null ) ? (int) ( $bsf_progress['progress']['chunks_done'] ?? -1 ) : -1;
	if ( $bsf_chunks !== $bsf_previous_chunks + 1 ) {
		$bsf_counter_advanced = false;
	}
	$bsf_previous_chunks = $bsf_chunks;

}

$bsf_ready = $bsf_get( $bsf_id )->get_data();
kntnt_extractor_assert( is_array( $bsf_ready ) && ( $bsf_ready['state'] ?? null ) === 'ready', 'The many-file job reaches ready across its chunks' );

// --- AC1: the file a save rewrites is bounded ------------------------------------

kntnt_extractor_assert(
	$bsf_max_state_bytes > 0 && $bsf_max_state_bytes < $bsf_ceiling,
	sprintf( 'The per-save state file never exceeds %d bytes across a %d-segment build (AC1)', $bsf_ceiling, $bsf_expected_segments ),
);

// Size alone could be met by a truncated record, so pin the mechanism too: the state
// file carries no path from the selection and no segment-name list.
$bsf_state_raw = (string) file_get_contents( $bsf_state_file );
kntnt_extractor_assert(
	! str_contains( $bsf_state_raw, $bsf_files[0] ) && ! str_contains( $bsf_state_raw, 'segment_names' ),
	'The per-save state file carries neither a selected path nor a segment-name list (AC1)',
);

// --- AC2: a chunk that skips nothing never rewrites the selection ----------------

clearstatcache( true, $bsf_job_file );
kntnt_extractor_assert(
	(string) file_get_contents( $bsf_job_file ) === $bsf_job_before,
	'The selection file is byte-identical after a build that advanced every chunk (AC2)',
);

// --- AC3: the liveness counter counted every chunk -------------------------------

kntnt_extractor_assert( $bsf_counter_advanced, 'The poll\'s chunks_done advances by exactly one per sealed chunk (AC3)' );
$bsf_final_chunks = is_array( $bsf_ready ) && is_array( $bsf_ready['progress'] ?? null ) ? (int) ( $bsf_ready['progress']['chunks_done'] ?? -1 ) : -1;
kntnt_extractor_assert(
	$bsf_final_chunks === $bsf_expected_segments,
	sprintf( 'A ready job reports chunks_done equal to its artifact\'s segment count, %d (AC3)', $bsf_expected_segments ),
);

// --- AC4: the sealed index is exact ----------------------------------------------

$bsf_url = is_array( $bsf_ready ) && is_string( $bsf_ready['download_url'] ?? null ) ? $bsf_ready['download_url'] : '';
$bsf_uploads = wp_upload_dir();
$bsf_basedir = rtrim( $bsf_uploads['basedir'], '/' );
$bsf_baseurl = rtrim( $bsf_uploads['baseurl'], '/' );
$bsf_artifact = $bsf_url !== '' ? $bsf_basedir . substr( $bsf_url, strlen( $bsf_baseurl ) ) : '';
$bsf_raw = $bsf_artifact !== '' && is_file( $bsf_artifact ) ? (string) file_get_contents( $bsf_artifact ) : '';
kntnt_extractor_assert( str_starts_with( $bsf_raw, Sealed_Writer::MAGIC ), 'The many-file job publishes a sealed container (AC4)' );

$bsf_names = $bsf_index_of( $bsf_raw, $bsf_keypair );
kntnt_extractor_assert(
	$bsf_names === array_merge( [ $wpdb->options ], $bsf_files ),
	'The sealed index names every segment in write order, accumulated outside the job record (AC4)',
);

// The index sidecar is working state, not an artifact: a finished build leaves none
// of it behind beside the published container.
kntnt_extractor_assert(
	$bsf_find_file( $bsf_work, $bsf_id, '.names' ) === '',
	'A finished build leaves no index sidecar in the job directory (AC4)',
);

// --- AC5: the container and the index sidecar roll back together -----------------

wp_set_current_user( $bsf_owner->ID );
$bsf_r_created = $bsf_post( $bsf_selection )->get_data();
$bsf_r_id = is_array( $bsf_r_created ) && is_string( $bsf_r_created['id'] ?? null ) ? $bsf_r_created['id'] : '';
$bsf_r_state = json_decode( (string) file_get_contents( $bsf_work . '/' . $bsf_r_id . '/state.json' ), true );
$bsf_r_secret = is_array( $bsf_r_state ) && is_string( $bsf_r_state['tick_secret'] ?? null ) ? $bsf_r_state['tick_secret'] : '';

// Advance a few chunks, then stop short: the build now has a committed container and
// a committed index sidecar, both anchored by the offsets the state file carries.
$bsf_tick( $bsf_r_id, $bsf_r_secret );
$bsf_tick( $bsf_r_id, $bsf_r_secret );
$bsf_tick( $bsf_r_id, $bsf_r_secret );

$bsf_r_container = $bsf_find_file( $bsf_work, $bsf_r_id, '.building' );
$bsf_r_sidecar = $bsf_find_file( $bsf_work, $bsf_r_id, '.names' );
kntnt_extractor_assert(
	$bsf_r_container !== '' && $bsf_r_sidecar !== '',
	'A mid-build job keeps its in-progress container and its index sidecar in the deny-hardened job directory (AC5)',
);

// Simulate a tick killed after it appended but before its progress was persisted, in
// BOTH files at once — the shape a crash actually leaves, since neither write is
// ordered against the other. A correct resume truncates each back to its own anchor.
file_put_contents( $bsf_r_container, random_bytes( 32 ), FILE_APPEND );
file_put_contents( $bsf_r_sidecar, random_bytes( 48 ), FILE_APPEND );

$bsf_r_ticks = 0;
while ( $bsf_r_ticks < 200 ) {
	$bsf_r_poll = $bsf_get( $bsf_r_id )->get_data();
	if ( is_array( $bsf_r_poll ) && ( $bsf_r_poll['state'] ?? null ) === 'ready' ) {
		break;
	}
	$bsf_tick( $bsf_r_id, $bsf_r_secret );
	$bsf_r_ticks++;
}

$bsf_r_ready = $bsf_get( $bsf_r_id )->get_data();
kntnt_extractor_assert( is_array( $bsf_r_ready ) && ( $bsf_r_ready['state'] ?? null ) === 'ready', 'A job crashed past both committed offsets resumes and reaches ready (AC5)' );

$bsf_r_url = is_array( $bsf_r_ready ) && is_string( $bsf_r_ready['download_url'] ?? null ) ? $bsf_r_ready['download_url'] : '';
$bsf_r_path = $bsf_r_url !== '' ? $bsf_basedir . substr( $bsf_r_url, strlen( $bsf_baseurl ) ) : '';
$bsf_r_raw = $bsf_r_path !== '' && is_file( $bsf_r_path ) ? (string) file_get_contents( $bsf_r_path ) : '';
$bsf_r_names = $bsf_index_of( $bsf_r_raw, $bsf_keypair );
kntnt_extractor_assert(
	$bsf_r_names === array_merge( [ $wpdb->options ], $bsf_files ),
	'The crashed sidecar bytes are truncated away, so the sealed index names exactly the selection in order (AC5)',
);

// --- AC6: a build begun under schema 6 is skipped, not migrated -----------------

wp_set_current_user( $bsf_owner->ID );
$bsf_l_created = $bsf_post( $bsf_selection )->get_data();
$bsf_l_id = is_array( $bsf_l_created ) && is_string( $bsf_l_created['id'] ?? null ) ? $bsf_l_created['id'] : '';
$bsf_l_state_file = $bsf_work . '/' . $bsf_l_id . '/state.json';
$bsf_l_state = json_decode( (string) file_get_contents( $bsf_l_state_file ), true );
$bsf_l_secret = is_array( $bsf_l_state ) && is_string( $bsf_l_state['tick_secret'] ?? null ) ? $bsf_l_state['tick_secret'] : '';

// Seal a few segments, so the record stripped below is a build genuinely part way
// through rather than one nothing would have been lost by abandoning.
$bsf_tick( $bsf_l_id, $bsf_l_secret );
$bsf_tick( $bsf_l_id, $bsf_l_secret );
$bsf_tick( $bsf_l_id, $bsf_l_secret );
kntnt_extractor_assert( $bsf_find_file( $bsf_work, $bsf_l_id, '.names' ) !== '', 'The schema-6 case has a real sidecar before the record is rewritten (AC6 precondition)' );

// Rewrite the record into the shape 0.5.1 left behind — the ordered names in the
// record, no segment count, no index offset, and none of the keys releases after it
// added — and delete the sidecar, which no release before this one ever wrote. This
// is exactly the on-disk state an operator installing the upgrade over a running
// extraction would create.
$bsf_sealed_so_far = array_slice( array_merge( [ $wpdb->options ], $bsf_files ), 0, 3 );
$bsf_l_state = json_decode( (string) file_get_contents( $bsf_l_state_file ), true );
$bsf_l_state['version'] = 6;
$bsf_l_state['progress']['segment_names'] = $bsf_sealed_so_far;
unset( $bsf_l_state['progress']['segment_count'], $bsf_l_state['progress']['index_bytes'] );
unset( $bsf_l_state['attempts'], $bsf_l_state['error'], $bsf_l_state['chunk_size'], $bsf_l_state['table_chunk_bytes'], $bsf_l_state['table_chunk_rows'] );
file_put_contents( $bsf_l_state_file, (string) wp_json_encode( $bsf_l_state ) );
@unlink( $bsf_find_file( $bsf_work, $bsf_l_id, '.names' ) );
clearstatcache();

// The record is not read at all, so there is no migration to get right and no
// half-nameless container to publish. It answers as no such job.
kntnt_extractor_assert( $bsf_get( $bsf_l_id )->get_status() === 404, 'A schema-6 record is no longer read, so its poll is a 404 rather than a migration (AC6)' );

// A further tick has nothing to drive: the record is left byte for byte as it was
// found, and no container is opened over the one the build already had.
$bsf_tick( $bsf_l_id, $bsf_l_secret );
$bsf_l_after = json_decode( (string) file_get_contents( $bsf_l_state_file ), true );
kntnt_extractor_assert(
	is_array( $bsf_l_after ) && ( $bsf_l_after['progress']['segment_names'] ?? null ) === $bsf_sealed_so_far && ! array_key_exists( 'attempts', $bsf_l_after ),
	'A further tick neither revives the schema-6 record nor rewrites it (AC6)',
);
kntnt_extractor_assert( $bsf_find_file( $bsf_work, $bsf_l_id, '.names' ) === '', 'No sidecar is reconstructed for it, because nothing reseeds one any more (AC6)' );

// The job beside it is unaffected, which is what makes skipping the unreadable one
// safe: one stale record on disk must not cost the jobs around it (ADR-0024).
$bsf_beside = $bsf_get( $bsf_r_id )->get_data();
kntnt_extractor_assert( is_array( $bsf_beside ) && ( $bsf_beside['state'] ?? null ) === 'ready', 'The finished job beside the skipped record still polls as ready (AC6)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $bsf_intercept, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $bsf_force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $bsf_force_work );
$bsf_rmrf( $bsf_fixture_dir );
$bsf_rmrf( $bsf_work );
$bsf_rmrf( $bsf_work . '-downloads' );
wp_set_current_user( 0 );

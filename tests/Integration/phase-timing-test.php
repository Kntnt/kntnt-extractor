<?php
/**
 * Integration test: a packaging tick can time its own phases, and does not by default.
 *
 * An ordinary small file costs roughly 229 ms per chunk on the measured
 * production host (`docs/measurements/2026-08-19-successful-run.md`), and
 * nothing has ever attributed that cost — the build could not see inside a
 * tick. This file pins the surface that makes the question answerable
 * (issue #39), built in the attempt log's manner (ADR-0016):
 *
 *  - AC1: with the knob off a chunk performs no timing work — the poll carries
 *    no `timings` member and the persisted record carries no `timing_log` key,
 *    its key set being exactly what it is without this feature.
 *  - AC2: with the knob on a completed chunk records separable durations —
 *    a file chunk names the container resume, the path resolution and stats,
 *    the source read, the seal, the container suspend and the record-split
 *    save, each on its own, and the chunk's own total bounds their sum.
 *  - AC3: the series is bounded — thirteen packaging chunks leave the newest
 *    eight, so it never grows with the selection.
 *  - AC4: `API_VERSION` does not move; the behaviour is named in `honours` and
 *    is visible on an authenticated `GET /status`.
 *  - AC5: a record written without the field still parses.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

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
 * @return array<string, mixed>
 */
$post_extractions = static function ( array $body ): array {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	$data = rest_get_server()->dispatch( $request )->get_data();
	return is_array( $data ) ? $data : [];
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
 * @return void
 */
$tick = static function ( string $id, string $secret ): void {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	rest_get_server()->dispatch( $request );
};

/**
 * Reads a job's persisted state record straight off disk.
 *
 * @param string $file Absolute path of the job's state file.
 * @return array<string, mixed>
 */
$read_state = static function ( string $file ): array {
	$decoded = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $decoded ) ? $decoded : [];
};

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-timings-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

$intercept = static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

$public_key = base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) );

wp_set_current_user( $owner->ID );

// --- AC1: the knob is off by default, and off means nothing is recorded -------

$plain = $post_extractions( [
	'tables' => [ $wpdb->options ],
	'files' => [ 'wp-load.php' ],
	'public_key' => $public_key,
] );
$plain_id = is_string( $plain['id'] ?? null ) ? $plain['id'] : '';
kntnt_extractor_assert( $plain_id !== '', 'POST /extractions creates the untimed job' );

$plain_file = $work . '/' . $plain_id . '/state.json';
$plain_state = $read_state( $plain_file );
$plain_secret = is_string( $plain_state['tick_secret'] ?? null ) ? $plain_state['tick_secret'] : '';

$tick( $plain_id, $plain_secret );
$tick( $plain_id, $plain_secret );

$plain_poll = $get_extraction( $plain_id );
kntnt_extractor_assert( ! array_key_exists( 'timings', $plain_poll ), 'A poll of an untimed job omits timings (AC1)' );

$plain_state = $read_state( $plain_file );
kntnt_extractor_assert( ! array_key_exists( 'timing_log', $plain_state ), 'An untimed job persists no timing_log (AC1)' );
kntnt_extractor_assert(
	array_keys( $plain_state ) === [
		'version',
		'id',
		'state',
		'owner',
		'public_key',
		'created_at',
		'updated_at',
		'tick_secret',
		'artifact',
		'progress',
		'progressed_at',
		'attempts',
		'error',
		'chunk_size',
		'table_chunk_bytes',
		'table_chunk_rows',
		'host_memory_limit',
		'host_max_execution_time',
		'raised_memory_limit',
		'raised_max_execution_time',
		'attempt_log',
	],
	'An untimed job record carries exactly the keys it carried before this feature (AC1)',
);

// --- AC2: the knob on records each phase of a chunk separately ----------------

$timing_on = static fn(): bool => true;
add_filter( 'kntnt_extractor_config_phase_timing', $timing_on );

// Twelve core files, so the file chunks outnumber the bounded series and every
// one of them but the last leaves work behind and must suspend the container.
$candidates = [
	'index.php',
	'wp-load.php',
	'wp-settings.php',
	'wp-blog-header.php',
	'wp-cron.php',
	'wp-links-opml.php',
	'wp-login.php',
	'wp-mail.php',
	'wp-signup.php',
	'wp-trackback.php',
	'xmlrpc.php',
	'wp-comments-post.php',
	'wp-activate.php',
	'license.txt',
	'readme.html',
];
$files = array_values( array_slice( array_filter( $candidates, static fn( string $path ): bool => is_file( ABSPATH . $path ) ), 0, 12 ) );
kntnt_extractor_assert( count( $files ) === 12, 'The installation supplies the twelve files the bounded series needs' );

$timed = $post_extractions( [
	'tables' => [ $wpdb->options ],
	'files' => $files,
	'public_key' => $public_key,
] );
$timed_id = is_string( $timed['id'] ?? null ) ? $timed['id'] : '';
kntnt_extractor_assert( $timed_id !== '', 'POST /extractions creates the timed job' );

$timed_file = $work . '/' . $timed_id . '/state.json';
$timed_state = $read_state( $timed_file );
$timed_secret = is_string( $timed_state['tick_secret'] ?? null ) ? $timed_state['tick_secret'] : '';

// The suite pins one chunk per tick, so the first tick is the table slice and
// the second is the first file part.
$tick( $timed_id, $timed_secret );
$tick( $timed_id, $timed_secret );

$timed_poll = $get_extraction( $timed_id );
$timings = is_array( $timed_poll['timings'] ?? null ) ? $timed_poll['timings'] : [];
kntnt_extractor_assert( count( $timings ) === 2, 'A timed job reports one entry per completed chunk (AC2)' );

$table_phases = is_array( $timings[0]['phases'] ?? null ) ? $timings[0]['phases'] : [];
kntnt_extractor_assert(
	is_int( $timings[0]['at'] ?? null ) && $timings[0]['at'] > 0,
	'A timing entry is stamped with the moment the chunk ended (AC2)',
);
kntnt_extractor_assert(
	array_key_exists( 'open', $table_phases )
		&& ! array_key_exists( 'resume', $table_phases )
		&& ! array_key_exists( 'resolve', $table_phases )
		&& ! array_key_exists( 'read', $table_phases ),
	'The opening chunk times the container open, and no file phase it never ran (AC2)',
);

$file_phases = is_array( $timings[1]['phases'] ?? null ) ? $timings[1]['phases'] : [];
foreach ( [ 'total', 'save', 'resume', 'resolve', 'read', 'seal', 'suspend' ] as $phase ) {
	kntnt_extractor_assert(
		array_key_exists( $phase, $file_phases ) && is_int( $file_phases[ $phase ] ) && $file_phases[ $phase ] >= 0,
		sprintf( 'A file chunk records the %s phase on its own (AC2)', $phase ),
	);
}
kntnt_extractor_assert( ! array_key_exists( 'open', $file_phases ), 'A resumed chunk times a resume rather than an open (AC2)' );

// The phases do not overlap, so the chunk's own total is an upper bound on what
// they add up to — which is what lets a reader see the unattributed remainder.
$parts = array_sum( array_diff_key( $file_phases, [ 'total' => 0 ] ) );
kntnt_extractor_assert(
	is_int( $file_phases['total'] ?? null ) && $file_phases['total'] >= $parts,
	'The chunk total bounds the sum of its separable phases (AC2)',
);

// A duration, not a zero: reading a file, sealing it and rewriting the record
// cannot cost under a microsecond, so a total of zero would mean the clock
// beneath the instrument has no resolution and the whole series is fiction.
kntnt_extractor_assert(
	is_int( $file_phases['total'] ?? null ) && $file_phases['total'] > 0,
	'A chunk records a real elapsed duration rather than a zero (AC2)',
);

// --- AC3: the series is bounded and never grows with the selection ------------

// Thirteen chunks in all: one table slice and twelve file parts, the last of
// which finalizes and publishes the container.
for ( $i = 0; $i < 11; $i++ ) {
	$tick( $timed_id, $timed_secret );
}

$done_poll = $get_extraction( $timed_id );
kntnt_extractor_assert( ( $done_poll['state'] ?? null ) === 'ready', 'The timed job packages its whole selection (AC3)' );

// Eight, mirroring Extraction_Job::TIMING_LOG_BOUND. Pinned as a number here on
// purpose: what "bounded" means for a debug surface is the number itself.
$bound = 8;
$done_timings = is_array( $done_poll['timings'] ?? null ) ? $done_poll['timings'] : [];
kntnt_extractor_assert(
	count( $done_timings ) === $bound,
	sprintf( 'Thirteen chunks leave the newest %d timings (AC3)', $bound ),
);

$done_state = $read_state( $timed_file );
$done_log = is_array( $done_state['timing_log'] ?? null ) ? $done_state['timing_log'] : [];
kntnt_extractor_assert( count( $done_log ) === $bound, 'The persisted series is bounded too (AC3)' );
foreach ( $done_log as $entry ) {
	kntnt_extractor_assert(
		is_array( $entry ) && array_keys( $entry ) === [ 'at', 'phases' ] && is_int( $entry['at'] ) && is_array( $entry['phases'] ),
		'Each persisted timing is a stamp and a map of phase durations (AC3)',
	);
}
kntnt_extractor_assert(
	! str_contains( (string) file_get_contents( $timed_file ), 'wp-load.php' ),
	'The timing series names no selected path (AC3)',
);

// --- AC4: the contract version is untouched and the behaviour is named --------

$status = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . Status_Controller::REST_NAMESPACE . '/status' ) )->get_data();
kntnt_extractor_assert(
	is_array( $status ) && ( $status['api_version'] ?? null ) === 7,
	'GET /status still reports api_version 7 (AC4)',
);
kntnt_extractor_assert(
	is_array( $status ) && is_array( $status['honours'] ?? null ) && in_array( 'timings', $status['honours'], true ),
	'An authenticated GET /status names timings among the honoured behaviours (AC4)',
);

// --- AC5: a record written without the field still parses ---------------------

unset( $done_state['timing_log'] );
file_put_contents( $timed_file, (string) wp_json_encode( $done_state ) );
$store = new Job_Store( new Config() );
$reloaded = $store->find( $timed_id );
kntnt_extractor_assert(
	$reloaded instanceof Extraction_Job && $reloaded->timing_log === [],
	'A record without timing_log still parses as an empty series (AC5)',
);

remove_filter( 'kntnt_extractor_config_phase_timing', $timing_on );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'pre_http_request', $intercept );
$rmrf( $work );
$rmrf( $work . '-downloads' );

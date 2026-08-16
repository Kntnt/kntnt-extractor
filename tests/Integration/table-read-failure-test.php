<?php
/**
 * Integration test: a table page that fails to read fails the job, instead of
 * being read as the end of the table and publishing a silently truncated dump.
 *
 * `Table_Dumper::fetch_rows()` used to hand a failed `$wpdb->get_results()` call
 * back to its caller as an empty page, indistinguishable from a table that simply
 * ran out of rows. `dump_chunk()` then reported the table complete, the build
 * moved on, and a `ready` job published an artifact whose dump for that table
 * stopped at an arbitrary row and reloaded into MySQL without a single error.
 * This file plants a failure on the second page of a multi-slice table — a
 * genuinely mid-table failure, not an empty one — through WordPress's own
 * `query` filter, so the read fails through the real code path rather than a
 * precondition written straight into a state file.
 *
 * It pins:
 *  - AC1: the job reaches `failed`, never `ready`.
 *  - AC2: no artifact is published for the failed job.
 *  - AC3 (control): the same fixture, dumped with the filter removed, still
 *    completes and carries every row — proof the failure above was caused by
 *    the planted fault and not by a broken fixture.
 *  - AC4 (regression guard): before this fix, the run in AC1 produced a `ready`
 *    job rather than a `failed` one.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
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

// Reads a job's persisted state file into a decoded array, or null when absent.
$read_state = static function ( string $work, string $id ): ?array {
	$path = $work . '/' . $id . '/state.json';
	$decoded = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $decoded ) ? $decoded : null;
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

// Reads a ready job's published artifact off disk through its own download_url.
$artifact_bytes = static function ( string $work, array $poll ): string {
	$uploads = wp_upload_dir();
	$baseurl = rtrim( $uploads['baseurl'], '/' );
	$url = is_string( $poll['download_url'] ?? null ) ? $poll['download_url'] : '';
	$path = $url === '' ? '' : rtrim( $uploads['basedir'], '/' ) . substr( $url, strlen( $baseurl ) );
	return $path !== '' && is_file( $path ) ? (string) file_get_contents( $path ) : '';
};

// Concatenates, in index order, every segment sealed under one name.
$reassemble = static function ( array $container, ?array $names, string $name, string $keypair ) use ( $open_segment ): string {
	$joined = '';
	foreach ( $container['records'] as $i => $record ) {
		if ( is_array( $names ) && ( $names[ $i ] ?? null ) === $name ) {
			$plain = $open_segment( $record, $keypair );
			$joined .= is_string( $plain ) ? $plain : '';
		}
	}
	return $joined;
};

// Drives a job through ticks until it reaches a terminal state (ready or failed),
// bounded so a driver that never terminates fails the test rather than hangs.
$drive_to_terminal = static function ( string $id, string $secret ) use ( $tick, $get_extraction ): array {
	$ticks = 0;
	$poll = [];
	while ( $ticks < 200 ) {
		$poll = $get_extraction( $id )->get_data();
		$state = is_array( $poll ) ? ( $poll['state'] ?? null ) : null;
		if ( $state === 'ready' || $state === 'failed' ) {
			break;
		}
		$tick( $id, $secret );
		$ticks++;
	}
	return is_array( $poll ) ? $poll : [];
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree, so this file's downloads
// directory holds nothing but what this file itself produces.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-readfail-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the planted-failure job and the control job
// this file needs can coexist.
$force_max = static fn(): int => 5;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Force a row budget small enough that the fixture below needs two slices, so a
// failure planted on the second page is a genuinely mid-table failure rather
// than a failure on an empty table.
$slice_rows = 100;
$force_slice = static fn(): int => $slice_rows;
add_filter( 'kntnt_extractor_config_table_chunk_rows', $force_slice );

// Short-circuit every loopback the driver fires, so a nudge never touches the
// real network; the ticks below drive each job synchronously across chunks.
$intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// A fixture table with more rows than one forced slice carries, so dumping it
// takes at least two pages.
$fixture_table = 'kntnt_extractor_readfail_single';
$fixture_rows = 150;
$wpdb->query( "DROP TABLE IF EXISTS `{$fixture_table}`" );
$wpdb->query( "CREATE TABLE `{$fixture_table}` ( `id` bigint(20) NOT NULL, `tag` varchar(40) NOT NULL, PRIMARY KEY (`id`) )" );
$values = [];
for ( $i = 1; $i <= $fixture_rows; $i++ ) {
	$values[] = "({$i}, 'READFAILMARK-{$i}')";
}
$wpdb->query( "INSERT INTO `{$fixture_table}` (`id`, `tag`) VALUES " . implode( ',', $values ) );

// A caller's ephemeral X25519 keypair; only the public half is submitted.
$keypair = sodium_crypto_box_keypair();
$public_key = sodium_crypto_box_publickey( $keypair );

// Builds a fresh `query`-filter callback that lets every query through
// untouched except the fixture table's own page reads, of which the first
// succeeds and the second — and only the second — is rewritten into a
// statement guaranteed to fail: a reference to a table that does not exist.
// The counter lives in this closure alone, so each run gets its own count.
$fixture_select_prefix = "SELECT * FROM `{$fixture_table}`";
$make_page_two_failure = static function () use ( $fixture_select_prefix ): callable {
	$seen = 0;
	return static function ( string $query ) use ( &$seen, $fixture_select_prefix ): string {
		if ( ! str_starts_with( $query, $fixture_select_prefix ) ) {
			return $query;
		}
		$seen++;
		return $seen === 2 ? 'SELECT * FROM `kntnt_extractor_readfail_no_such_table`' : $query;
	};
};

// --- AC1/AC2: a read failure on the table's second page fails the job ---

wp_set_current_user( $owner->ID );
$plant = $make_page_two_failure();
$previous_suppress = $wpdb->suppress_errors( true );
add_filter( 'query', $plant );

$response = $post_extractions( [ 'tables' => [ $fixture_table ], 'public_key' => base64_encode( $public_key ) ] );
$created = $response->get_data();
$id = is_array( $created ) && is_string( $created['id'] ?? null ) ? $created['id'] : '';
$secret = (string) ( ( $read_state( $work, $id ) ?? [] )['tick_secret'] ?? '' );

$failed_poll = $drive_to_terminal( $id, $secret );

remove_filter( 'query', $plant );
$wpdb->suppress_errors( $previous_suppress );

kntnt_extractor_assert(
	( $failed_poll['state'] ?? null ) === 'failed',
	'A table page that fails to read mid-dump fails the job, instead of being read as the end of the table (AC1)'
);

// The served downloads directory is hardened with an index.html at job-create
// time, before it is known whether the job will ever finish — that housekeeping
// file is not an artifact, so it is excluded the same way the state directory's
// own hardening files are excluded elsewhere in this suite.
$downloads_dir = $work . '-downloads';
$downloads_empty = ! is_dir( $downloads_dir ) || count( array_diff( scandir( $downloads_dir ) ?: [], [ '.', '..', 'index.html' ] ) ) === 0;
kntnt_extractor_assert(
	$downloads_empty && ( $failed_poll['download_url'] ?? null ) === null,
	'A job failed on a mid-table read publishes no artifact — no download path ever exists for it (AC2)'
);

// --- AC3: the control — the same fixture, filter removed, dumps completely ---

wp_set_current_user( $owner->ID );
$control_response = $post_extractions( [ 'tables' => [ $fixture_table ], 'public_key' => base64_encode( $public_key ) ] );
$control_created = $control_response->get_data();
$control_id = is_array( $control_created ) && is_string( $control_created['id'] ?? null ) ? $control_created['id'] : '';
$control_secret = (string) ( ( $read_state( $work, $control_id ) ?? [] )['tick_secret'] ?? '' );

$control_poll = $drive_to_terminal( $control_id, $control_secret );
kntnt_extractor_assert(
	( $control_poll['state'] ?? null ) === 'ready',
	'The same fixture, dumped with no failure planted, still reaches ready — the control proving AC1 was caused by the planted failure, not a broken fixture (AC3)'
);

$control_raw = $artifact_bytes( $work, $control_poll );
$control_container = $parse( $control_raw );
$control_names = $open_index( $control_container['sealed_index'], $keypair );
$control_sql = $reassemble( $control_container, $control_names, $fixture_table, $keypair );
preg_match_all( '/READFAILMARK-\d+/', $control_sql, $control_found );
kntnt_extractor_assert(
	count( $control_found[0] ) === $fixture_rows && count( array_unique( $control_found[0] ) ) === $fixture_rows,
	'The control run carries every row of the fixture table exactly once (AC3)'
);

// --- AC4: the regression this test guards against ---

// Before this fix, `fetch_rows()` returned the failed read's empty page as if
// the table had simply run out of rows, `dump_chunk()` reported the table
// complete, and the run above produced a `ready` job whose dump silently
// stopped after the first page — the opposite of the outcome asserted in AC1.
kntnt_extractor_assert(
	( $failed_poll['state'] ?? null ) !== 'ready',
	'REGRESSION GUARD: before this fix, a table page that failed to read was silently treated as the end of the table and this exact run produced a ready job with a truncated dump (AC4)'
);

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_table_chunk_rows', $force_slice );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
$wpdb->query( "DROP TABLE IF EXISTS `{$fixture_table}`" );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

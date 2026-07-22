<?php
/**
 * Integration test: structure-only (schema, no rows) table extraction (issue #16).
 *
 * Drives a single job that carries one full-data table, one structure-only table,
 * and one file to a sealed artifact, then unseals it with the caller's own private
 * key and pins the behaviour issue #16 introduces:
 *  - AC1: the full table's segment carries `INSERT INTO`, while the structure-only
 *    table's segment carries `DROP TABLE`/`CREATE TABLE` but NO `INSERT` — even
 *    though that table has rows, so the emptiness is a deliberate strip, not an
 *    accident of an empty fixture.
 *  - AC5: the structure-only table's name is recorded in the sealed index like any
 *    other segment, and it counts toward the poll `progress` table totals.
 *
 * @package Kntnt\Extractor
 * @since   0.1.2
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Crypto\Sealed_Writer;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

// The build machinery must exist for there to be anything to exercise; without it
// record the gap and stop this file cleanly.
if ( ! class_exists( Dispatcher::class ) || ! method_exists( \Kntnt\Extractor\Table_Dumper::class, 'dump_structure' ) ) {
	kntnt_extractor_assert( false, 'Table_Dumper::dump_structure() and the tick machinery are available (issue #16)' );
	return;
}
kntnt_extractor_assert( true, 'Table_Dumper::dump_structure() and the tick machinery are available (issue #16)' );

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

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Isolate the working directory under uploads so the artifact stays web-reachable
// and the run cleans up all of its own state afterwards.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-structonly-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Short-circuit every loopback so a nudge never touches the real network.
$intercept = static fn( $pre, $args, $url ) => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

// Seed a distinctive marker into the options table so the full-data segment proves
// real rows survive the seal, and confirm the structure-only table genuinely holds
// rows so its empty data block is a deliberate strip, not an empty-fixture accident.
$marker = 'STRUCTMARK-' . bin2hex( random_bytes( 16 ) );
update_option( 'kntnt_extractor_structonly_marker', $marker, false );
$structure_row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->users}`" ); // phpcs:ignore WordPress.DB
kntnt_extractor_assert( $structure_row_count > 0, 'The structure-only table has rows, so an empty data block proves a real strip (AC1 precondition)' );

// The caller generates its own ephemeral X25519 keypair and submits only the public
// half — the private key never leaves this test.
$keypair = sodium_crypto_box_keypair();
$public_key = sodium_crypto_box_publickey( $keypair );

// One full-data table (options, carrying the marker), one structure-only table
// (users, with rows that must NOT be dumped), and one file.
$selection = [
	'tables' => [ $wpdb->options ],
	'tables_structure_only' => [ $wpdb->users ],
	'files' => [ 'wp-load.php' ],
	'public_key' => base64_encode( $public_key ),
];

// --- Create and drive the job to ready ---

wp_set_current_user( $owner->ID );
$response = $post_extractions( $selection );
kntnt_extractor_assert( $response->get_status() === 201, 'A mixed full + structure-only + file selection creates a job (201) (AC4)' );
$id = is_array( $response->get_data() ) && is_string( $response->get_data()['id'] ?? null ) ? $response->get_data()['id'] : '';
kntnt_extractor_assert( $id !== '', 'The created structure-only job has an id' );

$state = json_decode( (string) file_get_contents( $work . '/' . $id . '/job.json' ), true );
$tick_secret = is_array( $state ) ? (string) ( $state['tick_secret'] ?? '' ) : '';

// The persisted state carries the structure-only selection as its own field, kept
// apart from the full-data tables.
kntnt_extractor_assert( is_array( $state ) && ( $state['structure_only'] ?? null ) === [ $wpdb->users ], 'The persisted job records the structure-only selection distinctly (AC5)' );

// Drive across ticks until ready, exactly as the loopback loop would.
wp_set_current_user( 0 );
$tick( $id, $tick_secret );
$driven = 0;
while ( $driven < 200 && ( $get_extraction( $id )->get_data()['state'] ?? null ) !== 'ready' ) {
	$tick( $id, $tick_secret );
	$driven++;
}

wp_set_current_user( $owner->ID );
$ready_poll = $get_extraction( $id )->get_data();
kntnt_extractor_assert( is_array( $ready_poll ) && ( $ready_poll['state'] ?? null ) === 'ready', 'The mixed job reaches ready' );

// --- AC5: structure-only tables count toward the poll progress table totals ---

$progress = is_array( $ready_poll ) && is_array( $ready_poll['progress'] ?? null ) ? $ready_poll['progress'] : [];
kntnt_extractor_assert( ( $progress['tables_total'] ?? null ) === 2, 'The poll progress counts the structure-only table in tables_total (AC5)' );
kntnt_extractor_assert( ( $progress['tables_done'] ?? null ) === 2, 'A ready job reports every table — full and structure-only — done (AC5)' );
kntnt_extractor_assert( ( $progress['files_total'] ?? null ) === 1 && ( $progress['files_done'] ?? null ) === 1, 'The single file still counts toward the file totals (AC5)' );

// --- Unseal the artifact and inspect its segments ---

$download_url = is_string( $ready_poll['download_url'] ?? null ) ? $ready_poll['download_url'] : '';
$uploads = wp_upload_dir();
$artifact_path = rtrim( $uploads['basedir'], '/' ) . substr( $download_url, strlen( rtrim( $uploads['baseurl'], '/' ) ) );
$raw = is_file( $artifact_path ) ? (string) file_get_contents( $artifact_path ) : '';
$container = $parse( $raw );

// Three segments: one full table, one structure-only table, one file.
kntnt_extractor_assert( $container['header_ok'] && count( $container['records'] ) === 3, 'The artifact holds one segment per selected resource (full + structure-only + file)' );

// The sealed index records every name in tables -> structure_only -> files order.
$names = $open_index( $container['sealed_index'], $keypair );
kntnt_extractor_assert( $names === [ $wpdb->options, $wpdb->users, 'wp-load.php' ], 'The sealed index records the structure-only table like any other segment, in full-then-structure-then-file order (AC5)' );

// --- AC1: the full segment carries data, the structure-only segment carries none ---

$full_sql = $open_segment( $container['records'][0], $keypair );
$structure_sql = $open_segment( $container['records'][1], $keypair );

kntnt_extractor_assert( is_string( $full_sql ) && str_contains( $full_sql, 'INSERT INTO `' . $wpdb->options . '`' ) && str_contains( $full_sql, $marker ), 'The full table segment carries INSERT INTO and round-trips its rows (AC1)' );
kntnt_extractor_assert( is_string( $structure_sql ) && str_contains( $structure_sql, 'DROP TABLE IF EXISTS `' . $wpdb->users . '`' ) && str_contains( $structure_sql, 'CREATE TABLE `' . $wpdb->users . '`' ), 'The structure-only segment carries DROP TABLE and CREATE TABLE (AC1)' );
kntnt_extractor_assert( is_string( $structure_sql ) && ! str_contains( $structure_sql, 'INSERT INTO' ), 'The structure-only segment carries NO INSERT, even though the table has rows (AC1)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
delete_option( 'kntnt_extractor_structonly_marker' );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

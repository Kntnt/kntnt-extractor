<?php
/**
 * Integration test: tick-driven extraction execution and the Download link.
 *
 * This harness exercises the core tracer bullet (ADR-0004/0007/0009) end to end
 * against the live REST server: a queued job is driven to a sealed artifact the
 * caller downloads and unseals with its own private key.
 *
 * It pins every acceptance criterion of issue #7:
 *  - AC1: an internal tick endpoint authenticated by a per-job secret advances
 *    the job, and an outsider without the secret — even a capable owner — cannot
 *    drive it, while an anonymous caller holding the secret can.
 *  - AC2: ticking a queued small-table selection dumps each table (a two-table
 *    selection, so "each" means more than the first) as a sealed segment and
 *    drives it queued -> running -> ready (the running state is observed mid-tick
 *    through the job's lifecycle action).
 *  - AC3: each table segment is mysqldump-compatible SQL (DROP TABLE / CREATE
 *    TABLE / INSERT INTO), asserted for both selected tables.
 *  - AC4: a ready poll returns a download_url a web server serves directly — no
 *    deny-all rule governs the artifact's path — at an unguessable per-artifact
 *    path that discloses no job id, and beside which no job.json sits, so the
 *    state file is neither served nor derivable from a leaked link.
 *  - AC5: a self-generated X25519 keypair round-trips — the submitted public key
 *    seals the artifact, and the matching private key recovers the table data.
 *  - AC6: the persisted job state holds no key able to open the artifact.
 *  - AC7: a status poll opportunistically nudges a job that is not currently
 *    being ticked, and never one that is.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Config;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Extraction_Job;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Crypto\Sealed_Writer;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

// The execution machinery lives behind the Dispatcher and its collaborators, and
// the tick endpoint behind them; without those there is nothing to exercise, so
// record the gap and stop this file cleanly (a red before green).
if ( ! class_exists( Dispatcher::class ) || ! class_exists( \Kntnt\Extractor\Table_Dumper::class ) || ! class_exists( \Kntnt\Extractor\Artifact_Builder::class ) ) {
	kntnt_extractor_assert( false, 'The tick-driven execution machinery (Dispatcher, Table_Dumper, Artifact_Builder) is available' );
	return;
}
kntnt_extractor_assert( true, 'The tick-driven execution machinery (Dispatcher, Table_Dumper, Artifact_Builder) is available' );

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

// Dispatches POST /extractions/{id}/tick, optionally carrying the per-job secret.
$tick = static function ( string $id, ?string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	if ( $secret !== null ) {
		$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	}
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

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// The owning administrator holds both capabilities.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so the
// artifact stays web-reachable (its download_url resolves) while the run owns all
// of its state and cleans it up afterwards.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-tick-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the several jobs this file needs at once can
// all be created (a ready job still occupies its slot until consumed).
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Short-circuit every loopback the code fires so a nudge never touches the real
// network, and capture each one for the AC7 assertions below.
$captured = [];
$intercept = static function ( $pre, $args, $url ) use ( &$captured ) {
	$captured[] = [ 'url' => (string) $url, 'headers' => is_array( $args['headers'] ?? null ) ? $args['headers'] : [] ];
	return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $intercept, 10, 3 );

// Seed a distinctive marker into the options table so the round-trip (AC5) proves
// real table data survives the seal, not merely that some ciphertext decodes.
$marker = 'RTMARK-' . bin2hex( random_bytes( 16 ) );
update_option( 'kntnt_extractor_roundtrip_marker', $marker, false );

// The caller generates its own ephemeral X25519 keypair and submits only the
// public half — the private key never leaves this test, exactly as a real client.
$keypair = sodium_crypto_box_keypair();
$public_key = sodium_crypto_box_publickey( $keypair );
$secret_key = sodium_crypto_box_secretkey( $keypair );

// The selection: two tables — the options table (whose marker row must round-trip)
// and the users table — so "dump each table as a sealed segment" is exercised with a
// plurality, not just a single table; plus the bootstrap file (proving a file segment
// round-trips too).
$selection = [
	'tables' => [ $wpdb->options, $wpdb->users ],
	'files' => [ 'wp-load.php' ],
	'public_key' => base64_encode( $public_key ),
];

// --- Create the job (owner), queued, with a per-job tick secret on disk ---

wp_set_current_user( $owner->ID );
$captured = [];
$response = $post_extractions( $selection );
kntnt_extractor_assert( $response->get_status() === 201, 'POST /extractions creates the job (201)' );
$created = $response->get_data();
$id = is_array( $created ) && is_string( $created['id'] ?? null ) ? $created['id'] : '';
kntnt_extractor_assert( $id !== '' && preg_match( '/^[a-f0-9]{32}$/', $id ) === 1, 'The created job has an unguessable id' );

// The per-job secret and the artifact filename live in the job's on-disk state;
// the server's own loopback reads them from there, and so does this test.
$state_path = $work . '/' . $id . '/job.json';
$state = is_file( $state_path ) ? json_decode( (string) file_get_contents( $state_path ), true ) : null;
$tick_secret = is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '';
kntnt_extractor_assert( $tick_secret !== '', 'The job persists a non-empty per-job tick secret' );

// The artifact's own unguessable filename, which the public download link is keyed
// on — never the job id — so a leaked link cannot locate the state directory.
$artifact_name = is_array( $state ) && is_string( $state['artifact'] ?? null ) ? $state['artifact'] : '';
kntnt_extractor_assert( $artifact_name !== '' && $artifact_name !== $id, 'The job persists an artifact token distinct from its id' );

// A queued poll reports no download_url yet: the artifact does not exist until the
// job is ready.
$queued_poll = $get_extraction( $id )->get_data();
kntnt_extractor_assert( is_array( $queued_poll ) && ( $queued_poll['state'] ?? null ) === 'queued', 'The fresh job polls as queued' );
kntnt_extractor_assert( is_array( $queued_poll ) && array_key_exists( 'download_url', $queued_poll ) && $queued_poll['download_url'] === null, 'A queued poll carries a null download_url' );

// --- AC1: the tick endpoint is driven only by the per-job secret ---

// No secret, a wrong secret, and a capable owner without the secret are all refused
// with 403; the capability the owner holds does not substitute for the secret.
kntnt_extractor_assert( $tick( $id, null )->get_status() === 403, 'A tick with no secret is refused 403 (AC1)' );
kntnt_extractor_assert( $tick( $id, 'wrong-secret' )->get_status() === 403, 'A tick with a wrong secret is refused 403 (AC1)' );
wp_set_current_user( $owner->ID );
kntnt_extractor_assert( $tick( $id, 'still-wrong' )->get_status() === 403, 'A capable owner without the secret cannot drive the job (AC1)' );

// The job must still be queued: no rejected tick advanced it.
$after_rejects = $get_extraction( $id )->get_data();
kntnt_extractor_assert( is_array( $after_rejects ) && ( $after_rejects['state'] ?? null ) === 'queued', 'A rejected tick never advances the job' );

// --- AC2: an authenticated tick drives queued -> running -> ready ---

// Capture the persisted state mid-tick, when the running phase is announced, to
// prove the job genuinely passes through running rather than jumping to ready.
$mid_tick_state = null;
$observe_running = static function ( $job ) use ( &$mid_tick_state, $state_path ): void {
	$mid = is_file( $state_path ) ? json_decode( (string) file_get_contents( $state_path ), true ) : null;
	$mid_tick_state = is_array( $mid ) ? ( $mid['state'] ?? null ) : null;
};
add_action( 'kntnt_extractor_job_running', $observe_running );

// An anonymous caller holding the secret drives the job — proving the secret, not
// a WordPress capability, authorizes the internal tick. The build is chunked (one
// bounded segment per tick, ADR-0007), so drive it across ticks until it is ready,
// exactly as the loopback loop would; the first tick makes the queued -> running
// transition the observer below captures.
wp_set_current_user( 0 );
$tick_response = $tick( $id, $tick_secret );
$driven = 0;
while ( $driven < 200 && ( $get_extraction( $id )->get_data()['state'] ?? null ) !== 'ready' ) {
	$tick( $id, $tick_secret );
	$driven++;
}
remove_action( 'kntnt_extractor_job_running', $observe_running );
kntnt_extractor_assert( $tick_response->get_status() === 200, 'An anonymous caller holding the secret drives the tick (200) (AC1)' );
kntnt_extractor_assert( $mid_tick_state === 'running', 'The job is persisted as running mid-tick (queued -> running -> ready) (AC2)' );

// The owner reads the outcome: the tick was driven anonymously by the secret, but
// polling still needs the capability gate the owner holds.
wp_set_current_user( $owner->ID );
$ready_poll = $get_extraction( $id )->get_data();
kntnt_extractor_assert( is_array( $ready_poll ) && ( $ready_poll['state'] ?? null ) === 'ready', 'After the tick the job is ready (AC2)' );

// --- AC4: a ready poll returns a download_url a web server serves directly ---

$download_url = is_array( $ready_poll ) && is_string( $ready_poll['download_url'] ?? null ) ? $ready_poll['download_url'] : '';
$uploads = wp_upload_dir();
$baseurl = rtrim( $uploads['baseurl'], '/' );
$basedir = rtrim( $uploads['basedir'], '/' );
kntnt_extractor_assert( $download_url !== '' && str_starts_with( $download_url, $baseurl ), 'A ready poll returns a download_url under the uploads base URL (AC4)' );

// The link is unguessable through the artifact's own random token, and it discloses
// the job id NOWHERE: the state directory (job.json — the tick secret and the
// plaintext selection) is named by that id, so a leaked link must not reveal it, or
// job.json would be a derivable sibling fetch (ADR-0008/0009).
kntnt_extractor_assert( str_contains( $download_url, $artifact_name ), 'The download_url is keyed on the unguessable artifact token (AC4)' );
kntnt_extractor_assert( ! str_contains( $download_url, $id ), 'The download_url discloses no job id, so job.json is not derivable from a leaked link (AC4)' );

// The download_url maps back to the on-disk artifact the web server serves directly.
$artifact_path = $basedir . substr( $download_url, strlen( $baseurl ) );
kntnt_extractor_assert( is_file( $artifact_path ), 'The download_url resolves to an existing static artifact file (AC4)' );
$raw = is_file( $artifact_path ) ? (string) file_get_contents( $artifact_path ) : '';
kntnt_extractor_assert( str_starts_with( $raw, Sealed_Writer::MAGIC ), 'The served artifact is a sealed container (AC4)' );

// "Serves directly" is the actual criterion, not merely "exists on disk". Playground
// runs no real web server, so stand in for one: walk the artifact's directory ancestry
// up to the uploads root and prove no deny-all .htaccess/web.config governs it — the
// exact rule that would make Apache or IIS answer a direct GET with 403. Without this
// the earlier build shipped the artifact inside the state tree's recursive deny, and a
// disk-read assertion sailed straight past it.
$governing_deny = static function ( string $path, string $stop_at ): bool {
	$stop_at = rtrim( $stop_at, '/' );
	$dir = dirname( $path );
	while ( str_starts_with( $dir . '/', $stop_at . '/' ) ) {
		$htaccess = is_file( $dir . '/.htaccess' ) ? (string) file_get_contents( $dir . '/.htaccess' ) : '';
		$webconfig = is_file( $dir . '/web.config' ) ? (string) file_get_contents( $dir . '/web.config' ) : '';
		if ( str_contains( $htaccess, 'Require all denied' ) || str_contains( $htaccess, 'Deny from all' ) || str_contains( $webconfig, 'deny users' ) ) {
			return true;
		}
		if ( $dir === $stop_at ) {
			break;
		}
		$dir = dirname( $dir );
	}
	return false;
};
kntnt_extractor_assert( ! $governing_deny( $artifact_path, $basedir ), 'No deny-all rule governs the artifact path, so a web server serves it directly (AC4)' );
kntnt_extractor_assert( $governing_deny( $state_path, $basedir ), 'The job state directory IS deny-governed (AC4 positive control: the servability check detects a deny)' );

// The served directory holds sealed artifacts only: job.json never sits beside the
// public link, so no sibling fetch derived from the download_url reaches the tick
// secret or the plaintext selection, on any web server (ADR-0008/0009).
kntnt_extractor_assert( ! is_file( dirname( $artifact_path ) . '/job.json' ), 'The served artifact directory contains no job.json (AC4)' );

// --- AC2/AC5: the container holds one sealed segment per selected resource ---

// Two tables plus one file: three segments, one per resource, proving "each table"
// with a plurality rather than a single-table shortcut that could only ever emit the
// first table.
$container = $parse( $raw );
kntnt_extractor_assert( $container['header_ok'] && count( $container['records'] ) === 3, 'The artifact holds one sealed segment per selected table and file — each of the two tables, not just the first (AC2)' );

$names = $open_index( $container['sealed_index'], $keypair );
kntnt_extractor_assert( $names === [ $wpdb->options, $wpdb->users, 'wp-load.php' ], 'The sealed index recovers every selected name in table-then-file order, with the private key (AC2/AC5)' );

// --- AC5: the caller's private key round-trips the actual data ---

$options_sql = $open_segment( $container['records'][0], $keypair );
$users_sql = $open_segment( $container['records'][1], $keypair );
$file_bytes = $open_segment( $container['records'][2], $keypair );
kntnt_extractor_assert( is_string( $options_sql ) && str_contains( $options_sql, $marker ), 'The unsealed options dump round-trips the seeded marker row (AC5)' );
kntnt_extractor_assert( is_string( $file_bytes ) && $file_bytes === (string) file_get_contents( ABSPATH . 'wp-load.php' ), 'The unsealed file segment round-trips the file byte-for-byte (AC5)' );

// --- AC3: EACH table dump is mysqldump-compatible SQL ---

// Assert the shape against both tables, not just the marker-bearing one, so a dumper
// that only ever emitted the first table would be caught here.
$is_mysqldump = static fn( string|false $sql, string $table ): bool => is_string( $sql )
	&& str_contains( $sql, 'DROP TABLE IF EXISTS `' . $table . '`' )
	&& str_contains( $sql, 'CREATE TABLE `' . $table . '`' )
	&& str_contains( $sql, 'INSERT INTO `' . $table . '`' );
kntnt_extractor_assert( $is_mysqldump( $options_sql, $wpdb->options ) && $is_mysqldump( $users_sql, $wpdb->users ), 'Each table dumps as mysqldump-compatible SQL: DROP TABLE, CREATE TABLE, INSERT INTO (AC3)' );

// --- AC6: the server's job state holds no key able to open the artifact ---

// Recover every segment's symmetric key with the private key, then confirm none of
// them — nor the private key itself — appears anywhere in the persisted job state.
$symmetric_keys = [];
foreach ( $container['records'] as $record ) {
	$k = sodium_crypto_box_seal_open( $record['sealed_key'], $keypair );
	if ( $k !== false ) {
		$symmetric_keys[] = $k;
	}
}
$state_raw = (string) file_get_contents( $state_path );
$leaks = false;
foreach ( array_merge( [ $secret_key ], $symmetric_keys ) as $secret ) {
	if ( str_contains( $state_raw, $secret ) || str_contains( $state_raw, base64_encode( $secret ) ) ) {
		$leaks = true;
	}
}
kntnt_extractor_assert( count( $symmetric_keys ) === 3 && ! $leaks, 'The persisted job state holds no key able to open the artifact (AC6)' );
kntnt_extractor_assert( str_contains( $state_raw, base64_encode( $public_key ) ), 'The key-leak scan is genuine: the harmless public key IS present in the job state (AC6 positive control)' );

// --- AC7: a status poll opportunistically nudges an untended job, never a tended one ---

// A fresh queued job that nothing is ticking: polling it fires exactly one loopback
// nudge to its own tick endpoint, carrying its secret.
wp_set_current_user( $owner->ID );
$n_response = $post_extractions( $selection );
$n_id = is_array( $n_response->get_data() ) ? (string) ( $n_response->get_data()['id'] ?? '' ) : '';
$n_state = json_decode( (string) file_get_contents( $work . '/' . $n_id . '/job.json' ), true );
$n_secret = is_array( $n_state ) ? (string) ( $n_state['tick_secret'] ?? '' ) : '';

$captured = [];
$get_extraction( $n_id );
$nudged = false;
foreach ( $captured as $call ) {
	if ( str_contains( $call['url'], '/extractions/' . $n_id . '/tick' ) && ( $call['headers'][ Dispatcher::TICK_SECRET_HEADER ] ?? '' ) === $n_secret ) {
		$nudged = true;
	}
}
kntnt_extractor_assert( $nudged, 'A poll of a queued, untended job nudges its tick endpoint with the secret (AC7)' );

// A job currently being ticked (running with a fresh heartbeat) must not be nudged:
// a live driver already owns it.
$store = new Job_Store( new Config() );
$n_job = $store->find( $n_id );
$store->save( $n_job->with_state( Job_State::Running ) );
$captured = [];
$get_extraction( $n_id );
$nudged_running = false;
foreach ( $captured as $call ) {
	if ( str_contains( $call['url'], '/extractions/' . $n_id . '/tick' ) ) {
		$nudged_running = true;
	}
}
kntnt_extractor_assert( ! $nudged_running, 'A poll of a job currently being ticked fires no nudge (AC7)' );

// A stalled job (running, but with a stale heartbeat) is untended again, so a poll
// re-nudges it — the fallback that restarts a queue whose driver died.
$stalled = new Extraction_Job( $n_job->id, Job_State::Running, $n_job->owner, $n_job->public_key, $n_job->tables, $n_job->structure_only, $n_job->files, $n_job->created_at, time() - 86400, $n_job->tick_secret, $n_job->artifact );
$store->save( $stalled );
$captured = [];
$get_extraction( $n_id );
$nudged_stalled = false;
foreach ( $captured as $call ) {
	if ( str_contains( $call['url'], '/extractions/' . $n_id . '/tick' ) ) {
		$nudged_stalled = true;
	}
}
kntnt_extractor_assert( $nudged_stalled, 'A poll of a stalled job re-nudges its tick endpoint (AC7)' );

// A finished (ready) job needs no advancing, so polling it fires no nudge.
$captured = [];
$get_extraction( $id );
$nudged_ready = false;
foreach ( $captured as $call ) {
	if ( str_contains( $call['url'], '/extractions/' . $id . '/tick' ) ) {
		$nudged_ready = true;
	}
}
kntnt_extractor_assert( ! $nudged_ready, 'A poll of a ready job fires no nudge (AC7)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
delete_option( 'kntnt_extractor_roundtrip_marker' );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

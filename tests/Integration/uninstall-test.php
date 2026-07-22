<?php
/**
 * Integration test: uninstall cleanup — no sensitive residue is left behind
 * (issue #13, ADR-0006/0008).
 *
 * Uninstalling the plugin must leave nothing sensitive on disk: the audit log
 * file and its containing directory go, every residual per-job working directory
 * goes, and nothing — no artifact, no job.json, no audit line — remains under the
 * resolved working, served-downloads, or audit directories. The cleanup lives in
 * a testable routine ({@see \Kntnt\Extractor\Uninstaller::purge_all()}) that the
 * guarded uninstall.php merely bootstraps, so this drives that routine directly.
 *
 * It pins every acceptance criterion of issue #13:
 *  - AC1: uninstall deletes the audit log file and its containing directory, and
 *    forgets the recorded path option.
 *  - AC2: uninstall removes any residual per-job working directory — a driven,
 *    ready job's and a never-driven queued job's alike — and the working base
 *    directory itself.
 *  - AC3: after uninstall no artifact, job state, or audit residue remains under
 *    the working, served-downloads, or audit directories, for both the default
 *    under-uploads location and an overridden outside-uploads work_dir.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Uninstaller;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

// The uninstall cleanup routine is what this issue adds; without it there is
// nothing to exercise, so record the gap and stop this file cleanly (a red
// before green).
if ( ! class_exists( Uninstaller::class ) || ! method_exists( Job_Store::class, 'purge_all' ) ) {
	kntnt_extractor_assert( false, 'The uninstall cleanup machinery is available' );
	return;
}
kntnt_extractor_assert( true, 'The uninstall cleanup machinery is available' );

// Recursively removes a directory tree so the suite leaves no working directory
// behind on the host even if an assertion below aborts before its own cleanup.
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

// Dispatches POST /extractions/{id}/tick carrying the per-job secret.
$tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// The id the last POST /extractions handed back.
$id_of = static function ( WP_REST_Response $response ): string {
	$data = $response->get_data();
	return is_array( $data ) && is_string( $data['id'] ?? null ) ? $data['id'] : '';
};

// Reads a field out of a job's on-disk state file, or '' when it is unreadable.
$state_field = static function ( string $work, string $id, string $field ): string {
	$path = $work . '/' . $id . '/job.json';
	$state = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
	return is_array( $state ) && is_string( $state[ $field ] ?? null ) ? $state[ $field ] : '';
};

// Isolate the working directory through a mutable knob the same filter reads, so
// a later block can relocate it (default under uploads, then outside uploads)
// while every collaborator — including the Uninstaller under test — resolves the
// current location. Raise concurrency so several jobs coexist, and short-circuit
// every loopback so a create's nudge never touches the real network. These
// filters are removed at the end of the file.
$current_work = wp_upload_dir()['basedir'] . '/kntnt-extractor-uninstall-' . bin2hex( random_bytes( 4 ) );
$force_work = static function () use ( &$current_work ): string {
	return $current_work;
};
$force_max = static fn(): int => 20;
$block_http = static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'kntnt_extractor_config_work_dir', $force_work );
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
add_filter( 'pre_http_request', $block_http );

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// Start from a clean audit slate: an earlier file may have driven a job to ready
// and left a recorded log, so clear it before this file's assertions.
$existing = get_option( 'kntnt_extractor_audit_log' );
if ( is_string( $existing ) && is_file( $existing ) ) {
	unlink( $existing );
}
delete_option( 'kntnt_extractor_audit_log' );

// The owning administrator holds both capabilities, and submits a real ephemeral
// X25519 public key so an artifact actually seals and jobs reach ready.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $owner->ID );
$public_key = base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) );
$selection = [ 'tables' => [ $wpdb->options ], 'files' => [ 'wp-load.php' ], 'public_key' => $public_key ];

// Drives a freshly-created job to ready with its own persisted tick secret. The
// build is chunked (one bounded segment per tick, ADR-0007), so tick across
// chunks until it reaches ready, exactly as the loopback loop would.
$drive_to_ready = static function ( string $id ) use ( &$current_work, $tick, $state_field ): void {
	$secret = $state_field( $current_work, $id, 'tick_secret' );
	$driven = 0;
	while ( $driven < 200 && $state_field( $current_work, $id, 'state' ) !== 'ready' ) {
		$tick( $id, $secret );
		$driven++;
	}
};

// --- Block A: the default under-uploads location (AC1, AC2, AC3) ---

$work = $current_work;
$downloads = $work . '-downloads';

// A driven, ready job leaves its per-job state directory and a sealed artifact,
// and its ready transition writes the audit log — the residue uninstall must
// clear.
$ready_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $ready_id );
$ready_dir = $work . '/' . $ready_id;
$ready_artifact = $downloads . '/' . $state_field( $work, $ready_id, 'artifact' );

// A never-driven queued job leaves only its per-job state directory — a residual
// working directory uninstall must remove just the same.
$queued_id = $id_of( $post_extractions( $selection ) );
$queued_dir = $work . '/' . $queued_id;

// The audit log the ready transition wrote, and its containing directory.
$log_path = get_option( 'kntnt_extractor_audit_log' );
$audit_dir = is_string( $log_path ) ? dirname( $log_path ) : '';

// Preconditions: every piece of residue is present before uninstall runs, so the
// after-cleanup assertions are discriminating rather than vacuous.
kntnt_extractor_assert( is_string( $log_path ) && is_file( $log_path ) && is_dir( $audit_dir ), 'A ready job writes an audit log and its directory (AC1 precondition)' );
kntnt_extractor_assert( is_dir( $ready_dir ) && is_file( $ready_artifact ), 'A ready job leaves a working directory and a sealed artifact (AC2/AC3 precondition)' );
kntnt_extractor_assert( is_dir( $queued_dir ), 'A queued job leaves a residual working directory (AC2 precondition)' );

// Run the cleanup through the real production entry point: uninstall_plugin()
// (from the plugin.php required above) defines WP_UNINSTALL_PLUGIN and includes
// the plugin's uninstall.php for real, so this block exercises the actual wiring —
// the autoloader path, the class reference, the guard, and the delegation — that a
// direct Uninstaller::purge_all() call would bypass. A wiring defect there would
// leave the residue below in place and fail the AC assertions, rather than passing
// vacuously. The active work_dir filter still resolves every collaborator, so the
// under-test cleanup targets this file's isolated directory. Block B below then
// covers the override case through the routine directly (include_once fires once).
uninstall_plugin( 'kntnt-extractor/kntnt-extractor.php' );

// AC1: the audit log file and its directory are gone and the path is forgotten.
kntnt_extractor_assert( ! is_file( (string) $log_path ), 'Uninstall deletes the audit log file (AC1)' );
kntnt_extractor_assert( $audit_dir !== '' && ! is_dir( $audit_dir ), 'Uninstall deletes the audit log directory (AC1)' );
kntnt_extractor_assert( get_option( 'kntnt_extractor_audit_log' ) === false, 'Uninstall forgets the recorded audit log path (AC1)' );

// AC2: every residual per-job working directory and the working base go.
kntnt_extractor_assert( ! is_dir( $ready_dir ), 'Uninstall removes the ready job\'s working directory (AC2)' );
kntnt_extractor_assert( ! is_dir( $queued_dir ), 'Uninstall removes a residual queued job\'s working directory (AC2)' );
kntnt_extractor_assert( ! is_dir( $work ), 'Uninstall removes the working base directory itself (AC2)' );

// AC3: no artifact, job state, or audit residue remains under any of the plugin's
// three directories.
kntnt_extractor_assert( ! is_file( $ready_artifact ), 'Uninstall deletes the sealed artifact (AC3)' );
kntnt_extractor_assert( ! is_dir( $downloads ), 'Uninstall removes the served downloads directory (AC3)' );
kntnt_extractor_assert( ! is_dir( $work ) && ! is_dir( $downloads ) && ! is_dir( $audit_dir ), 'No working, downloads, or audit residue remains under uploads after uninstall (AC3)' );

// Resilience: a second run against an already-clean install is a harmless no-op
// (a partially-present install must never fatal). Reaching the assertion proves
// the call did not throw.
Uninstaller::purge_all();
kntnt_extractor_assert( true, 'A second uninstall on an already-clean install is a harmless no-op (resilience)' );

// --- Block B: an overridden outside-uploads work_dir (AC2, AC3) ---

// Relocate the knob outside the uploads directory (wp-content itself, always
// writable in Playground yet not under uploads), so the override path is
// exercised. The same filter now resolves every collaborator here.
$uploads_base = wp_upload_dir()['basedir'];
$current_work = WP_CONTENT_DIR . '/kntnt-extractor-uninstall-out-' . bin2hex( random_bytes( 4 ) );
$out_work = $current_work;
$out_downloads = $out_work . '-downloads';
kntnt_extractor_assert( ! str_starts_with( $out_work, $uploads_base . '/' ), 'The override work_dir is outside the uploads directory (AC3 precondition)' );

// A driven and a queued job under the overridden location, so both an artifact in
// the outside downloads directory and residual per-job state directories exist.
$out_ready_id = $id_of( $post_extractions( $selection ) );
$drive_to_ready( $out_ready_id );
$out_ready_dir = $out_work . '/' . $out_ready_id;
$out_artifact = $out_downloads . '/' . $state_field( $out_work, $out_ready_id, 'artifact' );
$out_queued_id = $id_of( $post_extractions( $selection ) );
$out_queued_dir = $out_work . '/' . $out_queued_id;
kntnt_extractor_assert( is_dir( $out_ready_dir ) && is_file( $out_artifact ) && is_dir( $out_queued_dir ), 'The overridden location holds working directories and a sealed artifact before uninstall (precondition)' );

// Run the cleanup: it must honour the override and clean the outside location.
Uninstaller::purge_all();

kntnt_extractor_assert( ! is_dir( $out_ready_dir ) && ! is_dir( $out_queued_dir ), 'Uninstall removes per-job working directories at the overridden location (AC2)' );
kntnt_extractor_assert( ! is_file( $out_artifact ), 'Uninstall deletes the sealed artifact at the overridden location (AC3)' );
kntnt_extractor_assert( ! is_dir( $out_work ) && ! is_dir( $out_downloads ), 'Uninstall removes the overridden working and downloads directories whole (AC3)' );

// Remove every filter this file installed and clear its audit residue, so its
// position in the suite cannot pollute a later file.
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'pre_http_request', $block_http );
$rmrf( $work );
$rmrf( $downloads );
$rmrf( $out_work );
$rmrf( $out_downloads );
$leftover = get_option( 'kntnt_extractor_audit_log' );
if ( is_string( $leftover ) && is_file( $leftover ) ) {
	unlink( $leftover );
}
delete_option( 'kntnt_extractor_audit_log' );
wp_set_current_user( 0 );

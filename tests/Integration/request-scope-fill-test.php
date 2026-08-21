<?php
/**
 * Integration test: the per-request state one test file leaves behind (issue #40).
 *
 * The suite runs every `*-test.php` in one PHP process, so anything the plugin builds
 * once at load outlives the file that first used it — and any per-request memo held on
 * such an object becomes a suite-long memo that a later, unrelated file can observe.
 * That is not a hypothetical: it is what made plan 007 drop its step (c).
 *
 * This file is the establishing half of a pair. It drives a job one chunk forward
 * through the plugin's OWN wiring — the watchdog cron binding the Plugin registers,
 * which reaches the Dispatcher, the Artifact_Builder and the Table_Dumper the plugin
 * provides — so the catalog listing that path memoises has certainly been read by the
 * time `request-scope-probe-test.php` runs. The probe then proves it cannot see it.
 *
 * The two files only mean anything together, so this one leaves a marker in `$GLOBALS`
 * and the probe refuses to draw any conclusion without it.
 *
 * The selection is two tables and the suite pins every tick to one chunk, so the tick
 * stops with work remaining: the job never reaches ready, records no audit entry, and
 * publishes no artifact for a later file to trip over.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Config;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Watchdog;

global $wpdb;

// Give this file a working directory of its own, still under uploads, so the patrol
// below walks this file's single job and never one another file left behind.
$scope_fill_work = wp_upload_dir()['basedir'] . '/kntnt-extractor-scope-fill-' . bin2hex( random_bytes( 4 ) );
$scope_fill_dir = static fn(): string => $scope_fill_work;
add_filter( 'kntnt_extractor_config_work_dir', $scope_fill_dir );

// Answer every loopback the tick fires so a nudge never reaches the network.
$scope_fill_loopback = static fn(): array => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $scope_fill_loopback );

// Queue a two-table job. The first table is empty, so the one chunk this tick packages
// is cheap, and the second is what keeps the build unfinished.
$scope_fill_store = new Job_Store( new Config() );
$scope_fill_job = $scope_fill_store->create(
	0,
	base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) ),
	[ $wpdb->termmeta, $wpdb->options ],
	[],
	[],
);

// Drive it through the scheduled hook itself, so the dumper that reads the catalog is
// the plugin's own rather than one this file built.
do_action( Watchdog::WATCHDOG_HOOK );
$scope_fill_advanced = $scope_fill_store->find( $scope_fill_job->id );
$scope_fill_drove = $scope_fill_advanced !== null && $scope_fill_advanced->state !== Job_State::Queued;
kntnt_extractor_assert( $scope_fill_drove, 'The plugin\'s own driver dumped a table in this file, so whatever per-request state that path memoises is now filled (#40)' );

// Leave the marker the probe refuses to conclude anything without.
$GLOBALS['kntnt_extractor_request_scope_filled'] = $scope_fill_drove;

// Take the job, the working directory and both filters back out, so nothing this file
// established survives except the state the probe is about to look for.
$scope_fill_store->purge( $scope_fill_advanced ?? $scope_fill_job );
$scope_fill_rmrf = static function ( string $dir ) use ( &$scope_fill_rmrf ): void {
	foreach ( is_dir( $dir ) ? array_diff( scandir( $dir ) ?: [], [ '.', '..' ] ) : [] as $entry ) {
		$path = $dir . '/' . $entry;
		is_dir( $path ) ? $scope_fill_rmrf( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
};
$scope_fill_rmrf( $scope_fill_work );
remove_filter( 'pre_http_request', $scope_fill_loopback );
remove_filter( 'kntnt_extractor_config_work_dir', $scope_fill_dir );

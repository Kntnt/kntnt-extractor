<?php
/**
 * Integration test: a test file cannot see another file's request state (issue #40).
 *
 * The probing half of the pair `request-scope-fill-test.php` opens. That file drove a
 * job one chunk forward through the plugin's own watchdog binding, which is what makes
 * the plugin's Table_Dumper read `SHOW TABLES` and memoise the listing for the rest of
 * its life. This file drives an equivalent job the same way and counts the listings the
 * database is asked for.
 *
 * One is the answer of a dumper that had never read the catalog; zero is the answer of
 * one that read it in another file and kept it. So the count is the property stated as
 * a number: what the plugin builds for a request stops existing when that request does,
 * and no test file inherits it from the file before.
 *
 * The table dumped here — `termmeta` — is one WordPress creates at install, so it was in
 * the listing the fill file's dumper would have memoised. A table this file created for
 * itself would prove nothing: a memo that has never heard of it re-reads on the miss and
 * issues the very listing this counts, whether or not anything leaked.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Config;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Watchdog;

global $wpdb;

// The pair is only evidence together: without the establishing file there is no state
// to be blind to, and a green count here would mean nothing at all.
kntnt_extractor_assert( ( $GLOBALS['kntnt_extractor_request_scope_filled'] ?? false ) === true, 'The establishing file drove the plugin\'s own driver before this one ran (#40)' );

// Give this file a working directory of its own, still under uploads, so the patrol
// below walks this file's single job and never one another file left behind.
$scope_probe_work = wp_upload_dir()['basedir'] . '/kntnt-extractor-scope-probe-' . bin2hex( random_bytes( 4 ) );
$scope_probe_dir = static fn(): string => $scope_probe_work;
add_filter( 'kntnt_extractor_config_work_dir', $scope_probe_dir );

// Answer every loopback the tick fires so a nudge never reaches the network.
$scope_probe_loopback = static fn(): array => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $scope_probe_loopback );

// Count the catalog listings the tick asks for. Every statement $wpdb runs passes the
// `query` filter, which is what makes a memo that is not supposed to be there visible
// as an absence of work rather than as a wrong answer.
$scope_probe_listings = 0;
$scope_probe_counter = static function ( string $query ) use ( &$scope_probe_listings ): string {
	if ( str_starts_with( $query, 'SHOW TABLES' ) ) {
		++$scope_probe_listings;
	}
	return $query;
};

// Queue the same shape of job the establishing file queued: an empty first table to
// keep the chunk cheap, and a second one that keeps the build unfinished.
$scope_probe_store = new Job_Store( new Config() );
$scope_probe_job = $scope_probe_store->create(
	0,
	base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) ),
	[ $wpdb->termmeta, $wpdb->options ],
	[],
	[],
);

// Drive it through the scheduled hook itself, under the counter. The filter comes off
// even if the patrol throws, so no later file runs with a stray global filter.
add_filter( 'query', $scope_probe_counter );
try {
	do_action( Watchdog::WATCHDOG_HOOK );
} finally {
	remove_filter( 'query', $scope_probe_counter );
}

// The tick has to have happened for the count to mean anything: a job left queued would
// report zero listings for the entirely uninteresting reason that nothing dumped.
$scope_probe_advanced = $scope_probe_store->find( $scope_probe_job->id );
kntnt_extractor_assert( $scope_probe_advanced !== null && $scope_probe_advanced->state !== Job_State::Queued, 'The plugin\'s own driver dumped a table in this file too, so the count below is a count of its work (#40)' );

// The property itself: this file's dump read the catalog for itself, which it could not
// have needed to do had it inherited the listing the previous file's dump memoised.
kntnt_extractor_assert( $scope_probe_listings >= 1, "A dump driven through the plugin's own wiring reads the catalog afresh in this file: {$scope_probe_listings} SHOW TABLES, where a Table_Dumper shared with an earlier file would have asked for none (#40)" );

// Take the job, the working directory and both filters back out again.
$scope_probe_store->purge( $scope_probe_advanced ?? $scope_probe_job );
$scope_probe_rmrf = static function ( string $dir ) use ( &$scope_probe_rmrf ): void {
	foreach ( is_dir( $dir ) ? array_diff( scandir( $dir ) ?: [], [ '.', '..' ] ) : [] as $entry ) {
		$path = $dir . '/' . $entry;
		is_dir( $path ) ? $scope_probe_rmrf( $path ) : @unlink( $path );
	}
	@rmdir( $dir );
};
$scope_probe_rmrf( $scope_probe_work );
remove_filter( 'pre_http_request', $scope_probe_loopback );
remove_filter( 'kntnt_extractor_config_work_dir', $scope_probe_dir );

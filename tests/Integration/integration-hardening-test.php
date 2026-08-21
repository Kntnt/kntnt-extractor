<?php
/**
 * Integration test: the cross-issue hardening the mandatory integration review of
 * the #9 + #10 + #13 union surfaced (ADR-0007).
 *
 * The per-issue contracts each hold on their own, but three interaction defects
 * live only where the chunked build (#9), the unattended drivers (#10), and the
 * TTL sweep (#8) meet. This file pins the fix for each:
 *
 *  - Finding 1 (progress-aware ceiling): a large job that is still ADVANCING one
 *    chunk per cron cycle must not be reclaimed by the absolute lifetime ceiling
 *    just because it has lived longer than the ceiling — otherwise a genuinely
 *    large extraction on a loopback-dead host (the watchdog's whole reason to
 *    exist) can never complete. The ceiling still reclaims a job that has stopped
 *    making progress, which is the poison case it was written for.
 *  - Finding 2a (sweep honours the tick lock): the TTL sweep must not purge a job
 *    a live tick is holding the per-job lock on, or it deletes the container out
 *    from under an in-flight build.
 *  - Finding 2b (tick no-ops on an unreadable job): a tick whose locked re-read
 *    finds the job gone or unreadable must no-op, not fall back to the caller's
 *    stale snapshot and rebuild a job that no longer exists.
 *  - Finding 3 (patrol fault isolation): one job whose tick throws must not abort
 *    the whole watchdog patrol and starve every other stalled queue.
 *
 * It also pins the condition ADR-0024 puts on retiring a tolerance branch: a
 * record this release no longer understands is skipped, and the jobs around it
 * still enumerate. A removal that let one stale file break the listing or the
 * sweep for live jobs would be a defect rather than an application of that
 * decision, so the skip is asserted here beside the other store-walking cases.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Artifact_Builder;
use Kntnt\Extractor\Config;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Sweeper;
use Kntnt\Extractor\Table_Dumper;
use Kntnt\Extractor\Watchdog;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

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

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( 'kntnt_extractor_operate' ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $owner->ID );

// Redirect the working directory to an isolated tree still under uploads, and raise
// the concurrency ceiling so the several jobs this file needs at once can coexist.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-hardening-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Swallow every loopback so a nudge never touches the real network; this file cares
// about the driver's decisions, not the outgoing request, so a plain accept is enough.
$intercept = static fn() => [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
add_filter( 'pre_http_request', $intercept, 10, 3 );

$store = new Job_Store( new Config() );
$dispatcher = new Dispatcher( $store, new Config(), new Artifact_Builder( new Table_Dumper(), new Config() ) );
$watchdog = new Watchdog( $store, $dispatcher );
$sweeper = new Sweeper( $store, new Config() );

// A multi-segment selection, so a build genuinely spans several chunks.
$selection = [
	'tables' => [ $wpdb->options, $wpdb->users ],
	'structure_only' => [],
	'files' => [ 'wp-load.php' ],
	'public_key' => base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) ),
];

// Creates a queued job through the store and returns its id, so every case starts
// from a real on-disk job whose state file can then be rewritten to a fixture shape.
$create = static function () use ( $store, $owner, $selection ): string {
	return $store->create( $owner->ID, $selection['public_key'], $selection['tables'], $selection['structure_only'], $selection['files'] )->id;
};

// Reads and decodes a job's on-disk state file, so a test can rewrite individual
// fields — timestamps, the artifact token — into a fixture the constructor's own
// stamping would never produce.
$read_json = static function ( string $id ) use ( $work ): array {
	$data = json_decode( (string) file_get_contents( $work . '/' . $id . '/state.json' ), true );
	return is_array( $data ) ? $data : [];
};

// Writes a decoded record back over a job's state file verbatim, the escape hatch
// that lets these tests age a job or plant a hostile field directly.
$write_json = static function ( string $id, array $data ) use ( $work ): void {
	file_put_contents( $work . '/' . $id . '/state.json', (string) wp_json_encode( $data ) );
};

$hour = 3600;
$ceiling = 6 * $hour; // The default absolute lifetime: six one-hour TTLs.

// --- Finding 1: the ceiling reclaims a stalled job but spares a progressing one ---

// A job whose creation is far past the absolute ceiling but whose LAST PROGRESS is
// recent — exactly a large extraction advancing one chunk per cron cycle on a
// loopback-dead host. Its heartbeat is fresh (so only the ceiling could reclaim it),
// and the sweep must leave it to keep building.
$progressing = $create();
$data = $read_json( $progressing );
$data['state'] = Job_State::Running->value;
$data['created_at'] = time() - 30 * $hour;
$data['updated_at'] = time();
$data['progressed_at'] = time();
$write_json( $progressing, $data );

// A job equally old whose progress has been frozen for longer than the ceiling — the
// poison case the ceiling exists to bound (a chunk that dies uncatchably every attempt
// keeps a fresh heartbeat forever but never advances). The sweep must still reclaim it.
$stalled = $create();
$data = $read_json( $stalled );
$data['state'] = Job_State::Running->value;
$data['created_at'] = time() - 30 * $hour;
$data['updated_at'] = time();
$data['progressed_at'] = time() - 30 * $hour;
$write_json( $stalled, $data );

$swept = array_map( static fn( $j ): string => $j->id, $sweeper->sweep() );
kntnt_extractor_assert( ! in_array( $progressing, $swept, true ) && is_dir( $work . '/' . $progressing ), 'The sweep spares an old but still-progressing job so a large extraction can complete (finding 1)' );
kntnt_extractor_assert( in_array( $stalled, $swept, true ) && ! is_dir( $work . '/' . $stalled ), 'The sweep still reclaims an old job whose progress has frozen past the ceiling (finding 1)' );

// --- Finding 2a: the sweep leaves a job a live tick holds the lock on ---

// A job past the ceiling that the sweep would otherwise reclaim. Simulate a live tick
// by making the per-job lock unacquirable: with the lock file path occupied by a
// directory, the sweep's own lock attempt cannot take it — the exact "someone else is
// ticking this" signal — so it must defer the purge rather than delete the container
// out from under the build.
$locked = $create();
$data = $read_json( $locked );
$data['state'] = Job_State::Running->value;
$data['created_at'] = time() - 30 * $hour;
$data['updated_at'] = time();
$data['progressed_at'] = time() - 30 * $hour;
$write_json( $locked, $data );
$lock_path = $store->container_lock_path( $store->find( $locked ) );
mkdir( $lock_path );

$swept = array_map( static fn( $j ): string => $j->id, $sweeper->sweep() );
kntnt_extractor_assert( ! in_array( $locked, $swept, true ) && is_dir( $work . '/' . $locked ), 'The sweep defers a job whose per-job tick lock it cannot take, sparing an in-flight build (finding 2a)' );

// With the lock free again, the very next sweep reclaims it — the deferral is only for
// as long as a tick holds the lock, never permanent.
if ( is_dir( $lock_path ) ) {
	rmdir( $lock_path );
}
$swept = array_map( static fn( $j ): string => $j->id, $sweeper->sweep() );
kntnt_extractor_assert( in_array( $locked, $swept, true ) && ! is_dir( $work . '/' . $locked ), 'Once the tick lock is free the next sweep reclaims the job, so the deferral is not permanent (finding 2a)' );

// --- Finding 2b: a tick whose re-read finds the job unreadable no-ops ---

// Snapshot a real queued job, then corrupt its state file so the tick's locked re-read
// can no longer reconstruct it — the shape a job purged or half-written under the tick
// leaves behind. The tick must treat that as "job gone, nothing to do" and neither
// throw nor rebuild the record from the stale snapshot it was handed.
$gone = $create();
$snapshot = $store->find( $gone );
file_put_contents( $work . '/' . $gone . '/state.json', 'not a valid job record' );

$threw = false;
$result = null;
try {
	$result = $dispatcher->tick( $snapshot );
} catch ( \Throwable $e ) {
	$threw = true;
}
kntnt_extractor_assert( ! $threw, 'A tick whose re-read finds the job unreadable does not throw (finding 2b)' );
kntnt_extractor_assert( $result !== null && $result->state === Job_State::Queued, 'Such a tick returns the job un-advanced rather than driving the stale snapshot forward (finding 2b)' );
kntnt_extractor_assert( $store->find( $gone ) === null, 'Such a tick leaves the unreadable record untouched rather than rebuilding it (finding 2b)' );

// --- Finding 3: one throwing tick does not abort the whole patrol ---

// A poison job whose stored artifact token carries a null byte: the driver derives the
// per-job lock path from that token, and opening a path containing a null byte raises a
// ValueError from deep inside the tick — a genuine, uncatchable-by-tick throw standing
// in for the disk-full / unwritable-directory failure a real host hits. Alongside it, a
// healthy stalled queue that must still be restarted despite the poison job.
$poison = $create();
$data = $read_json( $poison );
$data['artifact'] = "poison\0.sealed";
$write_json( $poison, $data );

$healthy = $create();

$threw = false;
try {
	$watchdog->patrol();
} catch ( \Throwable $e ) {
	$threw = true;
}
kntnt_extractor_assert( ! $threw, 'A single job whose tick throws does not abort the watchdog patrol (finding 3)' );
kntnt_extractor_assert( ( $store->find( $healthy )->state ?? Job_State::Queued ) !== Job_State::Queued, 'The patrol still restarts a healthy stalled queue despite a poison job in the set (finding 3)' );

// --- An unsupported record is skipped, and the jobs around it still enumerate ---

// A record an earlier release wrote is no longer a shape this release parses
// (ADR-0024). What must hold is how it fails to: quietly, so one stale file on disk
// cannot break the listing or the sweep for the live jobs beside it. Drive a real
// job one chunk first, then strip it back to what 0.5.1 wrote — no attempt counter,
// no recorded reason, none of the schema-8 budget keys, and a progress record
// carrying nothing schema 6 or 7 added — so the fixture is a shape a release
// actually produced rather than a hand-built one.
$legacy = $create();
$dispatcher->tick( $store->find( $legacy ) );
$data = $read_json( $legacy );
unset( $data['attempts'], $data['error'], $data['chunk_size'], $data['table_chunk_bytes'], $data['table_chunk_rows'] );
if ( is_array( $data['progress'] ?? null ) ) {
	unset( $data['progress']['structure_done'], $data['progress']['index_bytes'], $data['progress']['segment_count'], $data['progress']['table_offset'], $data['progress']['table_cursor'] );
}
$write_json( $legacy, $data );

// A live job beside it, so "the enumeration completes" is an observation rather
// than a vacuous one.
$beside = $create();

$readable = [];
$threw = false;
try {
	$readable = array_map( static fn( $job ): string => $job->id, $store->all() );
} catch ( \Throwable $e ) {
	$threw = true;
}
kntnt_extractor_assert( ! $threw, 'Enumerating a store holding a record this release cannot parse does not throw (ADR-0024)' );
kntnt_extractor_assert( ! in_array( $legacy, $readable, true ), 'A record this release no longer understands is skipped rather than surfaced (ADR-0024)' );
kntnt_extractor_assert( in_array( $beside, $readable, true ), 'The jobs around it still enumerate, which is what makes the skip safe (ADR-0024)' );
kntnt_extractor_assert( $store->find( $legacy ) === null, 'Reading it directly answers no such job rather than a half-populated one (ADR-0024)' );

// The sweep walks the same enumeration, so it must be equally unbothered by it.
$swept = false;
try {
	$sweeper->sweep();
	$swept = true;
} catch ( \Throwable $e ) {
	$swept = false;
}
kntnt_extractor_assert( $swept, 'A sweep over a store holding an unparseable record completes (ADR-0024)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

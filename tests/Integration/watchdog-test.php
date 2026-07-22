<?php
/**
 * Integration test: the unattended drivers — loopback self-dispatch, the WP-Cron
 * watchdog, and the poll-nudge (issue #10, ADR-0007).
 *
 * The job must drive itself so no client ever ticks it: creating a job and
 * finishing a chunk each fire a non-blocking loopback that advances the next
 * chunk; a WP-Cron watchdog restarts a queue whose loopback has died; and a status
 * poll nudges a queue nothing is currently dispatching. The client still sees only
 * create / poll / download, and the tick loop is never exposed as a public route.
 *
 * It pins every acceptance criterion of issue #10:
 *  - AC1: creating a job fires a loopback tick, and finishing a chunk fires the
 *    NEXT chunk's loopback — each carrying the job's own secret to its own tick
 *    endpoint.
 *  - AC2: the WP-Cron watchdog callback detects a stalled queue (a queued job, or a
 *    running one whose heartbeat has gone stale) and advances it, while leaving a
 *    freshly-ticked running job to its live driver.
 *  - AC3: a status poll nudges a queued or stalled job nothing is dispatching, and
 *    never a running job with a fresh heartbeat.
 *  - AC4: on a host where every loopback fails, the job still reaches ready PURELY
 *    through repeated watchdog callbacks — the watchdog is the backstop, not the
 *    loopback.
 *  - AC5: no new public route exposes the tick/loop, and the tick endpoint still
 *    requires the per-job secret.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Artifact_Builder;
use Kntnt\Extractor\Config;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Extraction_Job;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;
use Kntnt\Extractor\Rest\Status_Controller;
use Kntnt\Extractor\Sweeper;
use Kntnt\Extractor\Table_Dumper;
use Kntnt\Extractor\Watchdog;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// The watchdog is the one new collaborator this issue introduces; without it there
// is nothing to exercise, so record the gap and stop this file cleanly (a red
// before green).
if ( ! class_exists( Watchdog::class ) ) {
	kntnt_extractor_assert( false, 'The WP-Cron watchdog (Watchdog) is available' );
	return;
}
kntnt_extractor_assert( true, 'The WP-Cron watchdog (Watchdog) is available' );

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

// True when the captured loopback calls include a nudge to this job's own tick
// endpoint carrying its secret — the exact request the driver fires.
$nudged_tick = static function ( array $captured, string $id, string $secret ): bool {
	foreach ( $captured as $call ) {
		if ( str_contains( $call['url'], '/extractions/' . $id . '/tick' ) && ( $call['headers'][ Dispatcher::TICK_SECRET_HEADER ] ?? '' ) === $secret ) {
			return true;
		}
	}
	return false;
};

// True when at least one nudge to this job's tick endpoint was fired NON-BLOCKING —
// the AC1 property that keeps a real host's create/poll/tick from serialising on its
// own HTTP round-trip. A regression to a blocking request would still be a nudge, so
// $nudged_tick alone cannot catch it; this pins 'blocking' => false explicitly.
$nudged_nonblocking = static function ( array $captured, string $id, string $secret ): bool {
	foreach ( $captured as $call ) {
		if ( str_contains( $call['url'], '/extractions/' . $id . '/tick' ) && ( $call['headers'][ Dispatcher::TICK_SECRET_HEADER ] ?? '' ) === $secret && ( $call['blocking'] ?? true ) === false ) {
			return true;
		}
	}
	return false;
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
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-watchdog-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Intercept every loopback so a nudge never touches the real network. The mode is
// switched to 'fail' for AC4, where a WP_Error short-circuits the request exactly as
// a host with a dead loopback would; otherwise each call is captured and accepted.
$captured = [];
$loopback_mode = 'ok';
$intercept = static function ( $pre, $args, $url ) use ( &$captured, &$loopback_mode ) {
	$captured[] = [
		'url' => (string) $url,
		'headers' => is_array( $args['headers'] ?? null ) ? $args['headers'] : [],
		'blocking' => $args['blocking'] ?? null,
		'timeout' => $args['timeout'] ?? null,
	];
	if ( $loopback_mode === 'fail' ) {
		return new WP_Error( 'kntnt_extractor_loopback_down', 'Loopback is unavailable on this host.' );
	}
	return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $intercept, 10, 3 );

// The driver stack the watchdog and the ad-hoc drive-through use directly.
$store = new Job_Store( new Config() );
$dispatcher = new Dispatcher( $store, new Config(), new Artifact_Builder( new Table_Dumper(), new Config() ) );
$watchdog = new Watchdog( $store, $dispatcher );

// Reads a job's persisted per-job secret straight from its on-disk state.
$secret_of = static function ( string $id ) use ( $work ): string {
	$state = json_decode( (string) file_get_contents( $work . '/' . $id . '/job.json' ), true );
	return is_array( $state ) ? (string) ( $state['tick_secret'] ?? '' ) : '';
};

// Ages a job's heartbeat far into the past so the stalled-heartbeat predicate treats
// it as untended again, preserving its state and build progress.
$stall = static function ( Extraction_Job $job ) use ( $store ): void {
	$store->save( new Extraction_Job( $job->id, $job->state, $job->owner, $job->public_key, $job->tables, $job->structure_only, $job->files, $job->created_at, time() - 86400, $job->tick_secret, $job->artifact, $job->progress ) );
};

// A multi-segment selection: two tables plus a file, so the build genuinely spans
// several chunks and a "finish a chunk" continuation nudge has a next chunk to fire.
$selection = [
	'tables' => [ $wpdb->options, $wpdb->users ],
	'files' => [ 'wp-load.php' ],
	'public_key' => base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) ),
];

// --- AC1: creating a job fires the first loopback tick ---

$captured = [];
$created = $post_extractions( $selection )->get_data();
$id = is_array( $created ) ? (string) ( $created['id'] ?? '' ) : '';
kntnt_extractor_assert( $id !== '', 'POST /extractions creates a job' );
$secret = $secret_of( $id );
kntnt_extractor_assert( $nudged_tick( $captured, $id, $secret ), 'Creating a job fires a loopback tick carrying its secret (AC1)' );
kntnt_extractor_assert( $nudged_nonblocking( $captured, $id, $secret ), 'The creation loopback tick is fired non-blocking, so create never waits on its own HTTP round-trip (AC1)' );

// --- AC1: finishing a chunk fires the NEXT chunk's loopback tick ---

// One authenticated tick packages a single bounded chunk and, because work remains,
// fires the continuation loopback for the next chunk unattended.
$captured = [];
$tick( $id, $secret );
$after_chunk = $store->find( $id );
kntnt_extractor_assert( $after_chunk !== null && $after_chunk->state === Job_State::Running, 'A first tick leaves the multi-chunk job running with work remaining (AC1)' );
kntnt_extractor_assert( $nudged_tick( $captured, $id, $secret ), 'Finishing a chunk fires the next chunk\'s loopback tick carrying its secret (AC1)' );
kntnt_extractor_assert( $nudged_nonblocking( $captured, $id, $secret ), 'The continuation loopback tick is fired non-blocking, so each chunk never waits on its own HTTP round-trip (AC1)' );

// --- AC3: a status poll nudges an untended queue, never a tended one ---

$poll_created = $post_extractions( $selection )->get_data();
$poll_id = is_array( $poll_created ) ? (string) ( $poll_created['id'] ?? '' ) : '';
$poll_secret = $secret_of( $poll_id );

// A fresh queued job nothing is dispatching: polling it nudges its tick endpoint.
$captured = [];
$get_extraction( $poll_id );
kntnt_extractor_assert( $nudged_tick( $captured, $poll_id, $poll_secret ), 'A poll of a queued, untended job nudges its tick endpoint (AC3)' );

// A running job with a fresh heartbeat is being ticked right now: a poll leaves it
// to its live driver and fires no nudge.
$store->save( $store->find( $poll_id )->with_state( Job_State::Running ) );
$captured = [];
$get_extraction( $poll_id );
kntnt_extractor_assert( ! $nudged_tick( $captured, $poll_id, $poll_secret ), 'A poll of a freshly-ticked running job fires no nudge (AC3)' );

// The same job, once its heartbeat goes stale, is untended again and a poll re-nudges
// it — the fallback that restarts a queue whose driver died.
$stall( $store->find( $poll_id ) );
$captured = [];
$get_extraction( $poll_id );
kntnt_extractor_assert( $nudged_tick( $captured, $poll_id, $poll_secret ), 'A poll of a stalled running job re-nudges its tick endpoint (AC3)' );

// --- AC2: the watchdog detects and restarts a stalled queue ---

// The watchdog is a WP-Cron backstop, so the plugin's wiring must actually stand it
// up: the Installer schedules its event at activation, and the Plugin contributes its
// sub-hourly recurrence to the cron intervals. Pin both, so deleting the schedule
// block, mistyping the hook, or dropping the cron_schedules filter fails here rather
// than silently leaving production with no watchdog at all (which would void AC4 too).
kntnt_extractor_assert( wp_next_scheduled( Watchdog::WATCHDOG_HOOK ) !== false, 'Activation scheduled the watchdog cron event against WATCHDOG_HOOK (AC2)' );
$schedules = wp_get_schedules();
kntnt_extractor_assert( isset( $schedules[ Watchdog::WATCHDOG_SCHEDULE ] ), 'The watchdog\'s sub-hourly recurrence is registered as a cron schedule (AC2)' );
kntnt_extractor_assert( ( $schedules[ Watchdog::WATCHDOG_SCHEDULE ]['interval'] ?? null ) === 900, 'The watchdog schedule runs every 900 seconds (AC2)' );

// Drive a stalled queued job through the SCHEDULED HOOK itself — do_action fires the
// exact add_action( WATCHDOG_HOOK, ... ) binding the Plugin registers against the
// plugin-wired Watchdog, not a locally built instance — so this binds the production
// callback, not just patrol() in isolation.
$hooked_created = $post_extractions( $selection )->get_data();
$hooked_id = is_array( $hooked_created ) ? (string) ( $hooked_created['id'] ?? '' ) : '';
do_action( Watchdog::WATCHDOG_HOOK );
kntnt_extractor_assert( ( $store->find( $hooked_id )->state ?? Job_State::Queued ) !== Job_State::Queued, 'The scheduled watchdog hook advances a stalled queued job past queued (AC2)' );

// A queued job that nothing is driving is stalled by definition; the watchdog
// callback advances it past queued.
$queued_created = $post_extractions( $selection )->get_data();
$queued_id = is_array( $queued_created ) ? (string) ( $queued_created['id'] ?? '' ) : '';
$driven = $watchdog->patrol();
$driven_ids = array_map( static fn( Extraction_Job $j ): string => $j->id, $driven );
kntnt_extractor_assert( in_array( $queued_id, $driven_ids, true ), 'The watchdog drives a stalled queued job (AC2)' );
kntnt_extractor_assert( ( $store->find( $queued_id )->state ?? Job_State::Queued ) !== Job_State::Queued, 'The watchdog advances the stalled queued job past queued (AC2)' );

// A running job whose heartbeat has gone stale is a queue whose loopback died; the
// watchdog restarts it too.
$stale_created = $post_extractions( $selection )->get_data();
$stale_id = is_array( $stale_created ) ? (string) ( $stale_created['id'] ?? '' ) : '';
$store->save( $store->find( $stale_id )->with_state( Job_State::Running ) );
$stall( $store->find( $stale_id ) );
$before = $store->find( $stale_id )->updated_at;
$driven = $watchdog->patrol();
$driven_ids = array_map( static fn( Extraction_Job $j ): string => $j->id, $driven );
kntnt_extractor_assert( in_array( $stale_id, $driven_ids, true ), 'The watchdog drives a stalled running job (AC2)' );
kntnt_extractor_assert( ( $store->find( $stale_id )->updated_at ?? 0 ) > $before, 'The watchdog refreshes the stalled running job\'s heartbeat as it advances it (AC2)' );

// A running job with a fresh heartbeat is tended by a live driver; the watchdog must
// leave it untouched so it never competes with the live tick.
$fresh_created = $post_extractions( $selection )->get_data();
$fresh_id = is_array( $fresh_created ) ? (string) ( $fresh_created['id'] ?? '' ) : '';
$store->save( $store->find( $fresh_id )->with_state( Job_State::Running ) );
$driven = $watchdog->patrol();
$driven_ids = array_map( static fn( Extraction_Job $j ): string => $j->id, $driven );
kntnt_extractor_assert( ! in_array( $fresh_id, $driven_ids, true ), 'The watchdog leaves a freshly-ticked running job to its live driver (AC2)' );

// --- AC4: on a dead-loopback host, progress comes purely from the watchdog ---

$loopback_mode = 'fail';
$dead_created = $post_extractions( $selection )->get_data();
$dead_id = is_array( $dead_created ) ? (string) ( $dead_created['id'] ?? '' ) : '';

// With every loopback failing, polling the job — which only nudges, never advances —
// leaves it stuck at queued: the positive control proving the loopback is truly dead.
$get_extraction( $dead_id );
$get_extraction( $dead_id );
kntnt_extractor_assert( ( $store->find( $dead_id )->state ?? null ) === Job_State::Queued, 'With loopback dead, polling alone makes no progress (AC4 control)' );

// Repeated watchdog callbacks — each restarting the stalled queue and advancing one
// chunk in-process — carry the job all the way to ready without a single working
// loopback, exactly as a real host would across successive cron cycles.
$rounds = 0;
while ( $rounds < 500 && ( $store->find( $dead_id )->state ?? null ) !== Job_State::Ready ) {
	$job = $store->find( $dead_id );
	if ( $job === null || $job->state->is_terminal() ) {
		break;
	}
	$stall( $job );
	$watchdog->patrol();
	$rounds++;
}
kntnt_extractor_assert( ( $store->find( $dead_id )->state ?? null ) === Job_State::Ready, 'A dead-loopback job still reaches ready purely through the watchdog (AC4)' );

$loopback_mode = 'ok';

// --- AC5: no public route exposes the loop, and the tick still needs the secret ---

// An allowlist, not a substring scan: collect every route the plugin registers under
// its namespace and assert the set is EXACTLY the client surface plus the one secret-
// guarded tick route. A substring scan for 'watchdog'/'nudge'/'dispatch' would pass a
// loop route exposed under any other name ('advance', 'drive', 'cron', ...); this pins
// the whole surface, so any new route whatsoever — whatever its name — fails.
$namespace = Status_Controller::REST_NAMESPACE;
$expected_routes = [
	'/' . $namespace,
	'/' . $namespace . '/status',
	'/' . $namespace . '/tables',
	'/' . $namespace . '/environment',
	'/' . $namespace . '/files',
	'/' . $namespace . '/audit-log',
	'/' . $namespace . '/extractions',
	'/' . $namespace . '/extractions/(?P<id>[a-f0-9]{32})',
	'/' . $namespace . '/extractions/(?P<id>[a-f0-9]{32})/consume',
	'/' . $namespace . '/extractions/(?P<id>[a-f0-9]{32})/tick',
];
$actual_routes = array_values( array_filter(
	array_keys( rest_get_server()->get_routes() ),
	static fn( string $route ): bool => $route === '/' . $namespace || str_starts_with( $route, '/' . $namespace . '/' ),
) );
sort( $expected_routes );
sort( $actual_routes );
kntnt_extractor_assert( $actual_routes === $expected_routes, 'The plugin exposes exactly its client surface plus the secret-guarded tick route, and no route exposes the loop under any name (AC5)' );
kntnt_extractor_assert( $tick( $id, null )->get_status() === 403, 'The tick endpoint still requires the per-job secret (AC5)' );
kntnt_extractor_assert( $tick( $id, 'wrong-secret' )->get_status() === 403, 'A wrong secret cannot drive the tick loop (AC5)' );

// --- Backstop: the watchdog's endless restarts cannot outlive the absolute ceiling ---

// A job the watchdog keeps restarting carries a forever-fresh heartbeat — each patrol
// stamps a new one — so the TTL's heartbeat window alone would retain its partial dump
// and re-run its failing chunk without bound (a chunk that dies uncatchably every
// attempt: an OOM or max_execution_time kill the tick's catch can never intercept). The
// sweep's absolute lifetime ceiling, measured from creation and immune to heartbeat
// refreshes, is the bound. Age one job's creation far past the default ceiling (six
// one-hour TTLs) while leaving its heartbeat fresh — the exact state a watchdog restart
// leaves behind each cycle — and confirm the sweep still reclaims it and purges its
// working directory.
$ceiling_created = $post_extractions( $selection )->get_data();
$ceiling_id = is_array( $ceiling_created ) ? (string) ( $ceiling_created['id'] ?? '' ) : '';
$ceiling_job = $store->find( $ceiling_id );
$store->save( new Extraction_Job( $ceiling_job->id, $ceiling_job->state, $ceiling_job->owner, $ceiling_job->public_key, $ceiling_job->tables, $ceiling_job->structure_only, $ceiling_job->files, time() - 30 * 3600, time(), $ceiling_job->tick_secret, $ceiling_job->artifact, $ceiling_job->progress ) );
$ceiling_dir = $work . '/' . $ceiling_id;

// A control created just now: fresh creation and heartbeat, so neither the heartbeat
// window nor the absolute ceiling touches it — the sweep must leave it intact.
$young_created = $post_extractions( $selection )->get_data();
$young_id = is_array( $young_created ) ? (string) ( $young_created['id'] ?? '' ) : '';

$sweeper = new Sweeper( $store, new Config() );
$swept = array_map( static fn( Extraction_Job $j ): string => $j->id, $sweeper->sweep() );
kntnt_extractor_assert( in_array( $ceiling_id, $swept, true ) && ! is_dir( $ceiling_dir ), 'The sweep reclaims an unfinished job past the absolute lifetime ceiling despite its fresh heartbeat, purging its partial dump (backstop)' );
kntnt_extractor_assert( ! in_array( $young_id, $swept, true ) && is_dir( $work . '/' . $young_id ), 'The sweep leaves a young unfinished job with a fresh heartbeat untouched (backstop control)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

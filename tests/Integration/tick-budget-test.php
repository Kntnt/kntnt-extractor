<?php
/**
 * Integration test: time-budgeted ticks and the continuation nudge's placement
 * (issue #18, ADR-0010).
 *
 * On a host where the self-loopback continuation never completes, throughput
 * collapses to the cron watchdog's cadence: one chunk per cycle. The fix makes a
 * tick time-budgeted — one PHP invocation packages as many chunks as fit in a
 * wall-clock budget — and moves the continuation nudge out of the per-chunk path
 * so a tick fires exactly one successor, after its per-job lock is released, and
 * only when work remains.
 *
 * It pins issue #18's acceptance criteria:
 *  - AC1: with `tick_budget` 0 a tick packages exactly one chunk — today's
 *    behaviour, which the suite-wide bootstrap pin relies on.
 *  - AC2: with a generous budget one tick invocation carries a multi-chunk job
 *    all the way to `ready`.
 *  - AC3: a tick that leaves work fires exactly one continuation nudge; a tick
 *    that finishes the job fires none.
 *
 * The cURL delivery hardening in `nudge()` (CULOPT_CONNECTTIMEOUT_MS / NOSIGNAL,
 * timeout => 1) cannot be observed here: `pre_http_request` short-circuits the
 * transport before any cURL handle is built, so those options are covered by
 * code review only.
 *
 * @package Kntnt\Extractor
 * @since   0.2.1
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Dispatcher;

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// The execution machinery lives behind the Dispatcher; without it there is
// nothing to exercise, so record the gap and stop this file cleanly.
if ( ! class_exists( Dispatcher::class ) ) {
	kntnt_extractor_assert( false, 'The tick-driven execution machinery (Dispatcher) is available' );
	return;
}

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
$get_state = static function ( string $id ): ?string {
	$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) )->get_data();
	return is_array( $data ) ? ( $data['state'] ?? null ) : null;
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret.
$tick = static function ( string $id, string $secret ) : WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( 'kntnt_extractor_operate' ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $owner->ID );

// Redirect the working directory to an isolated tree still under uploads, and
// raise the concurrency ceiling so the jobs this file needs can coexist.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-budget-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Force a small chunk size so a modest selection genuinely spans several chunks —
// making the budget's multi-chunk packaging observable within one tick — while
// staying coarse enough that the whole job completes well inside the budget.
$force_chunk = static fn(): int => 1024;
add_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );

// Short-circuit every loopback so a nudge never touches the network, and capture
// each one for the AC3 placement assertions.
$captured = [];
$intercept = static function ( $pre, $args, $url ) use ( &$captured ) {
	$captured[] = [ 'url' => (string) $url, 'headers' => is_array( $args['headers'] ?? null ) ? $args['headers'] : [] ];
	return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $intercept, 10, 3 );

// Reads a job's persisted per-job secret straight from its on-disk state.
$secret_of = static function ( string $id ) use ( $work ): string {
	$state = json_decode( (string) file_get_contents( $work . '/' . $id . '/job.json' ), true );
	return is_array( $state ) ? (string) ( $state['tick_secret'] ?? '' ) : '';
};

// Counts captured nudges to a given job's own tick endpoint.
$nudges_to = static function ( array $captured, string $id ): int {
	$count = 0;
	foreach ( $captured as $call ) {
		if ( str_contains( $call['url'], '/extractions/' . $id . '/tick' ) ) {
			++$count;
		}
	}
	return $count;
};

// A multi-segment selection: two tables plus a file, which under the tiny chunk
// size above spans many bounded chunks.
$selection = [
	'tables' => [ $wpdb->options, $wpdb->users ],
	'files' => [ 'wp-load.php' ],
	'public_key' => base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) ),
];

// --- AC2: a generous budget carries a multi-chunk job to ready in one tick ---

// Override the suite-wide budget-0 pin locally with a higher-priority filter so
// one tick may keep packaging for a generous wall-clock budget.
$force_budget = static fn(): int => 30;
add_filter( 'kntnt_extractor_config_tick_budget', $force_budget, 20 );

$budget_created = $post_extractions( $selection )->get_data();
$budget_id = is_array( $budget_created ) ? (string) ( $budget_created['id'] ?? '' ) : '';
$budget_secret = $secret_of( $budget_id );
kntnt_extractor_assert( $budget_id !== '', 'POST /extractions creates a multi-chunk job for the budget test' );

// A single tick invocation must drive the whole multi-chunk job to ready under a
// generous budget — not merely one chunk.
$captured = [];
$budget_tick = $tick( $budget_id, $budget_secret );
kntnt_extractor_assert( $budget_tick->get_status() === 200, 'A budgeted tick returns 200' );
kntnt_extractor_assert( $get_state( $budget_id ) === 'ready', 'One tick invocation carries a multi-chunk job to ready under a generous budget (AC2)' );

// --- AC3: the tick that finishes the job fires no continuation nudge ---

kntnt_extractor_assert( $nudges_to( $captured, $budget_id ) === 0, 'A tick that finishes the job fires no continuation nudge (AC3)' );

remove_filter( 'kntnt_extractor_config_tick_budget', $force_budget, 20 );

// --- AC1 / AC3: with budget 0 a tick packages exactly one chunk and, while work
// remains, fires exactly one continuation nudge after its lock ---

$step_created = $post_extractions( $selection )->get_data();
$step_id = is_array( $step_created ) ? (string) ( $step_created['id'] ?? '' ) : '';
$step_secret = $secret_of( $step_id );

// The first budget-0 tick advances a single chunk, leaving the job running with
// work remaining, and fires exactly one continuation nudge (AC1 stepping, AC3).
$captured = [];
$tick( $step_id, $step_secret );
kntnt_extractor_assert( $get_state( $step_id ) === 'running', 'With budget 0 a single tick advances one chunk and leaves the job running (AC1)' );
kntnt_extractor_assert( $nudges_to( $captured, $step_id ) === 1, 'A budget-0 tick that leaves work fires exactly one continuation nudge (AC3)' );

// Step the job the rest of the way one chunk per tick; the tick that finally
// reaches ready fires no nudge, every earlier one exactly one.
$last_nudges = null;
$steps = 0;
while ( $steps < 500 && $get_state( $step_id ) !== 'ready' ) {
	$captured = [];
	$tick( $step_id, $step_secret );
	$last_nudges = $nudges_to( $captured, $step_id );
	++$steps;
}
kntnt_extractor_assert( $get_state( $step_id ) === 'ready', 'Budget-0 stepping still drives the job to ready across many ticks (AC1)' );
kntnt_extractor_assert( $last_nudges === 0, 'The budget-0 tick that finishes the job fires no continuation nudge (AC3)' );

// Leave the suite state clean for later files.
remove_filter( 'pre_http_request', $intercept, 10 );
remove_filter( 'kntnt_extractor_config_chunk_size', $force_chunk );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

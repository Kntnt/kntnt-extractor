<?php
/**
 * Integration test: poll and create never block on the continuation (issue #19).
 *
 * `GET /extractions/{id}` and `POST /extractions` must compute and return their
 * response before any continuation work runs, so a caller's latency is decoupled
 * from loopback health (ADR-0010). The continuation — reviving a queued or stalled
 * job's driver — is scheduled on the `shutdown` hook and only runs after the body
 * has been echoed. This file pins that behaviour end to end:
 *  - AC1: a poll performs no outbound HTTP before its response is computed, and the
 *    body still carries the unchanged `{ id, state, download_url }` shape.
 *  - AC2: a create returns its 201 before any continuation work; no HTTP inline.
 *  - AC3: at shutdown a queued job gets exactly one guarded nudge, aimed at its own
 *    tick URL; a ready job gets none.
 *
 * The suite runs under WordPress Playground, where neither
 * `fastcgi_finish_request()` nor `litespeed_finish_request()` exists, so
 * `Dispatcher::detach()` reports false and the FALLBACK branch — one guarded
 * `maybe_nudge()` at shutdown — is the path this test exercises. The detached
 * branch (`detach() === true` drives the job in-process through `tick()`) cannot
 * execute here; its work path is `tick()` itself, already covered by the driver
 * tests, so only its branch selection is review-covered.
 *
 * Outbound HTTP is intercepted and counted with `pre_http_request`; the
 * continuation is fired deterministically with `do_action( 'shutdown' )`. Because
 * WordPress cannot deregister an anonymous shutdown closure, `remove_all_actions(
 * 'shutdown' )` isolates each block so an earlier block's scheduled continuation
 * cannot inflate a later block's count.
 *
 * @package Kntnt\Extractor
 * @since   0.2.1
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Config;
use Kntnt\Extractor\Dispatcher;
use Kntnt\Extractor\Job_State;
use Kntnt\Extractor\Job_Store;

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

// Dispatches POST /extractions with a JSON body through the live REST server.
$post_extractions = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $request );
};

// Dispatches GET /extractions/{id} through the live REST server, returning the
// full response so the caller can read both the body and its shape.
$get_extraction = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) );
};

// Dispatches POST /extractions/{id}/tick carrying the per-job secret; each call
// advances the build exactly one bounded chunk.
$tick = static function ( string $id, string $secret ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions/' . $id . '/tick' );
	$request->set_header( Dispatcher::TICK_SECRET_HEADER, $secret );
	return rest_get_server()->dispatch( $request );
};

// Reads a job's persisted per-job tick secret from its on-disk state.
$secret_of = static function ( string $work, string $id ): string {
	$state = is_file( $work . '/' . $id . '/job.json' ) ? json_decode( (string) file_get_contents( $work . '/' . $id . '/job.json' ), true ) : null;
	return is_array( $state ) && is_string( $state['tick_secret'] ?? null ) ? $state['tick_secret'] : '';
};

// The owning administrator holds both capabilities.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// Redirect the working directory to an isolated tree still under uploads, so the
// run owns all of its state and cleans it up afterwards.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-nonblocking-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// Raise the concurrency ceiling so the several jobs this file needs can coexist.
$force_max = static fn(): int => 20;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// Count every outbound HTTP call and short-circuit it: nothing this test does may
// touch the real network, and the count is the evidence for "no work before the
// response" and "exactly one continuation at shutdown". The last-hit URL lets a
// block prove the nudge was aimed at the job's own tick endpoint.
$http_count = 0;
$last_url = '';
$counter = static function ( $pre, $args, $url ) use ( &$http_count, &$last_url ) {
	++$http_count;
	$last_url = (string) $url;
	return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 202, 'message' => 'Accepted' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $counter, 10, 3 );

// The caller submits only the public half of an ephemeral X25519 keypair.
$public_key = base64_encode( sodium_crypto_box_publickey( sodium_crypto_box_keypair() ) );

wp_set_current_user( $owner->ID );

// Snapshot the shutdown hook before this file empties it to isolate continuation
// blocks. remove_all_actions( 'shutdown' ) strips WordPress core's own shutdown
// handlers too, and WordPress cannot deregister an anonymous continuation closure
// individually, so the hook is cloned by value here and reinstated verbatim in the
// cleanup — core's handlers survive the run and no continuation this file scheduled
// outlives it.
global $wp_filter;
$shutdown_snapshot = isset( $wp_filter['shutdown'] ) ? clone $wp_filter['shutdown'] : null;

// --- AC1/AC3: a poll does no HTTP before its response, one guarded nudge at shutdown ---

// A queued job is the clean case: it always warrants a continuation, so the
// fallback nudge is never guarded out. Create it, then discard the create's own
// scheduled continuation so this block observes the poll's continuation alone.
remove_all_actions( 'shutdown' );
$qid = (string) ( $post_extractions( [ 'tables' => [ $wpdb->options ], 'public_key' => $public_key ] )->get_data()['id'] ?? '' );
remove_all_actions( 'shutdown' );

// The poll computes and returns its body with no outbound HTTP: the continuation
// is only scheduled, never run inline (AC1).
$http_count = 0;
$poll = $get_extraction( $qid );
kntnt_extractor_assert( $http_count === 0, 'A poll performs no outbound HTTP before its response is returned (AC1)' );

// The response shape is unchanged: id, state, and a (null-until-ready) download_url (AC4).
$body = $poll->get_data();
kntnt_extractor_assert(
	is_array( $body )
		&& ( $body['id'] ?? null ) === $qid
		&& ( $body['state'] ?? null ) === 'queued'
		&& array_key_exists( 'download_url', $body )
		&& $body['download_url'] === null,
	'The poll body carries the unchanged { id, state, download_url } shape (AC4)',
);

// The continuation runs only at shutdown, and it is exactly one guarded nudge
// aimed at the queued job's own tick URL — the fallback branch (AC3).
$last_url = '';
do_action( 'shutdown' );
kntnt_extractor_assert( $http_count === 1, 'The queued job gets exactly one continuation, and only at shutdown (AC3)' );
kntnt_extractor_assert( str_contains( $last_url, $qid ) && str_ends_with( $last_url, '/tick' ), 'The continuation is aimed at the job\'s own tick URL (AC3)' );

// --- AC2/AC3: a create returns its 201 before any continuation work ---

remove_all_actions( 'shutdown' );
$http_count = 0;
$created = $post_extractions( [ 'tables' => [ $wpdb->users ], 'public_key' => $public_key ] );
kntnt_extractor_assert( $created->get_status() === 201, 'The create returns 201' );
kntnt_extractor_assert( $http_count === 0, 'The create performs no outbound HTTP before its response is returned (AC2)' );

// Its continuation, too, appears only at shutdown: one guarded nudge for the fresh
// queued job.
do_action( 'shutdown' );
kntnt_extractor_assert( $http_count === 1, 'The create schedules exactly one continuation, and only at shutdown (AC2/AC3)' );

// --- AC3: a finished job gets no continuation at all ---

// Drive a single-table job to ready, then clear both the counter and any closures
// the driving scheduled so the finished-job poll is observed in isolation.
remove_all_actions( 'shutdown' );
$rid = (string) ( $post_extractions( [ 'tables' => [ $wpdb->options ], 'public_key' => $public_key ] )->get_data()['id'] ?? '' );
$rsecret = $secret_of( $work, $rid );
for ( $i = 0; $i < 8 && ( $get_extraction( $rid )->get_data()['state'] ?? null ) !== 'ready'; ++$i ) {
	$tick( $rid, $rsecret );
}
kntnt_extractor_assert( ( $get_extraction( $rid )->get_data()['state'] ?? null ) === 'ready', 'The single-table job reaches ready' );

// A poll of the ready job schedules nothing: it neither works inline nor registers
// a shutdown continuation, so the counter stays put across the poll and the shutdown.
remove_all_actions( 'shutdown' );
$http_count = 0;
$ready_poll = $get_extraction( $rid );
kntnt_extractor_assert( $http_count === 0, 'A poll of a ready job performs no outbound HTTP (AC1)' );
kntnt_extractor_assert( is_string( $ready_poll->get_data()['download_url'] ?? null ) && ( $ready_poll->get_data()['download_url'] ?? '' ) !== '', 'A ready poll still returns a download_url (no regression)' );
do_action( 'shutdown' );
kntnt_extractor_assert( $http_count === 0, 'A ready (finished) job gets no continuation at shutdown (AC3)' );

// --- AC3: a terminal (failed) job gets no continuation either ---

// A terminal job is finished, so — exactly like the ready one — its poll neither
// nudges inline nor schedules a shutdown continuation. Create a fresh job and drop it
// straight to a terminal state through the store, then pin both halves so the "ready
// OR terminal" wording is covered by a terminal case too, not only by ready.
$store = new Job_Store( new Config() );
$fid = (string) ( $post_extractions( [ 'tables' => [ $wpdb->options ], 'public_key' => $public_key ] )->get_data()['id'] ?? '' );
$store->save( $store->find( $fid )->with_state( Job_State::Failed ) );

remove_all_actions( 'shutdown' );
$http_count = 0;
$failed_poll = $get_extraction( $fid );
kntnt_extractor_assert( $http_count === 0, 'A poll of a failed (terminal) job performs no outbound HTTP before its response (AC1)' );
kntnt_extractor_assert( ( $failed_poll->get_data()['state'] ?? null ) === 'failed', 'The terminal job polls as failed' );
do_action( 'shutdown' );
kntnt_extractor_assert( $http_count === 0, 'A failed (terminal) job gets no continuation at shutdown (AC3)' );

// Leave the suite state clean for later files: reinstate the shutdown hook exactly as
// it was rather than leaving it emptied, so WordPress core's own shutdown handlers
// survive for later files and no continuation this file scheduled outlives it.
remove_all_actions( 'shutdown' );
if ( $shutdown_snapshot !== null ) {
	$wp_filter['shutdown'] = $shutdown_snapshot;
} else {
	unset( $wp_filter['shutdown'] );
}
remove_filter( 'pre_http_request', $counter, 10 );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
$rmrf( $work );
$rmrf( $work . '-downloads' );
wp_set_current_user( 0 );

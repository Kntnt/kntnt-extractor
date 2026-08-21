<?php
/**
 * Integration test: POST /extractions creates a queued job and GET
 * /extractions/{id} reports its state.
 *
 * This harness exercises the create-and-poll surface of the Extraction job
 * (ADR-0004) end to end against the live REST server. It pins the whole
 * validation ladder in the order the contract fixes it — a malformed body is a
 * 422, an absent or malformed public key a 400, an unknown table or an
 * out-of-root file a 404 that fires BEFORE the capability gate (ADR-0003), and a
 * fully valid request from a caller lacking any identity a 401. A created
 * job is bound to its creator: a capable non-owner polling it is refused 403
 * (AC4). It proves the persisted job-state shape lands as a JSON file in a
 * randomly-named directory both under the uploads directory by default (AC5) and
 * at the location the `KNTNT_EXTRACTOR_WORK_DIR` filter redirects it to (AC6),
 * hardened with index.html and an .htaccess/web.config deny, and that the
 * one-non-terminal-job concurrency rule answers a second create with 429 unless
 * the limit is raised (AC7). A freshly created job polls as `queued` (AC8).
 *
 * It also pins that the ceiling is enforced by taking the slot and then
 * re-checking it, not by checking and then taking (issue #36): a create whose slot
 * was claimed by another create inside its own check-to-take window releases what
 * it took and is refused with the ceiling's existing 429, leaving nothing on disk.
 * That release is a purge like any other, so it takes the job's own tick lock
 * (ADR-0019) — a queued job needs no id and no scheduled continuation to be reached,
 * because `Watchdog::patrol()` enumerates the store — and a lock it cannot take
 * leaves the job standing for the TTL sweep, still answering the ceiling's own 429.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Config;
use Kntnt\Extractor\Job_Store;

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

/**
 * Dispatches POST /extractions with a JSON body through the live REST server.
 *
 * @param array<string, mixed>|string $body     Body to send: an array is JSON-encoded,
 *                                               a string is sent verbatim (malformed-body case).
 * @param string                      $type     Content-Type header to send.
 * @return WP_REST_Response
 */
$post_extractions = static function ( array|string $body, string $type = 'application/json' ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', $type );
	$request->set_body( is_string( $body ) ? $body : (string) wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $request );
};

/**
 * Dispatches GET /extractions/{id} through the live REST server.
 *
 * @param string $id Job identifier.
 * @return WP_REST_Response
 */
$get_extraction = static function ( string $id ): WP_REST_Response {
	return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/extractions/' . $id ) );
};

// A well-formed ephemeral X25519 public key is 32 bytes carried as base64.
$valid_key = base64_encode( random_bytes( 32 ) );

// A selection every install actually has: its own options table and its
// bootstrap file, both resolving inside the installation root.
$valid_body = static fn(): array => [
	'tables' => [ $GLOBALS['wpdb']->options ],
	'files' => [ 'wp-load.php' ],
	'public_key' => base64_encode( random_bytes( 32 ) ),
];

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// The owning administrator holds both capabilities; capture the id up front so
// later "who owns this" checks are unambiguous once a second admin exists.
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// --- AC5: the default working directory is under the uploads directory ---

// Create a job with no override in force and confirm its state file lands in a
// randomly-named directory under uploads, hardened against direct web access.
wp_set_current_user( $owner->ID );
$default_base = wp_upload_dir()['basedir'] . '/kntnt-extractor';
$response = $post_extractions( $valid_body() );
kntnt_extractor_assert( $response->get_status() === 201, 'POST /extractions creates a job (201)' );
$created = $response->get_data();
$default_id = is_array( $created ) && isset( $created['id'] ) && is_string( $created['id'] ) ? $created['id'] : '';
kntnt_extractor_assert( is_array( $created ) && isset( $created['id'], $created['state'] ), 'The create response carries an id and a state' );
kntnt_extractor_assert( $default_id !== '' && preg_match( '/^[a-f0-9]{32}$/', $default_id ) === 1, 'The job id is an unguessable 32-hex identifier' );
kntnt_extractor_assert( is_array( $created ) && ( $created['state'] ?? null ) === 'queued', 'A freshly created job is queued' );
kntnt_extractor_assert( is_file( $default_base . '/' . $default_id . '/job.json' ), 'Job state persists as JSON in a randomly-named dir under uploads' );
kntnt_extractor_assert( is_file( $default_base . '/index.html' ), 'The working directory carries an index.html that silences directory listing' );
$default_htaccess = is_file( $default_base . '/.htaccess' ) ? (string) file_get_contents( $default_base . '/.htaccess' ) : '';
$default_web_config = is_file( $default_base . '/web.config' ) ? (string) file_get_contents( $default_base . '/web.config' ) : '';
kntnt_extractor_assert( str_contains( $default_htaccess, 'Require all denied' ) && str_contains( $default_htaccess, 'Deny from all' ), 'The .htaccess actually denies direct web access on both Apache 2.4 and 2.2, not merely exists' );
kntnt_extractor_assert( str_contains( $default_web_config, 'deny users="*"' ), 'The web.config actually denies direct web access on IIS, not merely exists' );
kntnt_extractor_assert( is_file( $default_base . '/' . $default_id . '/index.html' ), 'The per-job directory carries its own index.html' );

// Reset the default location so its lone job cannot count against the isolated
// concurrency checks that follow. The served artifacts live in a sibling downloads
// directory, so clear that too and leave the uploads folder as it was found.
$rmrf( $default_base );
$rmrf( $default_base . '-downloads' );

// --- AC6: KNTNT_EXTRACTOR_WORK_DIR redirects the working directory ---

// Redirect the working directory to an isolated tree so every remaining check
// runs against known, self-owned state and proves the override path at once.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-test-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

// --- AC2/AC3: the validation ladder, verified from an UNAUTHORIZED caller ---

// Running these as an anonymous caller is the whole point: existence and shape
// errors must surface BEFORE the authorization gate, so each of these must be its
// own status code and never the 401 the caller would earn if the gate ran first.
// A body that parses as JSON but does not match the extraction-request shape —
// here a `tables` that is a string rather than an array — is unprocessable: 422.
// (A body that is not even valid JSON is a 400 owned by WordPress core, one layer
// below this contract; 422 is the well-formed-but-unprocessable case this endpoint
// defines.)
wp_set_current_user( 0 );
kntnt_extractor_assert( $post_extractions( '{"tables": [' )->get_status() === 400, 'A syntactically invalid JSON body is a 400 owned by WordPress core, one layer below this contract' );
kntnt_extractor_assert( $post_extractions( [ 'tables' => 'wp_options', 'public_key' => $valid_key ] )->get_status() === 422, 'A well-formed body that is not a valid extraction request is rejected 422 before the capability check' );
kntnt_extractor_assert( $post_extractions( [ 'public_key' => $valid_key ] )->get_status() === 422, 'A body that selects neither a table nor a file is rejected 422' );
kntnt_extractor_assert( $post_extractions( [ 'tables' => [ $wpdb->options ] ] )->get_status() === 400, 'An absent public_key is rejected 400 before the capability check' );
kntnt_extractor_assert( $post_extractions( [ 'tables' => [ $wpdb->options ], 'public_key' => 'not-a-valid-key' ] )->get_status() === 400, 'A malformed public_key is rejected 400' );
kntnt_extractor_assert( $post_extractions( [ 'tables' => [ 'wp_no_such_table_xyz' ], 'public_key' => $valid_key ] )->get_status() === 404, 'An unknown table is rejected 404 before the capability check' );
kntnt_extractor_assert( $post_extractions( [ 'files' => [ '..' ], 'public_key' => $valid_key ] )->get_status() === 404, 'A file resolving outside the installation root is rejected 404 before the capability check' );
kntnt_extractor_assert( $post_extractions( [ 'files' => [ '../wp-load.php' ], 'public_key' => $valid_key ] )->get_status() === 404, 'A traversal path resolving outside the root is rejected 404, never sanitised' );
kntnt_extractor_assert( $post_extractions( [ 'files' => [ "wp-load.php\u{0000}../../etc/passwd" ], 'public_key' => $valid_key ] )->get_status() === 404, 'A null byte in a file path is rejected 404 at the realpath boundary, never allowed to crash it' );
kntnt_extractor_assert( $post_extractions( $valid_body() )->get_status() === 401, 'A fully valid request from an unauthenticated caller is refused 401 once existence passes (ADR-0012)' );

// --- the two selection-shape caps, refused ahead of the capability gate ---

// Both caps are lowered through their own knobs so the fixtures stay cheap to
// build, and each filter is removed the moment its assertions are done: this
// file shares one process with every test file the bootstrap requires after it,
// so a cap left in force would leak into theirs.
$force_elements = static fn(): int => 3;
add_filter( 'kntnt_extractor_config_max_selection_elements', $force_elements );
$oversized_selection = $post_extractions( [ 'tables' => [ 'a', 'b' ], 'files' => [ 'c', 'd' ], 'public_key' => $valid_key ] );
$oversized_selection_data = $oversized_selection->get_data();
kntnt_extractor_assert( $oversized_selection->get_status() === 422, 'A selection over the element cap is rejected 422 before the capability check' );
kntnt_extractor_assert( is_array( $oversized_selection_data ) && ( $oversized_selection_data['code'] ?? null ) === 'kntnt_extractor_selection_too_large', 'The element-cap refusal names its own cause' );
kntnt_extractor_assert( is_array( $oversized_selection_data ) && ( $oversized_selection_data['data']['limit'] ?? null ) === 3 && ( $oversized_selection_data['data']['count'] ?? null ) === 4, 'The element-cap refusal reports the limit and the caller\'s own count, so a client can split its selection' );
kntnt_extractor_assert( $post_extractions( [ 'tables' => [ 'wp_no_such_table_xyz' ], 'public_key' => $valid_key ] )->get_status() === 404, 'A selection within the lowered cap still reaches the existence check' );
remove_filter( 'kntnt_extractor_config_max_selection_elements', $force_elements );

// The byte cap is pinned to the fixture's own encoded length rather than to a
// guessed number, so the two requests below straddle the boundary by exactly one
// byte: the same body is refused under a cap one short of it and admitted under a
// cap equal to it, which is what proves the comparison is on the right side.
$sized_body = [ 'tables' => [ $wpdb->options ], 'files' => [ 'wp-load.php' ], 'public_key' => $valid_key ];
$sized_bytes = strlen( (string) wp_json_encode( $sized_body ) );
$force_bytes = static fn(): int => $sized_bytes - 1;
add_filter( 'kntnt_extractor_config_max_body_bytes', $force_bytes );
$oversized_body = $post_extractions( $sized_body );
$oversized_body_data = $oversized_body->get_data();
kntnt_extractor_assert( $oversized_body->get_status() === 413, 'A body over the byte cap is rejected 413 before the plugin decodes it, before the capability check' );
kntnt_extractor_assert( is_array( $oversized_body_data ) && ( $oversized_body_data['code'] ?? null ) === 'kntnt_extractor_payload_too_large', 'The body-cap refusal names its own cause' );
kntnt_extractor_assert( is_array( $oversized_body_data ) && ( $oversized_body_data['data']['limit'] ?? null ) === $sized_bytes - 1 && ( $oversized_body_data['data']['bytes'] ?? null ) === $sized_bytes, 'The body-cap refusal reports the limit and the caller\'s own size, so a client can shrink its request' );
remove_filter( 'kntnt_extractor_config_max_body_bytes', $force_bytes );
$at_cap_bytes = static fn(): int => $sized_bytes;
add_filter( 'kntnt_extractor_config_max_body_bytes', $at_cap_bytes );
kntnt_extractor_assert( $post_extractions( $sized_body )->get_status() === 401, 'A body of exactly the cap is admitted and runs the whole ladder unchanged, down to the capability gate' );
remove_filter( 'kntnt_extractor_config_max_body_bytes', $at_cap_bytes );

// --- issue #16: the structure-only sibling list extends the same ladder ---

// AC2: a table listed in BOTH tables and tables_structure_only is a request-shape
// error — 422, decided as a malformed selection before existence and the gate.
kntnt_extractor_assert( $post_extractions( [ 'tables' => [ $wpdb->options ], 'tables_structure_only' => [ $wpdb->options ], 'public_key' => $valid_key ] )->get_status() === 422, 'A table in both tables and tables_structure_only is rejected 422 (AC2)' );

// AC3: an unknown structure-only table is a 404, and because this caller is
// unauthorized the 404 proves existence is decided BEFORE the authorization gate — the
// same existence-before-authorization order tables already hold to (ADR-0003).
kntnt_extractor_assert( $post_extractions( [ 'tables_structure_only' => [ 'wp_no_such_table_xyz' ], 'public_key' => $valid_key ] )->get_status() === 404, 'An unknown structure-only table is rejected 404 before the capability check (AC3)' );

// AC4: all three of tables, tables_structure_only, and files empty selects nothing
// — a 422 — while a selection of ONLY structure-only tables is valid and reaches the
// gate, so an unauthenticated caller earns the 401 that proves existence passed.
kntnt_extractor_assert( $post_extractions( [ 'tables' => [], 'tables_structure_only' => [], 'files' => [], 'public_key' => $valid_key ] )->get_status() === 422, 'A request selecting no table, structure-only table, or file is rejected 422 (AC4)' );
kntnt_extractor_assert( $post_extractions( [ 'tables_structure_only' => [ $wpdb->options ], 'public_key' => $valid_key ] )->get_status() === 401, 'A structure-only-only selection is a valid selection that reaches the authorization gate (AC4)' );

// No job may have been created by any of the rejected attempts above.
kntnt_extractor_assert( ! is_dir( $work ) || count( array_diff( scandir( $work ) ?: [], [ '.', '..', 'index.html', '.htaccess', 'web.config' ] ) ) === 0, 'A rejected create persists no job' );

// --- AC1/AC6/AC8: a valid create from the owner, landing at the override ---

wp_set_current_user( $owner->ID );
$response = $post_extractions( $valid_body() );
kntnt_extractor_assert( $response->get_status() === 201, 'An authorized caller creates a job (201)' );
$data = $response->get_data();
$id = is_array( $data ) && isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';
kntnt_extractor_assert( $id !== '' && is_file( $work . '/' . $id . '/job.json' ), 'The job state lands under the overridden working directory' );

// The job polls as queued for its owner (AC8) and echoes its own id (AC1).
$poll = $get_extraction( $id );
kntnt_extractor_assert( $poll->get_status() === 200, 'The owner may poll the job (200)' );
$poll_data = $poll->get_data();
kntnt_extractor_assert( is_array( $poll_data ) && ( $poll_data['state'] ?? null ) === 'queued', 'GET /extractions/{id} reports state queued' );
kntnt_extractor_assert( is_array( $poll_data ) && ( $poll_data['id'] ?? null ) === $id, 'The poll response echoes the polled id' );

// An id that is well formed but names no job is a 404.
kntnt_extractor_assert( $get_extraction( str_repeat( '0', 32 ) )->get_status() === 404, 'Polling an unknown job id is a 404' );

// --- AC4: a job is bound to its creator ---

// A second administrator holds both capabilities through the administrator role,
// so it clears the gate yet is refused the job it does not own.
$other_admin = wp_insert_user( [ 'user_login' => 'kntnt_extractions_other_admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ] );
wp_set_current_user( is_int( $other_admin ) ? $other_admin : 0 );
kntnt_extractor_assert( current_user_can( $operate ) && current_user_can( 'manage_options' ), 'The second administrator holds both capabilities' );
kntnt_extractor_assert( $get_extraction( $id )->get_status() === 403, 'A capable non-owner is refused the job (403)' );

// --- AC7: one non-terminal job globally, overridable ---

// A second create while the first job is still non-terminal is refused 429.
wp_set_current_user( $owner->ID );
kntnt_extractor_assert( $post_extractions( $valid_body() )->get_status() === 429, 'A second create while one job is active is refused 429' );

// Raising the limit through the filter lets a second job be created, and the
// third is refused again — the concurrency ceiling is overridable.
$force_max = static fn(): int => 2;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
kntnt_extractor_assert( $post_extractions( $valid_body() )->get_status() === 201, 'Raising the concurrency limit admits a second job (201)' );
kntnt_extractor_assert( $post_extractions( $valid_body() )->get_status() === 429, 'The raised limit still refuses the job past the ceiling (429)' );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );

// --- issue #36: the create path takes the slot and then re-checks it ---

// Isolate this on a working directory of its own: the checks above deliberately left
// live jobs behind, and the ceiling below must be reached by exactly the rival job
// this section plants and by nothing else.
$race_work = wp_upload_dir()['basedir'] . '/kntnt-extractor-race-' . bin2hex( random_bytes( 4 ) );
$force_race_work = static fn(): string => $race_work;
add_filter( 'kntnt_extractor_config_work_dir', $force_race_work, 20 );

// Drive the check-to-take window through the Config seam rather than by timing, which
// no single-process harness can interleave: `has_free_slot()` counts the live jobs
// first and resolves the ceiling second, so a filter on the ceiling knob runs inside
// the very window the race lives in, with the count already taken. A job created from
// there is precisely the rival create that arrived a moment earlier, and it lands
// deterministically on every run.
//
// That seam rests entirely on the evaluation order INSIDE `Job_Store::has_free_slot()`,
// whose body is the single expression
// `( $this->count_active() - $already_taken ) < $this->max_active_jobs()`. PHP evaluates
// it left to right, so the live jobs are already counted by the time the ceiling — and
// this filter with it — is resolved. Reorder that expression so the ceiling resolves
// first and the rival is planted BEFORE the count rather than inside the window: the
// pre-check ahead of the take would then refuse on its own, and every assertion in this
// section and the one below it would still pass while exercising nothing whatsoever.
// Anything that touches that expression has to come back here first.
$race_store = new Job_Store( new Config() );
$rival_id = '';
$rival_create = static function ( mixed $ceiling ) use ( &$rival_id, $race_store, $owner, $valid_key ): mixed {
	if ( $rival_id === '' ) {
		$rival_id = $race_store->create( (int) $owner->ID, $valid_key, [ $GLOBALS['wpdb']->options ], [], [] )->id;
	}
	return $ceiling;
};
add_filter( 'kntnt_extractor_config_max_active_jobs', $rival_create );
$lost = $post_extractions( $valid_body() );
$lost_data = $lost->get_data();
remove_filter( 'kntnt_extractor_config_max_active_jobs', $rival_create );

kntnt_extractor_assert( $rival_id !== '', 'The rival create lands inside the create path\'s own check-to-take window (#36)' );
kntnt_extractor_assert( $lost->get_status() === 429, 'A create whose slot was taken between the check and the take is refused 429 (#36)' );
kntnt_extractor_assert( is_array( $lost_data ) && ( $lost_data['code'] ?? null ) === 'kntnt_extractor_too_many_jobs', 'The lost-race refusal carries the concurrency ceiling\'s own error code (#36)' );

// The refusal is the one an ordinary second create already earns, byte for byte:
// what a 429 tells the caller about the occupied slot is a settled question this fix
// does not reopen (ADR-0028).
$plain_refusal = $post_extractions( $valid_body() );
kntnt_extractor_assert( $plain_refusal->get_status() === $lost->get_status() && $plain_refusal->get_data() === $lost_data, 'The lost-race refusal is the ceiling refusal sent today, unchanged in status, code and body (#36)' );

// The slot the refused caller took is given back, not leaked: only the rival's job
// survives on disk, so nothing counts against the ceiling that no caller holds.
$race_entries = scandir( $race_work );
$race_jobs = array_values( array_filter( $race_entries === false ? [] : $race_entries, static fn( string $entry ): bool => preg_match( '/^[a-f0-9]{32}$/', $entry ) === 1 ) );
kntnt_extractor_assert( $race_jobs === [ $rival_id ], 'The caller that lost the race releases the job it took, leaving only the rival on disk (#36)' );
kntnt_extractor_assert( $race_store->count_active() === 1, 'Exactly one job occupies a ceiling of one once the race has been decided (#36)' );

// Control: the same request against the same state, with room under the ceiling, is a
// 201 — so the refusal above was the slot and not this section's own isolation.
$force_race_max = static fn(): int => 2;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_race_max );
kntnt_extractor_assert( $post_extractions( $valid_body() )->get_status() === 201, 'The same create succeeds once the ceiling has room, so the refusal above was the slot (#36)' );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_race_max );

// Give the working directory back to the checks around this section, and take this
// one's own tree and its served downloads sibling with it.
remove_filter( 'kntnt_extractor_config_work_dir', $force_race_work, 20 );
$rmrf( $race_work );
$rmrf( $race_work . '-downloads' );

// --- issue #36: a lost race whose tick lock is held leaves the job to the sweep (ADR-0019) ---

// A working directory of this section's own again, for the same reason as above: the
// ceiling of one must be reached by exactly the rival this section plants.
$locked_work = wp_upload_dir()['basedir'] . '/kntnt-extractor-race-locked-' . bin2hex( random_bytes( 4 ) );
$force_locked_work = static fn(): string => $locked_work;
add_filter( 'kntnt_extractor_config_work_dir', $force_locked_work, 20 );

// The same seam as above, one turn further in. The ceiling filter fires twice per
// create — once for the pre-check, once for the re-check that already has this caller's
// own job on disk — so the first call plants the rival that takes the slot and the
// second takes the LOSER'S tick lock and holds it, which is what a `Watchdog::patrol()`
// advancing that queued job would be holding. The job's id has been handed to nobody and
// its first continuation is never scheduled, and neither fact protects it: the patrol
// enumerates `Job_Store::all()` and needs no id. flock() is per open file description
// rather than per process, so a second fopen() plus a non-blocking LOCK_EX genuinely
// denies the lock inside this one PHP process, exactly as consume-cancel-ttl-test.php
// demonstrates a live tick's lock for AC8.
$locked_store = new Job_Store( new Config() );
$locked_rival_id = '';
$locked_loser_id = '';
$held_lock = null;
$hold_losers_lock = static function ( mixed $ceiling ) use ( &$locked_rival_id, &$locked_loser_id, &$held_lock, $locked_store, $owner, $valid_key ): mixed {
	if ( $locked_rival_id === '' ) {
		$locked_rival_id = $locked_store->create( (int) $owner->ID, $valid_key, [ $GLOBALS['wpdb']->options ], [], [] )->id;
		return $ceiling;
	}
	if ( $held_lock === null ) {
		foreach ( $locked_store->all() as $candidate ) {
			if ( $candidate->id === $locked_rival_id ) {
				continue;
			}
			$handle = fopen( $locked_store->container_lock_path( $candidate ), 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- holding the losing create's own tick lock in-process, exactly as a live patrol would hold it.
			if ( $handle !== false && flock( $handle, LOCK_EX | LOCK_NB ) ) {
				$held_lock = $handle;
				$locked_loser_id = $candidate->id;
			}
			break;
		}
	}
	return $ceiling;
};
add_filter( 'kntnt_extractor_config_max_active_jobs', $hold_losers_lock );
$locked_lost = $post_extractions( $valid_body() );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $hold_losers_lock );

kntnt_extractor_assert( $held_lock !== null && $locked_loser_id !== '' && $locked_loser_id !== $locked_rival_id, 'The demonstration holds the losing create\'s own tick lock inside its check-to-take window (precondition, #36)' );
kntnt_extractor_assert( is_dir( $locked_work . '/' . $locked_loser_id ), 'A purge whose tick lock is held deletes nothing, leaving the job for the TTL sweep rather than out from under whatever holds the lock (#36, ADR-0019)' );
kntnt_extractor_assert( $locked_lost->get_status() === 429 && $locked_lost->get_data() === $lost_data, 'The refusal is the ceiling\'s own 429 whether or not the slot could be handed back — never a 409 (#36)' );

// Control: with the lock released the identical race purges the loser, which is what
// proves the survival above was the held lock and not this section's arrangement. The
// two jobs the run above left are cleared first so the same ceiling of one is reachable.
fclose( $held_lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- releasing the in-process demonstration lock.
foreach ( $locked_store->all() as $leftover ) {
	$locked_store->purge( $leftover );
}
$control_rival_id = '';
$plant_rival = static function ( mixed $ceiling ) use ( &$control_rival_id, $locked_store, $owner, $valid_key ): mixed {
	if ( $control_rival_id === '' ) {
		$control_rival_id = $locked_store->create( (int) $owner->ID, $valid_key, [ $GLOBALS['wpdb']->options ], [], [] )->id;
	}
	return $ceiling;
};
add_filter( 'kntnt_extractor_config_max_active_jobs', $plant_rival );
$control_lost = $post_extractions( $valid_body() );
remove_filter( 'kntnt_extractor_config_max_active_jobs', $plant_rival );
$control_entries = scandir( $locked_work );
$control_jobs = array_values( array_filter( $control_entries === false ? [] : $control_entries, static fn( string $entry ): bool => preg_match( '/^[a-f0-9]{32}$/', $entry ) === 1 ) );
kntnt_extractor_assert( $control_lost->get_status() === 429 && $control_jobs === [ $control_rival_id ], 'The same race with the lock free hands the slot back, so the job kept above was kept by the lock (#36 control)' );

// Give the working directory back to the checks around this section, and take this
// one's own tree and its served downloads sibling with it.
remove_filter( 'kntnt_extractor_config_work_dir', $force_locked_work, 20 );
$rmrf( $locked_work );
$rmrf( $locked_work . '-downloads' );

// Leave the suite state clean for later files, including the served downloads sibling.
$rmrf( $work );
$rmrf( $work . '-downloads' );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
wp_set_current_user( 0 );

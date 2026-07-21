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
 * fully valid request from a caller lacking the capabilities a 403. A created
 * job is bound to its creator: a capable non-owner polling it is refused 403
 * (AC4). It proves the persisted job-state shape lands as a JSON file in a
 * randomly-named directory both under the uploads directory by default (AC5) and
 * at the location the `KNTNT_EXTRACTOR_WORK_DIR` filter redirects it to (AC6),
 * hardened with index.html and an .htaccess/web.config deny, and that the
 * one-non-terminal-job concurrency rule answers a second create with 429 unless
 * the limit is raised (AC7). A freshly created job polls as `queued` (AC8).
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

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
kntnt_extractor_assert( is_file( $default_base . '/index.html' ) && is_file( $default_base . '/.htaccess' ) && is_file( $default_base . '/web.config' ), 'The working directory is hardened with index.html and an .htaccess/web.config deny' );
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
// errors must surface BEFORE the capability gate, so each of these must be its
// own status code and never the 403 the caller would earn if the gate ran first.
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
kntnt_extractor_assert( $post_extractions( $valid_body() )->get_status() === 403, 'A fully valid request from an unauthorized caller is refused 403 once existence passes' );

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

// Leave the suite state clean for later files, including the served downloads sibling.
$rmrf( $work );
$rmrf( $work . '-downloads' );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
wp_set_current_user( 0 );

<?php
/**
 * Integration test: unknown-resource errors name every offender, and
 * `strict: false` skips vanished files (S2).
 *
 * A file manifest is a snapshot and a live site is not. A selection built from
 * an hours-old walk used to die as an opaque 404 that named neither the missing
 * table nor the missing file, so the only recovery was a full re-walk. This file
 * pins the two halves of the close:
 *  - AC1: a 404 `kntnt_extractor_unknown_resource` carries every missing table
 *    name and every missing file path in `data`, not merely the first, so a
 *    missing table is distinguishable from a missing file.
 *  - AC2: omitted or `strict: true` keeps today's hard fail on a vanished file.
 *  - AC3: `strict: false` skips vanished files, persists them on the job, and
 *    reports them on the create and poll responses; a missing table still 404s.
 *  - AC4: a traversal or null-byte path is never a skip, even under
 *    `strict: false`.
 *  - AC5: the REST API version stays 6 — additive error `data`, an optional
 *    `strict` defaulting to true, and an optional `skipped_files` member do not
 *    change what an old client already understood.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Rest\Status_Controller;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

/**
 * Recursively removes a directory tree so the suite leaves no working directory
 * behind on the host.
 *
 * @param string $dir Directory to remove.
 * @return void
 */
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
 * @param array<string, mixed> $body Body to send, JSON-encoded.
 * @return WP_REST_Response
 */
$post_extractions = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/kntnt-extractor/v1/extractions' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
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

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// Isolate the working directory so this file's creates cannot collide with
// another suite file's active job.
$work = wp_upload_dir()['basedir'] . '/kntnt-extractor-test-' . bin2hex( random_bytes( 4 ) );
$force_work = static fn(): string => $work;
add_filter( 'kntnt_extractor_config_work_dir', $force_work );

$valid_key = base64_encode( random_bytes( 32 ) );
$owner = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// --- AC1: the 404 names every missing table and every missing file ---

// Run as an anonymous caller so a 404 here also proves existence still precedes
// the authorization gate (ADR-0003).
wp_set_current_user( 0 );

$two_tables = $post_extractions(
	[
		'tables' => [ 'wp_no_such_table_aaa', 'wp_no_such_table_bbb' ],
		'public_key' => $valid_key,
	]
);
$two_tables_data = $two_tables->get_data();
kntnt_extractor_assert( $two_tables->get_status() === 404, 'Two unknown tables are rejected 404 (AC1)' );
kntnt_extractor_assert(
	is_array( $two_tables_data ) && ( $two_tables_data['code'] ?? null ) === 'kntnt_extractor_unknown_resource',
	'The rejection keeps the kntnt_extractor_unknown_resource code (AC1)'
);
kntnt_extractor_assert(
	is_array( $two_tables_data ) && is_array( $two_tables_data['data'] ?? null ) && ( $two_tables_data['data']['tables'] ?? null ) === [ 'wp_no_such_table_aaa', 'wp_no_such_table_bbb' ],
	'The error data names every missing table, not merely the first (AC1)'
);
kntnt_extractor_assert(
	is_array( $two_tables_data ) && is_array( $two_tables_data['data'] ?? null ) && ( $two_tables_data['data']['files'] ?? null ) === [],
	'A table-only miss reports an empty files list, so the kind is unambiguous (AC1)'
);

$two_files = $post_extractions(
	[
		'files' => [ 'no-such-file-aaa.dat', 'no-such-file-bbb.dat' ],
		'public_key' => $valid_key,
	]
);
$two_files_data = $two_files->get_data();
kntnt_extractor_assert( $two_files->get_status() === 404, 'Two vanished files are rejected 404 under the default (AC1)' );
kntnt_extractor_assert(
	is_array( $two_files_data ) && is_array( $two_files_data['data'] ?? null ) && ( $two_files_data['data']['files'] ?? null ) === [ 'no-such-file-aaa.dat', 'no-such-file-bbb.dat' ],
	'The error data names every vanished file, not merely the first (AC1)'
);
kntnt_extractor_assert(
	is_array( $two_files_data ) && is_array( $two_files_data['data'] ?? null ) && ( $two_files_data['data']['tables'] ?? null ) === [],
	'A file-only miss reports an empty tables list, so the kind is unambiguous (AC1)'
);

$mixed = $post_extractions(
	[
		'tables' => [ 'wp_no_such_table_ccc' ],
		'tables_structure_only' => [ 'wp_no_such_table_ddd' ],
		'files' => [ 'no-such-file-ccc.dat' ],
		'public_key' => $valid_key,
	]
);
$mixed_data = $mixed->get_data();
kntnt_extractor_assert(
	is_array( $mixed_data ) && is_array( $mixed_data['data'] ?? null ) && ( $mixed_data['data']['tables'] ?? null ) === [ 'wp_no_such_table_ccc', 'wp_no_such_table_ddd' ] && ( $mixed_data['data']['files'] ?? null ) === [ 'no-such-file-ccc.dat' ],
	'A mixed miss names every missing table (full-data then structure-only) and every missing file (AC1)'
);

// --- AC2: omitted / strict: true still hard-fails a vanished file ---

kntnt_extractor_assert(
	$post_extractions(
		[
			'files' => [ 'wp-load.php', 'no-such-file-strict-default.dat' ],
			'public_key' => $valid_key,
		]
	)->get_status() === 404,
	'A vanished file beside a live one is still 404 when strict is omitted (AC2)'
);
kntnt_extractor_assert(
	$post_extractions(
		[
			'files' => [ 'wp-load.php', 'no-such-file-strict-true.dat' ],
			'strict' => true,
			'public_key' => $valid_key,
		]
	)->get_status() === 404,
	'A vanished file is still 404 when strict is true (AC2)'
);

// An ill-typed strict is a malformed body, decided before existence.
kntnt_extractor_assert(
	$post_extractions(
		[
			'files' => [ 'wp-load.php' ],
			'strict' => 'false',
			'public_key' => $valid_key,
		]
	)->get_status() === 422,
	'A non-boolean strict is rejected 422 (AC2)'
);

// --- AC3: strict: false skips vanished files and still hard-fails a table ---

$strict_false_table = $post_extractions(
	[
		'tables' => [ 'wp_no_such_table_eee' ],
		'files' => [ 'no-such-file-skipped-beside-table.dat' ],
		'strict' => false,
		'public_key' => $valid_key,
	]
);
$strict_false_table_data = $strict_false_table->get_data();
kntnt_extractor_assert( $strict_false_table->get_status() === 404, 'A missing table still 404s under strict: false (AC3)' );
kntnt_extractor_assert(
	is_array( $strict_false_table_data ) && is_array( $strict_false_table_data['data'] ?? null ) && ( $strict_false_table_data['data']['tables'] ?? null ) === [ 'wp_no_such_table_eee' ],
	'The table 404 under strict: false still names the missing table (AC3)'
);

// A selection that is only vanished files still 404s: after the skip nothing
// remains to extract, which is the same unknown-resource outcome as strict.
$only_vanished = $post_extractions(
	[
		'files' => [ 'no-such-file-only-aaa.dat', 'no-such-file-only-bbb.dat' ],
		'strict' => false,
		'public_key' => $valid_key,
	]
);
$only_vanished_data = $only_vanished->get_data();
kntnt_extractor_assert( $only_vanished->get_status() === 404, 'A selection that is only vanished files still 404s under strict: false (AC3)' );
kntnt_extractor_assert(
	is_array( $only_vanished_data ) && is_array( $only_vanished_data['data'] ?? null ) && ( $only_vanished_data['data']['files'] ?? null ) === [ 'no-such-file-only-aaa.dat', 'no-such-file-only-bbb.dat' ],
	'The empty-after-skip 404 still names every vanished file (AC3)'
);

// No job may have been created by any of the rejected attempts above.
kntnt_extractor_assert( ! is_dir( $work ) || count( array_diff( scandir( $work ) ?: [], [ '.', '..', 'index.html', '.htaccess', 'web.config' ] ) ) === 0, 'A rejected create persists no job' );

// An authorized create with a live table, a live file, and two vanished files
// is accepted; the vanished names land on the job and on both responses.
wp_set_current_user( $owner->ID );
$created = $post_extractions(
	[
		'tables' => [ $GLOBALS['wpdb']->options ],
		'files' => [ 'wp-load.php', 'no-such-file-skip-one.dat', 'no-such-file-skip-two.dat' ],
		'strict' => false,
		'public_key' => $valid_key,
	]
);
$created_data = $created->get_data();
kntnt_extractor_assert( $created->get_status() === 201, 'strict: false accepts a selection whose vanished files can be skipped (AC3)' );
$created_id = is_array( $created_data ) && isset( $created_data['id'] ) && is_string( $created_data['id'] ) ? $created_data['id'] : '';
kntnt_extractor_assert(
	is_array( $created_data ) && ( $created_data['skipped_files'] ?? null ) === [ 'no-such-file-skip-one.dat', 'no-such-file-skip-two.dat' ],
	'The 201 names every skipped file (AC3)'
);

$selection = is_file( $work . '/' . $created_id . '/job.json' ) ? json_decode( (string) file_get_contents( $work . '/' . $created_id . '/job.json' ), true ) : null;
kntnt_extractor_assert(
	is_array( $selection ) && ( $selection['files'] ?? null ) === [ 'wp-load.php' ],
	'The persisted selection keeps only the files that still exist (AC3)'
);
kntnt_extractor_assert(
	is_array( $selection ) && ( $selection['skipped_files'] ?? null ) === [ 'no-such-file-skip-one.dat', 'no-such-file-skip-two.dat' ],
	'The job record persists the skipped names (AC3)'
);

$poll = $get_extraction( $created_id );
$poll_data = $poll->get_data();
kntnt_extractor_assert( $poll->get_status() === 200, 'The owner may poll the skipped-file job (AC3)' );
kntnt_extractor_assert(
	is_array( $poll_data ) && ( $poll_data['skipped_files'] ?? null ) === [ 'no-such-file-skip-one.dat', 'no-such-file-skip-two.dat' ],
	'The poll reports the skipped files (AC3)'
);

// A job that skipped nothing omits the member. The first job still holds the
// slot, so raise the ceiling just for this create.
$force_max = static fn(): int => 2;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
$clean = $post_extractions(
	[
		'tables' => [ $GLOBALS['wpdb']->options ],
		'strict' => false,
		'public_key' => base64_encode( random_bytes( 32 ) ),
	]
);
remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max );
$clean_data = $clean->get_data();
$clean_id = is_array( $clean_data ) && isset( $clean_data['id'] ) && is_string( $clean_data['id'] ) ? $clean_data['id'] : '';
kntnt_extractor_assert( $clean->get_status() === 201, 'strict: false with nothing to skip still creates (AC3)' );
kntnt_extractor_assert(
	is_array( $clean_data ) && ! array_key_exists( 'skipped_files', $clean_data ),
	'A create that skipped nothing omits skipped_files (AC3)'
);
$clean_poll = $get_extraction( $clean_id )->get_data();
kntnt_extractor_assert(
	is_array( $clean_poll ) && ! array_key_exists( 'skipped_files', $clean_poll ),
	'A poll that skipped nothing omits skipped_files (AC3)'
);

// --- AC4: a traversal is never a skip ---

// Posted beside a live file so empty-after-skip cannot 404 on its own: if the
// traversal were classified vanished, strict: false would skip it and create.
// Two jobs already hold the slot, so raise the ceiling for that counterfactual.
wp_set_current_user( $owner->ID );
$force_max_ac4 = static fn(): int => 3;
add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max_ac4 );

$traversal = $post_extractions(
	[
		'files' => [ 'wp-load.php', '../wp-load.php' ],
		'strict' => false,
		'public_key' => $valid_key,
	]
);
$traversal_data = $traversal->get_data();
kntnt_extractor_assert( $traversal->get_status() === 404, 'A traversal beside a live file is still 404 under strict: false, not 201 (AC4)' );
kntnt_extractor_assert(
	is_array( $traversal_data ) && is_array( $traversal_data['data'] ?? null ) && ( $traversal_data['data']['files'] ?? null ) === [ '../wp-load.php' ],
	'The traversal 404 names the out-of-root path (AC4)'
);
kntnt_extractor_assert(
	is_array( $traversal_data )
		&& ! in_array( '../wp-load.php', $traversal_data['skipped_files'] ?? [], true )
		&& ! ( is_array( $traversal_data['data'] ?? null ) && in_array( '../wp-load.php', $traversal_data['data']['skipped_files'] ?? [], true ) ),
	'A traversal is never recorded as a skipped file (AC4)'
);

$unresolved = $post_extractions(
	[
		'files' => [ 'wp-load.php', 'wp-content/../../etc/missing' ],
		'strict' => false,
		'public_key' => $valid_key,
	]
);
$unresolved_data = $unresolved->get_data();
kntnt_extractor_assert( $unresolved->get_status() === 404, 'An unresolved traversal beside a live file is still 404 under strict: false, not 201 (AC4)' );
kntnt_extractor_assert(
	is_array( $unresolved_data ) && is_array( $unresolved_data['data'] ?? null ) && ( $unresolved_data['data']['files'] ?? null ) === [ 'wp-content/../../etc/missing' ],
	'The unresolved-traversal 404 names the out-of-root path (AC4)'
);
kntnt_extractor_assert(
	is_array( $unresolved_data )
		&& ! in_array( 'wp-content/../../etc/missing', $unresolved_data['skipped_files'] ?? [], true )
		&& ! ( is_array( $unresolved_data['data'] ?? null ) && in_array( 'wp-content/../../etc/missing', $unresolved_data['data']['skipped_files'] ?? [], true ) ),
	'An unresolved traversal is never recorded as a skipped file (AC4)'
);

remove_filter( 'kntnt_extractor_config_max_active_jobs', $force_max_ac4 );

wp_set_current_user( 0 );
kntnt_extractor_assert(
	$post_extractions(
		[
			'files' => [ "wp-load.php\u{0000}../../etc/passwd" ],
			'strict' => false,
			'public_key' => $valid_key,
		]
	)->get_status() === 404,
	'A null-byte path is still 404 under strict: false (AC4)'
);

// --- AC5: the API version stays 6 ---

kntnt_extractor_assert( Status_Controller::API_VERSION === 6, 'The REST contract stays at api_version 6 (AC5)' );
$status = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/status' ) )->get_data();
kntnt_extractor_assert( is_array( $status ) && ( $status['api_version'] ?? null ) === 6, 'GET /status still reports api_version 6 (AC5)' );

$rmrf( $work );
$rmrf( $work . '-downloads' );
remove_filter( 'kntnt_extractor_config_work_dir', $force_work );
wp_set_current_user( 0 );

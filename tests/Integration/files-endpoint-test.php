<?php
/**
 * Integration test: GET /files returns the recursive Manifest to an authorized
 * caller, paged through an opaque cursor and gated by the shared Authorizer.
 *
 * This harness exercises the REST wiring end to end against the live server: the
 * both-capabilities gate (AC5), the response shape (path/size/mtime, no
 * categorisation — AC1/AC4), the `Config` page-size knob (AC3), and the opaque
 * cursor round-tripping through the HTTP layer (AC2). The exhaustive
 * no-gaps/no-duplicates reassembly proof lives in files-manifest-test.php, which
 * drives the Manifest directly against a controlled tree; here the concern is
 * that the endpoint honours the same contract over a real WordPress install
 * without having to page the entire filesystem.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

/**
 * Dispatches GET /files through the live REST server with optional query params.
 *
 * @param array<string, string> $params Query parameters (e.g. the cursor).
 * @return WP_REST_Response
 */
$get_files = static function ( array $params = [] ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', '/kntnt-extractor/v1/files' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_get_server()->dispatch( $request );
};

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// The endpoint reuses the both-capabilities Authorizer (AC5): neither an
// anonymous caller nor an Operate-only caller may list.
wp_set_current_user( 0 );
kntnt_extractor_assert( $get_files()->get_status() === 403, 'An anonymous caller is refused GET /files (403)' );
$operate_only = wp_insert_user( [ 'user_login' => 'kntnt_files_operate_only', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
( new WP_User( $operate_only ) )->add_cap( $operate );
wp_set_current_user( $operate_only );
kntnt_extractor_assert( $get_files()->get_status() === 403, 'Operate without manage_options is refused GET /files (403)' );

// Authorize as an administrator, who holds both capabilities.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );

// Force a small page size through the Config knob (AC3) so a couple of pages
// exercise multi-page behaviour without walking the whole install.
$page_size = 3;
$force_page_size = static fn(): int => $page_size;
add_filter( 'kntnt_extractor_config_files_page_size', $force_page_size );

// The first page answers 200 and carries exactly the configured number of
// entries plus a non-null cursor — the Config knob governs the page size.
$response = $get_files();
kntnt_extractor_assert( $response->get_status() === 200, 'A caller holding both capabilities may list files (200)' );
$first = $response->get_data();
kntnt_extractor_assert( is_array( $first ) && isset( $first['files'], $first['cursor'] ) && is_array( $first['files'] ), 'GET /files returns a files array and a cursor' );
$first_files = is_array( $first ) && isset( $first['files'] ) && is_array( $first['files'] ) ? $first['files'] : [];
kntnt_extractor_assert( count( $first_files ) === $page_size, 'The Config page-size knob bounds the first page' );
kntnt_extractor_assert( isset( $first['cursor'] ) && is_string( $first['cursor'] ) && $first['cursor'] !== '', 'A non-final page carries a non-empty opaque cursor' );

// Every entry carries exactly path, size and mtime — recursive from the install
// root, with no categorisation of what any file is for (AC1/AC4).
$root = realpath( ABSPATH );
$well_formed = $first_files !== [];
foreach ( $first_files as $entry ) {
	$keys = is_array( $entry ) ? array_keys( $entry ) : [];
	sort( $keys );
	if ( $keys !== [ 'mtime', 'path', 'size' ]
		|| ! is_string( $entry['path'] )
		|| $entry['path'] === ''
		|| str_starts_with( $entry['path'], '/' )
		|| ! is_int( $entry['size'] )
		|| ! is_int( $entry['mtime'] )
		|| ! is_file( $root . '/' . $entry['path'] ) ) {
		$well_formed = false;
	}
}
kntnt_extractor_assert( $well_formed, 'Each entry carries exactly path, size and mtime, resolving to a real file under the root' );

// Following the cursor yields the next page: also 200 and well formed, and it
// never repeats an entry from the first page (no duplicates across the cursor).
$second = $get_files( [ 'cursor' => $first['cursor'] ] );
kntnt_extractor_assert( $second->get_status() === 200, 'Following the cursor returns the next page (200)' );
$second_data = $second->get_data();
$second_files = is_array( $second_data ) && isset( $second_data['files'] ) && is_array( $second_data['files'] ) ? $second_data['files'] : [];
$first_paths = array_map( static fn( array $f ): string => $f['path'], $first_files );
$second_paths = array_map( static fn( array $f ): string => $f['path'], $second_files );
kntnt_extractor_assert( $second_files !== [], 'The second page is non-empty on a real install' );
kntnt_extractor_assert( array_intersect( $first_paths, $second_paths ) === [], 'The second page repeats no entry from the first (no duplicates)' );

// A malformed cursor is a client error at an untrusted boundary — 400, not a
// silent restart or a 500.
kntnt_extractor_assert( $get_files( [ 'cursor' => '@@@' ] )->get_status() === 400, 'A cursor outside the token alphabet is refused (400)' );
$parent_cursor = rtrim( strtr( base64_encode( '../wp-config.php' ), '+/', '-_' ), '=' );
kntnt_extractor_assert( $get_files( [ 'cursor' => $parent_cursor ] )->get_status() === 400, 'A cursor with parent-directory components is refused (400)' );

// Leave the suite state clean for later files.
remove_filter( 'kntnt_extractor_config_files_page_size', $force_page_size );
wp_set_current_user( 0 );

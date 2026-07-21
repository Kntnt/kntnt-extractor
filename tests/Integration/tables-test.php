<?php
/**
 * Integration test: GET /tables returns the Table list to an authorized caller.
 *
 * The first authorized data endpoint. It enumerates exactly the tables that
 * exist in the site's database (ADR-0003 — no server-side categorisation) and
 * attaches, per table, an exact row count and a byte-size estimate. Verified
 * against the database's own `SHOW TABLES` so the listing is neither padded nor
 * filtered, and the row count is the real count rather than a zeroed estimate.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

global $wpdb;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( 'kntnt_extractor_operate' ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// Authorize as an administrator and list the tables.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );
$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/tables' ) );
kntnt_extractor_assert( $response->get_status() === 200, 'GET /tables responds 200 to an authorized caller' );

// The body carries a tables array.
$data = $response->get_data();
kntnt_extractor_assert( is_array( $data ) && isset( $data['tables'] ) && is_array( $data['tables'] ), 'GET /tables returns a tables array' );
$tables = is_array( $data ) && isset( $data['tables'] ) && is_array( $data['tables'] ) ? $data['tables'] : [];

// Every entry is well formed: a table name, an integer row count, and an
// integer byte-size estimate, none negative.
$well_formed = $tables !== [];
$by_name = [];
foreach ( $tables as $table ) {
	if ( ! is_array( $table )
		|| ! isset( $table['name'], $table['rows'], $table['bytes'] )
		|| ! is_string( $table['name'] )
		|| ! is_int( $table['rows'] )
		|| ! is_int( $table['bytes'] )
		|| $table['rows'] < 0
		|| $table['bytes'] < 0 ) {
		$well_formed = false;
		continue;
	}
	$by_name[ $table['name'] ] = $table;
}
kntnt_extractor_assert( $well_formed, 'Each entry carries a name, an integer row count, and an integer byte-size estimate' );

// The listing is exactly the site's own table enumeration — never padded,
// never filtered.
$expected = $wpdb->get_col( 'SHOW TABLES' );
sort( $expected );
$got = array_keys( $by_name );
sort( $got );
kntnt_extractor_assert( $got === $expected, "GET /tables enumerates exactly the site's tables" );

// The row count is the real count, not a zeroed estimate: the options and users
// tables are never empty.
kntnt_extractor_assert( isset( $by_name[ $wpdb->options ] ) && $by_name[ $wpdb->options ]['rows'] > 0, 'The options table reports a real, positive row count' );
kntnt_extractor_assert( isset( $by_name[ $wpdb->users ] ) && $by_name[ $wpdb->users ]['rows'] >= 1, 'The users table reports at least one row' );

// Leave the suite state clean for later files.
wp_set_current_user( 0 );

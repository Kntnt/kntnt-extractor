<?php
/**
 * Integration test: GET /tables returns the Table list to an authorized caller.
 *
 * The first authorized data endpoint. It enumerates exactly the tables that
 * exist in the site's database (ADR-0003 — no server-side categorisation) and
 * attaches, per table, an estimated row count and a byte-size estimate, both
 * read from the storage engine's `SHOW TABLE STATUS` metadata. This harness
 * verifies the listing's shape and that it is neither padded nor filtered
 * (checked against the database's own `SHOW TABLES`). It does not assert the
 * magnitudes: WordPress Playground runs on SQLite, whose `SHOW TABLE STATUS`
 * translation stubs `Rows`, `Data_length`, and `Index_length` to zero, so a
 * plausible-magnitude check is impossible here and lives in the MySQL-backed
 * DDEV harness (tests/Integration/DDEV) instead — the standard's prescribed
 * fallback for MySQL-specific SQL that Playground cannot exercise.
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

// Core tables that must always exist appear in the listing. Their row and byte
// magnitudes are not asserted here — see the file docblock: the SQLite harness
// stubs the engine statistics to zero, so magnitude is verified in the DDEV
// (MySQL) harness rather than in this suite.
kntnt_extractor_assert( isset( $by_name[ $wpdb->options ] ), 'The listing includes the options table' );
kntnt_extractor_assert( isset( $by_name[ $wpdb->users ] ), 'The listing includes the users table' );

// Leave the suite state clean for later files.
wp_set_current_user( 0 );

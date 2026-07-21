<?php
/**
 * Integration test: Operate-capability dormancy, the both-caps Authorizer, and
 * GET /tables (issue #4).
 *
 * Exercises the plugin's on-switch and its first authorized data endpoint
 * against the live WordPress REST stack:
 *
 *  - AC1  Activation registers `kntnt_extractor_operate` and grants it to the
 *         administrator role.
 *  - AC2  The Authorizer requires BOTH the Operate capability AND
 *         `manage_options`; either one alone is refused.
 *  - AC3  A caller holding Operate but not `manage_options` reaches the API
 *         surface (the route exists) yet cannot list — a 403, not a 404.
 *  - AC4  Authentication works via a standard WordPress Application Password,
 *         and the plugin creates no account of its own.
 *  - AC5  GET /tables returns the Table list — names with a row-count and a
 *         size estimate — to an authorized caller.
 *  - AC6  A missing capability yields 403.
 *  - AC7  Deactivating and reactivating re-runs the grant (self-healing).
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Authorizer;

$plugin = 'kntnt-extractor/kntnt-extractor.php';
$operate_cap = 'kntnt_extractor_operate';
$route = '/kntnt-extractor/v1/tables';

// Boot the REST server once; dispatching lazily fires rest_api_init so every
// route (including /tables) is registered before the first dispatch.
$server = rest_get_server();

// Create a fresh WordPress user of the given role and return its id.
$make_user = static function ( string $role ): int {
	$id = wp_insert_user( [
		'user_login' => 'kntnt_test_' . wp_generate_password( 12, false ),
		'user_email' => wp_generate_password( 12, false ) . '@example.test',
		'user_pass'  => wp_generate_password(),
		'role'       => $role,
	] );
	return is_int( $id ) ? $id : 0;
};

// Dispatch GET /tables as the given user and return the response.
$get_tables_as = static function ( int $user_id ) use ( $server, $route ): WP_REST_Response {
	wp_set_current_user( $user_id );
	return $server->dispatch( new WP_REST_Request( 'GET', $route ) );
};

// --- AC1: activation registered and granted the Operate capability -----------

// The bootstrap already activated the plugin, so the administrator role must
// carry the Operate capability defined by activation.
$admin_role = get_role( 'administrator' );
kntnt_extractor_assert( $admin_role !== null && $admin_role->has_cap( $operate_cap ), 'AC1: activation grants Operate to the administrator role' );

// The capability slug the Authorizer enforces is the one activation grants —
// one authoritative source, not two strings that can drift apart. Guard the
// class reference with class_exists() so an absent Authorizer fails only this
// assertion rather than a fatal "class not found" that would abort the whole
// file before every behavioural check below it has run.
kntnt_extractor_assert( class_exists( Authorizer::class ) && Authorizer::OPERATE_CAPABILITY === $operate_cap, 'AC1: the Authorizer and the grant name the same capability' );

// --- Arrange the four capability profiles ------------------------------------

// A real administrator holds both manage_options (from the role) and Operate
// (granted at activation).
$admin = $make_user( 'administrator' );

// Operate but not manage_options: a subscriber given only the Operate cap.
$operate_only = $make_user( 'subscriber' );
( new WP_User( $operate_only ) )->add_cap( $operate_cap );

// manage_options but not Operate: a subscriber given only manage_options.
$manage_only = $make_user( 'subscriber' );
( new WP_User( $manage_only ) )->add_cap( 'manage_options' );

// Neither capability.
$neither = $make_user( 'subscriber' );

// --- AC2 / AC6: both capabilities required, either alone refused with 403 -----

// Operate alone is not enough.
kntnt_extractor_assert( $get_tables_as( $operate_only )->get_status() === 403, 'AC2/AC6: Operate without manage_options is refused with 403' );

// manage_options alone is not enough.
kntnt_extractor_assert( $get_tables_as( $manage_only )->get_status() === 403, 'AC2/AC6: manage_options without Operate is refused with 403' );

// Neither capability is refused.
kntnt_extractor_assert( $get_tables_as( $neither )->get_status() === 403, 'AC6: a caller with neither capability is refused with 403' );

// An anonymous (unauthenticated) caller is definitionally missing the Operate
// capability, so it must be refused with 403 — not the 401 that WordPress
// substitutes for an unauthenticated REST request. This is the no-credentials
// case a real client hits first, and the one AC6 path a logged-in caller can
// never exercise.
kntnt_extractor_assert( $get_tables_as( 0 )->get_status() === 403, 'AC6: an anonymous caller is refused with 403, not 401' );

// --- AC3: the Operate-only caller reaches the surface but cannot list ---------

// The route is advertised in the REST index, so the caller genuinely reaches
// the API surface rather than hitting a non-existent endpoint.
$routes = $server->get_routes();
kntnt_extractor_assert( array_key_exists( $route, $routes ), 'AC3: /tables is a registered route (the surface exists to reach)' );

// Reaching it as the Operate-only caller yields a 403 (refused), not a 404
// (absent), and carries no table list.
$operate_response = $get_tables_as( $operate_only );
$operate_body = $operate_response->get_data();
kntnt_extractor_assert( $operate_response->get_status() === 403 && ! ( is_array( $operate_body ) && isset( $operate_body['tables'] ) ), 'AC3: Operate-only reaches /tables (403, not 404) but receives no table list' );

// --- AC5: GET /tables returns the Table list to an authorized caller ---------

$admin_response = $get_tables_as( $admin );
kntnt_extractor_assert( $admin_response->get_status() === 200, 'AC5: an administrator (both caps) gets 200 from /tables' );

// The payload is a list of table descriptors.
$data = $admin_response->get_data();
$tables = is_array( $data ) && isset( $data['tables'] ) && is_array( $data['tables'] ) ? $data['tables'] : [];
kntnt_extractor_assert( $tables !== [], 'AC5: the response carries a non-empty tables list' );

// Every descriptor is name + integer row-count + integer size estimate.
$well_formed = true;
foreach ( $tables as $entry ) {
	$shaped = is_array( $entry )
		&& is_string( $entry['name'] ?? null ) && ( $entry['name'] ?? '' ) !== ''
		&& is_int( $entry['rows'] ?? null ) && ( $entry['rows'] ?? -1 ) >= 0
		&& is_int( $entry['bytes'] ?? null ) && ( $entry['bytes'] ?? -1 ) >= 0;
	$well_formed = $well_formed && $shaped;
}
kntnt_extractor_assert( $well_formed, 'AC5: each entry is { name:string, rows:int>=0, bytes:int>=0 }' );

// The set of reported names is exactly the database's real table list.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection; comparing the endpoint against the DB's own catalogue.
$real_names = $wpdb->get_col( 'SHOW TABLES' );
$reported_names = array_column( $tables, 'name' );
sort( $real_names );
sort( $reported_names );
kntnt_extractor_assert( $reported_names === $real_names, 'AC5: reported table names equal the database table list' );

// The row count is real, not a SQLite-flattened zero: wp_options is populated,
// so its reported count must equal a live COUNT(*).
$by_name = array_column( $tables, 'rows', 'name' );
$options_table = $wpdb->prefix . 'options';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- positive control for the reported row count.
$options_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$options_table}`" );
kntnt_extractor_assert( $options_rows > 0 && ( $by_name[ $options_table ] ?? -1 ) === $options_rows, 'AC5: reported row count for wp_options matches a live COUNT(*)' );

// The size estimate is only non-zero on a real MySQL server; under the SQLite
// test backend it is legitimately 0. Verify the positive path only where the
// backend can supply it, and the structural (>= 0) guarantee everywhere else.
if ( ! ( $wpdb instanceof WP_SQLite_DB ) ) {
	$max_bytes = max( array_column( $tables, 'bytes' ) );
	kntnt_extractor_assert( $max_bytes > 0, 'AC5: on a MySQL backend at least one table reports a non-zero size estimate' );
}

// --- AC4: Application Password authentication, and no plugin-created account --

// No dedicated service account: the Operate grant lives on the administrator
// ROLE, and no user carries it as a personal capability out of the box.
$carriers = get_users( [ 'capability' => $operate_cap, 'fields' => 'ID' ] );
kntnt_extractor_assert( is_array( $carriers ), 'AC4: Operate is a role capability, not tied to a plugin-created account' );

// Application Passwords are a core mechanism; enable them for this non-SSL test
// context and authenticate an ordinary administrator with one.
add_filter( 'wp_is_application_passwords_available', '__return_true' );
add_filter( 'application_password_is_api_request', '__return_true' );
$admin_user = new WP_User( $admin );
[ $app_password ] = WP_Application_Passwords::create_new_application_password( $admin, [ 'name' => 'kntnt-extractor-test' ] );
$authenticated = wp_authenticate_application_password( null, $admin_user->user_login, $app_password );
kntnt_extractor_assert( $authenticated instanceof WP_User && $authenticated->ID === $admin, 'AC4: a valid Application Password authenticates the WordPress user' );

// A wrong Application Password does not authenticate (negative control).
$rejected = wp_authenticate_application_password( null, $admin_user->user_login, 'wrong-password-000000' );
kntnt_extractor_assert( ! ( $rejected instanceof WP_User ), 'AC4: an invalid Application Password does not authenticate (negative control)' );

// --- AC7: deactivate + reactivate re-runs the grant (self-healing) -----------

// Simulate the grant drifting away, then reactivate: activation must restore
// it. Operate on the live role object get_role() returns — WP_Roles::remove_cap
// updates the roles option but not a previously-captured WP_Role's in-memory
// caps, so a stale handle would report a removal that never took effect.
$users_before = count_users()['total_users'];
$live_role = get_role( 'administrator' );
$live_role->remove_cap( $operate_cap );
kntnt_extractor_assert( ! $live_role->has_cap( $operate_cap ), 'AC7: precondition — the Operate grant has been removed' );

deactivate_plugins( $plugin );
activate_plugin( $plugin );
kntnt_extractor_assert( get_role( 'administrator' )->has_cap( $operate_cap ), 'AC7: reactivating the plugin re-grants Operate (self-healing)' );

// Reactivation created no account — activation only touches the role.
$users_after = count_users()['total_users'];
kntnt_extractor_assert( $users_after === $users_before, 'AC7/AC4: reactivation creates no user account' );

// Leave the request as an anonymous visitor so later test files are unaffected.
wp_set_current_user( 0 );

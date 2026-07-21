<?php
/**
 * Integration test: the both-capabilities authorization gate on GET /tables.
 *
 * The Authorizer is the security seam every later data endpoint reuses. It
 * demands BOTH the Operate capability (the dormancy on-switch) and
 * `manage_options` (the administrator data gate). Either capability alone is
 * refused with 403; only a caller holding both may list. Exercised through the
 * live REST server so the gate is tested as a real client would meet it.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$operate = 'kntnt_extractor_operate';

/**
 * Dispatches GET /tables through the live REST server.
 *
 * @return WP_REST_Response
 */
$list_tables = static fn(): WP_REST_Response => rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/tables' ) );

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( $operate ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// Both capabilities: an administrator holds Operate and manage_options, so the
// request is authorized and lists.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );
kntnt_extractor_assert( current_user_can( $operate ) && current_user_can( 'manage_options' ), 'An administrator holds both Operate and manage_options' );
kntnt_extractor_assert( $list_tables()->get_status() === 200, 'A caller holding both capabilities may list tables (200)' );

// Operate only: a user with the on-switch but not manage_options reaches the
// API surface yet cannot list — refused with 403.
$operate_only = wp_insert_user( [ 'user_login' => 'kntnt_operate_only', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
( new WP_User( $operate_only ) )->add_cap( $operate );
wp_set_current_user( $operate_only );
kntnt_extractor_assert( current_user_can( $operate ) && ! current_user_can( 'manage_options' ), 'The Operate-only caller holds Operate but not manage_options' );
kntnt_extractor_assert( $list_tables()->get_status() === 403, 'Operate without manage_options is refused (403)' );

// manage_options only: the administrator data gate without the on-switch is
// equally refused.
$manage_only = wp_insert_user( [ 'user_login' => 'kntnt_manage_only', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
( new WP_User( $manage_only ) )->add_cap( 'manage_options' );
wp_set_current_user( $manage_only );
kntnt_extractor_assert( ! current_user_can( $operate ) && current_user_can( 'manage_options' ), 'The manage_options-only caller holds manage_options but not Operate' );
kntnt_extractor_assert( $list_tables()->get_status() === 403, 'manage_options without Operate is refused (403)' );

// Neither capability: a plain subscriber is refused.
$neither = wp_insert_user( [ 'user_login' => 'kntnt_neither', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
wp_set_current_user( $neither );
kntnt_extractor_assert( $list_tables()->get_status() === 403, 'A caller with neither capability is refused (403)' );

// Anonymous: an unauthenticated caller is refused with 403, not a 401 — a
// missing capability yields 403.
wp_set_current_user( 0 );
kntnt_extractor_assert( $list_tables()->get_status() === 403, 'An anonymous caller is refused (403)' );

// Leave the suite state clean for later files.
wp_set_current_user( 0 );

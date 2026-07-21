<?php
/**
 * Integration test: authentication via a standard WordPress Application Password.
 *
 * The plugin creates no service account (ADR-0002): a caller authenticates as
 * an ordinary WordPress user through a core Application Password. This exercises
 * WordPress's own application-password machinery against a password minted for a
 * real administrator, then drives the authenticated request through the
 * authorization gate to GET /tables — proving the plugin honours standard auth
 * and adds nothing of its own.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( 'kntnt_extractor_operate' ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];

// A standard Application Password can be minted for an ordinary WordPress user —
// no plugin-created account is involved.
$created = WP_Application_Passwords::create_new_application_password( $admin->ID, [ 'name' => 'kntnt-extractor-integration' ] );
kntnt_extractor_assert( ! is_wp_error( $created ) && isset( $created[0] ), 'A standard Application Password can be created for a WordPress user' );
$plaintext = $created[0];

// Core validates the password against the user's stored hash. The Playground
// harness serves over http, so force availability and mark this an API request
// exactly as a real REST call would be classified.
add_filter( 'wp_is_application_passwords_available', '__return_true' );
add_filter( 'application_password_is_api_request', '__return_true' );
$_SERVER['PHP_AUTH_USER'] = $admin->user_login;
$_SERVER['PHP_AUTH_PW'] = $plaintext;

// The valid password authenticates as the owning user; a wrong one is rejected.
$authenticated = wp_authenticate_application_password( null, $admin->user_login, $plaintext );
kntnt_extractor_assert( $authenticated instanceof WP_User && $authenticated->ID === $admin->ID, 'A valid Application Password authenticates as the owning WordPress user' );
kntnt_extractor_assert( is_wp_error( wp_authenticate_application_password( null, $admin->user_login, 'wrong wrong wrong wrong' ) ), 'An invalid Application Password is rejected' );

// The Application-Password-authenticated administrator reaches the authorized
// surface: GET /tables answers 200.
wp_set_current_user( $authenticated->ID );
$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/tables' ) );
kntnt_extractor_assert( $response->get_status() === 200, 'An Application-Password-authenticated administrator may list tables (200)' );

// Leave the suite state clean: drop the forced filters, the basic-auth server
// vars, and the current user.
remove_filter( 'wp_is_application_passwords_available', '__return_true' );
remove_filter( 'application_password_is_api_request', '__return_true' );
unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
wp_set_current_user( 0 );

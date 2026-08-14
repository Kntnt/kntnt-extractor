<?php
/**
 * Integration test: GET /status through the live REST server.
 *
 * The headline walking-skeleton test. Dispatches the unauthenticated status
 * request the way a real client's HTTP call would reach it, and checks the
 * whole contract: an anonymous 200, the exact API-version body, the namespace's
 * presence in the REST index, and the absence of any plugin release-version leak.
 *
 * It also pins the identity report the endpoint gained with ADR-0012: the
 * anonymous body stays exactly the historical handshake, while a request that
 * resolved to a WordPress user additionally names that user and reports both
 * capability holdings — the direct answer that replaces inferring identity from
 * the pattern of errors other endpoints return.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

require_once ABSPATH . 'wp-admin/includes/plugin.php';

/**
 * Dispatches GET /status through the live REST server.
 *
 * @return WP_REST_Response
 */
$get_status = static fn(): WP_REST_Response => rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/status' ) );

// Make the Operate grant a precondition regardless of file order.
if ( ! get_role( 'administrator' )->has_cap( 'kntnt_extractor_operate' ) ) {
	deactivate_plugins( 'kntnt-extractor/kntnt-extractor.php' );
	activate_plugin( 'kntnt-extractor/kntnt-extractor.php' );
}

// Dispatch the status request through the live REST server, unauthenticated.
$server = rest_get_server();
wp_set_current_user( 0 );
$response = $get_status();

// The endpoint answers 200 without authentication.
kntnt_extractor_assert( $response->get_status() === 200, 'GET /status responds 200 without authentication' );

// The anonymous body is exactly the API-version contract and nothing more — the
// identity members must never appear for a caller who supplied no credentials.
kntnt_extractor_assert( $response->get_data() === [ 'api_version' => 6 ], 'GET /status returns { api_version: 6 } and nothing more to an anonymous caller' );

// The namespace is advertised in WordPress's REST index.
$index = $server->dispatch( new WP_REST_Request( 'GET', '/' ) )->get_data();
kntnt_extractor_assert( in_array( 'kntnt-extractor/v1', $index['namespaces'], true ), 'kntnt-extractor/v1 appears in the REST index' );

// The response leaks no plugin release version: the plugin's own Version header
// string must not appear anywhere in the serialised body.
$version = get_file_data( '/wordpress/wp-content/plugins/kntnt-extractor/kntnt-extractor.php', [ 'Version' => 'Version' ] )['Version'];
$body = (string) wp_json_encode( $response->get_data() );
kntnt_extractor_assert( $version !== '' && ! str_contains( $body, $version ), 'GET /status body omits the plugin release version' );

// An authenticated administrator additionally learns who they are and that they
// hold both composing capabilities (ADR-0012).
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );
$authenticated = $get_status()->get_data();
kntnt_extractor_assert( is_array( $authenticated ) && ( $authenticated['api_version'] ?? null ) === 6, 'The authenticated status response still carries the API version' );
kntnt_extractor_assert( is_array( $authenticated ) && ( $authenticated['authenticated_as'] ?? null ) === $admin->user_login, 'GET /status names the authenticated user by user_login' );
kntnt_extractor_assert(
	is_array( $authenticated ) && ( $authenticated['capabilities'] ?? null ) === [ 'kntnt_extractor_operate' => true, 'manage_options' => true ],
	'GET /status reports an administrator holding both capabilities',
);

// A user login that is itself an email address round-trips verbatim, since that
// is the shape the primary consumer's credential convention has to split.
$email_login = wp_insert_user( [ 'user_login' => 'status@example.com', 'user_pass' => wp_generate_password(), 'user_email' => 'status@example.com', 'role' => 'subscriber' ] );
wp_set_current_user( is_int( $email_login ) ? $email_login : 0 );
$subscriber_status = $get_status()->get_data();
kntnt_extractor_assert( is_array( $subscriber_status ) && ( $subscriber_status['authenticated_as'] ?? null ) === 'status@example.com', 'GET /status reports an email-shaped user_login verbatim' );

// The capability report is the caller's own, not the administrator's: a user
// holding neither capability is told so, which is what separates "wrong user"
// from "missing capability" at the client.
kntnt_extractor_assert(
	is_array( $subscriber_status ) && ( $subscriber_status['capabilities'] ?? null ) === [ 'kntnt_extractor_operate' => false, 'manage_options' => false ],
	'GET /status reports a subscriber holding neither capability',
);

// Leave the suite state clean for later files.
wp_set_current_user( 0 );

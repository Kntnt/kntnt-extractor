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
 * It also pins the `honours` split ADR-0017 settles: the anonymous body still
 * carries nothing but `api_version`, and an authenticated caller receives a
 * sorted, duplicate-free list of behaviour names distinct from the existing
 * `capabilities` member, which keeps reporting the caller's own two WordPress
 * capabilities unchanged.
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
// identity members and the honoured-capability list (ADR-0017) must never
// appear for a caller who supplied no credentials.
kntnt_extractor_assert( $response->get_data() === [ 'api_version' => 7 ], 'GET /status returns { api_version: 7 } and nothing more to an anonymous caller — no honours member either' );

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
kntnt_extractor_assert( is_array( $authenticated ) && ( $authenticated['api_version'] ?? null ) === 7, 'The authenticated status response still carries the API version' );
kntnt_extractor_assert( is_array( $authenticated ) && ( $authenticated['authenticated_as'] ?? null ) === $admin->user_login, 'GET /status names the authenticated user by user_login' );
kntnt_extractor_assert(
	is_array( $authenticated ) && ( $authenticated['capabilities'] ?? null ) === [ 'kntnt_extractor_operate' => true, 'manage_options' => true ],
	'GET /status reports an administrator holding both capabilities',
);

// The authenticated response also carries `honours` (ADR-0017): a list of
// strings, distinct from `capabilities`, naming what this build implements
// rather than what the caller may do.
$honours = $authenticated['honours'] ?? null;
kntnt_extractor_assert( is_array( $honours ) && $honours !== [] && array_is_list( $honours ), 'GET /status reports honours as a non-empty list of strings' );
kntnt_extractor_assert( is_array( $honours ) && array_reduce( $honours, static fn( bool $carry, mixed $name ): bool => $carry && is_string( $name ), true ), 'GET /status reports honours as a list of strings' );

// The list is sorted and free of duplicates — absence is the only signal a
// caller has, so a stray duplicate or an unsorted entry would be noise.
kntnt_extractor_assert( is_array( $honours ) && $honours === array_unique( $honours ), 'GET /status reports honours free of duplicates' );
$sorted_honours = $honours;
sort( $sorted_honours, SORT_STRING );
kntnt_extractor_assert( is_array( $honours ) && $honours === $sorted_honours, 'GET /status reports honours sorted' );

// `strict` — the behaviour that motivated the whole split, since no version
// number distinguishes a build that honours it from one that does not.
kntnt_extractor_assert( is_array( $honours ) && in_array( 'strict', $honours, true ), 'GET /status reports honours naming strict' );

// `disclosure` — the define-disclosure discriminator GET /environment always
// carries (ADR-0018). It ships alongside a coordinated api_version bump rather
// than silently, but is still named here like every other honoured behaviour,
// so a caller can check for it the same way.
kntnt_extractor_assert( is_array( $honours ) && in_array( 'disclosure', $honours, true ), 'GET /status reports honours naming disclosure' );

// `state` — GET /extractions' optional filter that additionally admits the
// caller's own terminal jobs (ADR-0019), announced here rather than through a
// version bump since the parameter is absent-by-default and additive.
kntnt_extractor_assert( is_array( $honours ) && in_array( 'state', $honours, true ), 'GET /status reports honours naming state' );

// `chunk_size` — POST /extractions' optional per-run file-part budget (issue
// #28). The value that lets a long extraction survive is host-specific, and a
// client that cannot see this name has to fall back to whatever the site's own
// constant happens to be.
kntnt_extractor_assert( is_array( $honours ) && in_array( 'chunk_size', $honours, true ), 'GET /status reports honours naming chunk_size' );

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

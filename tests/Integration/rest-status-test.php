<?php
/**
 * Integration test: GET /status through the live REST server.
 *
 * The headline walking-skeleton test. Dispatches the unauthenticated status
 * request the way a real client's HTTP call would reach it, and checks the
 * whole contract: an anonymous 200, the exact API-version body, the namespace's
 * presence in the REST index, and the absence of any plugin release-version leak.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Dispatch the status request through the live REST server, unauthenticated.
$server = rest_get_server();
$response = $server->dispatch( new WP_REST_Request( 'GET', '/kntnt-extractor/v1/status' ) );

// The endpoint answers 200 without authentication.
kntnt_extractor_assert( $response->get_status() === 200, 'GET /status responds 200 without authentication' );

// The body is exactly the API-version contract and nothing more.
kntnt_extractor_assert( $response->get_data() === [ 'api_version' => 3 ], 'GET /status returns { api_version: 3 }' );

// The namespace is advertised in WordPress's REST index.
$index = $server->dispatch( new WP_REST_Request( 'GET', '/' ) )->get_data();
kntnt_extractor_assert( in_array( 'kntnt-extractor/v1', $index['namespaces'], true ), 'kntnt-extractor/v1 appears in the REST index' );

// The response leaks no plugin release version: the plugin's own Version header
// string must not appear anywhere in the serialised body.
$version = get_file_data( '/wordpress/wp-content/plugins/kntnt-extractor/kntnt-extractor.php', [ 'Version' => 'Version' ] )['Version'];
$body = (string) wp_json_encode( $response->get_data() );
kntnt_extractor_assert( $version !== '' && ! str_contains( $body, $version ), 'GET /status body omits the plugin release version' );

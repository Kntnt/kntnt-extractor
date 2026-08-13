<?php
/**
 * Integration test: no response in the Extractor namespace may be cached.
 *
 * The guarantee from ADR-0012. Every response the plugin gives depends on who
 * asked, and the one a cache must never keep is the refusal WordPress does not
 * protect: a request whose credentials resolved to no user is treated as
 * anonymous, so core sends no no-cache headers of its own and a page cache in
 * front of the site stores the 401 against the URL and replays it to every later
 * caller — including one presenting correct credentials.
 *
 * The seam is `rest_post_dispatch`, the filter core applies to every response in
 * the namespace, error responses included, immediately before sending it. The
 * checks below dispatch through the live REST server and then run the result
 * through that filter exactly as `WP_REST_Server::serve_request()` does, so the
 * headers are asserted where a real client would receive them.
 *
 * @package Kntnt\Extractor
 * @since   0.4.0
 */

declare( strict_types = 1 );

$server = rest_get_server();

/**
 * Dispatches a route and applies the response filter core applies before sending.
 *
 * @param string $route The REST route to dispatch.
 * @return WP_REST_Response The response as the client would receive it.
 */
$serve = static function ( string $route ) use ( $server ): WP_REST_Response {
	$request = new WP_REST_Request( 'GET', $route );
	return apply_filters( 'rest_post_dispatch', $server->dispatch( $request ), $server, $request );
};

/**
 * Reports whether a response carries the full no-cache contract.
 *
 * @param WP_REST_Response $response The response to inspect.
 * @return bool True when both headers hold their contracted values.
 */
$is_uncacheable = static function ( WP_REST_Response $response ): bool {
	$headers = $response->get_headers();
	return ( $headers['Cache-Control'] ?? null ) === 'no-store, no-cache, must-revalidate, max-age=0'
		&& ( $headers['Vary'] ?? null ) === 'Authorization';
};

// Record every reason LiteSpeed is told, so the control-API call can be asserted
// rather than assumed — it is the half of the contract no header shows.
$litespeed_reasons = [];
$capture_reason = static function ( string $reason = '' ) use ( &$litespeed_reasons ): void {
	$litespeed_reasons[] = $reason;
};
add_action( 'litespeed_control_set_nocache', $capture_reason );

// The unauthenticated handshake is uncacheable even though it needs no
// credentials: it now reports identity, so its body varies by caller.
wp_set_current_user( 0 );
kntnt_extractor_assert( $is_uncacheable( $serve( '/kntnt-extractor/v1/status' ) ), 'GET /status is marked uncacheable' );

// The headline case: the refusal an anonymous caller earns carries the headers
// too. This is the response that looks cacheable to every cache and to core.
$refusal = $serve( '/kntnt-extractor/v1/tables' );
kntnt_extractor_assert( $refusal->get_status() === 401, 'An anonymous GET /tables is refused 401 (ADR-0012)' );
kntnt_extractor_assert( $is_uncacheable( $refusal ), 'The anonymous refusal is marked uncacheable, not only the authorized payload' );

// A route inside the namespace that matches nothing is covered as well, so no
// endpoint can be added — or mistyped — outside the guarantee.
$unmatched = $serve( '/kntnt-extractor/v1/no-such-endpoint' );
kntnt_extractor_assert( $unmatched->get_status() === 404, 'An unmatched route inside the namespace is a 404' );
kntnt_extractor_assert( $is_uncacheable( $unmatched ), 'An unmatched route inside the namespace is marked uncacheable' );

// An authorized 200 is equally uncacheable: the payload is the caller's data.
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
wp_set_current_user( $admin->ID );
$authorized = $serve( '/kntnt-extractor/v1/status' );
kntnt_extractor_assert( $is_uncacheable( $authorized ), 'An authorized response is marked uncacheable' );

// LiteSpeed is told separately through its own control API, because its page
// cache decides there rather than on the response headers.
kntnt_extractor_assert( count( $litespeed_reasons ) >= 4, 'Every marked response also fires litespeed_control_set_nocache' );
kntnt_extractor_assert( ( $litespeed_reasons[0] ?? '' ) !== '', 'The LiteSpeed no-cache call carries a reason' );

// The plugin decides cacheability for its own namespace only: another
// namespace's response passes through untouched.
$foreign = $serve( '/wp/v2/types' );
$foreign_headers = $foreign->get_headers();
kntnt_extractor_assert( ! isset( $foreign_headers['Cache-Control'] ) && ! isset( $foreign_headers['Vary'] ), 'A response outside the namespace is left alone' );

// Leave the suite state clean for later files.
remove_action( 'litespeed_control_set_nocache', $capture_reason );
wp_set_current_user( 0 );

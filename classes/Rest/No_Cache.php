<?php
/**
 * The namespace-wide seam that forbids caching of any Extractor REST response.
 *
 * @package Kntnt\Extractor
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Marks every response in the `kntnt-extractor/v1` namespace as uncacheable.
 *
 * Every answer this plugin gives depends on who asked — the payload endpoints
 * obviously, but the refusals just as much, and those are the dangerous ones
 * (ADR-0012). WordPress sends its own no-cache headers only for a request it
 * considers authenticated, so a refusal that resolved to no user at all — a
 * stripped `Authorization` header, an unknown login, a wrong application
 * password — goes out looking exactly like a cacheable anonymous error. A page
 * cache in front of the site then stores that 401/403 against the URL and
 * replays it to every later caller, including one presenting perfect
 * credentials: a single failed attempt becomes a lasting lockout that no
 * amount of credential fixing clears.
 *
 * The guarantee is therefore unconditional and applied at one seam —
 * `rest_post_dispatch`, the last filter every response in the namespace passes
 * through, error responses included — rather than per controller, where a later
 * endpoint could forget it. Both the standard headers and LiteSpeed's own
 * control API are used: the page cache that produced the lockout this class
 * exists to prevent honours the latter and not always the former.
 *
 * @since 0.4.0
 */
final class No_Cache {

	/**
	 * The `Cache-Control` value every Extractor response carries.
	 *
	 * Names every mechanism that could retain the response: `no-store` forbids
	 * writing it down at all, `no-cache` and `must-revalidate` forbid serving any
	 * copy that was written anyway, and `max-age=0` leaves no freshness window a
	 * heuristic could stretch.
	 *
	 * @since 0.4.0
	 */
	private const string CACHE_CONTROL = 'no-store, no-cache, must-revalidate, max-age=0';

	/**
	 * The reason string reported to LiteSpeed when a response is marked uncacheable.
	 *
	 * LiteSpeed records it in its own debug log, so it is written for the person
	 * reading that log while diagnosing a stale response.
	 *
	 * @since 0.4.0
	 */
	private const string LITESPEED_REASON = 'Kntnt Extractor REST response depends on the caller identity';

	/**
	 * Forbids caching of an Extractor response. Filters `rest_post_dispatch`.
	 *
	 * Applies to every route in the namespace regardless of the request's
	 * authentication state, status code, or whether the route matched at all —
	 * the anonymous-looking refusal is precisely the response a cache must not
	 * keep. Responses belonging to any other namespace pass through untouched.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed           $response The response the REST server is about to send.
	 * @param WP_REST_Server  $server   The REST server answering; part of the filter
	 *                                  signature and not used here.
	 * @param WP_REST_Request $request  The request being answered.
	 * @return mixed The response, carrying the cache headers when it is ours.
	 */
	public function forbid_caching( mixed $response, WP_REST_Server $server, WP_REST_Request $request ): mixed {

		// Step aside for another namespace's response, and for anything an earlier
		// filter replaced the response object with — this is a shared core hook.
		if ( ! $response instanceof WP_REST_Response || ! $this->is_own_route( $request ) ) {
			return $response;
		}

		// Forbid every cache — shared, private, and revalidating — from retaining the
		// response, and key whatever ignores that on the credentials it was made with.
		$response->header( 'Cache-Control', self::CACHE_CONTROL );
		$response->header( 'Vary', 'Authorization' );

		// Tell LiteSpeed in its own terms as well: its page cache decides through this
		// control API rather than the response headers, and it is the cache that turned
		// one failed authentication into a lockout for every later caller.
		do_action( 'litespeed_control_set_nocache', self::LITESPEED_REASON );

		return $response;

	}

	/**
	 * Decides whether a request is addressed to this plugin's REST namespace.
	 *
	 * Matches the namespace itself and everything below it, but not a namespace
	 * that merely starts with the same characters, so a future `kntnt-extractor/v10`
	 * is not swept up by the `v1` test.
	 *
	 * @since 0.4.0
	 *
	 * @param WP_REST_Request $request The request being answered.
	 * @return bool True when the route belongs to `kntnt-extractor/v1`.
	 */
	private function is_own_route( WP_REST_Request $request ): bool {

		// Compare on the leading-slash-free form; the route is `/namespace/…`.
		$route = ltrim( $request->get_route(), '/' );

		return $route === Status_Controller::REST_NAMESPACE
			|| str_starts_with( $route, Status_Controller::REST_NAMESPACE . '/' );

	}

}

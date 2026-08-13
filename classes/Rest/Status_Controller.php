<?php
/**
 * REST controller for the unauthenticated status endpoint.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Rest;

use Kntnt\Extractor\Authorizer;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and answers `GET /kntnt-extractor/v1/status`.
 *
 * The status endpoint is deliberately public and unauthenticated: it lets a
 * caller read the REST contract's API version and decide whether it can drive
 * this installation before attempting anything that needs credentials. The
 * response carries only the API version, never the plugin's release version —
 * the two are distinct by design (see docs/adr/0005 and CONTEXT.md).
 *
 * It is also the endpoint that answers "who am I here?". Credentials are
 * optional but not ignored: when they arrive and resolve to a WordPress user,
 * the response additionally reports that user's login and which of the two
 * composing capabilities it holds, turning a diagnosis that used to be inferred
 * from the pattern of errors across other endpoints into a direct answer
 * (ADR-0012). The unauthenticated handshake is unchanged and stays reachable
 * without credentials, since every client depends on it.
 *
 * @since 0.1.0
 */
final class Status_Controller {

	/**
	 * The REST namespace this plugin owns, including its contract version.
	 *
	 * @since 0.1.0
	 */
	public const string REST_NAMESPACE = 'kntnt-extractor/v1';

	/**
	 * The REST contract's own version, distinct from the plugin release version.
	 *
	 * Increments only when caller-visible behaviour changes, including a purely
	 * behavioural change with no signature change; a bug fix that leaves the
	 * contract as callers already understood it does not bump it (ADR-0005).
	 *
	 * Public because it is the single source of truth for the contract version: the
	 * audit log stamps the same value into every record it writes (ADR-0006).
	 *
	 * Raised to 2 for the structure-only cutover trio (issues #15/#16/#17): the
	 * `tables_structure_only` request field and the structure-only segments it seals
	 * are a caller-visible change to the extraction contract (ADR-0005). The sibling
	 * tickets ship under this one coordinated bump rather than one bump each.
	 *
	 * Raised to 3 for the restricted-path deny-list (issue #21, ADR-0011):
	 * `POST /extractions` now rejects a selection naming a credential-bearing path
	 * with a new `kntnt_extractor_restricted_path` 422, a caller-visible behaviour
	 * change even though no request field changed shape.
	 *
	 * Raised to 4 for the identity-diagnosis change (ADR-0012): this endpoint now
	 * reports the authenticated caller's login and capabilities alongside the
	 * version, and the shared authorization gate answers 401
	 * `kntnt_extractor_not_authenticated` or a 403 naming the missing capability in
	 * place of the single `kntnt_extractor_forbidden` it used to return. Both are
	 * caller-visible and ship under one coordinated bump.
	 *
	 * Raised to 5 for chunked table dumping (ADR-0013): a table now appears in the
	 * sealed index once per slice rather than once, so a reader must concatenate every
	 * segment carrying a name — the rule file parts already required — rather than
	 * expecting one segment per table. The artifact's shape is part of the contract.
	 *
	 * @since 0.1.0
	 */
	public const int API_VERSION = 5;

	/**
	 * Registers the status route. Hooked on `rest_api_init`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => $this->get_status( ... ),
				'permission_callback' => '__return_true',
			],
		);

	}

	/**
	 * Returns the API version, plus the caller's identity when there is one.
	 *
	 * An anonymous caller receives exactly the historical handshake and nothing
	 * more — `{ "api_version": <int> }` — so the contract every existing client
	 * reads is untouched. A request whose credentials resolved to a WordPress user
	 * receives two further members: `authenticated_as`, the user's `user_login`,
	 * and `capabilities`, a map of each of the plugin's two composing capabilities
	 * to whether that user holds it. That is the whole identity question answered
	 * in one unauthenticated-by-default call (ADR-0012), so a caller meeting a
	 * refusal elsewhere can tell "my credentials never arrived" from "they arrived
	 * as somebody who may not do this" without probing other endpoints.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response The API version, and the caller's login and
	 *                          capabilities when a user is authenticated.
	 */
	public function get_status(): WP_REST_Response {

		// The version handshake is the entire contract for an anonymous caller.
		$status = [ 'api_version' => self::API_VERSION ];

		// Answer the identity question outright for an authenticated caller.
		if ( is_user_logged_in() ) {
			$status['authenticated_as'] = wp_get_current_user()->user_login;
			$status['capabilities'] = [
				Authorizer::OPERATE_CAPABILITY => current_user_can( Authorizer::OPERATE_CAPABILITY ),
				Authorizer::MANAGE_CAPABILITY => current_user_can( Authorizer::MANAGE_CAPABILITY ),
			];
		}

		return new WP_REST_Response( $status );

	}

}

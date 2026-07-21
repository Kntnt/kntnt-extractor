<?php
/**
 * REST controller for the unauthenticated status endpoint.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Rest;

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
	 * @since 0.1.0
	 */
	public const int API_VERSION = 1;

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
	 * Returns the API version. Public and unauthenticated by contract.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response The API version, as `{ "api_version": <int> }`.
	 */
	public function get_status(): WP_REST_Response {
		return new WP_REST_Response( [ 'api_version' => self::API_VERSION ] );
	}

}

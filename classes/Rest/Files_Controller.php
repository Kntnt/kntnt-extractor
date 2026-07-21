<?php
/**
 * REST controller for the authorized recursive-file-Manifest endpoint.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Rest;

use InvalidArgumentException;
use Kntnt\Extractor\Authorizer;
use Kntnt\Extractor\Config;
use Kntnt\Extractor\Manifest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and answers `GET /kntnt-extractor/v1/files`.
 *
 * Returns the recursive Manifest — path, size and mtime for every file from the
 * WordPress installation root downward, reported exactly as it exists with no
 * categorisation of what any file is for (ADR-0003). The listing is complete but
 * paged through an opaque, path-ordered cursor the caller loops over to
 * exhaustion, which bounds each response's memory and time on a large install.
 * Access is gated by the shared Authorizer, so only a caller holding both the
 * Operate capability and `manage_options` reaches the data; everyone else is
 * refused with 403.
 *
 * @since 0.1.0
 */
final class Files_Controller {

	/**
	 * Entries per page when neither the constant nor the filter overrides it.
	 *
	 * The page size is resolved through the Config seam under the knob name
	 * `files_page_size`, so a site sets it with the `KNTNT_EXTRACTOR_FILES_PAGE_SIZE`
	 * constant or the `kntnt_extractor_config_files_page_size` filter; this is only
	 * the fallback when neither is present.
	 *
	 * @since 0.1.0
	 */
	private const int DEFAULT_PAGE_SIZE = 1000;

	/**
	 * Wires the controller to the shared authorization gate and the Config seam.
	 *
	 * @since 0.1.0
	 *
	 * @param Authorizer $authorizer The shared both-capabilities access gate.
	 * @param Config     $config     The constant-then-filter configuration seam.
	 */
	public function __construct(
		private readonly Authorizer $authorizer,
		private readonly Config $config,
	) {}

	/**
	 * Registers the files route. Hooked on `rest_api_init`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			Status_Controller::REST_NAMESPACE,
			'/files',
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => $this->get_files( ... ),
				'permission_callback' => $this->authorizer->authorize( ... ),
				'args' => [
					'cursor' => [
						'type' => 'string',
						'required' => false,
						'sanitize_callback' => 'sanitize_text_field',
						'description' => __( 'Opaque pagination cursor from a previous page; omit it for the first page.', 'kntnt-extractor' ),
					],
				],
			],
		);

	}

	/**
	 * Returns one page of the Manifest, resuming after the caller's cursor.
	 *
	 * The page size comes from the Config seam and is clamped to at least one, so a
	 * misconfigured non-positive value cannot stall a client with an empty page
	 * that still reports more to come. The Manifest is rooted at the installation
	 * root resolved to a real path, so reported paths derive from a stable prefix.
	 * A malformed cursor is answered with 400 rather than a silent restart or a 500.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming request, carrying an optional cursor.
	 * @return WP_REST_Response|WP_Error The page as `{ "files": [ { path, size, mtime } ], "cursor": <token|null> }`,
	 *                                   or a 400 error for a malformed cursor.
	 */
	public function get_files( WP_REST_Request $request ): WP_REST_Response|WP_Error {

		// Resolve the page size through the Config seam and clamp it to at least one.
		// A non-numeric override is ignored in favour of the default rather than
		// coerced to a meaningless zero.
		$configured = $this->config->get( 'files_page_size', self::DEFAULT_PAGE_SIZE );
		$page_size = max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_PAGE_SIZE );

		// Root the Manifest at the installation root, resolved to a canonical real
		// path; fall back to ABSPATH itself only if it cannot be resolved.
		$root = realpath( ABSPATH );
		$manifest = new Manifest( $root === false ? ABSPATH : $root, $page_size );

		// Resume after the caller's cursor, or start fresh when none is given. A
		// malformed cursor is a client error at an untrusted boundary — answer 400.
		$cursor = $request->get_param( 'cursor' );
		try {
			$page = $manifest->page( is_string( $cursor ) && $cursor !== '' ? $cursor : null );
		} catch ( InvalidArgumentException ) {
			return new WP_Error(
				'kntnt_extractor_invalid_cursor',
				__( 'The pagination cursor is malformed.', 'kntnt-extractor' ),
				[ 'status' => 400 ],
			);
		}

		return new WP_REST_Response( $page );

	}

}

<?php
/**
 * REST controller for the administrator-only audit-log endpoint.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Rest;

use Kntnt\Extractor\Audit_Log;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Registers and answers `GET /kntnt-extractor/v1/audit-log`.
 *
 * This is the only sanctioned read path for the audit log (ADR-0006). Unlike the
 * data endpoints, it is gated on `manage_options` alone, not the Operate capability:
 * an administrator must always be able to read what was extracted, even from an
 * installation whose Operate switch has since been revoked. The audit trail is
 * deliberately not filtered per user — it shows every user's actions, since a log
 * that only showed the reader their own would not serve its purpose.
 *
 * @since 0.1.0
 */
final class Audit_Log_Controller {

	/**
	 * The default page size when the caller does not ask for one.
	 *
	 * @since 0.1.0
	 */
	private const int DEFAULT_PER_PAGE = 50;

	/**
	 * The largest page size a caller may request.
	 *
	 * @since 0.1.0
	 */
	private const int MAX_PER_PAGE = 200;

	/**
	 * Binds the controller to the audit log it reads through.
	 *
	 * @since 0.1.0
	 *
	 * @param Audit_Log $audit_log The audit log subsystem.
	 */
	public function __construct(
		private readonly Audit_Log $audit_log,
	) {}

	/**
	 * Registers the audit-log route. Hooked on `rest_api_init`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {

		register_rest_route(
			Status_Controller::REST_NAMESPACE,
			'/audit-log',
			[
				'methods' => WP_REST_Server::READABLE,
				'callback' => $this->get_log( ... ),
				'permission_callback' => $this->authorize( ... ),
				'args' => [
					'from' => [
						'type' => 'string',
						'required' => false,
						'description' => __( 'Inclusive lower date bound (YYYY-MM-DD).', 'kntnt-extractor' ),
					],
					'to' => [
						'type' => 'string',
						'required' => false,
						'description' => __( 'Inclusive upper date bound (YYYY-MM-DD).', 'kntnt-extractor' ),
					],
					'page' => [
						'type' => 'integer',
						'required' => false,
						'default' => 1,
						'minimum' => 1,
					],
					'per_page' => [
						'type' => 'integer',
						'required' => false,
						'default' => self::DEFAULT_PER_PAGE,
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
					],
				],
			],
		);

	}

	/**
	 * Authorises the reader: an administrator, and nothing less.
	 *
	 * Gated on `manage_options` alone (ADR-0006), so the endpoint answers 401 to an
	 * anonymous caller and 403 to a signed-in non-administrator, through the standard
	 * REST authorization code.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error True when the caller may read the log, or an error otherwise.
	 */
	public function authorize(): bool|WP_Error {

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'kntnt_extractor_forbidden',
			__( 'You are not allowed to read the extraction audit log.', 'kntnt-extractor' ),
			[ 'status' => rest_authorization_required_code() ],
		);

	}

	/**
	 * Answers the audit-log read, newest-first, filtered and paginated.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming audit-log read request.
	 * @return WP_REST_Response The recorded entries and their paging metadata.
	 */
	public function get_log( WP_REST_Request $request ): WP_REST_Response {

		// Resolve the optional date window and the page bounds from the request, then
		// hand off to the audit log, which rotates, filters, orders, and slices.
		$from = $request->get_param( 'from' );
		$to = $request->get_param( 'to' );
		$page_param = $request->get_param( 'page' );
		$per_page_param = $request->get_param( 'per_page' );
		$page = max( 1, is_numeric( $page_param ) ? (int) $page_param : 1 );
		$per_page = min( self::MAX_PER_PAGE, max( 1, is_numeric( $per_page_param ) ? (int) $per_page_param : self::DEFAULT_PER_PAGE ) );

		$result = $this->audit_log->entries(
			is_string( $from ) && $from !== '' ? $from : null,
			is_string( $to ) && $to !== '' ? $to : null,
			$page,
			$per_page,
		);

		return new WP_REST_Response( $result );

	}

}

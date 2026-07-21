<?php
/**
 * The both-capabilities authorization seam shared by every data endpoint.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use WP_Error;

/**
 * Enforces the plugin's two-capability access rule.
 *
 * A data request is authorized only when the current user holds BOTH the
 * plugin's own Operate capability — the dormancy on-switch that keeps the
 * runtime surface inert until deliberately granted (ADR-0001) — AND the
 * administrator capability `manage_options` that authorizes the data itself
 * (ADR-0002). The two compose: Operate opens the door, `manage_options`
 * authorizes the payload, and neither alone is enough. This is the single seam
 * every later listing and extraction endpoint reuses as its permission
 * callback, so the rule lives in exactly one place.
 *
 * @since 0.1.0
 */
final class Authorizer {

	/**
	 * The plugin-defined capability that gates access to the REST API at all.
	 *
	 * Registered and granted to the administrator role on activation; revoking
	 * it is how a site owner switches the API off without touching any user's
	 * administrator role. Public because it is the plugin's published access
	 * contract — activation and the test suite both name it.
	 *
	 * @since 0.1.0
	 */
	public const string OPERATE_CAPABILITY = 'kntnt_extractor_operate';

	/**
	 * The core administrator capability that authorizes the data itself.
	 *
	 * @since 0.1.0
	 */
	private const string MANAGE_CAPABILITY = 'manage_options';

	/**
	 * Permission callback: admits only a caller holding both capabilities.
	 *
	 * Returns an explicit 403 error — not `false` — for a refused caller, so a
	 * missing capability always yields 403 and never the 401 WordPress would
	 * otherwise substitute for an anonymous request. `/tables` carries no
	 * caller-supplied resource, so there is nothing to validate before this
	 * check (ADR-0003); an endpoint that does take a resource validates it in
	 * its own argument schema, which WordPress runs before the permission
	 * callback.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error True when the current user is authorized; a 403
	 *                       WP_Error otherwise.
	 */
	public function authorize(): true|WP_Error {

		// Admit only a caller holding both the dormancy on-switch and the
		// administrator data gate.
		if ( current_user_can( self::OPERATE_CAPABILITY ) && current_user_can( self::MANAGE_CAPABILITY ) ) {
			return true;
		}

		// Refuse everyone else with an explicit 403.
		return new WP_Error(
			'kntnt_extractor_forbidden',
			__( 'You are not allowed to use the Kntnt Extractor API.', 'kntnt-extractor' ),
			[ 'status' => 403 ],
		);

	}

}

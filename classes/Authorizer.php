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
	 * Public for the same reason as the Operate capability: it is half of the
	 * plugin's published access contract, and `GET /status` reports whether the
	 * authenticated caller holds it (ADR-0012).
	 *
	 * @since 0.1.0
	 */
	public const string MANAGE_CAPABILITY = 'manage_options';

	/**
	 * Permission callback: admits only a caller holding both capabilities.
	 *
	 * Returns an explicit WP_Error — not `false` — for a refused caller, and each
	 * refusal names its own cause rather than collapsing into one opaque code.
	 * The distinction is operational, not cosmetic: a request that resolved to no
	 * user at all answers 401 `kntnt_extractor_not_authenticated`, while an
	 * authenticated caller missing either half of the composition answers 403
	 * naming the missing capability. Conflating the two is what made a stripped
	 * `Authorization` header, an unknown login, and a cached error response
	 * indistinguishable from a genuinely under-privileged user (ADR-0012).
	 *
	 * `/tables` carries no caller-supplied resource, so there is nothing to
	 * validate before this check (ADR-0003); an endpoint that does take a resource
	 * validates it in its own argument schema, which WordPress runs before the
	 * permission callback.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error True when the current user is authorized; a 401 or 403
	 *                       WP_Error naming the specific refusal otherwise.
	 */
	public function authorize(): true|WP_Error {

		// Admit only a caller holding both the dormancy on-switch and the
		// administrator data gate.
		if ( current_user_can( self::OPERATE_CAPABILITY ) && current_user_can( self::MANAGE_CAPABILITY ) ) {
			return true;
		}

		// Report a request that resolved to no user as unauthenticated, so a caller
		// learns its credentials never landed instead of inferring a capability it
		// may well hold. `GET /status` answers the follow-up question of who did
		// authenticate, if anyone.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'kntnt_extractor_not_authenticated',
				__( 'No WordPress user is authenticated for this request. Send an application password for a user holding both the kntnt_extractor_operate and manage_options capabilities.', 'kntnt-extractor' ),
				[ 'status' => 401 ],
			);
		}

		// Name the missing half of the composition for an authenticated caller,
		// starting with the dormancy on-switch — the grant a site owner revokes.
		if ( ! current_user_can( self::OPERATE_CAPABILITY ) ) {
			return new WP_Error(
				'kntnt_extractor_missing_operate_capability',
				__( 'Your WordPress user does not hold the kntnt_extractor_operate capability that the Kntnt Extractor API requires.', 'kntnt-extractor' ),
				[ 'status' => 403 ],
			);
		}

		// The only refusal left: an authenticated caller holding the on-switch but
		// not the administrator capability that authorizes the data itself.
		return new WP_Error(
			'kntnt_extractor_missing_manage_capability',
			__( 'Your WordPress user does not hold the manage_options capability that authorizes the data the Kntnt Extractor API returns.', 'kntnt-extractor' ),
			[ 'status' => 403 ],
		);

	}

}

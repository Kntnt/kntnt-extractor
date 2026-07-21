<?php
/**
 * Activation and deactivation lifecycle: the Operate capability grant.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * Switches the plugin's dormancy on and off through the Operate capability.
 *
 * Activation grants the Operate capability to the administrator role — the
 * plugin's only persistent footprint and the reason its runtime surface stays
 * inert until deliberately switched on (ADR-0001/0002). Deactivation removes
 * the grant. Because activation re-runs the grant unconditionally, deactivating
 * and reactivating restores a grant lost by any means; that round trip is the
 * only sanctioned recovery, and it is sufficient precisely because the plugin
 * leaves behind no dedicated account that could be accidentally deleted.
 *
 * @since 0.1.0
 */
final class Installer {

	/**
	 * Grants the Operate capability to the administrator role.
	 *
	 * Idempotent: re-running it on an already-granted role is a no-op, which is
	 * what makes reactivation a safe recovery for a missing grant. Registered as
	 * the plugin's activation hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {

		// Grant the on-switch to administrators. The role is absent only before
		// WordPress has installed its default roles, which never holds here.
		get_role( 'administrator' )?->add_cap( Authorizer::OPERATE_CAPABILITY );

	}

	/**
	 * Removes the Operate capability from the administrator role.
	 *
	 * The off-switch: it cuts API access without touching any user's
	 * administrator role. Registered as the plugin's deactivation hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {

		get_role( 'administrator' )?->remove_cap( Authorizer::OPERATE_CAPABILITY );

	}

}

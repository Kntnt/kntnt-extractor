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
 * Switches the plugin's dormancy on and off, and schedules its TTL sweep.
 *
 * Activation grants the Operate capability to the administrator role — the
 * plugin's only persistent footprint and the reason its runtime surface stays
 * inert until deliberately switched on (ADR-0001/0002) — and schedules the
 * recurring TTL sweep that reclaims never-consumed jobs (ADR-0004). Deactivation
 * removes the grant and clears the sweep. Because activation re-runs the grant
 * unconditionally, deactivating and reactivating restores a grant lost by any
 * means; that round trip is the only sanctioned recovery, and it is sufficient
 * precisely because the plugin leaves behind no dedicated account that could be
 * accidentally deleted.
 *
 * @since 0.1.0
 */
final class Installer {

	/**
	 * Grants the Operate capability and schedules the recurring TTL sweep.
	 *
	 * The grant is idempotent: re-running it on an already-granted role is a no-op,
	 * which is what makes reactivation a safe recovery for a missing grant. The sweep
	 * is scheduled only when it is not already pending, so reactivation never stacks a
	 * second event. Registered as the plugin's activation hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {

		// Grant the on-switch to administrators. The role is absent only before
		// WordPress has installed its default roles, which never holds here.
		get_role( 'administrator' )?->add_cap( Authorizer::OPERATE_CAPABILITY );

		// Schedule the TTL sweep backstop (ADR-0004) unless it is already pending, so a
		// never-consumed artifact is reclaimed even when no caller ever confirms.
		if ( wp_next_scheduled( Sweeper::SWEEP_HOOK ) === false ) {
			wp_schedule_event( time(), 'hourly', Sweeper::SWEEP_HOOK );
		}

	}

	/**
	 * Removes the Operate capability and clears the recurring TTL sweep.
	 *
	 * The off-switch: it cuts API access without touching any user's administrator
	 * role, and stops the sweep that reactivation reschedules. Registered as the
	 * plugin's deactivation hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {

		// Revoke the on-switch and stop the recurring sweep; reactivation restores both.
		get_role( 'administrator' )?->remove_cap( Authorizer::OPERATE_CAPABILITY );
		wp_clear_scheduled_hook( Sweeper::SWEEP_HOOK );

	}

}

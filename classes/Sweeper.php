<?php
/**
 * The time-to-live sweep: the backstop that reclaims never-consumed jobs.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * Expires every job that has outlived the time-to-live without being consumed.
 *
 * A caller is meant to consume a ready artifact and have the server delete it
 * (ADR-0004); this sweep is the backstop for the caller that never confirms. It
 * walks the live job set and, for each non-terminal job whose heartbeat has been
 * silent longer than the TTL, deletes the artifact and the working directory and
 * records the job expired — the same irreversible cleanup consume and cancel reach
 * through {@see Job_Store::purge()}.
 *
 * Measuring staleness from the job's heartbeat rather than its creation is
 * deliberate: a ready artifact's clock starts when it became ready, and a job still
 * being ticked keeps a fresh heartbeat, so an actively-running extraction is never
 * swept out from under its own driver while a stalled or long-unconsumed one is.
 *
 * The TTL is a Config knob (ADR-0004): the constant `KNTNT_EXTRACTOR_TTL` or the
 * `kntnt_extractor_config_ttl` filter, defaulting to the value below. The sweep is
 * answered on a recurring schedule the {@see Installer} registers against
 * {@see SWEEP_HOOK}.
 *
 * @since 0.1.0
 */
final class Sweeper {

	/**
	 * The cron hook the recurring sweep is scheduled against.
	 *
	 * Public because both ends name it: the Installer schedules and clears the event,
	 * and the Plugin binds this sweep to it.
	 *
	 * @since 0.1.0
	 */
	public const string SWEEP_HOOK = 'kntnt_extractor_sweep';

	/**
	 * Seconds a job may go without a heartbeat before the sweep expires it.
	 *
	 * Resolved through the Config seam under the knob `ttl`, so a site tunes it with
	 * the `KNTNT_EXTRACTOR_TTL` constant or the `kntnt_extractor_config_ttl` filter.
	 * This is only the fallback when neither is set — one hour, a short backstop for
	 * an artifact a caller fetched but never confirmed.
	 *
	 * @since 0.1.0
	 */
	private const int DEFAULT_TTL = 3600;

	/**
	 * Wires the sweep to the job store and the Config seam it reads the TTL through.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_Store $store  Persistence for Extraction jobs.
	 * @param Config    $config The constant-then-filter configuration seam.
	 */
	public function __construct(
		private readonly Job_Store $store,
		private readonly Config $config,
	) {}

	/**
	 * Expires and purges every non-terminal job whose heartbeat has gone stale.
	 *
	 * Returns the jobs it expired, each already moved into the expired state, so the
	 * caller — the cron event, or a test — can see exactly what was reclaimed. A job
	 * whose heartbeat is still within the TTL, and every already-terminal job, is left
	 * untouched.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, Extraction_Job> The jobs this sweep expired.
	 */
	public function sweep(): array {

		// Reclaim every non-terminal job that has been silent longer than the TTL:
		// delete its artifact and working directory, and record it as expired.
		$ttl = $this->ttl();
		$now = time();
		$expired = [];
		foreach ( $this->store->all() as $job ) {
			if ( ! $job->state->is_terminal() && ( $now - $job->updated_at ) > $ttl ) {
				$this->store->purge( $job );
				$expired[] = $job->with_state( Job_State::Expired );
			}
		}

		return $expired;

	}

	/**
	 * Answers the recurring cron event, running one sweep and discarding its result.
	 *
	 * The scheduled hook (see {@see SWEEP_HOOK}) needs a void callback; the reclaimed
	 * jobs {@see sweep()} returns are of use only to a programmatic caller, so this
	 * thin adapter runs the sweep for its effect alone and returns nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run(): void {

		$this->sweep();

	}

	/**
	 * Resolves the time-to-live through the Config seam, clamped to at least one second.
	 *
	 * A non-numeric or non-positive override cannot disable the backstop; the floor of
	 * one second keeps the sweep meaningful however the knob is misconfigured.
	 *
	 * @since 0.1.0
	 *
	 * @return int Seconds a job may be silent before the sweep expires it.
	 */
	private function ttl(): int {

		$configured = $this->config->get( 'ttl', self::DEFAULT_TTL );

		return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_TTL );

	}

}

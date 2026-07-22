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
 * The heartbeat window alone is not enough, though: the stall {@see Watchdog}
 * restarts an unfinished job every cron cycle and refreshes its heartbeat as it does,
 * so a chunk that dies uncatchably every attempt — an OOM or `max_execution_time`
 * kill the tick's `catch` can never intercept — would keep a fresh heartbeat forever
 * and never fall past this heartbeat window, leaving its partial dump on disk and
 * re-running its failing chunk without bound. So the sweep applies a SECOND,
 * absolute ceiling measured from the job's creation, independent of any heartbeat
 * refresh: an unfinished job that has outlived that ceiling is reclaimed regardless
 * of how recently the watchdog touched it, which is what bounds the retry loop and
 * purges the partial container the watchdog would otherwise retain indefinitely.
 *
 * Both thresholds are Config knobs (ADR-0004): the TTL heartbeat window through the
 * constant `KNTNT_EXTRACTOR_TTL` or the `kntnt_extractor_config_ttl` filter, and the
 * absolute ceiling through `KNTNT_EXTRACTOR_MAX_LIFETIME` or its filter — defaulting
 * to several TTLs and floored at the TTL so the ceiling can never be tighter than the
 * heartbeat window. The sweep is answered on a recurring schedule the {@see Installer}
 * registers against {@see SWEEP_HOOK}.
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
	 * How many TTLs an unfinished job may live from creation before the ceiling reclaims it.
	 *
	 * The default absolute lifetime ({@see max_lifetime()}) is this multiple of the
	 * resolved TTL, so it always sits safely above the heartbeat window and scales with
	 * a site that tunes the TTL. Six TTLs is a wide margin for a legitimate large
	 * extraction that advances one chunk per cron cycle, yet still bounds a job the
	 * watchdog keeps restarting forever.
	 *
	 * @since 0.1.0
	 */
	private const int MAX_LIFETIME_TTL_MULTIPLE = 6;

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
	 * caller — the cron event, or a test — can see exactly what was reclaimed. A
	 * non-terminal job is reclaimed when its heartbeat has been silent longer than the
	 * TTL, OR when it has outlived the absolute lifetime ceiling measured from creation;
	 * the second test is what catches a job the watchdog keeps restarting with a forever-
	 * fresh heartbeat. A job within both windows, and every already-terminal job, is
	 * left untouched.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, Extraction_Job> The jobs this sweep expired.
	 */
	public function sweep(): array {

		// Reclaim every non-terminal job that has either gone silent longer than the TTL
		// or outlived the absolute lifetime ceiling from creation: delete its artifact and
		// working directory, and record it as expired. The ceiling ignores heartbeat
		// refreshes, so an unfinished job the watchdog restarts forever is still bounded.
		$ttl = $this->ttl();
		$max_lifetime = $this->max_lifetime( $ttl );
		$now = time();
		$expired = [];
		foreach ( $this->store->all() as $job ) {
			if ( $job->state->is_terminal() ) {
				continue;
			}
			$silent = ( $now - $job->updated_at ) > $ttl;
			$outlived = ( $now - $job->created_at ) > $max_lifetime;
			if ( $silent || $outlived ) {
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

	/**
	 * Resolves the absolute lifetime ceiling through the Config seam, floored at the TTL.
	 *
	 * The knob `max_lifetime` (`KNTNT_EXTRACTOR_MAX_LIFETIME` or its filter) overrides the
	 * default of {@see MAX_LIFETIME_TTL_MULTIPLE} times the resolved TTL. The result is
	 * floored at the TTL so the absolute ceiling can never be tighter than the heartbeat
	 * window — a misconfiguration cannot turn it into a reaper that sweeps an actively
	 * running job the heartbeat window still protects.
	 *
	 * @since 0.1.0
	 *
	 * @param int $ttl The resolved heartbeat TTL, the ceiling's floor and default basis.
	 * @return int Seconds a job may live from creation before the ceiling reclaims it.
	 */
	private function max_lifetime( int $ttl ): int {

		$default = $ttl * self::MAX_LIFETIME_TTL_MULTIPLE;
		$configured = $this->config->get( 'max_lifetime', $default );

		return max( $ttl, is_numeric( $configured ) ? (int) $configured : $default );

	}

}

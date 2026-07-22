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
 * absolute ceiling. It is measured from the job's LAST PROGRESS — the timestamp the
 * build stamps only when a chunk actually advances ({@see Extraction_Job::with_progress()}),
 * falling back to the creation time for a job that has never progressed — not from raw
 * age, and it is immune to the heartbeat the watchdog refreshes on every restart. That
 * distinction is what lets it tell apart the two jobs a heartbeat cannot: a legitimately
 * large extraction advancing one chunk per cron cycle keeps its last-progress stamp
 * fresh and is spared however long it has existed, while a job whose chunk fails
 * uncatchably every attempt never advances, so its stamp freezes and the ceiling
 * reclaims it — bounding the retry loop and purging the partial container without ever
 * reaping a slow-but-progressing build.
 *
 * A live tick building a job through is the one actor that must never have its container
 * swept out from under it. The sweep therefore takes the same per-job tick lock a tick
 * holds ({@see Job_Store::lock()}) before purging, and defers a job whose lock it cannot
 * take to a later cycle — so the lock-free queue (ADR-0007) and the sweep never race the
 * same in-progress container.
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
	 * a site that tunes the TTL. Because the ceiling is measured from the last progress,
	 * not raw age, six TTLs is the window a build may go without advancing a single chunk
	 * before it counts as wedged — ample for a legitimate large extraction (which resets
	 * it on every chunk), yet still bounding a job whose chunk fails uncatchably forever.
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
	 * TTL, OR when its build has not advanced a chunk within the absolute lifetime
	 * ceiling; the second test catches a job whose chunk fails uncatchably every attempt
	 * while sparing one that is still progressing, however old. A job a live tick holds
	 * the lock on is deferred rather than purged, so an in-flight build is never deleted
	 * out from under itself. A job within both windows, and every already-terminal job,
	 * is left untouched.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, Extraction_Job> The jobs this sweep expired.
	 */
	public function sweep(): array {

		// Reclaim every non-terminal job that has either gone silent longer than the TTL
		// or not progressed a chunk within the absolute ceiling: delete its artifact and
		// working directory, and record it as expired. The ceiling is measured from the
		// last progress (falling back to creation for a job that never progressed) and
		// ignores heartbeat refreshes, so a job the watchdog restarts forever without it
		// ever advancing is bounded, while a slow-but-advancing large build is spared.
		$ttl = $this->ttl();
		$max_lifetime = $this->max_lifetime( $ttl );
		$now = time();
		$expired = [];
		foreach ( $this->store->all() as $job ) {
			if ( $job->state->is_terminal() ) {
				continue;
			}
			$silent = ( $now - $job->updated_at ) > $ttl;
			$outlived = ( $now - ( $job->progressed_at ?? $job->created_at ) ) > $max_lifetime;
			if ( ! ( $silent || $outlived ) ) {
				continue;
			}

			// Honour the tick lock: a job a live tick is building through must not have
			// its container deleted underneath it. Take the same per-job lock a tick holds
			// and defer any job whose lock is already held — a later sweep reclaims it once
			// the tick has released it, so the deferral is never permanent.
			$lock = $this->store->lock( $job );
			if ( $lock === null ) {
				continue;
			}
			try {
				$this->store->purge( $job );
				$expired[] = $job->with_state( Job_State::Expired );
			} finally {
				$this->store->unlock( $lock );
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

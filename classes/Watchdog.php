<?php
/**
 * The stall watchdog: the backstop that restarts a queue whose loopback died.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * Restarts every stalled job the self-dispatching loopback loop has stopped driving.
 *
 * The job's primary driver is the non-blocking loopback it fires to itself on
 * creation and after each chunk (ADR-0007). On the minority of hosts where a given
 * loopback dies, that chain breaks and the queue stalls. This watchdog is the
 * backstop for exactly that case: answered on a recurring schedule the
 * {@see Installer} registers against {@see WATCHDOG_HOOK}, it walks the live job set
 * and hands each stalled job one chunk of progress through the {@see Dispatcher}.
 *
 * It is a distinct concern from the {@see Sweeper}: the sweep RECLAIMS a
 * never-consumed job by expiring it, while this RESTARTS an unfinished one by
 * advancing it, so the two run on separate hooks and never share a callback. What
 * keeps the watchdog from competing with a live driver is the Dispatcher's own
 * {@see Dispatcher::needs_advance()} predicate, reused through
 * {@see Dispatcher::advance_stalled()}: a running job with a fresh heartbeat is
 * being ticked right now and is left untouched, and only a queued job or one whose
 * heartbeat has gone stale is restarted. Because that restart advances the chunk
 * in-process rather than firing another loopback, the job makes progress even where
 * the loopback is dead — one chunk per cron cycle until it reaches ready.
 *
 * @since 0.1.0
 */
final class Watchdog {

	/**
	 * The cron hook the recurring stall patrol is scheduled against.
	 *
	 * Public because both ends name it: the Installer schedules and clears the event,
	 * and the Plugin binds this patrol to it. Deliberately distinct from
	 * {@see Sweeper::SWEEP_HOOK} so restart and reclaim stay separate concerns.
	 *
	 * @since 0.1.0
	 */
	public const string WATCHDOG_HOOK = 'kntnt_extractor_watchdog';

	/**
	 * The recurring schedule the patrol runs on, and its interval in seconds.
	 *
	 * A dedicated schedule the {@see register_schedule()} filter contributes, because
	 * the built-in `hourly` is too coarse a backstop: a stalled queue must be restarted
	 * well before the TTL sweep ({@see Sweeper}) would reclaim it as never-consumed, so
	 * its heartbeat keeps being refreshed while it still has work. Fifteen minutes is the
	 * shortest interval WordPress recommends and still leaves a wide margin under the
	 * one-hour default TTL.
	 *
	 * @since 0.1.0
	 */
	public const string WATCHDOG_SCHEDULE = 'kntnt_extractor_watchdog_interval';

	/**
	 * Seconds between stall patrols.
	 *
	 * @since 0.1.0
	 */
	private const int WATCHDOG_INTERVAL = 900;

	/**
	 * Wires the watchdog to the job store and the driver it restarts stalled jobs with.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_Store  $store      Persistence for Extraction jobs.
	 * @param Dispatcher $dispatcher Drives a stalled job one chunk forward.
	 */
	public function __construct(
		private readonly Job_Store $store,
		private readonly Dispatcher $dispatcher,
	) {}

	/**
	 * Restarts every stalled job, returning the jobs it advanced.
	 *
	 * Walks the live job set and hands each untended, unfinished job one chunk of
	 * progress through {@see Dispatcher::advance_stalled()}, which restarts only a
	 * queued or stale-running job and leaves a freshly-ticked one to its live driver.
	 * Returns the jobs it drove, each in the state it reached, so a caller — the cron
	 * event, or a test — can see exactly which queues were restarted.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, Extraction_Job> The jobs this patrol advanced.
	 */
	public function patrol(): array {

		// Restart each stalled queue, collecting only the jobs actually advanced; a
		// tended job returns null from the driver and is skipped.
		$driven = [];
		foreach ( $this->store->all() as $job ) {
			$advanced = $this->dispatcher->advance_stalled( $job );
			if ( $advanced !== null ) {
				$driven[] = $advanced;
			}
		}

		return $driven;

	}

	/**
	 * Answers the recurring cron event, running one patrol and discarding its result.
	 *
	 * The scheduled hook (see {@see WATCHDOG_HOOK}) needs a void callback; the restarted
	 * jobs {@see patrol()} returns are of use only to a programmatic caller, so this thin
	 * adapter runs the patrol for its effect alone and returns nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run(): void {

		$this->patrol();

	}

	/**
	 * Contributes the watchdog's sub-hourly schedule to WordPress's cron intervals.
	 *
	 * Registered on the `cron_schedules` filter so {@see WATCHDOG_SCHEDULE} is a known
	 * recurrence by the time the {@see Installer} schedules the event at activation.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules The existing cron schedules.
	 * @return array<string, array{interval: int, display: string}> The schedules with the watchdog interval added.
	 */
	public function register_schedule( array $schedules ): array {

		$schedules[ self::WATCHDOG_SCHEDULE ] = [
			'interval' => self::WATCHDOG_INTERVAL,
			'display' => __( 'Every fifteen minutes (Kntnt Extractor watchdog)', 'kntnt-extractor' ),
		];

		return $schedules;

	}

}

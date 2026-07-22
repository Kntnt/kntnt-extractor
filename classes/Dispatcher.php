<?php
/**
 * Drives an Extraction job forward one tick at a time and nudges a stalled queue.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use Kntnt\Extractor\Rest\Status_Controller;
use Throwable;

/**
 * The extraction job's driver: it ticks a job forward and nudges an untended one.
 *
 * A job is advanced by a tick — a call authenticated by the job's own secret, so
 * the loopback driver can drive it without a WordPress session (ADR-0007). Each tick
 * packages bounded chunks through the {@see Artifact_Builder} — one table dump, or one
 * file part up to the configured chunk size — within a wall-clock budget (`tick_budget`,
 * default 15 s; zero means exactly one chunk per tick), persisting the progress after
 * each chunk so the heartbeat stays fresh, and leaves the job running while work remains
 * so the next tick resumes where this one stopped. Only the last chunk finalizes the
 * container and marks the job ready for download; a large selection therefore completes
 * across one or more budgeted ticks and survives an interruption between them. Once the
 * lock is released the tick fires the continuation loopback once, and only when work
 * remains. A build that throws drops the job to failed rather than leaving it wedged in
 * running (ADR-0010).
 *
 * Two liveness signals share the job's own state and heartbeat rather than a lock.
 * A tick is the authenticated driver, so it advances any queued or still-running
 * job — that is what lets each chunk's continuation carry the build forward. The
 * poll-nudge, by contrast, treats a running job with a recent heartbeat as being
 * ticked right now and leaves it alone, nudging only a queued or stalled one; once
 * the heartbeat goes stale the job counts as stalled and a nudge may restart it.
 * That split is what lets a status poll restart a stalled queue without ever
 * competing with a live driver mid-build.
 *
 * The poll and create endpoints reach that signal through a third path that never
 * couples their response latency to it (ADR-0010): {@see continue_after_response()}
 * schedules the work for after the body is echoed. Post-detach it is a full
 * in-process {@see tick()} — no loopback at all, driving a dead-loopback host at
 * poll cadence — and where the worker cannot detach it is the same guarded
 * poll-nudge, now hard-bounded and paid only after the response is already sent.
 * The cron watchdog remains the backstop under both.
 *
 * @since 0.1.0
 */
final class Dispatcher {

	/**
	 * HTTP header the loopback tick request carries its per-job secret in.
	 *
	 * Public because both ends name it: the nudge sets it on the outgoing request,
	 * and the tick endpoint reads it to authenticate the caller.
	 *
	 * @since 0.1.0
	 */
	public const string TICK_SECRET_HEADER = 'X-Kntnt-Extractor-Tick-Secret';

	/**
	 * Seconds after a running job's heartbeat before it counts as stalled.
	 *
	 * Resolved through the Config seam under the knob `tick_stale_after`, so a site
	 * tunes it with the `KNTNT_EXTRACTOR_TICK_STALE_AFTER` constant or its filter.
	 * This is only the fallback when neither is set.
	 *
	 * @since 0.1.0
	 */
	private const int DEFAULT_STALE_AFTER = 120;

	/**
	 * Seconds of wall clock one tick may keep packaging chunks before yielding.
	 *
	 * Resolved through the Config seam under the knob `tick_budget`, so a site tunes
	 * it with the `KNTNT_EXTRACTOR_TICK_BUDGET` constant or its filter. Zero means
	 * exactly one chunk per tick. The default of 15 s is deliberately well under the
	 * common 30 s FPM/PHP execution limits. This is only the fallback when neither is
	 * set.
	 *
	 * @since 0.2.1
	 */
	private const int DEFAULT_TICK_BUDGET = 15;

	/**
	 * Wires the driver to the job store, the Config seam, and the artifact builder.
	 *
	 * @since 0.1.0
	 *
	 * @param Job_Store        $store    Persistence for Extraction jobs.
	 * @param Config           $config   The constant-then-filter configuration seam.
	 * @param Artifact_Builder $builder  Seals a job's selection into its artifact.
	 */
	public function __construct(
		private readonly Job_Store $store,
		private readonly Config $config,
		private readonly Artifact_Builder $builder,
	) {}

	/**
	 * Advances a job by up to a budget of bounded chunks, returning the state it reached.
	 *
	 * A queued or running job is packaged forward under a single held lock: the first
	 * chunk stamps it running, each subsequent one appends the next segment, and the
	 * last one finalizes and publishes the container and marks the job ready. The loop
	 * always runs one chunk and keeps going while the job is still running and the
	 * `tick_budget` wall-clock deadline has not passed, so a zero budget is one chunk
	 * per tick and a positive one collapses many cron/loopback round trips into a single
	 * PHP invocation (ADR-0010). While work remains the job is left running with a fresh
	 * heartbeat, and the continuation loopback is fired once after the lock is released
	 * so the next tick runs without waiting for a poll. A finished, ready, or terminal
	 * job is left untouched, so a duplicate loopback is a harmless no-op. A build failure
	 * lands the job in failed.
	 *
	 * The nudger's client disconnects almost immediately by design, so the tick calls
	 * {@see ignore_user_abort()} first and keeps packaging through that abort.
	 *
	 * Ticks on one job are serialised by a per-job advisory lock. The lock-free queue
	 * (ADR-0007) lets a continuation loopback and a poll-nudge fire against the same
	 * job at once, and both appending to the single in-progress container would corrupt
	 * it or clobber a finished job back to failed. This call takes the lock for its
	 * whole duration; a racer that cannot take it touches nothing and no-ops. Under the
	 * lock the job is re-read so the decision runs on its committed state, not a
	 * snapshot a racing tick may already have carried to ready or failed.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job to advance.
	 * @return Extraction_Job The job in its resulting state.
	 */
	public function tick( Extraction_Job $job ): Extraction_Job {

		// The nudging client disconnects almost immediately by design; the tick must
		// keep packaging after that abort rather than dying mid-chunk (ADR-0010).
		ignore_user_abort( true );

		// Cheap pre-check before touching the filesystem: a ready or terminal job is a
		// no-op, so a duplicate or late loopback never rebuilds a done job.
		if ( $job->state !== Job_State::Queued && $job->state !== Job_State::Running ) {
			return $job;
		}

		// Serialise ticks on this job; a racer that cannot take the lock no-ops rather
		// than racing the shared container.
		$lock = $this->store->lock( $job );
		if ( $lock === null ) {
			return $job;
		}

		try {

			// Re-read under the lock so the decision runs on the committed state, not the
			// snapshot the caller was handed. A job that no longer reads back — purged by
			// the TTL sweep, or left half-written by a crashed tick — is gone: no-op on the
			// caller's stale snapshot rather than rebuilding a record that no longer exists.
			$current = $this->store->find( $job->id );
			if ( $current === null ) {
				return $job;
			}

			// Re-check the guard against that committed state, so a tick that lost the race
			// to finish this job cannot rebuild it or clobber the state the winner saved.
			if ( $current->state !== Job_State::Queued && $current->state !== Job_State::Running ) {
				return $current;
			}

			// Package chunks until the job leaves running or the wall-clock budget is spent;
			// the first chunk always runs, so a zero budget means exactly one chunk per tick
			// and a positive one collapses many cron/loopback round trips into a single PHP
			// invocation (ADR-0010). Each iteration saves the job, so the heartbeat stays
			// fresh throughout the budget.
			$deadline = microtime( true ) + $this->tick_budget();
			do {
				$current = $this->advance_one_chunk( $current );
			} while ( $current->state === Job_State::Running && microtime( true ) < $deadline );

		} finally {
			$this->store->unlock( $lock );
		}

		// Fire the continuation only now that the lock is released, so the tick it spawns
		// can take the lock instead of no-opping against this one, and only when work
		// remains — a finished or failed job needs no successor (ADR-0010). The early
		// returns above (terminal state, lost lock, vanished job) correctly fire none.
		if ( $current->state === Job_State::Running ) {
			$this->nudge( $current );
		}

		return $current;

	}

	/**
	 * Packages one bounded chunk of a lock-held, queued-or-running job to its next state.
	 *
	 * Split from {@see tick()} so the locking and re-read stay readable above it: by the
	 * time this runs the per-job lock is held and the job's committed state has been
	 * confirmed to be queued or running. Firing the continuation loopback is not this
	 * method's job — the budgeted loop in {@see tick()} owns that, once, after the lock
	 * is released (ADR-0010).
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job, freshly read under the tick lock.
	 * @return Extraction_Job The job in its resulting state.
	 */
	private function advance_one_chunk( Extraction_Job $job ): Extraction_Job {

		// Stamp the job running with a fresh heartbeat before any heavy work, so a
		// concurrent poll sees it as actively progressing (ADR-0007), and announce the
		// queued -> running transition once so observers can react to it.
		$was_queued = $job->state === Job_State::Queued;
		$running = $job->with_state( Job_State::Running );
		$this->store->save( $running );
		if ( $was_queued ) {
			do_action( 'kntnt_extractor_job_running', $running );
		}

		// Package exactly one bounded chunk; a build that throws drops the job to failed
		// rather than leaving it stuck in running.
		try {
			$progress = $this->builder->advance( $running, $this->store->container_build_path( $running ), $this->store->artifact_path( $running ) );
		} catch ( Throwable ) {
			$failed = $running->with_state( Job_State::Failed );
			$this->store->save( $failed );
			return $failed;
		}

		// A null result means the last chunk finalized and published the container, so
		// the job is ready for download and its completion is announced.
		if ( $progress === null ) {
			$ready = $running->with_state( Job_State::Ready );
			$this->store->save( $ready );
			do_action( 'kntnt_extractor_job_ready', $ready );
			return $ready;
		}

		// Work remains: persist the advanced progress and keep the job running with a
		// fresh heartbeat. The continuation loopback for the next chunk is fired once by
		// the budgeted loop in tick() after the lock is released, not per chunk here.
		$advanced = $running->with_progress( $progress );
		$this->store->save( $advanced );

		return $advanced;

	}

	/**
	 * Nudges a job's queue forward when — and only when — nothing is tending it.
	 *
	 * This is the poll-nudge fallback (ADR-0007): a status poll calls it to kick a
	 * queued or stalled job's loopback loop, but a job that is finished or currently
	 * being ticked is deliberately left alone so the poll never competes with a live
	 * driver or does the work itself.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The polled job.
	 * @return void
	 */
	public function maybe_nudge( Extraction_Job $job ): void {

		if ( $this->needs_advance( $job ) ) {
			$this->nudge( $job );
		}

	}

	/**
	 * Schedules a job's continuation to run after the current response is sent.
	 *
	 * The create and poll endpoints must never couple their response latency to
	 * the continuation's network or packaging work (ADR-0010): this registers a
	 * shutdown callback that first tries to detach the worker from the client —
	 * fastcgi_finish_request() on FPM, litespeed_finish_request() on LiteSpeed —
	 * and, once detached, drives the job in-process through {@see tick()}, needing
	 * no loopback at all. Where the worker cannot detach it falls back to the
	 * guarded {@see maybe_nudge()}, whose delivery is hard-bounded, so the caller
	 * waits at most about a second after the body has already been echoed.
	 *
	 * The detached branch deliberately calls tick() rather than maybe_nudge():
	 * after detaching, driving is free for the client, the per-job lock and state
	 * guard in tick() already make a racer a no-op, and skipping the staleness
	 * gate lets a dead-loopback host advance at poll cadence instead of only
	 * after tick_stale_after. A job past running is left alone: a ready or
	 * terminal job needs no continuation.
	 *
	 * @since 0.2.1
	 *
	 * @param Extraction_Job $job The job the just-answered request concerned.
	 * @return void
	 */
	public function continue_after_response( Extraction_Job $job ): void {

		// Only a job that can still advance warrants scheduling anything.
		if ( $job->state !== Job_State::Queued && $job->state !== Job_State::Running ) {
			return;
		}

		// Defer the work to shutdown: driving the job in-process once detached, or the
		// guarded nudge where the SAPI cannot detach — never before the body is echoed.
		add_action(
			'shutdown',
			function () use ( $job ): void {
				if ( $this->detach() ) {
					$this->tick( $job );
				} else {
					$this->maybe_nudge( $job );
				}
			},
		);

	}

	/**
	 * Detaches the PHP worker from the client, reporting whether it succeeded.
	 *
	 * After a successful detach the response is fully delivered and the process
	 * is free to keep working without the client waiting; on SAPIs offering no
	 * detach primitive this reports false and the caller must stay cheap.
	 *
	 * @since 0.2.1
	 *
	 * @return bool True when the client no longer waits on this process.
	 */
	private function detach(): bool {

		// Prefer the FPM primitive, then LiteSpeed's; a SAPI offering neither cannot
		// detach, so the caller must keep the post-response work cheap.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			return fastcgi_finish_request();
		}
		if ( function_exists( 'litespeed_finish_request' ) ) {
			return (bool) litespeed_finish_request();
		}

		return false;

	}

	/**
	 * Advances a stalled job a budget of chunks in-process, or leaves a tended one alone.
	 *
	 * This is the watchdog's entry point (ADR-0007): it acts only on a job the SAME
	 * {@see needs_advance()} predicate judges untended — a queued job, or a running one
	 * whose heartbeat has gone stale — so it never competes with a live driver mid-build.
	 * Unlike {@see maybe_nudge()}, which kicks the loopback loop, this drives the chunks
	 * itself through the now time-budgeted {@see tick()}: one call packages a whole
	 * `tick_budget` of chunks (zero means exactly one) rather than a single one (ADR-0010).
	 * That in-process advance is exactly what lets a job make progress on a host where the
	 * loopback is dead: the cron watchdog runs this in its own PHP process, so no working
	 * loopback is required to move the job forward. While work still remains the tick fires
	 * one continuation loopback for the next budget, so on a healthy host this restarts the
	 * queue and hands it back to the loopback loop.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job the watchdog is patrolling.
	 * @return Extraction_Job|null The job in the state it reached, or null when it was
	 *                             tended and left untouched.
	 */
	public function advance_stalled( Extraction_Job $job ): ?Extraction_Job {

		if ( ! $this->needs_advance( $job ) ) {
			return null;
		}

		return $this->tick( $job );

	}

	/**
	 * Fires a non-blocking loopback tick, carrying the job's secret.
	 *
	 * Best-effort by design: on a host with no working loopback this simply does
	 * nothing and the cron watchdog covers the gap, so a failed dispatch is never
	 * surfaced. The secret rides an HTTP header, which is what lets the tick endpoint
	 * authenticate the driver without any WordPress session.
	 *
	 * Delivery is hardened so a dead loopback can never stall the nudging process:
	 * the connect phase is hard-bounded through the `http_api_curl` action (a
	 * sub-second `blocking => false` timeout alone does not bound cURL's connect/DNS,
	 * and `CURLOPT_NOSIGNAL` is required for short timeouts to be honoured with the
	 * synchronous resolver at all). The `timeout => 1` gives a healthy host time to
	 * finish connect-and-send before teardown; the receiving tick runs
	 * {@see ignore_user_abort()} so it survives the nudger aborting at that bound
	 * (ADR-0010).
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job to drive.
	 * @return void
	 */
	private function nudge( Extraction_Job $job ): void {

		// Hard-bound the connect phase so a dead loopback stalls no process; NOSIGNAL is
		// required for sub-second cURL timeouts under the synchronous resolver (ADR-0010).
		$harden = static function ( \CurlHandle $handle ): void {
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the http_api_curl action hands over the raw cURL handle precisely to set options the wp_remote_* API does not expose; CURLOPT_NOSIGNAL has no WordPress equivalent.
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT_MS, 1000 );
			curl_setopt( $handle, CURLOPT_NOSIGNAL, true );
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
		};
		add_action( 'http_api_curl', $harden );

		// Fire the loopback and remove the transient hardening whatever happens, so it
		// never leaks onto an unrelated later request.
		try {
			wp_remote_post(
				$this->tick_url( $job->id ),
				[
					'blocking' => false,
					'timeout' => 1,
					'sslverify' => false,
					'headers' => [ self::TICK_SECRET_HEADER => $job->tick_secret ],
				],
			);
		} finally {
			remove_action( 'http_api_curl', $harden );
		}

	}

	/**
	 * Whether a job is an untended, unfinished one that a tick or nudge should act on.
	 *
	 * A queued job always qualifies. A running job qualifies only once its heartbeat
	 * has gone stale — until then it is being ticked right now and must be left to
	 * its live driver. Every terminal or ready state is finished and qualifies for
	 * neither. This single predicate is what keeps the tick guard and the poll-nudge
	 * in exact agreement about what "currently being ticked" means.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job to judge.
	 * @return bool True when the job should be advanced, false otherwise.
	 */
	private function needs_advance( Extraction_Job $job ): bool {

		return match ( $job->state ) {
			Job_State::Queued => true,
			Job_State::Running => ( time() - $job->updated_at ) > $this->stale_after(),
			default => false,
		};

	}

	/**
	 * Builds the absolute URL of a job's tick endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id The job id.
	 * @return string The loopback URL the nudge posts to.
	 */
	private function tick_url( string $id ): string {

		return rest_url( Status_Controller::REST_NAMESPACE . '/extractions/' . $id . '/tick' );

	}

	/**
	 * Resolves the stalled-heartbeat threshold through the Config seam, clamped to >= 1.
	 *
	 * @since 0.1.0
	 *
	 * @return int Seconds after which a running job counts as stalled.
	 */
	private function stale_after(): int {

		$configured = $this->config->get( 'tick_stale_after', self::DEFAULT_STALE_AFTER );

		return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_STALE_AFTER );

	}

	/**
	 * Resolves the per-tick wall-clock budget through the Config seam, clamped to >= 0.
	 *
	 * Zero is a meaningful value — exactly one chunk per tick, which the test suite
	 * pins — so it is not clamped up to one; only a negative or non-numeric override
	 * is coerced back to a sane floor.
	 *
	 * @since 0.2.1
	 *
	 * @return float Seconds one tick may keep packaging chunks before yielding.
	 */
	private function tick_budget(): float {

		$configured = $this->config->get( 'tick_budget', self::DEFAULT_TICK_BUDGET );

		return max( 0.0, is_numeric( $configured ) ? (float) $configured : (float) self::DEFAULT_TICK_BUDGET );

	}

}

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
 * running (ADR-0010), recording which throwable, from where, and on which chunk, so the
 * failure is readable from the poll instead of inferred afterwards. A build whose chunk
 * keeps dying where no `catch` can see it — a memory or execution-time kill — first
 * halves the budgets of the chunk that died and retries, and is failed only once they
 * cannot shrink further (ADR-0015). A job stalled and failed by a release that could
 * not do that — an on-disk record from 0.5.1 or earlier — is re-driven instead: the
 * next tick, or the watchdog, re-enters `running` with a fresh attempt window and
 * adapted budgets, truncating the container to the last acknowledged offset so an
 * uncommitted tail cannot be duplicated.
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
	 * Chunk attempts allowed without a single advance before the job is failed.
	 *
	 * The bound on the retry loop a chunk that dies OUTSIDE PHP creates (ADR-0013): an
	 * exhausted memory limit or execution time kills the worker where it stands, so the
	 * tick's own `catch` never runs, the job stays running with a heartbeat the watchdog
	 * keeps refreshing, and it is retried forever while a polling caller reads a state
	 * that never changes. Resolved through the Config seam under the knob
	 * `max_stall_attempts`, so a site tunes it with the
	 * `KNTNT_EXTRACTOR_MAX_STALL_ATTEMPTS` constant or its filter. This is only the
	 * fallback when neither is set.
	 *
	 * Two, lowered from three when what exceeding the bound *does* changed. It used to
	 * fail the job outright, so a false positive cost the whole run and being cautious
	 * was worth several attempts. It now halves the chunk and carries on (ADR-0015), so
	 * a false positive costs throughput for the rest of the run and nothing else, while
	 * every extra attempt costs a full execution-time limit — paid 23 times over on the
	 * way from 8 MiB to the floor, and paid only on a host that is already failing.
	 *
	 * Not one, which the same reasoning nearly reaches. The kill is usually
	 * deterministic and a second attempt at the same size usually dies the same way, but
	 * not every kill is about the size: an FPM reload, the kernel's OOM killer choosing
	 * this worker because of a neighbour, or a momentary I/O stall on a networked mount
	 * all present identically. At one attempt each of those permanently halves the
	 * budget, which never recovers within a run. One confirmation before a decision that
	 * cannot be undone is proportionate; a third is not.
	 *
	 * @since 0.4.0
	 */
	private const int DEFAULT_MAX_STALL_ATTEMPTS = 2;

	/**
	 * Seconds of execution time a tick asks the host for before it packages anything.
	 *
	 * The counterpart to shrinking the chunk (ADR-0015), and the cheaper half: where
	 * adaptation searches downward for a size the host survives, this simply asks the
	 * host upward for room, once per tick, with no search. The measured pair is
	 * persisted on the job at the first tick so a later stall reason still names
	 * those numbers. If the ask is granted the whole search is skipped for a stall
	 * the clock caused.
	 *
	 * Deliberately finite. Zero would remove the last bound inside PHP on a chunk that
	 * genuinely hangs, and such a chunk holds the per-job tick lock until the process
	 * ends. It costs little to be generous instead: `tick_budget` already stops a tick
	 * after its wall-clock slice, so this bounds one pathological chunk rather than the
	 * tick, and 900 seconds is well above the 300 the observed production host reports.
	 *
	 * Resolved through the Config seam under `max_execution_time`, so a site tunes it
	 * with the `KNTNT_EXTRACTOR_MAX_EXECUTION_TIME` constant or its filter, and a site
	 * that wants the host's own value left alone sets it to a value the host already
	 * meets. This is a *request*: many managed hosts lock the directive, and PHP's own
	 * timer does not count time in system calls on Unix, so a grant is neither certain
	 * nor sufficient. {@see stall_reason()} reports what was asked and what was given.
	 *
	 * @since 0.6.0
	 */
	private const int DEFAULT_MAX_EXECUTION_TIME = 900;

	/**
	 * Bytes of memory a tick asks the host for before it packages anything.
	 *
	 * The memory-side twin of {@see DEFAULT_MAX_EXECUTION_TIME}, and subject to the
	 * same caveats plus a harder one: a container or cgroup cap kills the worker
	 * whatever `memory_limit` says, so even a granted raise can be overruled by the
	 * kernel. The raise never lowers an existing limit, and never touches a host that
	 * already allows more. One GiB, a modest step above the 768 MB the observed
	 * production host reports.
	 *
	 * Resolved through the Config seam under `memory_limit`, so a site tunes it with
	 * the `KNTNT_EXTRACTOR_MEMORY_LIMIT` constant or its filter.
	 *
	 * @since 0.6.0
	 */
	private const int DEFAULT_MEMORY_LIMIT = 1073741824;

	/**
	 * Characters of a caught throwable's own message the failure reason keeps.
	 *
	 * The reason is written onto the job record and returned verbatim by the poll, so
	 * a throwable's message travels to every caller that can read the job. Unlike the
	 * stall reason, which is composed entirely from the caller's own selection and two
	 * settings `GET /environment` already discloses, this one relays a string the
	 * plugin did not write and cannot bound: a PDO or filesystem error carries a query
	 * fragment or a path, and third-party code hooked into the packaging carries
	 * anything at all. Truncating is what keeps the record's size and its disclosure
	 * both bounded, and the head is the half worth keeping — a throwable states what
	 * went wrong first and elaborates afterwards.
	 *
	 * Not a Config knob. Raising it would widen what a failed poll discloses, which is
	 * a decision about the REST contract rather than a tuning choice, and lowering it
	 * only breaks the diagnosis this exists to provide.
	 *
	 * @since 0.6.1
	 */
	private const int THROWN_MESSAGE_BOUND = 300;

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
	 * so the next tick runs without waiting for a poll. A finished, ready, or
	 * unresumable terminal job is left untouched, so a duplicate loopback is a
	 * harmless no-op. A never-adapted stall-failed job is the exception: it is
	 * re-entered into running under the lock, with a fresh attempt window and
	 * halved budgets, then packaged like any other running job (ADR-0015). A build
	 * failure lands the job in failed.
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

		// Ask the host for room before packaging anything. This is the cheap half of the
		// stall remedy: shrinking the chunk searches downward over many attempts, while
		// this asks upward once and skips that search entirely when the clock or the
		// memory ceiling was the whole problem (ADR-0015). The readings go onto the job
		// at the first tick so a later stall reason names this pair, not a later process.
		$limits = $this->raise_limits();

		// Pre-check before taking the lock: a ready or unresumable terminal job is a
		// no-op, so a duplicate or late loopback never rebuilds a done job. A
		// never-adapted stall-failed job is the exception — it still holds the
		// container a resume needs (ADR-0015).
		if ( $job->state !== Job_State::Queued && $job->state !== Job_State::Running && ! $this->is_resumable( $job ) ) {
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
			// A resumable stall-failed job is re-entered into running here, under the
			// lock, so the slot is taken back before any chunk runs.
			if ( $current->state === Job_State::Failed ) {
				$resumed = $this->resume_failed( $current );
				if ( $resumed === null ) {
					return $current;
				}
				$current = $resumed;
			} elseif ( $current->state !== Job_State::Queued && $current->state !== Job_State::Running ) {
				return $current;
			}

			// Persist the first tick's pre-raise and post-raise pair once. A later tick
			// still raises its own process, but must not overwrite the numbers a stall
			// reason will report.
			if ( ! $current->has_recorded_limits() ) {
				$current = $current->with_host_limits(
					$limits['host_memory_limit'],
					$limits['host_max_execution_time'],
					$limits['raised_memory_limit'],
					$limits['raised_max_execution_time'],
				);
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
	 * It is also where a build that cannot advance at all stops being retried. A chunk
	 * that exhausts the host's memory limit or execution time is killed outside PHP, so
	 * the `catch` below never runs and the job would otherwise stay running forever,
	 * restarted by every watchdog cycle and reporting a frozen progress to its caller.
	 * The attempt counter, incremented before the work and cleared by every real
	 * advance, is what makes that visible after the fact. Once it is spent the driver
	 * halves the bounds the chunk that died actually spends and retries; only bounds
	 * that cannot shrink further fail the job, with a reason naming the chunk
	 * (ADR-0013/0015).
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job, freshly read under the tick lock.
	 * @return Extraction_Job The job in its resulting state.
	 */
	private function advance_one_chunk( Extraction_Job $job ): Extraction_Job {

		// Call the build stalled once this many attempts have begun on the same chunk
		// without one of them ever finishing it: the attempts were counted before the
		// work, so a chunk killed outside PHP still leaves its evidence. While the
		// bounds that chunk actually spends can still shrink, halve them and retry
		// rather than failing — that is what makes a crash "carry on" instead of
		// "start over" (ADR-0015). Bounds already at their floor cannot shrink, and
		// retrying would only reproduce the kill, so that is the case that still fails.
		if ( $job->attempts >= $this->max_stall_attempts() ) {
			$adapted = $this->adapt( $job );
			if ( $adapted === null ) {
				return $this->persist_failure( $job->with_failure( $this->stall_reason( $job ) ) );
			}
			$job = $adapted;
			$this->store->save( $job );
		}

		// Stamp the job running with a fresh heartbeat before any heavy work, so a
		// concurrent poll sees it as actively progressing (ADR-0007), count the attempt
		// while a record of it can still be written, and announce the queued -> running
		// transition once so observers can react to it.
		$was_queued = $job->state === Job_State::Queued;
		$running = $job->with_state( Job_State::Running )->with_attempt();
		$this->store->save( $running );
		if ( $was_queued ) {
			do_action( 'kntnt_extractor_job_running', $running );
		}

		// Package exactly one bounded chunk; a build that throws drops the job to failed
		// rather than leaving it stuck in running, carrying what threw in a field of its
		// own so the failure is readable from the poll alone rather than reconstructed
		// afterwards, and without the record starting to look like the one failure this
		// release re-drives (issue #25).
		try {
			$step = $this->builder->advance( $running, $this->store->container_build_path( $running ), $this->store->artifact_path( $running ) );
		} catch ( Throwable $thrown ) {
			return $this->persist_failure( $running->with_thrown_failure( $this->thrown_reason( $running, $thrown ) ) );
		}

		// A complete step means the last chunk finalized and published the container, so
		// the job is ready for download and its completion is announced. Its progress is
		// persisted alongside the state rather than discarded: the finalizing step sealed
		// a segment like any other, and dropping its record would leave a ready job
		// reporting one chunk fewer than its artifact holds.
		if ( $step->complete ) {
			$ready = $running->with_progress( $step->progress )->with_state( Job_State::Ready );
			$this->store->save( $ready );
			do_action( 'kntnt_extractor_job_ready', $ready );
			return $ready;
		}

		// Work remains: persist the advanced progress and keep the job running with a
		// fresh heartbeat. The continuation loopback for the next chunk is fired once by
		// the budgeted loop in tick() after the lock is released, not per chunk here.
		$advanced = $running->with_progress( $step->progress );
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
	 * its live driver. A stall-failed job qualifies when it is still resumable: the
	 * watchdog is what re-drives a never-adapted stall left behind by an earlier
	 * release, so an upgrade recovers it without a new REST verb (ADR-0015). Every other
	 * terminal or ready state is finished and qualifies for neither. This single
	 * predicate is what keeps the tick guard and the poll-nudge in exact agreement
	 * about what "currently being ticked" means. The poll-nudge still never sees a
	 * failed job — {@see continue_after_response()} ignores anything past running —
	 * so a status poll cannot accidentally revive one.
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
			Job_State::Failed => $this->is_resumable( $job ),
			default => false,
		};

	}

	/**
	 * Composes the caller-visible reason a build that never advances is failed with.
	 *
	 * This is the message that has to explain the next occurrence without a server log,
	 * because the host that produced this failure mode had `WP_DEBUG_LOG` off and left
	 * nothing behind at all (ADR-0013). So it names all three things a reader needs: how
	 * many attempts died, exactly which chunk they died on, and the two host limits that
	 * are almost always the cause — neither of which the tick can observe itself being
	 * killed by, but both of which it can report. The two pairs come from the job
	 * record — the first tick's measurement — not from this process, so a stall
	 * written hours later still describes the limits in force when the chunk died.
	 * Everything in it is either the caller's own selection or a runtime setting
	 * `GET /environment` already discloses to the same capability, so this reason —
	 * unlike the one a throw relays — discloses nothing the plugin did not write
	 * (ADR-0022).
	 *
	 * @since 0.4.0
	 *
	 * @param Extraction_Job $job The job whose build has run out of attempts.
	 * @return string The failure reason, phrased for the polling owner to act on.
	 */
	private function stall_reason( Extraction_Job $job ): string {

		return sprintf(
			/* translators: 1: number of consecutive attempts, 2: a description of the chunk being packaged, 3: the host's own memory_limit setting, 4: the host's own max_execution_time setting in seconds, 5: the memory_limit in force after the plugin asked for more, 6: the max_execution_time in force after the plugin asked for more. */
			__( 'The extraction stalled: %1$d consecutive attempts to package %2$s ended without advancing, so the run is being killed before a single chunk can finish. This host is configured with memory_limit %3$s and max_execution_time %4$s; the plugin asked for more and is running with memory_limit %5$s and max_execution_time %6$s. Where those two pairs are equal the host refused the request, and the only remedy left is to raise the limits in the host configuration. Where they differ the raise was granted and the chunk still died, so what killed it is the web server or the container rather than PHP, and neither a smaller chunk nor a larger PHP limit will help. Otherwise lower KNTNT_EXTRACTOR_TABLE_CHUNK_BYTES, then KNTNT_EXTRACTOR_TABLE_CHUNK_ROWS (for a table) or KNTNT_EXTRACTOR_CHUNK_SIZE (for a file), and request the extraction again.', 'kntnt-extractor' ),
			$job->attempts,
			$this->stalled_chunk( $job ),
			$job->host_memory_limit ?? '',
			$job->host_max_execution_time ?? '',
			$job->raised_memory_limit ?? '',
			$job->raised_max_execution_time ?? '',
		);

	}

	/**
	 * Names the chunk a stalled build died on, in the caller's own vocabulary.
	 *
	 * Derived entirely from the persisted progress and the job's selection, so it needs
	 * nothing recorded at attempt time: the progress a stalled build carries is by
	 * definition the point it never got past, and the build order in
	 * {@see Artifact_Builder::advance()} says which resource that is. The row and byte
	 * offsets are included because they are what makes the chunk reproducible — the
	 * reader can go and look at that row range or that part of that file.
	 *
	 * @since 0.4.0
	 *
	 * @param Extraction_Job $job The job whose build has run out of attempts.
	 * @return string A translatable description of the chunk.
	 */
	private function stalled_chunk( Extraction_Job $job ): string {

		// Follow the builder's own order — full-data tables, then structure-only tables,
		// then files — and name whichever resource the progress stopped inside. A build
		// past all three died writing the container's sealed index, which names nothing
		// of the selection. A job killed before its first chunk ever persisted progress
		// stalled at the start of the build, which a zeroed progress describes exactly.
		$progress = $job->progress ?? new Build_Progress( 0, 0, 0, 0, 0, 0, 0 );
		$table = $job->tables[ $progress->tables_done ] ?? null;
		$structure = $job->structure_only[ $progress->structure_done ] ?? null;
		$file = $job->files[ $progress->file_index ] ?? null;
		if ( $table !== null ) {
			/* translators: 1: database table name, 2: the row number the dump had reached. */
			return sprintf( __( 'table `%1$s` from row %2$d onwards', 'kntnt-extractor' ), $table, $progress->table_offset );
		}
		if ( $structure !== null ) {
			/* translators: %s: database table name. */
			return sprintf( __( 'the structure of table `%s`', 'kntnt-extractor' ), $structure );
		}
		if ( $file !== null ) {
			/* translators: 1: file path relative to the installation root, 2: the byte offset the packaging had reached. */
			return sprintf( __( 'file `%1$s` from byte %2$d onwards', 'kntnt-extractor' ), $file, $progress->file_offset );
		}

		return __( 'the artifact\'s sealed index', 'kntnt-extractor' );

	}

	/**
	 * Composes the caller-visible reason a build killed by an unexpected throw is failed with.
	 *
	 * The counterpart to {@see stall_reason()} for the other way a build ends, and the
	 * reason this one exists at all: a production run failed at 97.8 % after six hours
	 * and the whole account of why was `error_of()`'s fallback sentence for a null
	 * `error`, so the cause had to be inferred from four converging facts instead of
	 * read off the record (`docs/measurements/2026-08-18-production-run.md` §3). It
	 * names what a reader needs and the plugin can actually see: which throwable, its
	 * own message, where it came from, and — reusing the stall's own chunk naming,
	 * because the persisted progress means exactly the same thing here — which chunk
	 * was being packaged when it threw. On the observed failure that last fact alone
	 * would have identified the vanished file.
	 *
	 * It opens by saying which of the two failures this is, because that is what the
	 * reader must not have to guess: a stall exhausted its budgets and may be worth
	 * retrying at a smaller size, whereas this is a bug or an environment fault, is
	 * never re-driven (ADR-0015), and has already had its part-built container
	 * discarded.
	 *
	 * The origin is named relative to the installation root, the same way the chunk it
	 * names beside it already is ({@see stalled_chunk()}) and the same way
	 * `GET /environment` reports every path it discloses. One sentence disclosing one
	 * file root-relative and another absolutely would be two rules with a reason for
	 * neither, and the plugin's own composition is the half of this string it fully
	 * controls.
	 *
	 * What it deliberately does not carry is a stack trace, and what it bounds is the
	 * throwable's own message — see {@see THROWN_MESSAGE_BOUND} for why that string,
	 * alone among the parts, is the one the plugin cannot vouch for.
	 *
	 * This cannot describe a failure that killed the PHP process outright, since no
	 * `catch` runs then and nothing is written. That is the point of keeping the
	 * fallback: after this, a failed job still polling as the fallback sentence is one
	 * PHP died on rather than threw, and those two were previously indistinguishable.
	 *
	 * @since 0.6.1
	 *
	 * @param Extraction_Job $job    The job whose chunk threw, as it stood when the throw happened.
	 * @param Throwable      $thrown The throwable the packaging died on.
	 * @return string The failure reason, phrased for the polling owner to act on.
	 */
	private function thrown_reason( Extraction_Job $job, Throwable $thrown ): string {

		// Keep the head of the throwable's own message and mark where it was cut, so a
		// truncated diagnosis cannot be misread as the whole of what PHP reported.
		$message = $thrown->getMessage();
		if ( mb_strlen( $message ) > self::THROWN_MESSAGE_BOUND ) {
			$message = mb_substr( $message, 0, self::THROWN_MESSAGE_BOUND ) . '…';
		}

		return sprintf(
			/* translators: 1: the class name of the throwable, 2: the throwable's own message, truncated, 3: the source file the throw came from, relative to the installation root, 4: the line number in that file, 5: a description of the chunk being packaged. */
			__( 'The extraction failed with an unexpected error rather than by exhausting its budgets: %1$s — “%2$s” — thrown at %3$s line %4$d while packaging %5$s. Nothing here is a host limit the run can adapt to, so this is not a resumable state and the part-built container has already been discarded. Address what the error names — a file or table that changed or disappeared mid-run, a permission, or a fault in code hooked into the packaging — and request the extraction again.', 'kntnt-extractor' ),
			$thrown::class,
			$message,
			$this->relative_to_root( $thrown->getFile() ),
			$thrown->getLine(),
			$this->stalled_chunk( $job ),
		);

	}

	/**
	 * Expresses an absolute path relative to the installation root.
	 *
	 * A path at or under the root loses the root prefix; one outside it — a
	 * `WP_CONTENT_DIR` moved elsewhere, a mu-plugin loaded from a sibling directory —
	 * is expressed with leading `../` segments, so it is still relative and still
	 * discloses no absolute prefix. Both sides are normalised to forward slashes with
	 * no trailing slash first, so the comparison holds on Windows/IIS too.
	 *
	 * This is the same rule `Rest\Environment_Controller::relative_to_root()` applies
	 * to every path that endpoint discloses. It is restated here rather than shared
	 * because the two have no seam in common and one small algorithm in two places is
	 * cheaper than a utility layer built for two callers; a third caller is the point
	 * at which extracting it is worth the indirection.
	 *
	 * @since 0.6.1
	 *
	 * @param string $absolute_path An absolute filesystem path to relativise.
	 * @return string The path relative to the installation root.
	 */
	private function relative_to_root( string $absolute_path ): string {

		// Normalise both paths to a comparable, slash-consistent, untrailed form.
		$root = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$target = untrailingslashit( wp_normalize_path( $absolute_path ) );

		// A path at or under the root is the root prefix stripped away.
		if ( $target === $root ) {
			return '';
		}
		if ( str_starts_with( $target, $root . '/' ) ) {
			return substr( $target, strlen( $root ) + 1 );
		}

		// A path outside the root is expressed by walking up past the divergent tail of
		// the root, then down into the target's own tail.
		$root_parts = explode( '/', trim( $root, '/' ) );
		$target_parts = explode( '/', trim( $target, '/' ) );
		$common = 0;
		while ( isset( $root_parts[ $common ], $target_parts[ $common ] ) && $root_parts[ $common ] === $target_parts[ $common ] ) {
			++$common;
		}
		$up = array_fill( 0, count( $root_parts ) - $common, '..' );
		$down = array_slice( $target_parts, $common );

		return implode( '/', [ ...$up, ...$down ] );

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

	/**
	 * Resolves the no-progress attempt ceiling through the Config seam, clamped to >= 1.
	 *
	 * The floor of one keeps the ceiling meaningful however the knob is misconfigured: a
	 * zero or negative override cannot disable the bound and restore the endless retry
	 * loop it exists to close.
	 *
	 * @since 0.4.0
	 *
	 * @return int Chunk attempts allowed without an advance before the job is failed.
	 */
	private function max_stall_attempts(): int {

		$configured = $this->config->get( 'max_stall_attempts', self::DEFAULT_MAX_STALL_ATTEMPTS );

		return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_MAX_STALL_ATTEMPTS );

	}

	/**
	 * Whether a failed job holds a stall that was never adapted, and so can be re-driven.
	 *
	 * Four things have to be true together, and each one closes a resume that would be
	 * unsafe or pointless (ADR-0015).
	 *
	 * The failure must be one the plugin diagnosed. An unexpected throw leaves `error`
	 * null and must stay dead, or a permanent bug — a file that changed mid-build, a
	 * path that no longer resolves — would retry forever. That a throw now records what
	 * it was does not soften this: the reason it records lives in a field of its own
	 * precisely so this predicate keeps reading the nullity of `error` as "no stall was
	 * diagnosed here" (issue #25).
	 *
	 * The job's budgets must be untouched. This is the condition that decides what
	 * automatic resume is actually *for*, and it is narrower than it first looks. A
	 * stall this release meets never fails the job while its budget can still shrink:
	 * it halves and carries on, and the only stall that reaches `failed` is one already
	 * at the floor. Re-driving that would walk straight back into a wall this release
	 * has already measured all the way down. What remains, and what this predicate
	 * admits, is a job stalled and failed by a release that had no adaptation at all —
	 * an on-disk record from 0.5.1 or earlier, whose container and progress are still
	 * valid and whose budget has never been tried at anything smaller. That is the
	 * upgrade path a stranded production run is actually recovered through, and it is
	 * self-limiting: the resume persists adapted budgets, so a second failure of the
	 * same job is no longer resumable.
	 *
	 * That untouched budget must still be able to shrink, so the re-driven job packages
	 * something smaller than what killed it rather than repeating it.
	 *
	 * And re-entering `running` must not push the site past the concurrency ceiling,
	 * because a failed job frees its slot and a new create may already have taken it.
	 *
	 * The first two live on the record itself, as {@see Extraction_Job::is_pre_adaptation_stall()},
	 * because the TTL sweep must spare exactly the records this predicate admits and
	 * would otherwise carry its own copy of the rule. The last two are the driver's
	 * own to judge — one needs the builder, the other the live job set — so they stay
	 * here.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job to judge.
	 * @return bool True when a tick or the watchdog may re-drive this job.
	 */
	private function is_resumable( Extraction_Job $job ): bool {

		return $job->is_pre_adaptation_stall()
			&& $this->adapted_budgets( $job ) !== null
			&& $this->store->has_free_slot();

	}

	/**
	 * Persists a failed job and discards its in-progress container and sidecar.
	 *
	 * This method is reached only by a failure this release just recorded — a
	 * floor stall, a structure-only or index stall that cannot shrink, or an
	 * unexpected throw. None of those are resumable, so the staging is residue.
	 * A pre-adaptation record never enters here: it is planted on disk by an
	 * older release and re-driven by {@see resume_failed()}, which does not
	 * call this. A throw is not one either, however old the record it happens
	 * to fail — it leaves `error` null, which is the first thing
	 * {@see Extraction_Job::is_pre_adaptation_stall()} tests.
	 *
	 * The record is saved first so a poll still reports `failed` with its reason.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $failed The job already moved into `failed`.
	 * @return Extraction_Job The same job, after the record and the reclaim.
	 */
	private function persist_failure( Extraction_Job $failed ): Extraction_Job {

		// Save the failed record first, then drop staging a resume will never read.
		$this->store->save( $failed );
		$this->store->reclaim_staging( $failed );

		return $failed;

	}

	/**
	 * Re-enters a stall-failed job into `running`, or null when it must stay failed.
	 *
	 * Persists the transition under the caller-held tick lock so the slot is taken back
	 * before any chunk runs, then re-reads the live job set and stands down if a create
	 * claimed the same slot in the window between the two. The tick lock is per job, so
	 * it cannot serialise this against a `POST /extractions` for a different one; taking
	 * the slot first and yielding it back on a lost race is what keeps the ceiling from
	 * being exceeded, and it yields in the direction that favours the caller's explicit
	 * create over an automatic resume.
	 *
	 * The container is not touched here — {@see Artifact_Builder::advance()} truncates it
	 * to the committed offset on the first chunk, which is what discards an
	 * unacknowledged tail.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The committed failed job, read under the tick lock.
	 * @return Extraction_Job|null The job now running, or null when it is not resumable.
	 */
	private function resume_failed( Extraction_Job $job ): ?Extraction_Job {

		// Judge the resume and compute the smaller budgets it will run under; either
		// answering no leaves the job exactly as it was found.
		if ( ! $this->is_resumable( $job ) ) {
			return null;
		}
		$budgets = $this->adapted_budgets( $job );
		if ( $budgets === null ) {
			return null;
		}

		// Take the slot back by committing the transition, then confirm nothing else took
		// it first. Losing that race restores the failure verbatim, so a declined resume
		// is indistinguishable on disk from one that was never attempted.
		$resumed = $job->with_resume( $budgets );
		$this->store->save( $resumed );
		if ( ! $this->store->has_free_slot( 1 ) ) {
			$this->store->save( $job );
			return null;
		}

		return $resumed;

	}

	/**
	 * Halves the stalled chunk's budget and clears the attempt counter, or null.
	 *
	 * Null means the budget cannot shrink — the job has reached the floor and
	 * must fail rather than retry the same one-byte chunk forever.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job whose current chunk has run out of attempts.
	 * @return Extraction_Job|null The job carrying the smaller budget, or null at the floor.
	 */
	private function adapt( Extraction_Job $job ): ?Extraction_Job {

		$budgets = $this->adapted_budgets( $job );

		return $budgets === null ? null : $job->with_budgets( $budgets );

	}

	/**
	 * Computes the budgets to package under after one stall, or null at the floor.
	 *
	 * Only the bounds the current chunk actually spends shrink, because halving one the
	 * failing work never consulted changes nothing and merely burns another attempt
	 * window at the same real size. A file part spends one bound; a table slice spends
	 * both of its own ({@see Chunk_Budgets}). A structure-only table and the sealed
	 * index spend none, so those stalls return null and fail the job, as does a chunk
	 * whose bounds are all at their floor.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job whose current chunk has stalled.
	 * @return Chunk_Budgets|null The budgets for the next attempt, or null at the floor.
	 */
	private function adapted_budgets( Extraction_Job $job ): ?Chunk_Budgets {

		$budgets = $this->builder->budgets( $job );

		return match ( $this->stalled_budget( $job ) ) {
			'table' => $budgets->halved_for_table(),
			'file' => $budgets->halved_for_file(),
			default => null,
		};

	}

	/**
	 * Asks the host for execution time and memory before a tick packages anything.
	 *
	 * The cheap half of the stall remedy, and the one that runs first. Shrinking the
	 * chunk (ADR-0015) searches downward for a size the host survives, at three
	 * attempts per step; this asks upward once, costs nothing, and removes the need for
	 * that search entirely when a host limit — rather than the work itself — was what
	 * killed the worker.
	 *
	 * Everything here is best-effort, and deliberately so. A managed host may lock
	 * either directive, may disable `set_time_limit()` outright, and may cap the
	 * process from a container the raise cannot reach. PHP's own timer does not count
	 * time spent in system calls on Unix either, so a granted raise does not even prove
	 * the clock was the killer. None of that is a reason not to ask: a refused ask
	 * leaves the run exactly where it was, and a granted one that still dies is itself
	 * a finding — the kill came from above PHP or from the kernel, which is a diagnosis
	 * the attempt counter alone can never reach. The measured pair is persisted on
	 * the job at the first tick so {@see stall_reason()} can still name it after
	 * a later process has raised its own limits.
	 *
	 * @since 0.6.0
	 *
	 * @return array{host_memory_limit: string, host_max_execution_time: string, raised_memory_limit: string, raised_max_execution_time: string} The pre-raise and post-raise readings.
	 */
	private function raise_limits(): array {

		// Read the host's own settings before anything has been asked for, so the
		// first tick can persist them as the "configured with" half of the stall
		// reason. A later tick still raises, but does not overwrite that record.
		$host_max_execution_time = (string) ini_get( 'max_execution_time' );
		$host_memory_limit = (string) ini_get( 'memory_limit' );

		// Ask for execution time. Best-effort by nature: the directive is locked on many
		// managed hosts, set_time_limit() may be disabled outright, and on Unix the timer
		// ignores time spent in system calls — so a grant is neither certain nor enough.
		$seconds = $this->configured( 'max_execution_time', self::DEFAULT_MAX_EXECUTION_TIME );
		if ( $seconds > 0 && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a host that locks the directive raises a warning the operator can do nothing about; the outcome is read back and reported instead.
		}

		// Ask for memory, but never lower what the host already grants. Even a granted
		// raise can be overruled by a container's own cap, which no PHP setting reaches.
		$bytes = $this->configured( 'memory_limit', self::DEFAULT_MEMORY_LIMIT );
		if ( $bytes > 0 && wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) < $bytes ) {
			@ini_set( 'memory_limit', (string) $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed -- raising the ceiling for one packaging chunk is the documented remedy this endpoint exists to attempt; the outcome is read back and reported.
		}

		return [
			'host_memory_limit' => $host_memory_limit,
			'host_max_execution_time' => $host_max_execution_time,
			'raised_memory_limit' => (string) ini_get( 'memory_limit' ),
			'raised_max_execution_time' => (string) ini_get( 'max_execution_time' ),
		];

	}

	/**
	 * Reads one runtime-limit knob through the Config seam, clamped to at least zero.
	 *
	 * Zero is meaningful and means "ask for nothing": a site that wants its own host
	 * settings honoured verbatim sets the knob to zero rather than guessing a value
	 * the host would refuse anyway.
	 *
	 * @since 0.6.0
	 *
	 * @param string $knob     The Config key to resolve.
	 * @param int    $fallback The compiled-in value to use when the knob is unset.
	 * @return int The amount to ask the host for, or zero to ask for nothing.
	 */
	private function configured( string $knob, int $fallback ): int {

		$configured = $this->config->get( $knob, $fallback );

		return max( 0, is_numeric( $configured ) ? (int) $configured : $fallback );

	}

	/**
	 * Names which packaging budget the current chunk is spending.
	 *
	 * Follows the builder's own order so it agrees with {@see stalled_chunk()}
	 * about what is being packaged. Null means the chunk has no shrinkable
	 * budget — a structure-only table, or the sealed index.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job whose current chunk has stalled.
	 * @return string|null `file`, `table`, or null when the chunk cannot shrink.
	 */
	private function stalled_budget( Extraction_Job $job ): ?string {

		$progress = $job->progress ?? new Build_Progress( 0, 0, 0, 0, 0, 0, 0 );
		if ( ( $job->tables[ $progress->tables_done ] ?? null ) !== null ) {
			return 'table';
		}
		if ( ( $job->structure_only[ $progress->structure_done ] ?? null ) !== null ) {
			return null;
		}
		if ( ( $job->files[ $progress->file_index ] ?? null ) !== null ) {
			return 'file';
		}

		return null;

	}

}

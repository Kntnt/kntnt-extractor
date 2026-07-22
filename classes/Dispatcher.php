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
 * the loopback driver can drive it without a WordPress session (ADR-0007). Each
 * tick packages exactly ONE bounded chunk through the {@see Artifact_Builder} — one
 * table dump, or one file part up to the configured chunk size — persists the
 * progress, and leaves the job running with a fresh heartbeat while work remains, so
 * the next tick resumes where this one stopped. Only the last chunk finalizes the
 * container and marks the job ready for download; a large selection therefore
 * completes across many ticks and survives an interruption between them. A build
 * that throws drops the job to failed rather than leaving it wedged in running.
 *
 * Two liveness signals share the job's own state and heartbeat rather than a lock.
 * A tick is the authenticated driver, so it advances any queued or still-running
 * job — that is what lets each chunk's continuation carry the build forward. The
 * poll-nudge, by contrast, treats a running job with a recent heartbeat as being
 * ticked right now and leaves it alone, nudging only a queued or stalled one; once
 * the heartbeat goes stale the job counts as stalled and a nudge may restart it.
 * That split is what lets a status poll opportunistically restart a stalled queue
 * without ever competing with a live driver mid-build.
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
	 * Advances a job by one bounded chunk, returning the job in the state it reached.
	 *
	 * A queued or running job is packaged one chunk further in this call: the first
	 * chunk stamps it running, each subsequent one appends the next segment, and the
	 * last one finalizes and publishes the container and marks the job ready. While
	 * work remains the job is left running with a fresh heartbeat and a continuation
	 * loopback is fired so the next chunk runs without waiting for a poll (ADR-0007).
	 * A finished, ready, or terminal job is left untouched, so a duplicate loopback is
	 * a harmless no-op. A build failure lands the job in failed.
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

			return $this->advance_one_chunk( $current );

		} finally {
			$this->store->unlock( $lock );
		}

	}

	/**
	 * Packages one bounded chunk of a lock-held, queued-or-running job to its next state.
	 *
	 * Split from {@see tick()} so the locking and re-read stay readable above it: by the
	 * time this runs the per-job lock is held and the job's committed state has been
	 * confirmed to be queued or running.
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

		// Work remains: persist the advanced progress, keep the job running with a fresh
		// heartbeat, and fire the continuation loopback so the next chunk resumes without
		// waiting for a poll. The nudge is unconditional here because this tick IS the
		// driver scheduling its own next chunk, not the poll-nudge that defers to a live one.
		$advanced = $running->with_progress( $progress );
		$this->store->save( $advanced );
		$this->nudge( $advanced );

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
	 * Advances a stalled job one chunk in-process, or leaves a tended one untouched.
	 *
	 * This is the watchdog's entry point (ADR-0007): it acts only on a job the SAME
	 * {@see needs_advance()} predicate judges untended — a queued job, or a running one
	 * whose heartbeat has gone stale — so it never competes with a live driver mid-build.
	 * Unlike {@see maybe_nudge()}, which kicks the loopback loop, this drives the chunk
	 * itself through {@see tick()}. That in-process advance is exactly what lets a job
	 * make progress on a host where the loopback is dead: the cron watchdog runs this in
	 * its own PHP process, so no working loopback is required to move the job forward.
	 * A tick still fires the continuation loopback for the next chunk, so on a healthy
	 * host this only restarts the queue and hands it back to the loopback loop.
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
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job to drive.
	 * @return void
	 */
	private function nudge( Extraction_Job $job ): void {

		wp_remote_post(
			$this->tick_url( $job->id ),
			[
				'blocking' => false,
				'timeout' => 0.01,
				'sslverify' => false,
				'headers' => [ self::TICK_SECRET_HEADER => $job->tick_secret ],
			],
		);

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

}

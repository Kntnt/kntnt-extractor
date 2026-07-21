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
 * the loopback driver can drive it without a WordPress session (ADR-0007). One
 * tick takes a queued small selection all the way to ready: it stamps the job
 * running, seals every table and file through the {@see Artifact_Builder}, and
 * marks it ready for download. A build that throws drops the job to failed rather
 * than leaving it wedged in running.
 *
 * The liveness signal is the job's own state and heartbeat, not a separate lock:
 * a running job whose updated-at is recent is being ticked right now, so neither a
 * second tick nor a poll's nudge competes for it; once that heartbeat goes stale
 * the job counts as stalled and a nudge may restart it. That is what lets a status
 * poll opportunistically nudge a job that is not currently being ticked, and only
 * such a job.
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
	 * Advances a job one tick, returning the job in whatever state it reached.
	 *
	 * A queued or stalled job is driven queued/running -> running -> ready in this
	 * single call; a job that is finished, ready, or actively running is left
	 * untouched, so a racing loopback and poll-nudge cannot rebuild a live or done
	 * job. A build failure lands the job in failed.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job The job to advance.
	 * @return Extraction_Job The job in its resulting state.
	 */
	public function tick( Extraction_Job $job ): Extraction_Job {

		// Only an untended, unfinished job may run; everything else is a no-op.
		if ( ! $this->needs_advance( $job ) ) {
			return $job;
		}

		// Stamp the job running before any heavy work, so its fresh heartbeat marks
		// it as actively ticking for any concurrent poll (ADR-0007), and announce it
		// so observers can react to the transition.
		$running = $job->with_state( Job_State::Running );
		$this->store->save( $running );
		do_action( 'kntnt_extractor_job_running', $running );

		// Seal the selection, then mark the job ready for download; a build that
		// throws drops the job to failed rather than leaving it stuck in running.
		try {
			$this->builder->build( $running, $this->store->artifact_path( $running ) );
		} catch ( Throwable ) {
			$failed = $running->with_state( Job_State::Failed );
			$this->store->save( $failed );
			return $failed;
		}
		$ready = $running->with_state( Job_State::Ready );
		$this->store->save( $ready );
		do_action( 'kntnt_extractor_job_ready', $ready );

		return $ready;

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

<?php
/**
 * The lifecycle state of an Extraction job.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * The state an Extraction job (ADR-0004) is in at rest.
 *
 * The value is what crosses the persistence and REST boundaries — it is written
 * verbatim into the job-state JSON and returned to a polling caller — so the
 * enum is string-backed and its cases are the wire vocabulary later issues share.
 * This issue only ever mints a `Queued` job and never advances it; the remaining
 * cases are declared here so the store's concurrency rule can reason about
 * terminality in one place and so the execution, consume, cancel, and sweep work
 * that follows extends this vocabulary rather than reinventing it.
 *
 * The terminal/non-terminal split is the only behaviour this issue needs: a
 * non-terminal job occupies the single global slot the concurrency rule guards,
 * whereas a terminal one is finished and no longer counts against it.
 *
 * @since 0.1.0
 */
enum Job_State: string {

	/**
	 * Created and waiting to run; no work has started yet.
	 *
	 * @since 0.1.0
	 */
	case Queued = 'queued';

	/**
	 * Executing: packaging and sealing the requested selection.
	 *
	 * @since 0.1.0
	 */
	case Running = 'running';

	/**
	 * Finished successfully; the sealed artifact awaits a download and consume.
	 *
	 * @since 0.1.0
	 */
	case Ready = 'ready';

	/**
	 * The caller confirmed the download; the artifact has been deleted.
	 *
	 * @since 0.1.0
	 */
	case Consumed = 'consumed';

	/**
	 * The run failed; no artifact will be produced.
	 *
	 * @since 0.1.0
	 */
	case Failed = 'failed';

	/**
	 * The caller cancelled the job before it completed.
	 *
	 * @since 0.1.0
	 */
	case Cancelled = 'cancelled';

	/**
	 * Removed by the time-to-live sweep without ever being consumed.
	 *
	 * @since 0.1.0
	 */
	case Expired = 'expired';

	/**
	 * Whether the job has reached a final state and no longer holds its slot.
	 *
	 * A non-terminal job — queued, running, or ready with an unconsumed artifact —
	 * still occupies the single global concurrency slot; a terminal one is done
	 * and does not count against a new create.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the job is finished, false while it is still live.
	 */
	public function is_terminal(): bool {

		return match ( $this ) {
			self::Consumed, self::Failed, self::Cancelled, self::Expired => true,
			self::Queued, self::Running, self::Ready => false,
		};

	}

}

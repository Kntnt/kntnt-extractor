<?php
/**
 * One packaging step's outcome: the progress it reached, and whether it finished.
 *
 * @package Kntnt\Extractor
 * @since   0.5.1
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * What one call to {@see Artifact_Builder::advance()} accomplished.
 *
 * The builder used to answer with a {@see Build_Progress} or `null`, where null meant
 * "the build is complete". That conflated two questions — how far did this step get,
 * and is there another one — and it cost the answer to the first exactly when the
 * second was yes: the call that seals a selection's LAST segment also finalizes and
 * publishes the container, so under the old shape it returned null and the segment it
 * had just sealed was never recorded. A ready job therefore reported one chunk fewer
 * than its artifact holds, which had to be written down as a documented off-by-one in
 * three places rather than simply not existing.
 *
 * Splitting the two answers apart costs one small object and removes that wart:
 * the progress is always returned, so the caller persists an accurate record whatever
 * the step did, and {@see $complete} carries the "no successor" signal on its own.
 *
 * On a completing step the progress is a closing balance, not a resume anchor. Its
 * counters and its segment count are exact and are what a poll reports; its
 * `container_bytes` and `index_bytes` are the last committed offsets, carried through
 * unchanged because a finished container is never resumed and the writer is already
 * closed by the time the step is known to be the last one. Nothing reads them after
 * completion.
 *
 * @since 0.5.1
 */
final readonly class Build_Step {

	/**
	 * Binds a step's resulting progress to whether the build is now finished.
	 *
	 * @since 0.5.1
	 *
	 * @param Build_Progress $progress The progress this step reached, always accurate for what it sealed.
	 * @param bool           $complete True once the container has been finalized and published, so no further step is due.
	 * @param string|null    $skipped_file The installation-root-relative path this step passed over because it had vanished, or null. Non-null only under `strict: false` (ADR-0026), and a step that carries one sealed no segment: it moved the build past a file rather than packaging one, and the caller owes the path to the job's skipped list before the progress beside it is persisted.
	 */
	public function __construct(
		public Build_Progress $progress,
		public bool $complete,
		public ?string $skipped_file = null,
	) {}

}

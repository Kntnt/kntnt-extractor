<?php
/**
 * The three size bounds one packaging chunk is allowed to spend.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * The resolved budgets a chunk packages under, and the only place they shrink.
 *
 * A stall (ADR-0013/0015) is the signal that the host cannot finish a chunk of the
 * current size, and the remedy is to make the next one smaller. Which value counts as
 * "smaller" depends on what the chunk was doing, and getting that wrong is a silent
 * no-op rather than a visible bug — halving a bound the failing work never consulted
 * simply burns the attempt window again at the same real size. Collecting all three
 * bounds and both shrink rules in one object is what keeps that decision in a single
 * readable place instead of spread across the driver.
 *
 * A file part spends one bound: the bytes {@see Artifact_Builder::read_part()} reads.
 * A table slice spends two, and both have to give. `table_bytes` bounds only what is
 * rendered, escaped and sealed; the rows themselves are materialised in memory by
 * `$wpdb->get_results()` under `table_rows` alone, which is the half a memory kill is
 * likeliest to have happened in. Shrinking the byte budget without the row budget
 * would leave the fetch exactly as large as the one that just died.
 *
 * One byte, and one row, are the floors. At the floor a chunk cannot be made smaller
 * and retrying it would only reproduce the kill, so the shrink methods answer null and
 * the caller fails the job.
 *
 * @since 0.6.0
 */
final readonly class Chunk_Budgets {

	/**
	 * Binds the three resolved bounds one chunk may spend.
	 *
	 * @since 0.6.0
	 *
	 * @param int $file_bytes  Maximum bytes packaged into one file part.
	 * @param int $table_bytes Maximum bytes of rendered rows packaged into one table slice.
	 * @param int $table_rows  Maximum rows fetched for one table slice.
	 */
	public function __construct(
		public int $file_bytes,
		public int $table_bytes,
		public int $table_rows,
	) {}

	/**
	 * Halves the file-part budget, or answers null when it is already at the floor.
	 *
	 * @since 0.6.0
	 *
	 * @return self|null The budgets with a smaller file part, or null at one byte.
	 */
	public function halved_for_file(): ?self {

		if ( $this->file_bytes <= 1 ) {
			return null;
		}

		return new self( intdiv( $this->file_bytes, 2 ), $this->table_bytes, $this->table_rows );

	}

	/**
	 * Halves both table bounds, or answers null when neither can shrink further.
	 *
	 * Each is halved independently and only down to its own floor, so a slice whose
	 * byte budget has bottomed out keeps shrinking its fetch — the two bound different
	 * costs and reach their floors at different times.
	 *
	 * @since 0.6.0
	 *
	 * @return self|null The budgets with a smaller table slice, or null at both floors.
	 */
	public function halved_for_table(): ?self {

		if ( $this->table_bytes <= 1 && $this->table_rows <= 1 ) {
			return null;
		}

		return new self( $this->file_bytes, max( 1, intdiv( $this->table_bytes, 2 ) ), max( 1, intdiv( $this->table_rows, 2 ) ) );

	}

}

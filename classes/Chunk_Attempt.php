<?php
/**
 * One begun packaging attempt, as persisted on the job's attempt log.
 *
 * @package Kntnt\Extractor
 * @since   0.6.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * A single last-N attempt-log entry (ADR-0016).
 *
 * Recorded at the moment a tick begins a chunk, before the work that a host
 * kill would never let finish. The persisted shape is four scalars — when,
 * which kind of chunk, the index into that selection, and the row or byte
 * offset — so the state file stays path-free (ADR-0014) and carries no
 * secret. The poll resolves `name` from the job's own selection at read
 * time.
 *
 * @since 0.6.0
 */
final readonly class Chunk_Attempt {

	/**
	 * Captures one begun attempt.
	 *
	 * @since 0.6.0
	 *
	 * @param int          $at     Unix timestamp the attempt began at.
	 * @param Attempt_Kind $kind   Which kind of chunk this attempt began.
	 * @param int          $n      Index into that kind's selection, or 0 for the sealed index.
	 * @param int          $offset Row offset (table) or byte offset (file), or 0 otherwise.
	 */
	public function __construct(
		public int $at,
		public Attempt_Kind $kind,
		public int $n,
		public int $offset,
	) {}

	/**
	 * Describes the chunk the job is about to begin, from its persisted progress.
	 *
	 * The same derivation {@see Dispatcher} uses to name a stall: the progress a
	 * build carries is the point it has not yet got past, so the next attempt is
	 * whatever resource that points at. A job that has not begun reads as the
	 * first full-data table, or the first structure-only table, or the first
	 * file, or the sealed index.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job whose next chunk is being begun.
	 * @return self The attempt that describes that chunk.
	 */
	public static function begun_on( Extraction_Job $job ): self {

		// Name the resource the persisted progress has not yet got past, in the
		// builder's own order. A job that has not begun reads as the first of
		// whichever selection it has.
		$progress = $job->progress ?? new Build_Progress( 0, 0, 0, 0, 0, 0, 0 );
		$at = time();
		if ( isset( $job->tables[ $progress->tables_done ] ) ) {
			return new self( $at, Attempt_Kind::Table, $progress->tables_done, $progress->table_offset );
		}
		if ( isset( $job->structure_only[ $progress->structure_done ] ) ) {
			return new self( $at, Attempt_Kind::Structure, $progress->structure_done, 0 );
		}
		if ( isset( $job->files[ $progress->file_index ] ) ) {
			return new self( $at, Attempt_Kind::File, $progress->file_index, $progress->file_offset );
		}

		return new self( $at, Attempt_Kind::Index, 0, 0 );

	}

	/**
	 * Resolves the caller-facing name of this attempt from the job's selection.
	 *
	 * Table names and install-root-relative file paths are the caller's own
	 * selection. The sealed index names nothing. A stale index (a selection
	 * that shrank after the attempt was recorded) yields null rather than a
	 * guessed name.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job whose selection this attempt indexes.
	 * @return string|null The table name or file path, or null for the index.
	 */
	public function name_on( Extraction_Job $job ): ?string {

		return match ( $this->kind ) {
			Attempt_Kind::Table => $job->tables[ $this->n ] ?? null,
			Attempt_Kind::Structure => $job->structure_only[ $this->n ] ?? null,
			Attempt_Kind::File => $job->files[ $this->n ] ?? null,
			Attempt_Kind::Index => null,
		};

	}

	/**
	 * Serialises the attempt into the four scalars persisted on the state file.
	 *
	 * @since 0.6.0
	 *
	 * @return array{at: int, kind: string, n: int, offset: int}
	 */
	public function to_array(): array {

		return [
			'at' => $this->at,
			'kind' => $this->kind->value,
			'n' => $this->n,
			'offset' => $this->offset,
		];

	}

	/**
	 * Reconstructs an attempt from a decoded log entry, or null when it is not one.
	 *
	 * @since 0.6.0
	 *
	 * @param mixed $data A decoded attempt_log element.
	 * @return self|null The reconstructed attempt, or null when the entry is unusable.
	 */
	public static function from_array( mixed $data ): ?self {

		if ( ! is_array( $data ) ) {
			return null;
		}

		// Narrow the four scalars; an unknown kind or an ill-typed field drops
		// the entry rather than the whole job.
		$kind = is_string( $data['kind'] ?? null ) ? Attempt_Kind::tryFrom( $data['kind'] ) : null;
		$at = $data['at'] ?? null;
		$n = $data['n'] ?? null;
		$offset = $data['offset'] ?? null;
		if ( $kind === null
			|| ! is_int( $at ) || $at < 0
			|| ! is_int( $n ) || $n < 0
			|| ! is_int( $offset ) || $offset < 0 ) {
			return null;
		}

		return new self( $at, $kind, $n, $offset );

	}

}

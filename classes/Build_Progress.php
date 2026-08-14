<?php
/**
 * The durable build-progress of a resumable, chunked Extraction job.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

/**
 * An immutable snapshot of how far a chunked artifact build has got (ADR-0007).
 *
 * A large selection is packaged one bounded chunk per tick and must survive an
 * interruption between ticks, so the point the build reached is persisted in the
 * job record rather than held in memory. This value carries exactly what the next
 * tick needs to resume without redoing or corrupting a completed segment: how many
 * full-data table segments and structure-only table segments are sealed, how far into
 * the table currently being dumped its sealed rows reach, which file is being packaged
 * and the byte offset within it already sealed, the committed byte lengths of the
 * in-progress container and of its index sidecar, how many segments are sealed, and
 * the identity of the file currently being split into parts.
 *
 * **Every field is a scalar, and that is a requirement rather than an observation.**
 * This record is rewritten twice per chunk, so anything here whose size grows with the
 * selection is paid for tens of thousands of times over a large build. The ordered
 * segment names used to live here, growing by one entry per chunk, which made each
 * save proportional to the build's own progress and dominated the wall clock of a real
 * extraction (ADR-0014). They now accumulate in an append-only sidecar the
 * {@see \Kntnt\Extractor\Crypto\Sealed_Writer} owns, and what remains here is the count
 * — a liveness signal the poll reports — and the sidecar's committed byte length.
 *
 * The container-byte offset is the resume anchor: a tick reopens the in-progress
 * container, truncates it back to this length — discarding any partial write a
 * crashed prior tick left past it — and appends the next sealed segment. The index
 * offset is its exact counterpart for the sidecar, and the two are always persisted
 * and rolled back together, so the container and the index can never disagree about
 * how far the build got. Because each segment is sealed independently (ADR-0009),
 * resuming needs no cross-segment authentication state, only this bookkeeping.
 *
 * The file identity — the size and mtime captured when a file's first part was
 * sealed — pins the version being packaged. A multi-tick build spans minutes, and a
 * file under the installation root can be rewritten, grow, or be truncated between
 * ticks; verifying the identity on every later part is what stops two versions from
 * being spliced into one segment stream and published as an authentic extraction.
 * Both are null between files, when no file is mid-package.
 *
 * The table position is the row-side counterpart of the file's byte offset (ADR-0013):
 * a table too large to dump inside one tick is sealed in bounded slices, so the next
 * tick must know how many of its rows are already sealed and, for a keyed table, which
 * key tuple to seek past. Both are zero and null between tables, when no table is
 * mid-dump; the cursor also stays null for a keyless table, which is walked by the row
 * count alone.
 *
 * @since 0.1.0
 */
final readonly class Build_Progress {

	/**
	 * Captures the progress a chunked build has reached.
	 *
	 * @since 0.1.0
	 *
	 * @param int                     $tables_done     Count of full-data table segments already sealed, in the job's table order.
	 * @param int                     $structure_done  Count of structure-only table segments already sealed, in the job's structure-only order (issue #16).
	 * @param int                     $file_index      Index into the job's files of the file currently being packaged.
	 * @param int                     $file_offset     Bytes of that file already sealed into earlier parts.
	 * @param int                     $container_bytes Committed byte length of the in-progress container.
	 * @param int                     $index_bytes     Committed byte length of the container's index sidecar.
	 * @param int                     $segment_count   Number of segments sealed so far, in write order (ADR-0014).
	 * @param int|null                $file_size       Size of the mid-package file when its first part was sealed, or null between files.
	 * @param int|null                $file_mtime      Mtime of the mid-package file when its first part was sealed, or null between files.
	 * @param int                     $table_offset    Rows of the mid-dump table already sealed into earlier slices, zero between tables.
	 * @param array<int, string>|null $table_cursor    Primary-key tuple the mid-dump table's last slice ended on, or null between tables and for a keyless table.
	 * @param array<int, string>|null $legacy_names    Segment names carried by a record written under schema 6, for the one resume that rebuilds the sidecar from them, or null for every record written since. Never persisted.
	 */
	public function __construct(
		public int $tables_done,
		public int $structure_done,
		public int $file_index,
		public int $file_offset,
		public int $container_bytes,
		public int $index_bytes,
		public int $segment_count,
		public ?int $file_size = null,
		public ?int $file_mtime = null,
		public int $table_offset = 0,
		public ?array $table_cursor = null,
		public ?array $legacy_names = null,
	) {}

	/**
	 * Serialises the progress into the associative array persisted with the job.
	 *
	 * The legacy name list is deliberately absent: it exists only to carry a schema-6
	 * record's names into the sidecar on the first resume after an upgrade, and writing
	 * it back would reintroduce the unbounded field this record no longer has.
	 *
	 * @since 0.1.0
	 *
	 * @return array{tables_done: int, structure_done: int, file_index: int, file_offset: int, container_bytes: int, index_bytes: int, segment_count: int, file_size: int|null, file_mtime: int|null, table_offset: int, table_cursor: array<int, string>|null}
	 */
	public function to_array(): array {

		return [
			'tables_done' => $this->tables_done,
			'structure_done' => $this->structure_done,
			'file_index' => $this->file_index,
			'file_offset' => $this->file_offset,
			'container_bytes' => $this->container_bytes,
			'index_bytes' => $this->index_bytes,
			'segment_count' => $this->segment_count,
			'file_size' => $this->file_size,
			'file_mtime' => $this->file_mtime,
			'table_offset' => $this->table_offset,
			'table_cursor' => $this->table_cursor,
		];

	}

	/**
	 * Reconstructs progress from a decoded record, or null when it is not one.
	 *
	 * This is a deserialization boundary — a truncated write or a hand-edited file
	 * can reach here — so every field is checked and an unrecognisable value yields
	 * null, which a reader treats as "no persisted progress" (a build not yet begun).
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $data The decoded `progress` value from a job file.
	 * @return self|null The reconstructed progress, or null when the record is unusable.
	 */
	public static function from_array( mixed $data ): ?self {

		// A progress record is a map of the build counters plus an optional file
		// identity; anything missing or ill-typed disqualifies it. The counters are byte
		// offsets and indices, so a negative one is nonsensical and rejected outright
		// rather than fed to a truncate that would misread it.
		if ( ! is_array( $data ) ) {
			return null;
		}
		$tables_done = $data['tables_done'] ?? null;
		$structure_done = $data['structure_done'] ?? 0;
		$file_index = $data['file_index'] ?? null;
		$file_offset = $data['file_offset'] ?? null;
		$container_bytes = $data['container_bytes'] ?? null;
		$file_size = self::non_negative_or_null( $data['file_size'] ?? null );
		$file_mtime = self::non_negative_or_null( $data['file_mtime'] ?? null );

		// The segment bookkeeping is a schema-7 change (ADR-0014): the ordered names
		// moved out to the writer's index sidecar, leaving a count and that sidecar's
		// committed length. A schema-6 record carries the names instead, so its count is
		// their number and the names ride along — unpersisted — for the one resume that
		// rebuilds the sidecar from them. A record carrying neither shape describes no
		// build at all, and a present-but-ill-typed name list is a malformed index that
		// disqualifies it, exactly as it always did.
		$has_legacy = array_key_exists( 'segment_names', $data );
		$legacy_names = $has_legacy ? self::string_list( $data['segment_names'] ) : null;
		$segment_count = $data['segment_count'] ?? ( $legacy_names === null ? null : count( $legacy_names ) );
		$index_bytes = $data['index_bytes'] ?? 0;

		// The table position is a schema-6 addition (ADR-0013); a record written before
		// it carries neither key, so an absent row offset reads as zero and an absent
		// cursor as none — the shape of a build not currently mid-table, which is what a
		// pre-0.4.0 record's table always was. A cursor that IS present but is not a list
		// of strings is a malformed resume anchor and disqualifies the record, the same
		// stance the segment-name list takes.
		$table_offset = $data['table_offset'] ?? 0;
		$has_cursor = ( $data['table_cursor'] ?? null ) !== null;
		$table_cursor = $has_cursor ? self::string_list( $data['table_cursor'] ) : null;
		if ( ! is_int( $tables_done ) || $tables_done < 0
			|| ! is_int( $structure_done ) || $structure_done < 0
			|| ! is_int( $file_index ) || $file_index < 0
			|| ! is_int( $file_offset ) || $file_offset < 0
			|| ! is_int( $container_bytes ) || $container_bytes < 0
			|| ! is_int( $index_bytes ) || $index_bytes < 0
			|| ! is_int( $segment_count ) || $segment_count < 0
			|| ! is_int( $table_offset ) || $table_offset < 0
			|| ( $has_cursor && $table_cursor === null )
			|| ( $has_legacy && $legacy_names === null )
			|| $file_size === false
			|| $file_mtime === false ) {
			return null;
		}

		return new self( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $index_bytes, $segment_count, $file_size, $file_mtime, $table_offset, $table_cursor, $legacy_names );

	}

	/**
	 * Narrows a decoded value to a non-negative int, null, or `false` when neither.
	 *
	 * The file identity is optional (null between files) but, when present, is a size
	 * or mtime that cannot be negative; a `false` result signals a disqualifying value
	 * the caller rejects, distinct from a legitimately absent identity of null.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value A decoded `file_size` or `file_mtime` value.
	 * @return int|null|false The non-negative int, null when absent, or false when invalid.
	 */
	private static function non_negative_or_null( mixed $value ): int|null|false {

		return $value === null ? null : ( is_int( $value ) && $value >= 0 ? $value : false );

	}

	/**
	 * Coerces a decoded value into a list of strings, or null when it is not one.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value A decoded `table_cursor` or legacy `segment_names` value.
	 * @return array<int, string>|null The value as a list of strings, or null.
	 */
	private static function string_list( mixed $value ): ?array {

		// Only a list-shaped array of strings qualifies; anything else disqualifies it.
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}
		$strings = [];
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return null;
			}
			$strings[] = $item;
		}

		return $strings;

	}

}

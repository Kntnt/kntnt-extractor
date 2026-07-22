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
 * table segments are sealed, which file is being packaged and the byte offset
 * within it already sealed, the committed byte length of the in-progress container,
 * the ordered names of every segment written so far (the sealed index the container
 * is finalized with), and the identity of the file currently being split into parts.
 *
 * The container-byte offset is the resume anchor: a tick reopens the in-progress
 * container, truncates it back to this length — discarding any partial write a
 * crashed prior tick left past it — and appends the next sealed segment. Because
 * each segment is sealed independently (ADR-0009), resuming needs no cross-segment
 * authentication state, only this bookkeeping.
 *
 * The file identity — the size and mtime captured when a file's first part was
 * sealed — pins the version being packaged. A multi-tick build spans minutes, and a
 * file under the installation root can be rewritten, grow, or be truncated between
 * ticks; verifying the identity on every later part is what stops two versions from
 * being spliced into one segment stream and published as an authentic extraction.
 * Both are null between files, when no file is mid-package.
 *
 * @since 0.1.0
 */
final readonly class Build_Progress {

	/**
	 * Captures the progress a chunked build has reached.
	 *
	 * @since 0.1.0
	 *
	 * @param int                $tables_done     Count of table segments already sealed, in the job's table order.
	 * @param int                $file_index      Index into the job's files of the file currently being packaged.
	 * @param int                $file_offset     Bytes of that file already sealed into earlier parts.
	 * @param int                $container_bytes Committed byte length of the in-progress container.
	 * @param array<int, string> $segment_names   Names of every sealed segment so far, in write order.
	 * @param int|null           $file_size       Size of the mid-package file when its first part was sealed, or null between files.
	 * @param int|null           $file_mtime      Mtime of the mid-package file when its first part was sealed, or null between files.
	 */
	public function __construct(
		public int $tables_done,
		public int $file_index,
		public int $file_offset,
		public int $container_bytes,
		public array $segment_names,
		public ?int $file_size = null,
		public ?int $file_mtime = null,
	) {}

	/**
	 * Serialises the progress into the associative array persisted with the job.
	 *
	 * @since 0.1.0
	 *
	 * @return array{tables_done: int, file_index: int, file_offset: int, container_bytes: int, segment_names: array<int, string>, file_size: int|null, file_mtime: int|null}
	 */
	public function to_array(): array {

		return [
			'tables_done' => $this->tables_done,
			'file_index' => $this->file_index,
			'file_offset' => $this->file_offset,
			'container_bytes' => $this->container_bytes,
			'segment_names' => $this->segment_names,
			'file_size' => $this->file_size,
			'file_mtime' => $this->file_mtime,
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

		// A progress record is a map of the four counters plus a list of segment names
		// and an optional file identity; anything missing or ill-typed disqualifies it.
		// The counters are byte offsets and indices, so a negative one is nonsensical
		// and rejected outright rather than fed to a truncate that would misread it.
		if ( ! is_array( $data ) ) {
			return null;
		}
		$tables_done = $data['tables_done'] ?? null;
		$file_index = $data['file_index'] ?? null;
		$file_offset = $data['file_offset'] ?? null;
		$container_bytes = $data['container_bytes'] ?? null;
		$segment_names = self::string_list( $data['segment_names'] ?? null );
		$file_size = self::non_negative_or_null( $data['file_size'] ?? null );
		$file_mtime = self::non_negative_or_null( $data['file_mtime'] ?? null );
		if ( ! is_int( $tables_done ) || $tables_done < 0
			|| ! is_int( $file_index ) || $file_index < 0
			|| ! is_int( $file_offset ) || $file_offset < 0
			|| ! is_int( $container_bytes ) || $container_bytes < 0
			|| $segment_names === null
			|| $file_size === false
			|| $file_mtime === false ) {
			return null;
		}

		return new self( $tables_done, $file_index, $file_offset, $container_bytes, $segment_names, $file_size, $file_mtime );

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
	 * @param mixed $value A decoded `segment_names` value.
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

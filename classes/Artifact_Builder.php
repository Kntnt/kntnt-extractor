<?php
/**
 * Packages a job's resolved selection into its sealed artifact, one chunk per tick.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use Kntnt\Extractor\Crypto\Sealed_Writer;
use RuntimeException;

/**
 * Seals a job's selection into a per-segment-encrypted container, chunk by chunk.
 *
 * This is the seam between a resolved job and the crypto container (ADR-0009). It
 * draws each bounded slice of each table's dump and each bounded part of each file and
 * hands them to {@see Sealed_Writer} as ordered segments, so plaintext is only ever the
 * single part being sealed and never a whole plain archive on disk. Full-data tables
 * come first, each split into bounded slices of rows sealed under the table's name;
 * then structure-only tables (issue #16), each a single DDL-only segment; then files,
 * each split into bounded parts sealed under its installation-root-relative path. A
 * table and a file are packaged by the same rule, so the sealed index reassembles both
 * the same way: concatenate, in index order, every segment carrying the name (AC1).
 *
 * The build is resumable by construction (ADR-0007): {@see advance()} packages
 * exactly ONE bounded chunk — one slice of a table's rows, or one file part up to the
 * configured chunk size — appends it to the in-progress container, and returns a
 * {@see Build_Step}: the progress reached, and whether the container has now been
 * finalized and published. Because each segment is sealed independently there is no
 * cross-segment authentication state to serialise: resuming reopens the container
 * and appends, never re-encrypting a completed segment.
 *
 * @since 0.1.0
 */
final class Artifact_Builder {

	/**
	 * Bytes of a file packaged per bounded part when the knob does not override it.
	 *
	 * A file larger than this is split into several independently-sealed parts, so a
	 * large selection completes across many ticks and no single tick must hold a whole
	 * file in memory (ADR-0007). Resolved through the Config seam under the knob
	 * `chunk_size`, so a site tunes it with the `KNTNT_EXTRACTOR_CHUNK_SIZE` constant
	 * or its filter, and tests force multi-chunk behaviour on small fixtures. This is
	 * only the fallback when neither is set.
	 *
	 * 256 KB, read off `docs/measurements/2026-08-19-chunk-size-curve.md` rather than
	 * chosen (ADR-0021). It is the only value this project has watched complete a real
	 * clone — 48,578 files and 186 tables in 3.56 h — and the fastest of the four sizes
	 * that controlled experiment measured; the 8 MiB this replaces sits far past a
	 * threshold between 2 and 4 MiB that turns a slow run into an impossible one, and
	 * had never produced a full-size part on that host until the run it killed. It is
	 * **not** claimed optimal: nothing below 256 KB was tested, and the curve's shape
	 * suggests smaller may be faster still, traded against a per-chunk overhead those
	 * numbers do not resolve. Nor does it generalise — every figure is one host, and
	 * the knob above is what answers a different one.
	 *
	 * @since 0.1.0
	 */
	private const int DEFAULT_CHUNK_SIZE = 262144;

	/**
	 * Rows of a table packaged per bounded slice when the knob does not override it.
	 *
	 * A table with more rows than this is split into several independently-sealed
	 * slices, exactly as an oversized file is split into parts, so no single tick has
	 * to hold — or finish — a whole table (ADR-0013). It is the coarser of a slice's
	 * two bounds: it caps how many rows a page may READ, while
	 * {@see DEFAULT_TABLE_CHUNK_BYTES} caps how many of them are rendered. Resolved
	 * through the Config seam under the knob `table_chunk_rows`, so a site tunes it
	 * with the `KNTNT_EXTRACTOR_TABLE_CHUNK_ROWS` constant or its filter, and tests
	 * force multi-slice behaviour on small fixtures. This is only the fallback when
	 * neither is set.
	 *
	 * @since 0.4.0
	 */
	private const int DEFAULT_TABLE_CHUNK_ROWS = 1000;

	/**
	 * Bytes of rendered rows packaged per bounded slice when the knob does not override it.
	 *
	 * The bound that a row count cannot express and a host nonetheless enforces. Rows
	 * are what the page query is written in, but memory and execution time are what a
	 * request is killed over, and the two part company completely on a table of few fat
	 * rows: production met a 726-row, ~23 KB-per-row table that sat far inside the
	 * 1,000-row budget, was therefore taken in a single slice, and never finished one —
	 * while a 100,890-row table of small rows went through in a hundred slices without
	 * trouble. It is not a table's size that decides this, it is its rows'.
	 *
	 * Four MiB, which was originally derived as half the file-part
	 * {@see DEFAULT_CHUNK_SIZE} on the belief that the host packaged 8 MiB file parts
	 * without complaint. The chunk-size curve then measured that belief false, and the
	 * file-part default has moved to 256 KB (ADR-0021), so the derivation is gone and
	 * this figure now stands on its own. It is left where it is deliberately: nothing
	 * has measured the table side, a slice is a different cost from a part — fetched as
	 * PHP row arrays, escaped into SQL, and copied again through the seal — and the one
	 * production clone that completed took all 186 of its tables at this value. Moving
	 * it on the strength of a file-part measurement would be inference, not evidence. A
	 * table of ordinary rows never reaches this bound at all — the row budget is spent
	 * long first — so it costs nothing on the tables that already worked. Resolved
	 * through the Config seam under the knob `table_chunk_bytes`, so a site tunes it
	 * with the `KNTNT_EXTRACTOR_TABLE_CHUNK_BYTES` constant or its filter.
	 *
	 * @since 0.5.0
	 */
	private const int DEFAULT_TABLE_CHUNK_BYTES = 4194304;

	/**
	 * Binds the builder to the table dumper and the Config seam it reads.
	 *
	 * @since 0.1.0
	 *
	 * @param Table_Dumper $dumper Produces each table's `mysqldump`-compatible SQL.
	 * @param Config       $config The constant-then-filter configuration seam the chunk size resolves through.
	 */
	public function __construct(
		private readonly Table_Dumper $dumper,
		private readonly Config $config,
	) {}

	/**
	 * Packages one bounded chunk of the job, or finalizes and publishes the container.
	 *
	 * A single call seals exactly one segment — the next slice of the table currently
	 * being dumped, or the next bounded part of the file currently being packaged —
	 * into the in-progress container at `$build_path`, appending to whatever earlier
	 * ticks left. When that segment is the last of the selection the container's sealed
	 * index is written and the finished container is published to `$download_path` with
	 * a single atomic rename, so a ready poll never observes a partial container
	 * (ADR-0004/0008).
	 *
	 * The job's own persisted {@see Build_Progress} (null before the first chunk) says
	 * where to resume; the {@see Build_Step} handed back carries the progress to persist
	 * and, separately, whether the build is now complete. Both answers are always given,
	 * including on the step that finalizes: the call that seals a selection's last
	 * segment also publishes the container, and reporting only "complete" there would
	 * lose the record of the segment it had just sealed. The build is crash-safe:
	 * reopening truncates the container back to the committed offset, so a partial write
	 * a crashed tick left behind is discarded rather than sealed into the result (AC3).
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job           The running job whose selection to package.
	 * @param string         $build_path    Absolute path of the in-progress container in the job's state directory.
	 * @param string         $download_path Absolute path the finished container is published to.
	 * @return Build_Step The progress to persist, and whether the build is complete.
	 *
	 * @throws RuntimeException When the public key is undecodable, a file resolves outside
	 *                          the root or cannot be read, or the container cannot be
	 *                          written or published.
	 */
	public function advance( Extraction_Job $job, string $build_path, string $download_path ): Build_Step {

		// Recover the 32 raw bytes the seal draws each segment's key against from the
		// canonical base64 the job persisted; an undecodable key is a corrupt record.
		$public_key = base64_decode( $job->public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the job's stored X25519 public key, not obfuscating code.
		if ( $public_key === false ) {
			throw new RuntimeException( 'The job public key is not decodable base64.' );
		}

		// Resume from the job's persisted progress, or start fresh when the build has not
		// begun. A fresh build opens a new container and writes its header; a resumed one
		// reopens the in-progress container at the committed offset to append.
		$progress = $job->progress;
		$writer = new Sealed_Writer( $build_path );
		if ( $progress === null ) {
			$tables_done = 0;
			$structure_done = 0;
			$file_index = 0;
			$file_offset = 0;
			$file_size = null;
			$file_mtime = null;
			$table_offset = 0;
			$table_cursor = null;
			$segment_count = 0;
			$container_bytes = 0;
			$index_bytes = 0;
			$writer->open( $public_key );
		} else {

			// A prior tick may have finalized and published the container, then died in
			// the window before its ready state was saved; the build file is gone but the
			// finished artifact already sits at the download path. Treat that as complete
			// rather than failing to resume a container that was correctly moved away.
			if ( ! is_file( $build_path ) && is_file( $download_path ) ) {
				return new Build_Step( $progress, true );
			}
			$tables_done = $progress->tables_done;
			$structure_done = $progress->structure_done;
			$file_index = $progress->file_index;
			$file_offset = $progress->file_offset;
			$file_size = $progress->file_size;
			$file_mtime = $progress->file_mtime;
			$table_offset = $progress->table_offset;
			$table_cursor = $progress->table_cursor;
			$segment_count = $progress->segment_count;
			$container_bytes = $progress->container_bytes;

			// A build begun under record schema 6 kept its segment names in the job record
			// and left no sidecar, so rebuild one from them before resuming; every record
			// written since carries the sidecar's own committed length instead (ADR-0014).
			$index_bytes = $progress->legacy_names === null ? $progress->index_bytes : $writer->seed_index( $progress->legacy_names );
			$writer->resume( $public_key, $container_bytes, $index_bytes );
		}

		// Resolve the size bounds this chunk may spend once, so the per-job budgets a
		// stall adapted down to (ADR-0015) and the Config defaults are read in one place.
		$budgets = $this->budgets( $job );

		// Seal the next bounded chunk in a fixed order: every full-data table as bounded
		// slices of rows under the table's name first, then every structure-only table as
		// one DDL-only segment (issue #16), then each file as bounded parts under its
		// relative path. When all three selections are exhausted there is no data segment
		// left and only the trailer remains to be written.
		if ( $tables_done < count( $job->tables ) ) {
			$table = $job->tables[ $tables_done ];
			[ $slice, $next_cursor, $next_rows, $table_complete ] = $this->dumper->dump_chunk( $table, $table_cursor, $table_offset, $budgets->table_rows, $budgets->table_bytes );
			$writer->add_segment( $table, $slice );
			++$segment_count;
			if ( $table_complete ) {
				++$tables_done;
				$table_offset = 0;
				$table_cursor = null;
			} else {
				$table_offset = $next_rows;
				$table_cursor = $next_cursor;
			}
		} elseif ( $structure_done < count( $job->structure_only ) ) {
			$table = $job->structure_only[ $structure_done ];
			$writer->add_segment( $table, $this->dumper->dump_structure( $table ) );
			++$segment_count;
			++$structure_done;
		} elseif ( $file_index < count( $job->files ) ) {
			$file = $job->files[ $file_index ];
			[ $part, $next_offset, $file_done, $file_size, $file_mtime ] = $this->read_part( $file, $file_offset, $file_size, $file_mtime, $budgets->file_bytes );
			$writer->add_segment( $file, $part );
			++$segment_count;
			if ( $file_done ) {
				++$file_index;
				$file_offset = 0;
				$file_size = null;
				$file_mtime = null;
			} else {
				$file_offset = $next_offset;
			}
		} else {

			// Finalize and publish before discarding the sidecar: it is what a resume
			// needs to roll a crashed tick back to, so it stays on disk until the
			// container it belongs to has actually been moved into the served directory
			// and can no longer be resumed. Discarding it any earlier would strand a
			// finished-but-unpublished container behind a resume that fails closed.
			$writer->finalize();
			$this->publish( $build_path, $download_path );
			$writer->discard_index();
			return new Build_Step( new Build_Progress( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $index_bytes, $segment_count, $file_size, $file_mtime, $table_offset, $table_cursor ), true );
		}

		// The build is complete once the last table and the last file part are sealed:
		// finalize the sealed index and publish the container in one atomic rename.
		// Otherwise suspend the container and hand back the offsets the next tick resumes
		// from, so a completed segment is never redone or re-encrypted. A completing step
		// reports the offsets it came in with, since finalize() has closed the writer and
		// a published container is never resumed; its segment count, which the poll does
		// read, is exact either way.
		if ( $tables_done >= count( $job->tables ) && $structure_done >= count( $job->structure_only ) && $file_index >= count( $job->files ) ) {

			// Finalize, publish, then discard the sidecar — see the identical sequence
			// in the branch above for why the ordering matters.
			$writer->finalize();
			$this->publish( $build_path, $download_path );
			$writer->discard_index();
			return new Build_Step( new Build_Progress( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $index_bytes, $segment_count, $file_size, $file_mtime, $table_offset, $table_cursor ), true );
		}
		[ $container_bytes, $index_bytes ] = $writer->suspend();

		return new Build_Step( new Build_Progress( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $index_bytes, $segment_count, $file_size, $file_mtime, $table_offset, $table_cursor ), false );

	}

	/**
	 * Reads the next bounded part of a file, reporting whether it reaches the end.
	 *
	 * The part is at most the configured chunk size, read from the given offset; an
	 * empty file yields a single empty part so it still appears in the sealed index.
	 * The returned flag is true once the part reaches or passes the file's end, which
	 * is how {@see advance()} knows to move on to the next file.
	 *
	 * The file's size and mtime are pinned when its first part is sealed and enforced
	 * on every later part: a multi-tick build spans minutes, and a file under the
	 * installation root (an upload, a cache, a log) can be rewritten, grow, or be
	 * truncated between ticks. Splicing two versions into one segment stream would
	 * publish a hybrid the caller cannot detect, so a changed identity fails the build
	 * outright rather than sealing corrupt data as an authentic extraction (AC2/AC5).
	 * The returned identity is the pinned one — captured now on the first part, carried
	 * through unchanged on later parts — for the caller to persist.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $file           The installation-root-relative file path.
	 * @param int      $offset         Byte offset the part starts at.
	 * @param int|null $expected_size  Pinned size from the first part, or null on the first part.
	 * @param int|null $expected_mtime Pinned mtime from the first part, or null on the first part.
	 * @param int      $max_bytes      The resolved file-part budget, already adapted for this job (ADR-0015).
	 * @return array{0: string, 1: int, 2: bool, 3: int, 4: int} The part bytes, the offset
	 *         after it, whether the file is now fully packaged, and the pinned size and mtime.
	 *
	 * @throws RuntimeException When the path resolves outside the root, cannot be opened,
	 *                          seeked, or read, or changed since its first part was sealed.
	 */
	private function read_part( string $file, int $offset, ?int $expected_size, ?int $expected_mtime, int $max_bytes ): array {

		// Re-resolve the path inside the root every time (defence in depth against a
		// record altered after create-time validation), then measure the file so the
		// end-of-file decision does not depend on a short read alone.
		$abs = $this->resolve_in_root( $file );
		$size = filesize( $abs );
		$mtime = filemtime( $abs );
		if ( $size === false || $mtime === false ) {
			throw new RuntimeException( 'Unable to size a requested file for packaging.' );
		}

		// Enforce the file's pinned identity on every part after the first: a size or
		// mtime that no longer matches means the file was rewritten, grew, or shrank
		// mid-build, so the parts would splice two versions — fail rather than seal a
		// hybrid. The first part (null expectation) pins the identity the rest hold to.
		if ( ( $expected_size !== null && $size !== $expected_size ) || ( $expected_mtime !== null && $mtime !== $expected_mtime ) ) {
			throw new RuntimeException( 'A requested file changed while it was being packaged.' );
		}

		// Open the validated path, seek to the part's offset, and read one bounded chunk;
		// past the end this reads nothing, which still yields a single empty part for an
		// empty file. Direct stream I/O is required because a part is read incrementally.
		$handle = fopen( $abs, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a bounded file part into the sealed writer; WP_Filesystem has no incremental-read API.
		if ( $handle === false ) {
			throw new RuntimeException( 'Unable to open a requested file for packaging.' );
		}
		if ( $offset > 0 && fseek( $handle, $offset ) === -1 ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing after a failed seek; see the fopen above.
			throw new RuntimeException( 'Unable to seek a requested file for packaging.' );
		}

		// Read one bounded part, or nothing at all once the offset has reached the end —
		// which is how a zero-byte file still yields exactly one empty part and completes.
		$part = '';
		if ( $offset < $size ) {
			$read = fread( $handle, max( 1, $max_bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading one bounded file part into the sealed writer; WP_Filesystem has no incremental-read API.

			// A bounded read below the end that yields nothing is never a legitimate
			// outcome, and treating it as one is worse than it looks: the part seals
			// empty, the offset does not move, and the chunk still counts as progress —
			// which clears the stall counter and refreshes both the heartbeat and the
			// last-progress stamp. All three bounds that would stop a wedged build are
			// reset together, so the job would seal empty segments forever while holding
			// the concurrency slot. Fail instead.
			if ( $read === false || $read === '' ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the read handle after a failed part read; see the fopen above.
				throw new RuntimeException( 'Unable to read a part of a requested file for packaging.' );
			}
			$part = $read;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the read handle after one bounded part; see the fopen above.

		// Report the offset after this part and whether it reached the file's end, so the
		// caller advances to the next file only once the whole file is packaged.
		$next_offset = $offset + strlen( $part );

		return [ $part, $next_offset, $next_offset >= $size, $size, $mtime ];

	}

	/**
	 * Publishes the finished container into the served downloads directory atomically.
	 *
	 * The in-progress container is built in the job's deny-hardened state directory and
	 * moved into the served directory only here, with a single rename, so a ready poll
	 * never observes a partial container and no plaintext ever lands in the served area
	 * (ADR-0008/0009). Both directories are siblings on one filesystem, so the rename is
	 * atomic.
	 *
	 * @since 0.1.0
	 *
	 * @param string $build_path    Absolute path of the finished container in the state directory.
	 * @param string $download_path Absolute path in the served downloads directory to publish to.
	 * @return void
	 *
	 * @throws RuntimeException When the container cannot be published into place.
	 */
	private function publish( string $build_path, string $download_path ): void {

		// Move the sealed container into the served directory in one atomic step.
		if ( ! rename( $build_path, $download_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-filesystem publish of the plugin's own sealed artifact; WP_Filesystem::move offers no atomicity guarantee.
			throw new RuntimeException( 'Unable to publish the sealed artifact into place.' );
		}

	}

	/**
	 * Resolves a requested file to a real absolute path at or under the root, or fails.
	 *
	 * The path was validated when the job was created, but a job record can be read
	 * again much later; re-resolving it against the installation root here is defence
	 * in depth against a record altered in between, and it is a boundary check, never a
	 * sanitiser — a path that resolves outside the root fails the build outright. The
	 * root and the resolved path are compared on `wp_normalize_path`'d separators so the
	 * boundary holds on Windows/IIS too, where `realpath` renders paths with backslashes
	 * a forward-slash prefix would never match — the same normalisation the create-time
	 * check applies (Extractions_Controller::first_out_of_root_file). A null byte counts
	 * as out of root because `realpath` would raise a ValueError on it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file The installation-root-relative file path.
	 * @return string The validated absolute path.
	 *
	 * @throws RuntimeException When the path resolves outside the root.
	 */
	private function resolve_in_root( string $file ): string {

		// Fail closed unless the path resolves to a real location at or under the
		// canonical installation root, comparing on normalised separators so the boundary
		// holds on every platform.
		$root = realpath( ABSPATH );
		$root = $root === false ? false : wp_normalize_path( $root );
		$abs = $root === false || str_contains( $file, "\0" ) ? false : realpath( $root . '/' . $file );
		$abs = $abs === false ? false : wp_normalize_path( $abs );
		if ( $root === false || $abs === false || ! ( $abs === $root || str_starts_with( $abs, $root . '/' ) ) ) {
			throw new RuntimeException( 'A requested file resolves outside the installation root.' );
		}

		return $abs;

	}

	/**
	 * Resolves the three size bounds a job's next chunk may spend.
	 *
	 * A positive per-job budget — persisted when a stall halved it (ADR-0015) — wins
	 * over the Config knob, so a resumed or continued job packages the size that
	 * survived rather than rediscovering the host's ceiling. Zero means "not yet
	 * adapted" and falls through to the configured default. Every bound is clamped to
	 * at least one, which keeps a misconfigured knob from disabling it: at one byte a
	 * slice still renders its first row, so the build advances a row at a time rather
	 * than stopping.
	 *
	 * This is the single seam the driver reads the budgets through, so the Config
	 * lookups and the per-job override rule have one home rather than one per bound.
	 *
	 * @since 0.6.0
	 *
	 * @param Extraction_Job $job The job whose persisted budgets, if any, to honour.
	 * @return Chunk_Budgets The resolved file-part, table-byte and table-row bounds.
	 */
	public function budgets( Extraction_Job $job ): Chunk_Budgets {

		return new Chunk_Budgets(
			$job->chunk_size > 0 ? $job->chunk_size : $this->configured( 'chunk_size', self::DEFAULT_CHUNK_SIZE ),
			$job->table_chunk_bytes > 0 ? $job->table_chunk_bytes : $this->configured( 'table_chunk_bytes', self::DEFAULT_TABLE_CHUNK_BYTES ),
			$job->table_chunk_rows > 0 ? $job->table_chunk_rows : $this->configured( 'table_chunk_rows', self::DEFAULT_TABLE_CHUNK_ROWS ),
		);

	}

	/**
	 * Reads one size knob through the Config seam, clamped to at least one.
	 *
	 * @since 0.6.0
	 *
	 * @param string $knob     The Config key to resolve.
	 * @param int    $fallback The compiled-in value to use when the knob is unset.
	 * @return int The configured size, or the fallback when it is absent or not numeric.
	 */
	private function configured( string $knob, int $fallback ): int {

		$configured = $this->config->get( $knob, $fallback );

		return max( 1, is_numeric( $configured ) ? (int) $configured : $fallback );

	}

}

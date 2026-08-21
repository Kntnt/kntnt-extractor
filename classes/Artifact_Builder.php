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
 * Because a build spans hours on a site that stays live, this is also where a file
 * can be found gone that was there when the job was created. Under `strict: false`
 * that is a reported skip rather than a fatal error, and the reach of the flag is
 * the whole run rather than the create alone (ADR-0026); the two cases it must not
 * absorb — a path out of bounds, and a file already half-packaged — are argued at
 * {@see locate_in_root()} and in {@see advance()}'s file branch.
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
	 * chosen (ADR-0023). It is the only value this project has watched complete a real
	 * clone — 48,578 files and 186 tables in 3.56 h — and the fastest of the four sizes
	 * that controlled experiment measured; the 8 MiB this replaces sits far past a
	 * threshold between 2 and 4 MiB that turns a slow run into an impossible one, and
	 * the production host was never once asked for a part that size until the run it
	 * killed. It is **not** claimed optimal: nothing below 256 KB was tested, and the
	 * curve's shape suggests smaller may be faster still, traded against a per-chunk
	 * overhead those numbers do not resolve. Nor does it generalise — every figure is
	 * one host, and the knob above is what answers a different one.
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
	 * file-part default has moved to 256 KB (ADR-0023), so the derivation is gone and
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
	 * A `strict: false` job passes over a file that has gone since it was created rather
	 * than failing, and the step names it so the caller can record the skip (ADR-0026).
	 * Such a step seals no segment; it moves the build past one file, which is progress
	 * of the ordinary kind and clears the stall counter like any other.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job   $job           The running job whose selection to package.
	 * @param string           $build_path    Absolute path of the in-progress container in the job's state directory.
	 * @param string           $download_path Absolute path the finished container is published to.
	 * @param Phase_Timer|null $timer         Instrument to mark this chunk's phases on, or null — the default and the production case — to package without reading a clock at all (issue #39).
	 * @return Build_Step The progress to persist, whether the build is complete, and any file skipped.
	 *
	 * @throws RuntimeException When the public key is undecodable, a file is out of bounds,
	 *                          is gone and not skippable, or cannot be read, or the
	 *                          container cannot be written or published.
	 */
	public function advance( Extraction_Job $job, string $build_path, string $download_path, ?Phase_Timer $timer = null ): Build_Step {

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
			$timer?->start( Phase_Timer::OPEN );
			$writer->open( $public_key );
			$timer?->stop( Phase_Timer::OPEN );
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
			$index_bytes = $progress->index_bytes;

			// Reopen the container and its sidecar at the two offsets the last clean tick
			// acknowledged, which is what discards a crashed tick's uncommitted tail.
			$timer?->start( Phase_Timer::RESUME );
			$writer->resume( $public_key, $container_bytes, $index_bytes );
			$timer?->stop( Phase_Timer::RESUME );
		}

		// Resolve the size bounds this chunk may spend once, so the per-job budgets a
		// stall adapted down to (ADR-0015) and the Config defaults are read in one place.
		$budgets = $this->budgets( $job );

		// Seal the next bounded chunk in a fixed order: every full-data table as bounded
		// slices of rows under the table's name first, then every structure-only table as
		// one DDL-only segment (issue #16), then each file as bounded parts under its
		// relative path. When all three selections are exhausted there is no data segment
		// left and only the trailer remains to be written. A file branch that finds its
		// file gone may pass over it instead of sealing anything, and names it here for
		// the caller to record; no other branch can (ADR-0026).
		$skipped_file = null;
		if ( $tables_done < count( $job->tables ) ) {
			$table = $job->tables[ $tables_done ];
			[ $slice, $next_cursor, $next_rows, $table_complete ] = $this->dumper->dump_chunk( $table, $table_cursor, $table_offset, $budgets->table_rows, $budgets->table_bytes );
			$timer?->start( Phase_Timer::SEAL );
			$writer->add_segment( $table, $slice );
			$timer?->stop( Phase_Timer::SEAL );
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
			$ddl = $this->dumper->dump_structure( $table );
			$timer?->start( Phase_Timer::SEAL );
			$writer->add_segment( $table, $ddl );
			$timer?->stop( Phase_Timer::SEAL );
			++$segment_count;
			++$structure_done;
		} elseif ( $file_index < count( $job->files ) ) {
			$file = $job->files[ $file_index ];

			// Under `strict: false` a file that has gone since the job was created is a
			// reported skip rather than a fatal error, wherever in the run it is reached
			// (ADR-0026) — but only while none of it has been sealed. `$file_size` is the
			// identity pinned when the first part was written, so its nullity is exactly
			// "nothing of this file is in the container yet". Once a part is in, skipping
			// would publish the file truncated at whatever offset the deletion fell on,
			// and `docs/container-format.md` gives a reader no way to tell that from a
			// short file — so this is one of the places the project fails loudly instead.
			// A path that is out of bounds rather than gone never reaches either branch:
			// locate_in_root() throws on it, exactly as the create path 404s on it. The
			// check is a `realpath` walk of its own, so it is marked on the same
			// resolution phase the part read's own resolution accumulates into: a
			// filesystem call left outside the instrument would be attributed to nothing,
			// which is the remainder issue #39 exists to shrink.
			$timer?->start( Phase_Timer::RESOLVE );
			$vanished = ! $job->strict && $this->locate_in_root( $file ) === null;
			$timer?->stop( Phase_Timer::RESOLVE );
			if ( $vanished ) {
				if ( $file_size !== null ) {
					throw new RuntimeException( 'A requested file was deleted after part of it had been packaged.' );
				}
				$skipped_file = $file;
				++$file_index;
				$file_offset = 0;
			}

			// Seal the next bounded part of the file the build is on, unless the branch
			// above has just passed over it.
			if ( $skipped_file === null ) {
				[ $part, $next_offset, $file_done, $file_size, $file_mtime ] = $this->read_part( $file, $file_offset, $file_size, $file_mtime, $budgets->file_bytes, $timer );
				$timer?->start( Phase_Timer::SEAL );
				$writer->add_segment( $file, $part );
				$timer?->stop( Phase_Timer::SEAL );
				++$segment_count;
				if ( $file_done ) {
					++$file_index;
					$file_offset = 0;
					$file_size = null;
					$file_mtime = null;
				} else {
					$file_offset = $next_offset;
				}
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
			return new Build_Step( new Build_Progress( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $index_bytes, $segment_count, $file_size, $file_mtime, $table_offset, $table_cursor ), true, $skipped_file );
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
			return new Build_Step( new Build_Progress( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $index_bytes, $segment_count, $file_size, $file_mtime, $table_offset, $table_cursor ), true, $skipped_file );
		}
		$timer?->start( Phase_Timer::SUSPEND );
		[ $container_bytes, $index_bytes ] = $writer->suspend();
		$timer?->stop( Phase_Timer::SUSPEND );

		return new Build_Step( new Build_Progress( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $index_bytes, $segment_count, $file_size, $file_mtime, $table_offset, $table_cursor ), false, $skipped_file );

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
	 * @param string           $file           The installation-root-relative file path.
	 * @param int              $offset         Byte offset the part starts at.
	 * @param int|null         $expected_size  Pinned size from the first part, or null on the first part.
	 * @param int|null         $expected_mtime Pinned mtime from the first part, or null on the first part.
	 * @param int              $max_bytes      The resolved file-part budget, already adapted for this job (ADR-0015).
	 * @param Phase_Timer|null $timer          Instrument to mark the resolution and the read on, or null to read no clock.
	 * @return array{0: string, 1: int, 2: bool, 3: int, 4: int} The part bytes, the offset
	 *         after it, whether the file is now fully packaged, and the pinned size and mtime.
	 *
	 * @throws RuntimeException When the path is out of bounds or gone, cannot be opened,
	 *                          seeked, or read, or changed since its first part was sealed.
	 */
	private function read_part( string $file, int $offset, ?int $expected_size, ?int $expected_mtime, int $max_bytes, ?Phase_Timer $timer ): array {

		// Re-resolve the path inside the root every time (defence in depth against a
		// record altered after create-time validation), then measure the file so the
		// end-of-file decision does not depend on a short read alone. This is the
		// `realpath` walk and the two stats a per-chunk cost is most often blamed on,
		// so it is measured apart from the read that follows it (issue #39).
		$timer?->start( Phase_Timer::RESOLVE );
		$abs = $this->resolve_in_root( $file );
		$size = filesize( $abs );
		$mtime = filemtime( $abs );
		$timer?->stop( Phase_Timer::RESOLVE );
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
		// The clock runs from here to the close: every failure below leaves the chunk
		// throwing, and a chunk that throws records no timing at all.
		$timer?->start( Phase_Timer::READ );
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
		$timer?->stop( Phase_Timer::READ );

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
	 * The strict counterpart of {@see locate_in_root()}: everything that method reports
	 * as gone is an error here. This is the path a part read takes, where a file that is
	 * not there is a failure whatever the reason — either because the job is strict, or
	 * because {@see advance()} has already decided the file is not skippable.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file The installation-root-relative file path.
	 * @return string The validated absolute path.
	 *
	 * @throws RuntimeException When the path is out of bounds or no longer exists.
	 */
	private function resolve_in_root( string $file ): string {

		// A file that is merely gone still fails here, and says so in its own words: an
		// out-of-bounds message would misdescribe the ordinary case of a deleted file.
		$abs = $this->locate_in_root( $file );
		if ( $abs === null ) {
			throw new RuntimeException( 'A requested file no longer exists.' );
		}

		return $abs;

	}

	/**
	 * Locates a requested file inside the root, telling "gone" apart from "hostile".
	 *
	 * The path was validated when the job was created, but a job record can be read
	 * again much later; re-resolving it against the installation root here is defence
	 * in depth against a record altered in between, and it is a boundary check, never a
	 * sanitiser — a path that tries to leave the root fails the build outright. The root
	 * and the resolved path are compared on `wp_normalize_path`'d separators so the
	 * boundary holds on Windows/IIS too, where `realpath` renders paths with backslashes
	 * a forward-slash prefix would never match.
	 *
	 * The two outcomes are separated because `strict: false` may skip one of them and
	 * must never skip the other, and the line is drawn exactly where the create path
	 * draws it (Extractions_Controller::classify_files, ADR-0003/0026): a null byte, a
	 * `..` segment, and a path resolving outside the root are out of bounds however they
	 * fail, while a path that never attempted to leave the root and does not resolve is
	 * simply gone. The `..` test stands on its own because a traversal whose target does
	 * not exist would otherwise be indistinguishable from a deleted file — `realpath`
	 * answers false for both — and the create path refuses exactly that case.
	 *
	 * @param string $file The installation-root-relative file path.
	 * @return string|null The validated absolute path, or null when the file no longer exists.
	 *
	 * @throws RuntimeException When the path is out of bounds.
	 */
	private function locate_in_root( string $file ): ?string {

		// Refuse a path that is hostile rather than merely absent: a null byte, which
		// would make realpath raise a ValueError, and a `..` segment, which is a traversal
		// attempt whether or not its target happens to exist. A root that cannot be
		// canonicalised is a broken install and fails closed for the same reason the
		// create path treats every file as out of bounds there.
		$root = realpath( ABSPATH );
		$root = $root === false ? false : wp_normalize_path( $root );
		if ( $root === false || str_contains( $file, "\0" ) || in_array( '..', explode( '/', str_replace( '\\', '/', $file ) ), true ) ) {
			throw new RuntimeException( 'A requested file resolves outside the installation root.' );
		}

		// An unresolvable path inside the root is a file that has gone, which is what the
		// caller may be entitled to skip; a resolved one is accepted only when it sits at
		// or under the root on normalised separators.
		$abs = realpath( $root . '/' . $file );
		if ( $abs === false ) {
			return null;
		}
		$abs = wp_normalize_path( $abs );
		if ( ! ( $abs === $root || str_starts_with( $abs, $root . '/' ) ) ) {
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

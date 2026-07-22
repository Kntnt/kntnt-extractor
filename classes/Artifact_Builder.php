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
 * draws each table's dump and each bounded part of each file and hands them to
 * {@see Sealed_Writer} as ordered segments, so plaintext is only ever the single
 * part being sealed and never a whole plain archive on disk. Full-data tables come
 * first, each a single segment; then structure-only tables (issue #16), each a single
 * DDL-only segment; then files, each split into bounded parts sealed under its
 * installation-root-relative path, so the sealed index can reassemble the ordered
 * parts by that path (AC1).
 *
 * The build is resumable by construction (ADR-0007): {@see advance()} packages
 * exactly ONE bounded chunk — one table dump, or one file part up to the configured
 * chunk size — appends it to the in-progress container, and returns the progress the
 * next tick resumes from, or null once the last chunk has finalized and published
 * the container. Because each segment is sealed independently there is no
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
	 * @since 0.1.0
	 */
	private const int DEFAULT_CHUNK_SIZE = 8388608;

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
	 * A single call seals exactly one segment — the next table dump, or the next
	 * bounded part of the file currently being packaged — into the in-progress
	 * container at `$build_path`, appending to whatever earlier ticks left. When that
	 * segment is the last of the selection the container's sealed index is written and
	 * the finished container is published to `$download_path` with a single atomic
	 * rename, so a ready poll never observes a partial container (ADR-0004/0008).
	 *
	 * The job's own persisted {@see Build_Progress} (null before the first chunk) says
	 * where to resume; the return value is the progress the next tick resumes from, or
	 * null once the build is complete and published. The build is crash-safe: reopening
	 * truncates the container back to the committed offset, so a partial write a crashed
	 * tick left behind is discarded rather than sealed into the result (AC3).
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job           The running job whose selection to package.
	 * @param string         $build_path    Absolute path of the in-progress container in the job's state directory.
	 * @param string         $download_path Absolute path the finished container is published to.
	 * @return Build_Progress|null The progress to persist and resume from, or null once complete.
	 *
	 * @throws RuntimeException When the public key is undecodable, a file resolves outside
	 *                          the root or cannot be read, or the container cannot be
	 *                          written or published.
	 */
	public function advance( Extraction_Job $job, string $build_path, string $download_path ): ?Build_Progress {

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
			$names = [];
			$writer->open( $public_key );
		} else {

			// A prior tick may have finalized and published the container, then died in
			// the window before its ready state was saved; the build file is gone but the
			// finished artifact already sits at the download path. Treat that as complete
			// rather than failing to resume a container that was correctly moved away.
			if ( ! is_file( $build_path ) && is_file( $download_path ) ) {
				return null;
			}
			$tables_done = $progress->tables_done;
			$structure_done = $progress->structure_done;
			$file_index = $progress->file_index;
			$file_offset = $progress->file_offset;
			$file_size = $progress->file_size;
			$file_mtime = $progress->file_mtime;
			$names = $progress->segment_names;
			$writer->resume( $public_key, $names, $progress->container_bytes );
		}

		// Seal the next bounded chunk in a fixed order: every full-data table as one
		// segment first, then every structure-only table as one DDL-only segment (issue
		// #16), then each file as bounded parts under its relative path. When all three
		// selections are exhausted there is no data segment left and only the trailer
		// remains to be written.
		if ( $tables_done < count( $job->tables ) ) {
			$table = $job->tables[ $tables_done ];
			$writer->add_segment( $table, $this->stream_of( $this->dumper->dump( $table ) ) );
			$names[] = $table;
			++$tables_done;
		} elseif ( $structure_done < count( $job->structure_only ) ) {
			$table = $job->structure_only[ $structure_done ];
			$writer->add_segment( $table, $this->stream_of( $this->dumper->dump_structure( $table ) ) );
			$names[] = $table;
			++$structure_done;
		} elseif ( $file_index < count( $job->files ) ) {
			$file = $job->files[ $file_index ];
			[ $part, $next_offset, $file_done, $file_size, $file_mtime ] = $this->read_part( $file, $file_offset, $file_size, $file_mtime );
			$writer->add_segment( $file, $this->stream_of( $part ) );
			$names[] = $file;
			if ( $file_done ) {
				++$file_index;
				$file_offset = 0;
				$file_size = null;
				$file_mtime = null;
			} else {
				$file_offset = $next_offset;
			}
		} else {
			$writer->finalize();
			$this->publish( $build_path, $download_path );
			return null;
		}

		// The build is complete once the last table and the last file part are sealed:
		// finalize the sealed index and publish the container in one atomic rename.
		// Otherwise suspend the container and hand back the offset the next tick resumes
		// from, so a completed segment is never redone or re-encrypted.
		if ( $tables_done >= count( $job->tables ) && $structure_done >= count( $job->structure_only ) && $file_index >= count( $job->files ) ) {
			$writer->finalize();
			$this->publish( $build_path, $download_path );
			return null;
		}
		$container_bytes = $writer->suspend();

		return new Build_Progress( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $names, $file_size, $file_mtime );

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
	 * @return array{0: string, 1: int, 2: bool, 3: int, 4: int} The part bytes, the offset
	 *         after it, whether the file is now fully packaged, and the pinned size and mtime.
	 *
	 * @throws RuntimeException When the path resolves outside the root, cannot be read, or
	 *                          changed since its first part was sealed.
	 */
	private function read_part( string $file, int $offset, ?int $expected_size, ?int $expected_mtime ): array {

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
		$part = $offset < $size ? (string) fread( $handle, max( 1, $this->chunk_size() ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading one bounded file part into the sealed writer; WP_Filesystem has no incremental-read API.
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
	 * Wraps a byte string in a rewound in-memory stream for the sealed writer.
	 *
	 * A `php://temp` stream keeps the segment in memory for small chunks and spills to a
	 * temp file only if it grows large, matching the writer's one-segment working set.
	 *
	 * @since 0.1.0
	 *
	 * @param string $data The segment's plaintext — a table dump or a bounded file part.
	 * @return resource A rewound readable stream over the data.
	 *
	 * @throws RuntimeException When the in-memory stream cannot be opened.
	 */
	private function stream_of( string $data ) {

		// Buffer the bounded chunk in memory and rewind it for the streaming writer.
		$stream = fopen( 'php://temp', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory buffer handed to the streaming sealed writer, not a filesystem write.
		if ( $stream === false ) {
			throw new RuntimeException( 'Unable to open an in-memory stream for a segment.' );
		}
		fwrite( $stream, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to the in-memory buffer above.
		rewind( $stream );

		return $stream;

	}

	/**
	 * Resolves the file-part chunk size through the Config seam, clamped to at least one.
	 *
	 * @since 0.1.0
	 *
	 * @return int The maximum bytes packaged into one file part.
	 */
	private function chunk_size(): int {

		$configured = $this->config->get( 'chunk_size', self::DEFAULT_CHUNK_SIZE );

		return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_CHUNK_SIZE );

	}

}

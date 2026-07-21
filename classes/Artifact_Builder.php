<?php
/**
 * Builds a job's sealed artifact from its resolved table and file selection.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor;

use Kntnt\Extractor\Crypto\Sealed_Writer;
use RuntimeException;
use Throwable;

/**
 * Packages a job's selection into one sealed, per-segment-encrypted artifact.
 *
 * This is the seam between a resolved job and the crypto container: it draws each
 * table's dump and each file's bytes and hands them to {@see Sealed_Writer} as
 * ordered segments, so plaintext is only ever the single segment being sealed and
 * never a whole plain archive on disk (ADR-0009). Tables come first, then files,
 * and that order is the sealed index the caller reads back.
 *
 * Files are packaged whole here. Splitting a large file into bounded parts — the
 * other half of ADR-0009's resumable format — is a later concern; the tracer
 * bullet this belongs to seals a small selection in a single pass.
 *
 * @since 0.1.0
 */
final class Artifact_Builder {

	/**
	 * Binds the builder to the table dumper it draws SQL segments from.
	 *
	 * @since 0.1.0
	 *
	 * @param Table_Dumper $dumper Produces each table's `mysqldump`-compatible SQL.
	 */
	public function __construct(
		private readonly Table_Dumper $dumper,
	) {}

	/**
	 * Seals the job's selection into the container at the given destination.
	 *
	 * The job's public key seals every segment, so only the caller's private key can
	 * open the result. The write is encrypt-as-you-go: each table dump and each file
	 * is sealed and appended in turn, and the container is finalized once.
	 *
	 * The container is built into a private temporary file and published into place
	 * with a single atomic rename. The extraction driver is deliberately lock-free
	 * (ADR-0007), so two ticks can briefly race the same job; building to a temp file
	 * and renaming means such a race can only ever replace one complete artifact with
	 * another complete one, never leave the served path holding a half-written,
	 * truncated container a ready poll would hand out. A build that fails removes its
	 * temporary file rather than leaving a partial one behind in the served directory.
	 *
	 * @since 0.1.0
	 *
	 * @param Extraction_Job $job              The running job whose selection to seal.
	 * @param string         $destination_path Absolute path the sealed container is published to.
	 * @return void
	 *
	 * @throws RuntimeException When the job's public key is undecodable, a requested
	 *                          file resolves outside the root or cannot be opened, or
	 *                          the container cannot be written or published.
	 * @throws Throwable        Re-raised after a failed build removes its partial temp
	 *                          artifact, so the driver can move the job to failed.
	 */
	public function build( Extraction_Job $job, string $destination_path ): void {

		// Recover the 32 raw bytes the seal draws each segment's key against from the
		// canonical base64 the job persisted; an undecodable key is a corrupt record.
		$public_key = base64_decode( $job->public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the job's stored X25519 public key, not obfuscating code.
		if ( $public_key === false ) {
			throw new RuntimeException( 'The job public key is not decodable base64.' );
		}

		// Build into a private temp file beside the destination so the rename below is a
		// same-directory, atomic swap; a random suffix keeps two racing builds from
		// sharing a temp path.
		$temp_path = $destination_path . '.' . bin2hex( random_bytes( 8 ) ) . '.part';

		try {

			// Seal the selection in order — every table as a SQL segment, then every file
			// as its own segment — and close the container exactly once.
			$writer = new Sealed_Writer( $temp_path );
			$writer->open( $public_key );
			foreach ( $job->tables as $table ) {
				$writer->add_segment( $table, $this->sql_stream( $this->dumper->dump( $table ) ) );
			}
			foreach ( $job->files as $file ) {
				$writer->add_segment( $file, $this->file_stream( $file ) );
			}
			$writer->finalize();

			// Publish the finished container in one atomic rename, so the served path is
			// never observed mid-write.
			if ( ! rename( $temp_path, $destination_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic same-directory publish of the plugin's own sealed artifact; WP_Filesystem::move offers no atomicity guarantee.
				throw new RuntimeException( 'Unable to publish the sealed artifact into place.' );
			}

		} catch ( Throwable $error ) {

			// Never leave a partial temp file behind in the served directory; drop it and
			// re-raise so the driver can move the job to failed.
			if ( is_file( $temp_path ) ) {
				unlink( $temp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own abandoned temp artifact from its scratch area.
			}
			throw $error;

		}

	}

	/**
	 * Wraps a table dump in an in-memory stream for the sealed writer to consume.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql The table's dumped SQL.
	 * @return resource A rewound readable stream over the SQL.
	 *
	 * @throws RuntimeException When the in-memory stream cannot be opened.
	 */
	private function sql_stream( string $sql ) {

		// A php://temp stream keeps the segment in memory for small dumps and spills
		// to a temp file only if it grows large, matching the writer's one-segment
		// working set.
		$stream = fopen( 'php://temp', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an in-memory buffer handed to the streaming sealed writer, not a filesystem write.
		if ( $stream === false ) {
			throw new RuntimeException( 'Unable to open an in-memory stream for a table dump.' );
		}
		fwrite( $stream, $sql ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to the in-memory buffer above.
		rewind( $stream );

		return $stream;

	}

	/**
	 * Opens a requested file for reading after re-checking it is inside the root.
	 *
	 * The path was validated when the job was created, but a job record can be read
	 * again much later; re-resolving it against the installation root here is defence
	 * in depth against a record altered in between, and it is a boundary check, never
	 * a sanitiser — a path that resolves outside the root fails the build outright.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file The installation-root-relative file path.
	 * @return resource A readable stream over the file.
	 *
	 * @throws RuntimeException When the path resolves outside the root or cannot be opened.
	 */
	private function file_stream( string $file ) {

		// Fail closed unless the path resolves to a real location at or under the
		// canonical installation root. The root and the resolved path are compared on
		// wp_normalize_path'd separators so the boundary holds on Windows/IIS too, where
		// realpath renders paths with backslashes a forward-slash prefix would never
		// match — the same normalisation the create-time check applies
		// (Extractions_Controller::first_out_of_root_file). A null byte counts as out of
		// root because realpath would raise a ValueError on it.
		$root = realpath( ABSPATH );
		$root = $root === false ? false : wp_normalize_path( $root );
		$abs = $root === false || str_contains( $file, "\0" ) ? false : realpath( $root . '/' . $file );
		$abs = $abs === false ? false : wp_normalize_path( $abs );
		if ( $root === false || $abs === false || ! ( $abs === $root || str_starts_with( $abs, $root . '/' ) ) ) {
			throw new RuntimeException( 'A requested file resolves outside the installation root.' );
		}

		// Open the validated absolute path for the writer to seal.
		$stream = fopen( $abs, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a selected file into the sealed writer; WP_Filesystem has no incremental-read API.
		if ( $stream === false ) {
			throw new RuntimeException( 'Unable to open a requested file for packaging.' );
		}

		return $stream;

	}

}

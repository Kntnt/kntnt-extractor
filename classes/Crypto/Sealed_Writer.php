<?php
/**
 * The per-segment sealed encryption container every extraction writes through.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Crypto;

use LogicException;
use RuntimeException;

/**
 * Builds an artifact of independently-encrypted segments, sealed to the caller.
 *
 * Every `sodium` call and every byte of container framing lives behind this one
 * seam; nothing else in the codebase touches the crypto. The container is built
 * encrypt-as-you-go — each segment is ciphered and written the moment it is
 * added — so plaintext never accumulates on disk and the writer needs to hold
 * only one segment in memory at a time (ADR-0009).
 *
 * The key handling is asymmetric and one-directional. For each segment the
 * writer draws a fresh random symmetric key, encrypts the segment with it, seals
 * that key to the caller's ephemeral X25519 public key, writes the ciphertext
 * and the sealed key, and zeroes the plaintext key. The server therefore never
 * retains a key that can decrypt its own output: only the caller's private key,
 * which never reaches the server, can open the artifact. The index of segment
 * names is itself sealed, so a holder of only the artifact learns nothing about
 * which tables or files it contains.
 *
 * ## Wire format
 *
 * ```
 * MAGIC (8 bytes) | FORMAT_VERSION (1 byte)
 * repeated per segment, in order:
 *     sk_length   (8 bytes, unsigned 64-bit little-endian)
 *     sealed_key  (sk_length bytes, sodium box_seal of the segment's symmetric key)
 *     nonce       (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
 *     ct_length   (8 bytes, unsigned 64-bit little-endian)
 *     ciphertext  (ct_length bytes, sodium secretbox output incl. its MAC)
 * trailer:
 *     sealed_index (sodium box_seal of the length-prefixed name list)
 *     index_length (8 bytes, unsigned 64-bit little-endian)
 * ```
 *
 * The reader takes the last 8 bytes to find the sealed index, unseals it for the
 * ordered names, and walks the self-framed segment records in between. Every
 * variable-length field carries its own length, so the format depends on no
 * `sodium` size constant. Segment sizes and count are visible framing; the names
 * and every plaintext are not. Inside the index each name is likewise prefixed with
 * its own 64-bit little-endian length, so the list round-trips any byte sequence a
 * file path may hold, independent of character encoding, with no delimiter it could
 * collide with.
 *
 * ## The index sidecar
 *
 * The index payload is accumulated on disk beside the in-progress container rather
 * than in memory or in the job record. A build spans thousands of ticks, and the
 * name list grows by one entry per segment, so carrying it through the job record
 * made every per-chunk save re-encode and rewrite a document that grew without
 * bound — the single largest cost in a large extraction, and one that bought
 * nothing, since only {@see finalize()} ever reads the list. Appending each name to
 * a sidecar as it is sealed costs one write of its own length and keeps the job's
 * persisted state O(1) in the selection.
 *
 * The sidecar holds exactly the bytes {@see finalize()} seals — the same
 * length-prefixed framing the index has always used — so it is the index payload
 * under construction, not a second encoding of it. It is anchored the same way the
 * container is: {@see suspend()} reports its committed length, {@see resume()}
 * truncates back to that length, and the two roll back together, so a tick killed
 * between appending a segment and persisting its progress leaves neither file
 * ahead of the other. It lives in the job's own deny-hardened state directory and
 * is never published. {@see finalize()} seals its names into the container's
 * trailer but leaves the sidecar itself on disk; it is discarded, via
 * {@see discard_index()}, only once the caller has published the finished
 * container, so a crash in between still leaves a resumable build rather than a
 * finished container with no way back to it.
 *
 * @since 0.1.0
 */
final class Sealed_Writer {

	/**
	 * Magic bytes that identify the container format.
	 *
	 * @since 0.1.0
	 */
	public const string MAGIC = 'KNTNTEXT';

	/**
	 * Version of the wire format documented on this class.
	 *
	 * The format is caller-visible and therefore bound to the API version
	 * (ADR-0009); a change callers can observe increments this.
	 *
	 * @since 0.1.0
	 */
	public const int FORMAT_VERSION = 1;

	/**
	 * Handle to the container file while it is open, `null` before {@see open()}
	 * and after {@see finalize()}.
	 *
	 * @since 0.1.0
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * The caller's X25519 public key while the container is open.
	 *
	 * Held only for the build; cleared by {@see finalize()}. It cannot open the
	 * artifact, so retaining it does not weaken the seal.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $public_key = null;

	/**
	 * Suffix appended to the container's path to name its index sidecar.
	 *
	 * @since 0.6.0
	 */
	public const string INDEX_SUFFIX = '.names';

	/**
	 * Handle to the index sidecar while the container is open, `null` otherwise.
	 *
	 * Opened and closed in lockstep with {@see $handle}, so the two files are only
	 * ever written by the same live writer and are suspended together.
	 *
	 * @since 0.6.0
	 *
	 * @var resource|null
	 */
	private $index_handle = null;

	/**
	 * Binds the writer to the path its container will be written to.
	 *
	 * @since 0.1.0
	 *
	 * @param string $destination_path Absolute path the container is written to.
	 *                                 Overwritten when {@see open()} is called.
	 */
	public function __construct(
		private readonly string $destination_path,
	) {}

	/**
	 * Begins a container sealed to the caller's public key.
	 *
	 * Validates the key before touching the filesystem, then opens the
	 * destination and writes the format header. A rejected key leaves no file
	 * behind.
	 *
	 * @since 0.1.0
	 *
	 * @param string $public_key The caller's ephemeral X25519 public key, exactly
	 *                           `SODIUM_CRYPTO_BOX_PUBLICKEYBYTES` bytes.
	 * @return void
	 *
	 * @throws LogicException     When a container is already open on this writer.
	 * @throws Invalid_Public_Key When the key is absent or the wrong length.
	 * @throws RuntimeException   When the destination cannot be opened or the
	 *                            header cannot be written.
	 */
	public function open( string $public_key ): void {

		// Refuse to reopen an already-open container: a second open() would leak
		// the live handle and truncate the file via fopen('wb') while leaving the
		// prior segment names in the index. The lifecycle is exactly one
		// open→add*→finalize per writer — one writer per extraction (ADR-0009).
		if ( $this->handle !== null ) {
			throw new LogicException( 'Sealed_Writer::open() cannot reopen an already-open container; call finalize() first.' );
		}

		// Reject an absent or malformed key before any byte reaches disk — an
		// X25519 public key is exactly SODIUM_CRYPTO_BOX_PUBLICKEYBYTES long.
		if ( strlen( $public_key ) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ) {
			throw new Invalid_Public_Key( 'A sealed container requires a 32-byte X25519 public key.' );
		}

		// Open the destination for writing; a failure here is a filesystem fault,
		// not a caller error. Direct stream I/O is required because the container
		// is written incrementally and WP_Filesystem cannot append a chunk.
		$handle = fopen( $this->destination_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming encrypt-as-you-go write; WP_Filesystem has no incremental-append API.
		if ( $handle === false ) {
			throw new RuntimeException( 'Unable to open the sealed container for writing.' );
		}

		// Start the index sidecar empty, discarding any residue an abandoned build at
		// this path left behind, so the accumulated names describe this container alone.
		$index_handle = fopen( $this->index_path(), 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the container's own index sidecar, appended one name per segment; see the class docblock.
		if ( $index_handle === false ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the container after the sidecar could not be opened; see the fopen above.
			throw new RuntimeException( 'Unable to open the sealed container index for writing.' );
		}

		// Lay down the versioned format header and record the open container.
		$this->write( $handle, self::MAGIC . chr( self::FORMAT_VERSION ) );
		$this->handle = $handle;
		$this->index_handle = $index_handle;
		$this->public_key = $public_key;

	}

	/**
	 * Writes the index sidecar from an already-committed name list, returning its length.
	 *
	 * The migration path for a job whose build began under record schema 6, where the
	 * committed names lived in the job record and no sidecar existed. Those names ARE
	 * the committed index payload, so writing them out reconstructs exactly the sidecar
	 * this build would have had, and the returned length is the anchor
	 * {@see resume()} then truncates back to. Called at most once per job, on the first
	 * tick after the upgrade; every later tick resumes from the sidecar like any other.
	 *
	 * Without it an in-flight build could not be resumed at all — {@see resume()} fails
	 * closed on a missing sidecar rather than silently sealing an index that names only
	 * the segments written after the upgrade — and an extraction hours into its run
	 * would be abandoned by installing a release. Schema 6 is the last shape that
	 * carried the names, so this can be dropped once no such record can still be live.
	 *
	 * @since 0.6.0
	 *
	 * @param array<int, string> $committed_names Names of the segments already written, in order.
	 * @return int Byte length of the reconstructed sidecar.
	 *
	 * @throws LogicException   When a container is already open on this writer.
	 * @throws RuntimeException When the sidecar cannot be written in full.
	 */
	public function seed_index( array $committed_names ): int {

		// Refuse to rewrite the sidecar under a live writer: it is append-only from
		// open()/resume() onwards, and rewriting it whole would drop names already sealed.
		if ( $this->handle !== null ) {
			throw new LogicException( 'Sealed_Writer::seed_index() cannot rewrite the index of an open container; call finalize() or suspend() first.' );
		}

		// Encode the names in the index's own framing and publish the sidecar whole; a
		// short write would understate the anchor and silently drop names on resume.
		$payload = '';
		foreach ( $committed_names as $name ) {
			$payload .= pack( 'P', strlen( $name ) ) . $name;
		}
		$written = file_put_contents( $this->index_path(), $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- reconstructing the container's own index sidecar in the plugin's scratch area; WP_Filesystem would demand FTP credentials on some hosts.
		if ( $written === false || $written < strlen( $payload ) ) {
			throw new RuntimeException( 'Unable to reconstruct the sealed container index.' );
		}

		return strlen( $payload );

	}

	/**
	 * Reopens an in-progress container to append the next sealed segment.
	 *
	 * The chunked, resumable build (ADR-0007) writes one bounded segment per tick and
	 * finalizes only once, so between ticks the container is a header plus the
	 * segments sealed so far, with no trailer yet. Resuming restores exactly the
	 * state {@see add_segment()} and {@see finalize()} need — the caller's key, and the
	 * two files positioned to append — and nothing else. Because each segment is sealed
	 * independently there is no cross-segment authentication state to restore, and the
	 * ordered names are already on disk in the sidecar rather than in any caller's hand.
	 *
	 * The container and its index sidecar are first truncated back to their committed
	 * byte lengths. Those lengths are the resume anchors persisted after the last clean
	 * tick, so a partial record a crashed tick left past either one is discarded here
	 * rather than sealed into the result — the write path's counterpart to
	 * {@see write()}'s short-write guard. Both are rolled back in the same call because
	 * a crash can land between the two appends: truncating only the container would
	 * leave the index naming a segment that no longer exists.
	 *
	 * An anchor is trusted only after it is proven consistent with the file on disk.
	 * A file shorter than its anchor — a torn progress/container write (they live
	 * in separate files with no ordering guarantee), a truncated file, or tampering —
	 * would otherwise have `ftruncate` EXTEND it with NUL bytes, sealing a run of zeros
	 * into the record stream and publishing a well-framed but unopenable artifact.
	 * Every neighbouring path in this seam fails closed; so does this one. A missing
	 * sidecar fails closed for the same reason: it means the committed names are gone,
	 * and continuing would publish an index that omits every segment sealed so far.
	 *
	 * @since 0.1.0
	 *
	 * @param string $public_key            The caller's ephemeral X25519 public key, exactly
	 *                                      `SODIUM_CRYPTO_BOX_PUBLICKEYBYTES` bytes.
	 * @param int    $committed_bytes       Byte length the container is truncated back to.
	 * @param int    $committed_index_bytes Byte length the index sidecar is truncated back to.
	 * @return void
	 *
	 * @throws LogicException     When a container is already open on this writer.
	 * @throws Invalid_Public_Key When the key is absent or the wrong length.
	 * @throws RuntimeException    When either anchor is inconsistent with the file on
	 *                             disk, or the container or its sidecar cannot be
	 *                             reopened, truncated, or positioned for appending.
	 */
	public function resume( string $public_key, int $committed_bytes, int $committed_index_bytes ): void {

		// Refuse to reopen a live writer, and reject a malformed key, exactly as open()
		// does — the same lifecycle and key contract applies to a resumed container.
		if ( $this->handle !== null ) {
			throw new LogicException( 'Sealed_Writer::resume() cannot reopen an already-open container; call finalize() or suspend() first.' );
		}
		if ( strlen( $public_key ) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ) {
			throw new Invalid_Public_Key( 'A sealed container requires a 32-byte X25519 public key.' );
		}

		// Reopen the existing container for in-place update before trusting anything about
		// it — an fstat() on the open handle re-walks no path component and cannot race a
		// separate fopen() the way a stat-then-open pair can, closing the
		// time-of-check-to-time-of-use gap the two calls used to leave open. A failure
		// here is "the file cannot be opened", a fault distinct from "the file is shorter
		// than its anchor" below, and the two throw different messages so a caller can
		// tell them apart.
		$header_length = strlen( self::MAGIC ) + 1;
		$handle = fopen( $this->destination_path, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- resuming a streaming encrypt-as-you-go write; WP_Filesystem has no incremental-append API.
		if ( $handle === false ) {
			throw new RuntimeException( 'Unable to reopen the in-progress sealed container.' );
		}

		// Fail closed on an anchor that cannot describe a real in-progress container:
		// it must at least cover the format header, and the file on disk must already be
		// that long. A shorter file means the anchor and the container disagree, and
		// extending it with zeros would corrupt the sealed result — so it is rejected
		// rather than coerced.
		$stat = fstat( $handle );
		$size = $stat !== false ? $stat['size'] : false;
		if ( $committed_bytes < $header_length || $size === false || $size < $committed_bytes ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle after a rejected anchor; see open().
			throw new RuntimeException( 'The in-progress sealed container is shorter than its committed offset.' );
		}

		// Verify the reopened file still begins with this format's header before trusting
		// the anchor any further, then drop anything past the committed offset a crashed
		// tick may have left and position at the end so the next segment appends cleanly.
		$header = (string) fread( $handle, $header_length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading the container's own format header to validate the resume anchor.
		if ( $header !== self::MAGIC . chr( self::FORMAT_VERSION ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle after a rejected header; see open().
			throw new RuntimeException( 'The in-progress sealed container is missing its format header.' );
		}

		// Discard a crashed tick's unacknowledged tail — but only when there is one. The
		// check above has already proved the file is at least as long as the anchor, so
		// an equal length means nothing to roll back, and truncating anyway would spend a
		// synchronous metadata round trip on a file that grows past a gigabyte, once per
		// chunk, to change nothing. The seek is unconditional: the handle must be
		// positioned to append whether or not anything was discarded.
		if ( $size > $committed_bytes && ftruncate( $handle, $committed_bytes ) === false ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle after a failed rollback; see open().
			throw new RuntimeException( 'Unable to position the in-progress sealed container for appending.' );
		}
		if ( fseek( $handle, $committed_bytes ) === -1 ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle after a failed reopen; see open().
			throw new RuntimeException( 'Unable to position the in-progress sealed container for appending.' );
		}

		// Roll the sidecar back to its own anchor in the same way, so a name a crashed
		// tick appended for a segment the truncation above just discarded goes with it.
		// Opening it before stat'ing it closes the same TOCTOU gap the container's anchor
		// check just closed, and again separates "cannot open" from "shorter than its
		// anchor" into two distinguishable faults.
		$index_handle = fopen( $this->index_path(), 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- resuming the container's own index sidecar; see the class docblock.
		if ( $index_handle === false ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the container after the sidecar could not be opened; see open().
			throw new RuntimeException( 'Unable to reopen the in-progress sealed container index.' );
		}
		$index_stat = fstat( $index_handle );
		$index_size = $index_stat !== false ? $index_stat['size'] : false;
		if ( $committed_index_bytes < 0 || $index_size === false || $index_size < $committed_index_bytes ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the container after the sidecar's anchor was rejected; see open().
			fclose( $index_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the sidecar handle after its anchor was rejected.
			throw new RuntimeException( 'The in-progress sealed container index is shorter than its committed offset.' );
		}
		if ( $index_size > $committed_index_bytes && ftruncate( $index_handle, $committed_index_bytes ) === false ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the container after the sidecar could not be positioned; see open().
			fclose( $index_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the sidecar handle after a failed rollback.
			throw new RuntimeException( 'Unable to position the in-progress sealed container index for appending.' );
		}
		if ( fseek( $index_handle, $committed_index_bytes ) === -1 ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the container after the sidecar could not be positioned; see open().
			fclose( $index_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the sidecar handle after a failed seek.
			throw new RuntimeException( 'Unable to position the in-progress sealed container index for appending.' );
		}

		// Restore the writer's state so add_segment() and finalize() continue the build.
		$this->handle = $handle;
		$this->index_handle = $index_handle;
		$this->public_key = $public_key;

	}

	/**
	 * Flushes and closes the container without a trailer, returning its byte length.
	 *
	 * This ends a non-final chunk: the container keeps its header and the segments
	 * sealed so far, but no trailer, so the next tick can {@see resume()} it. The
	 * returned lengths are the committed byte offsets the resume truncates each file
	 * back to. Like {@see finalize()}, this drops every reference, but unlike it the
	 * container is left mid-build rather than sealed shut.
	 *
	 * Both files are suspended in one call, and a failure in either escalates, because
	 * the pair of offsets is only usable as a pair: persisting one that reached disk
	 * beside one that did not would let the next resume roll the two files back to
	 * points that disagree.
	 *
	 * @since 0.1.0
	 *
	 * @return array{0: int, 1: int} The committed byte lengths of the in-progress
	 *         container and of its index sidecar.
	 *
	 * @throws LogicException   When called before {@see open()} or {@see resume()}.
	 * @throws RuntimeException When either file cannot be flushed, measured, or closed.
	 */
	public function suspend(): array {

		// Require an open container: guards the ordering contract and narrows the handles
		// away from null.
		$handle = $this->handle;
		$index_handle = $this->index_handle;
		if ( $handle === null || $index_handle === null ) {
			throw new LogicException( 'Sealed_Writer::open() or resume() must be called before suspend().' );
		}

		// Flush buffered bytes and measure both committed lengths before closing, so the
		// next tick's resume truncates each file to exactly what reached disk. A failed
		// flush or unreadable position means an offset would be untrustworthy, so it is
		// escalated rather than persisted.
		$flushed = fflush( $handle );
		$index_flushed = fflush( $index_handle );
		$bytes = ftell( $handle );
		$index_bytes = ftell( $index_handle );
		$closed = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- suspending a streaming encrypt-as-you-go write; see open().
		$index_closed = fclose( $index_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- suspending the container's own index sidecar; see open().
		$this->handle = null;
		$this->index_handle = null;
		$this->public_key = null;
		if ( $flushed === false || $index_flushed === false || $bytes === false || $index_bytes === false || $closed === false || $index_closed === false ) {
			throw new RuntimeException( 'Unable to suspend the in-progress sealed container.' );
		}

		return [ $bytes, $index_bytes ];

	}

	/**
	 * Encrypts one segment and appends it to the container.
	 *
	 * The segment is encrypted under a fresh random symmetric key, and that key
	 * is sealed to the caller's public key; the plaintext key and the plaintext
	 * itself are then zeroed. The caller already holds the segment as a bounded
	 * string (a table dump or a chunk of a file), so it is taken directly rather
	 * than through any intermediate stream.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name      Identifier recorded (sealed) in the index — a
	 *                          table name or an installation-root-relative file
	 *                          path.
	 * @param string $plaintext The segment's bounded plaintext.
	 * @return void
	 *
	 * @throws LogicException When called before {@see open()}.
	 */
	public function add_segment( string $name, string $plaintext ): void {

		// Require an open container: this guards the open→add→finalize order and
		// narrows the handles and key away from null for the operations below.
		$handle = $this->handle;
		$index_handle = $this->index_handle;
		$public_key = $this->public_key;
		if ( $handle === null || $index_handle === null || $public_key === null ) {
			throw new LogicException( 'Sealed_Writer::open() must be called before add_segment().' );
		}

		// Encrypt under a fresh random symmetric key and seal that key to the
		// caller's public key, then wipe the key and the plaintext so the server
		// keeps nothing able to open its own output (ADR-0009). The wipe runs in
		// every environment — see {@see wipe()} for how it scrubs with or without
		// the native extension.
		$key = sodium_crypto_secretbox_keygen();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		$sealed_key = sodium_crypto_box_seal( $key, $public_key );
		$this->wipe( $key );
		$this->wipe( $plaintext );

		// Append the self-framed segment record and its name's index entry. Both the
		// sealed key and the ciphertext carry their own length so the reader needs
		// no box_seal size constant, and the name is framed the same way so the
		// sidecar is the index payload as it accumulates.
		$this->write( $handle, pack( 'P', strlen( $sealed_key ) ) . $sealed_key . $nonce . pack( 'P', strlen( $ciphertext ) ) . $ciphertext );
		$this->write( $index_handle, pack( 'P', strlen( $name ) ) . $name );

	}

	/**
	 * Seals the index, writes the trailer, and closes the container.
	 *
	 * After this call the writer holds no value able to open the artifact: the
	 * handles are closed and the public key is dropped, and every segment's
	 * symmetric key was already zeroed in {@see add_segment()}. The index sidecar
	 * survives this call — it is the caller's to remove, via {@see discard_index()},
	 * once the finished container has been published. A crash between the two would
	 * otherwise strand a container that is complete on disk but whose sidecar is
	 * gone, and {@see resume()} fails closed on a missing sidecar rather than risk
	 * publishing an index that omits segments already sealed.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 *
	 * @throws LogicException   When called before {@see open()}.
	 * @throws RuntimeException When the index cannot be read, the trailer cannot be
	 *                          written, or the container cannot be closed cleanly.
	 */
	public function finalize(): void {

		// Require an open container: guards the ordering contract and narrows the
		// handles and key away from null.
		$handle = $this->handle;
		$index_handle = $this->index_handle;
		$public_key = $this->public_key;
		if ( $handle === null || $index_handle === null || $public_key === null ) {
			throw new LogicException( 'Sealed_Writer::open() must be called before finalize().' );
		}

		// Close the sidecar and read back the payload it accumulated — the names of
		// every segment this container holds, already in the index's own framing. A
		// short flush here would silently drop the tail of the index, so an unreadable
		// payload fails the build rather than sealing an incomplete one.
		$index_closed = fclose( $index_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the index sidecar before reading the payload it accumulated; see open().
		$this->index_handle = null;
		$index = $index_closed === false ? false : file_get_contents( $this->index_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the container's own index sidecar from the plugin's scratch area, not a remote resource.
		if ( $index === false ) {
			throw new RuntimeException( 'Unable to read the sealed container index before finalizing.' );
		}

		// Seal the index of names so a holder of only the artifact cannot tell
		// which tables or files it contains, then frame its length as the trailer
		// the reader locates it by.
		$sealed_index = sodium_crypto_box_seal( $index, $public_key );
		$this->write( $handle, $sealed_index . pack( 'P', strlen( $sealed_index ) ) );

		// Close the container and drop every reference the writer held on it, leaving
		// the sidecar in place: it is pure working state now that its names are sealed
		// into the container, but it is the caller's to discard once the container is
		// published, not this method's. A failed close can mean buffered trailer bytes
		// never reached disk — a truncated artifact — so it is escalated, but only once
		// the references are already gone.
		$closed = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming encrypt-as-you-go write; see open().
		$this->handle = null;
		$this->public_key = null;
		if ( $closed === false ) {
			throw new RuntimeException( 'Unable to close the sealed container after writing its trailer.' );
		}

	}

	/**
	 * Returns the path of the container's index sidecar.
	 *
	 * Derived from the container's own path so the two are always siblings and a
	 * relocated working directory moves both together.
	 *
	 * @since 0.6.0
	 *
	 * @return string Absolute path to the index sidecar.
	 */
	private function index_path(): string {

		return $this->destination_path . self::INDEX_SUFFIX;

	}

	/**
	 * Removes the index sidecar once the container it belongs to has been published.
	 *
	 * The sidecar is working state, not part of the artifact: {@see finalize()} seals
	 * its names into the container's trailer and leaves the sidecar on disk so a crash
	 * before the caller publishes the finished container can still {@see resume()} and
	 * retry rather than strand a complete container behind a missing sidecar. Once the
	 * caller has moved the container into place it can never be resumed again, so the
	 * sidecar is safe to discard — the caller does so by calling this method. Safe to
	 * call when the sidecar is already gone: it is best-effort, since by the time it
	 * runs the sidecar is pure residue and a failure to unlink it cannot affect the
	 * published artifact. The job directory is removed whole on consume, cancel, or
	 * sweep in any case.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public function discard_index(): void {

		if ( is_file( $this->index_path() ) ) {
			unlink( $this->index_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own index sidecar once its names are sealed into the container.
		}

	}

	/**
	 * Writes a complete buffer to the open container or fails loudly.
	 *
	 * `fwrite()` can write fewer bytes than asked — or none — when the disk or
	 * the account quota fills, which is a real possibility here because an
	 * extraction dumps whole tables and large files. A silent short write would
	 * truncate this security-critical artifact while {@see finalize()} still
	 * reported success, so any incomplete write is escalated to a
	 * `RuntimeException` the job can surface rather than shipping a valid-looking
	 * but short container. This mirrors the seam's existing handling of a failed
	 * open and a failed stream read.
	 *
	 * @since 0.1.0
	 *
	 * @param resource $handle Open container stream to write to.
	 * @param string   $bytes  Buffer that must be written in full.
	 * @return void
	 *
	 * @throws RuntimeException When fewer than all bytes are written.
	 */
	private function write( $handle, string $bytes ): void {

		// Treat anything short of the full buffer — including an outright false —
		// as a fatal build error, since it leaves a truncated container.
		$written = fwrite( $handle, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming encrypt-as-you-go write; see open().
		if ( $written !== strlen( $bytes ) ) {
			throw new RuntimeException( 'A partial write truncated the sealed container.' );
		}

	}

	/**
	 * Scrubs a secret's bytes from the given variable once it is no longer needed.
	 *
	 * The native `sodium` extension bundled with the required PHP 8.4 overwrites
	 * the string in place in constant time (and leaves the variable `null`).
	 * WordPress's pure-PHP `sodium_compat` fallback cannot do that and throws
	 * from {@see sodium_memzero()} rather than feign a secure wipe, so where the
	 * extension is absent this overwrites the bytes with zeros instead — best
	 * effort, not constant time, but it still clears the secret from the variable
	 * so it does not linger past this call. Splitting the wipe out this way keeps
	 * it a single code path that executes in every environment, not a branch that
	 * is dead wherever the extension is not compiled in.
	 *
	 * @since 0.1.0
	 *
	 * @param string $secret Secret to clear; nulled or zero-filled in place.
	 *
	 * @param-out string|null $secret Left `null` by the native scrub, or a
	 *                                zero-filled string by the fallback.
	 * @return void
	 */
	private function wipe( string &$secret ): void {

		// Prefer the extension's constant-time in-place scrub; fall back to a
		// plain overwrite only where sodium_compat would otherwise throw from it.
		if ( extension_loaded( 'sodium' ) ) {
			sodium_memzero( $secret );
			return;
		}
		$secret = str_repeat( "\x00", strlen( $secret ) );

	}

}

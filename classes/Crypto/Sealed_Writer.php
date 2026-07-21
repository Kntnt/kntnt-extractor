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
 * and every plaintext are not.
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
	 * Names of the segments added so far, in write order, for the sealed index.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $segment_names = [];

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

		// Lay down the versioned format header and record the open container.
		$this->write( $handle, self::MAGIC . chr( self::FORMAT_VERSION ) );
		$this->handle = $handle;
		$this->public_key = $public_key;

	}

	/**
	 * Encrypts one segment and appends it to the container.
	 *
	 * The segment is drawn from the stream, encrypted under a fresh random
	 * symmetric key, and that key is sealed to the caller's public key; the
	 * plaintext key and the plaintext itself are then zeroed. The stream is
	 * assumed already bounded by the caller (a table dump or a chunk of a file),
	 * so it is read in full.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name   Identifier recorded (sealed) in the index — a table
	 *                         name or an installation-root-relative file path.
	 * @param resource $stream Readable stream supplying the segment's plaintext.
	 * @return void
	 *
	 * @throws LogicException  When called before {@see open()}.
	 * @throws RuntimeException When the stream cannot be read.
	 */
	public function add_segment( string $name, $stream ): void {

		// Require an open container: this guards the open→add→finalize order and
		// narrows the handle and key away from null for the operations below.
		$handle = $this->handle;
		$public_key = $this->public_key;
		if ( $handle === null || $public_key === null ) {
			throw new LogicException( 'Sealed_Writer::open() must be called before add_segment().' );
		}

		// Read the whole (already bounded) segment from the caller's stream.
		$plaintext = stream_get_contents( $stream );
		if ( $plaintext === false ) {
			throw new RuntimeException( 'Unable to read a segment stream.' );
		}

		// Encrypt under a fresh random symmetric key and seal that key to the
		// caller's public key, then wipe the key and the plaintext so the server
		// keeps nothing able to open its own output (ADR-0009). The bundled sodium
		// extension (present on the required PHP 8.5) wipes in place; WordPress's
		// pure-PHP sodium_compat fallback cannot and refuses to, so the wipe is
		// guarded to the extension — the locals are dropped either way on return.
		$key = sodium_crypto_secretbox_keygen();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		$sealed_key = sodium_crypto_box_seal( $key, $public_key );
		if ( extension_loaded( 'sodium' ) ) {
			sodium_memzero( $key );
			sodium_memzero( $plaintext );
		}

		// Append the self-framed segment record and remember its name. Both the
		// sealed key and the ciphertext carry their own length so the reader needs
		// no box_seal size constant.
		$this->write( $handle, pack( 'P', strlen( $sealed_key ) ) . $sealed_key . $nonce . pack( 'P', strlen( $ciphertext ) ) . $ciphertext );
		$this->segment_names[] = $name;

	}

	/**
	 * Seals the index, writes the trailer, and closes the container.
	 *
	 * After this call the writer holds no value able to open the artifact: the
	 * handle is closed, the public key and the name list are dropped, and every
	 * segment's symmetric key was already zeroed in {@see add_segment()}.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 *
	 * @throws LogicException   When called before {@see open()}.
	 * @throws RuntimeException When the trailer cannot be written or the
	 *                          container cannot be closed cleanly.
	 */
	public function finalize(): void {

		// Require an open container: guards the ordering contract and narrows the
		// handle and key away from null.
		$handle = $this->handle;
		$public_key = $this->public_key;
		if ( $handle === null || $public_key === null ) {
			throw new LogicException( 'Sealed_Writer::open() must be called before finalize().' );
		}

		// Seal the index of names so a holder of only the artifact cannot tell
		// which tables or files it contains, then frame its length as the trailer
		// the reader locates it by.
		$sealed_index = sodium_crypto_box_seal( $this->encode_index(), $public_key );
		$this->write( $handle, $sealed_index . pack( 'P', strlen( $sealed_index ) ) );

		// Close the container and drop every reference, so no value able to open
		// the artifact survives this call. A failed close can mean buffered
		// trailer bytes never reached disk — a truncated artifact — so it is
		// escalated, but only once the references are already gone.
		$closed = fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming encrypt-as-you-go write; see open().
		$this->handle = null;
		$this->public_key = null;
		$this->segment_names = [];
		if ( $closed === false ) {
			throw new RuntimeException( 'Unable to close the sealed container after writing its trailer.' );
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
	 * Serialises the segment names into a length-prefixed byte string.
	 *
	 * Each name is prefixed with its 64-bit little-endian length so the index
	 * round-trips any byte sequence a file path may hold, independent of
	 * character encoding, without a delimiter it could collide with.
	 *
	 * @since 0.1.0
	 *
	 * @return string The unsealed index payload.
	 */
	private function encode_index(): string {

		$index = '';
		foreach ( $this->segment_names as $name ) {
			$index .= pack( 'P', strlen( $name ) ) . $name;
		}

		return $index;

	}

}

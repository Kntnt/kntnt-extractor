<?php
/**
 * A reader for the sealed container format — for this test suite alone.
 *
 * Written from `docs/container-format.md` §4 and §7 alone: the reading algorithm, the
 * refusals a reader is required to make, and §5's reassembly rule, without reading the
 * code that produces containers. That is the whole point of it. A reader that imported
 * its expectations from the code it checks could not detect that code changing them, so
 * every constant below is the document's, restated here.
 *
 * **It is not normative and it never ships.** The document governs on any disagreement:
 * where this class and `docs/container-format.md` differ, this class is wrong. It lives
 * under `tests/` because no production path in this plugin could ever call it — every
 * segment key and the sealed index are sealed to the caller's X25519 public key and the
 * private half never reaches this site (ADR-0009), so the server holds nothing able to
 * open its own output and must be seen to hold nothing. `tests/Build/build-release-zip-test.sh`
 * already asserts that no path under `tests/` reaches the distributable, which is what
 * keeps that true. ADR-0025 settles the decision and its cost: `phpcs` and `phpstan` read
 * only `classes/`, so nothing here is statically analysed. The trade is paid for by the
 * class staying small, having one caller, and being exercised by a dozen assertions
 * every time the gate runs.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Tests;

use RuntimeException;

/**
 * Parses and opens a sealed container, refusing anything the specification says to refuse.
 */
final class Sealed_Reader {

	/**
	 * The literal that identifies the format, fixed across every version (§8).
	 */
	private const string MAGIC = 'KNTNTEXT';

	/**
	 * Bytes of header before the first segment record: the magic plus one version byte (§3).
	 */
	private const int HEADER_LENGTH = 9;

	/**
	 * Bytes in every length field in the format — all of them u64 little-endian (§3).
	 */
	private const int LENGTH_FIELD = 8;

	/**
	 * The highest format version this reader implements; a higher one is refused (§8).
	 */
	private const int HIGHEST_VERSION = 1;

	/**
	 * @param string $keypair The recipient's X25519 key pair, as `sodium_crypto_box_keypair()` returns one.
	 */
	public function __construct( private readonly string $keypair ) {}

	/**
	 * Walks a container's framing and recovers its ordered segment names.
	 *
	 * Opens no segment: this is §4 steps 1–9, which need the private key for the sealed
	 * index and for nothing else. Every length field is bounds-checked before the span it
	 * frames is touched, because §7 makes the framing the one part of the format no MAC
	 * covers and therefore the one part a reader must not trust.
	 *
	 * @param string $raw The whole container, as bytes.
	 * @return array{version: int, total: int, index_length: int, names: list<string>, records: list<array{sk_length: int, ct_length: int, sealed_key: string, nonce: string, ciphertext: string}>}
	 *
	 * @throws RuntimeException When the container is not one, or its framing is corrupt, or the index will not unseal.
	 */
	public function parse( string $raw ): array {

		// A container holds at least a header and a trailer; anything shorter cannot be
		// walked at all, and every bounds check below assumes those two spans exist.
		$total = strlen( $raw );
		if ( $total < self::HEADER_LENGTH + self::LENGTH_FIELD ) {
			throw new RuntimeException( 'Sealed_Reader: the container is shorter than a header and a trailer.' );
		}

		// §4 steps 2 and 3: the magic identifies the format in every version, and a version
		// above the highest implemented here is refused rather than read best-effort — a
		// higher version is free to have changed something this reader would misread in
		// silence.
		if ( substr( $raw, 0, strlen( self::MAGIC ) ) !== self::MAGIC ) {
			throw new RuntimeException( 'Sealed_Reader: the container does not begin with the format\'s magic bytes.' );
		}
		$version = ord( $raw[ strlen( self::MAGIC ) ] );
		if ( $version > self::HIGHEST_VERSION ) {
			throw new RuntimeException( 'Sealed_Reader: the container declares a format version this reader does not implement.' );
		}

		// §4 steps 4 and 5: the last eight bytes give the sealed index's length, and the
		// index is the span immediately before them. What is left over between the header
		// and that span is the segment body.
		$trailer_at = $total - self::LENGTH_FIELD;
		$index_length = (int) unpack( 'P', substr( $raw, $trailer_at, self::LENGTH_FIELD ) )[1];
		if ( $index_length < 0 || $index_length > $trailer_at - self::HEADER_LENGTH ) {
			throw new RuntimeException( 'Sealed_Reader: the trailer\'s index length reaches back past the start of the container body.' );
		}
		$body_end = $trailer_at - $index_length;

		// §4 step 6: only the recipient's own key pair opens the index. A failure here is
		// the wrong key pair or a tampered trailer, and the two are indistinguishable.
		$index_payload = sodium_crypto_box_seal_open( substr( $raw, $body_end, $index_length ), $this->keypair );
		if ( $index_payload === false ) {
			throw new RuntimeException( 'Sealed_Reader: the sealed index would not unseal.' );
		}

		// §4 steps 7–9: the names, then the records, then the rule that pairs them. The
		// count must match because §5's name-to-segment correspondence is positional and
		// there is no other identifier joining the two lists.
		$names = $this->read_names( $index_payload );
		$records = $this->read_records( $raw, $body_end );
		if ( count( $names ) !== count( $records ) ) {
			throw new RuntimeException( 'Sealed_Reader: the container holds a different number of names than segment records.' );
		}

		return [
			'version' => $version,
			'total' => $total,
			'index_length' => $index_length,
			'names' => $names,
			'records' => $records,
		];

	}

	/**
	 * Reduces a parsed container to everything in it the format fixes.
	 *
	 * The version byte, the total length, the index length and the ordered sealed-key and
	 * ciphertext lengths are every byte of a container that is not a sealed key, a nonce or
	 * a ciphertext — and those three are random by construction. Two containers built from
	 * one recipe therefore agree here exactly, or the format moved between them.
	 *
	 * @param array $parsed A container as {@see parse()} returned it.
	 * @return array{version: int, total: int, index_length: int, lengths: list<array{0: int, 1: int}>} Key order is significant: callers compare these arrays with `===`.
	 */
	public function framing( array $parsed ): array {

		return [
			'version' => $parsed['version'],
			'total' => $parsed['total'],
			'index_length' => $parsed['index_length'],
			'lengths' => array_map( static fn( array $record ): array => [ $record['sk_length'], $record['ct_length'] ], $parsed['records'] ),
		];

	}

	/**
	 * Recovers one segment's plaintext.
	 *
	 * §4 step 10: unseal the segment's own symmetric key with the recipient's key pair,
	 * then open the ciphertext with that key and the segment's nonce. Either step failing
	 * means tampering or corruption, and the segment is unrecoverable — never a placeholder
	 * and never the raw ciphertext.
	 *
	 * @param array $parsed   A container as {@see parse()} returned it.
	 * @param int   $position The segment's position in container order, counting from zero.
	 * @return string The segment's plaintext, which may legitimately be empty (§5).
	 *
	 * @throws RuntimeException When there is no such segment, or it will not open.
	 */
	public function open_segment( array $parsed, int $position ): string {

		$record = $parsed['records'][ $position ] ?? null;
		if ( $record === null ) {
			throw new RuntimeException( 'Sealed_Reader: the container holds no segment record at that position.' );
		}

		// Unseal the segment's key, then open its ciphertext under that key and nonce.
		$key = sodium_crypto_box_seal_open( $record['sealed_key'], $this->keypair );
		if ( $key === false ) {
			throw new RuntimeException( 'Sealed_Reader: a segment\'s sealed key would not unseal.' );
		}
		$plaintext = sodium_crypto_secretbox_open( $record['ciphertext'], $record['nonce'], $key );
		if ( $plaintext === false ) {
			throw new RuntimeException( 'Sealed_Reader: a segment\'s ciphertext would not open.' );
		}

		return $plaintext;

	}

	/**
	 * Reassembles one named resource from every segment that carries its name.
	 *
	 * §4 step 11 and §5: filter the segments to those whose positionally-paired name equals
	 * the target, open each, and concatenate the recovered plaintexts in that order. No
	 * delimiter is inserted — a chunk boundary falls exactly where the bytes should be
	 * adjacent — and a name with no segments reassembles to nothing rather than refusing,
	 * since a caller asking for a resource the container does not hold is asking a question,
	 * not presenting a corrupt container.
	 *
	 * @param array  $parsed A container as {@see parse()} returned it.
	 * @param string $name   The segment name to reassemble, compared as raw bytes.
	 * @return string The concatenated plaintexts, in index order.
	 *
	 * @throws RuntimeException When one of the segments will not open.
	 */
	public function reassemble( array $parsed, string $name ): string {

		$reassembled = '';
		foreach ( $parsed['names'] as $position => $candidate ) {
			if ( $candidate === $name ) {
				$reassembled .= $this->open_segment( $parsed, $position );
			}
		}

		return $reassembled;

	}

	/**
	 * Splits the unsealed index payload into its ordered names.
	 *
	 * §3 and §4 step 7: a flat repetition of an 8-byte length and that many raw bytes, with
	 * no outer count and no terminator other than reaching the end of the payload. A name
	 * carries no encoding assumption, so nothing here decodes or normalises one.
	 *
	 * @param string $payload The unsealed index payload.
	 * @return list<string> The names, in index order.
	 *
	 * @throws RuntimeException When an entry's length field runs past the payload.
	 */
	private function read_names( string $payload ): array {

		$names = [];
		$offset = 0;
		$end = strlen( $payload );
		while ( $offset < $end ) {
			$length = $this->read_length( $payload, $offset, $end, 'sealed index' );
			$names[] = substr( $payload, $offset, $length );
			$offset += $length;
		}

		return $names;

	}

	/**
	 * Walks the self-framed segment records between the header and the sealed index.
	 *
	 * §4 step 8. Each record carries its own two lengths, so the walk needs no size constant
	 * from libsodium other than the nonce's, and the nonce is the format's one fixed-width
	 * field.
	 *
	 * @param string $raw      The whole container, as bytes.
	 * @param int    $body_end The offset the segment body ends at.
	 * @return list<array{sk_length: int, ct_length: int, sealed_key: string, nonce: string, ciphertext: string}> The records, in container order.
	 *
	 * @throws RuntimeException When a length field or a nonce runs past the end of the body.
	 */
	private function read_records( string $raw, int $body_end ): array {

		$records = [];
		$offset = self::HEADER_LENGTH;
		while ( $offset < $body_end ) {

			// The sealed key, framed by its own length.
			$sk_length = $this->read_length( $raw, $offset, $body_end, 'container body' );
			$sealed_key = substr( $raw, $offset, $sk_length );
			$offset += $sk_length;

			// The nonce, the format's only fixed-width variable field, bounds-checked the
			// same way as everything the framing claims: nothing authenticates the lengths
			// that led here (§7), so its presence is not implied by theirs.
			if ( $offset + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES > $body_end ) {
				throw new RuntimeException( 'Sealed_Reader: a segment\'s nonce reads past the end of the container body.' );
			}
			$nonce = substr( $raw, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$offset += SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

			// The ciphertext, framed by its own length, with its MAC folded in (§3).
			$ct_length = $this->read_length( $raw, $offset, $body_end, 'container body' );
			$ciphertext = substr( $raw, $offset, $ct_length );
			$offset += $ct_length;

			$records[] = [
				'sk_length' => $sk_length,
				'ct_length' => $ct_length,
				'sealed_key' => $sealed_key,
				'nonce' => $nonce,
				'ciphertext' => $ciphertext,
			];

		}

		return $records;

	}

	/**
	 * Reads one u64 little-endian length field and proves the span it frames is really there.
	 *
	 * Both checks happen before anything is read, which §7 requires and which is not merely
	 * tidier: a reader that unpacks a short string first reaches the same verdict by way of
	 * PHP's own warnings, and a refusal that depends on the error handler to be correct is
	 * not the reader's refusal. A value above `PHP_INT_MAX` arrives here as a negative int —
	 * which is what a four-byte length field read as eight can produce, about half the time,
	 * depending on the top byte of whatever follows it — and is refused under the same
	 * message as an overlong one, because §7 treats a negative and a past-the-end span as one
	 * verdict and a deterministic message is worth more than the distinction.
	 *
	 * @param string $raw    The bytes being walked.
	 * @param int    $offset The offset to read at; advanced past the length field.
	 * @param int    $end    The offset the span being walked ends at.
	 * @param string $where  What is being walked, for the refusal message.
	 * @return int The length, guaranteed readable from the advanced offset within `$end`.
	 *
	 * @throws RuntimeException When the field is not wholly present, or frames a span that is not.
	 */
	private function read_length( string $raw, int &$offset, int $end, string $where ): int {

		// The field itself must be wholly present before it can be unpacked.
		if ( $offset + self::LENGTH_FIELD > $end ) {
			throw new RuntimeException( sprintf( 'Sealed_Reader: a length field in the %s is not wholly present.', $where ) );
		}

		// Unpack it, then hold the span it frames to the same standard.
		$length = (int) unpack( 'P', substr( $raw, $offset, self::LENGTH_FIELD ) )[1];
		$offset += self::LENGTH_FIELD;
		if ( $length < 0 || $length > $end - $offset ) {
			throw new RuntimeException( sprintf( 'Sealed_Reader: a length field in the %s reads past its end.', $where ) );
		}

		return $length;

	}

}

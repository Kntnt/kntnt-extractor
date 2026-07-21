<?php
/**
 * Exception for an absent or malformed caller public key.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Extractor\Crypto;

use InvalidArgumentException;

/**
 * Thrown when the X25519 public key handed to {@see Sealed_Writer::open()} is
 * absent or not exactly `SODIUM_CRYPTO_BOX_PUBLICKEYBYTES` bytes long.
 *
 * It extends `InvalidArgumentException` so the REST layer can map the whole
 * class of bad-input rejections to a `400` without knowing this type, while a
 * caller that wants the specific reason can still catch it by name (ADR-0009:
 * the extraction request must carry a valid public key, and its absence or
 * malformation is a client error).
 *
 * @since 0.1.0
 */
final class Invalid_Public_Key extends InvalidArgumentException {

}

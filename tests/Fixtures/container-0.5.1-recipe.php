<?php
/**
 * The recipe the golden container was built from.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

/**
 * Returns the ordered segments the golden container holds, as [ name, plaintext ] pairs.
 *
 * Deterministic by construction — no randomness, no clock, no host-dependent value — so
 * the container can be re-derived from this file and a checkout of the release it was cut
 * from. The cases are chosen to exercise the specification's rules rather than to look
 * like a real extraction: one table taken whole, one table taken as three same-named
 * segments (the reassembly rule), a zero-byte file (an empty segment is legal), a
 * non-ASCII path holding every byte value (names and plaintexts are raw bytes), and two
 * segments with identical plaintext (a fresh key and nonce per segment).
 *
 * @return array<int, array{0: string, 1: string}> The segments, in container order.
 */
function kntnt_extractor_golden_recipe(): array {

	// A payload covering every byte value, so a reader that assumes text fails here.
	$binary = '';
	for ( $i = 0; $i < 256; $i++ ) {
		$binary .= chr( $i );
	}
	$binary = str_repeat( $binary, 4 );

	return [
		[ 'wp_users', "-- MySQL dump\nINSERT INTO `wp_users` VALUES (1,'admin','s3cr3t');\n" ],
		[ 'wp_options', "-- part 1\nINSERT INTO `wp_options` VALUES (1,'siteurl');\n" ],
		[ 'wp_options', "-- part 2\nINSERT INTO `wp_options` VALUES (2,'home');\n" ],
		[ 'wp_options', "-- part 3\nINSERT INTO `wp_options` VALUES (3,'blogname');\n" ],
		[ 'wp-content/uploads/2026/08/empty.txt', '' ],
		[ "wp-content/uploads/2026/08/bin\xC3\xA4r-\xE3\x83\x95\xE3\x82\xA1\xE3\x82\xA4\xE3\x83\xAB.bin", $binary ],
		[ 'duplicate-payload-a', 'THE-SAME-BYTES' ],
		[ 'duplicate-payload-b', 'THE-SAME-BYTES' ],
	];

}

/**
 * Returns the fixed X25519 key pair the golden container is sealed to.
 *
 * The seed is the one `tests/Integration/sealed-writer-test.php` already uses, so the
 * suite has one test key pair rather than two. It is a constant in a public repository
 * and protects nothing; it exists so the fixture is reproducible and openable in-process.
 *
 * @return string The key pair, as `sodium_crypto_box_keypair()` returns one.
 */
function kntnt_extractor_golden_keypair(): string {

	return sodium_crypto_box_seed_keypair( str_repeat( "\x2a", SODIUM_CRYPTO_BOX_SEEDBYTES ) );

}

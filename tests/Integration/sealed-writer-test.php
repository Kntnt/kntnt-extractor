<?php
/**
 * Integration test: the Sealed_Writer crypto seam (Seam 2).
 *
 * A narrow, crypto-focused round-trip that proves the correctness a
 * security-critical container must not leave to end-to-end coverage alone.
 * Segments are sealed to a fixed test keypair and recovered with the matching
 * private key using nothing but PHP-bundled `sodium`, exactly as the caller
 * (kntnt-wp-skills) will. It exercises every acceptance criterion of issue #3:
 * per-segment plaintext fidelity, a fresh random symmetric key per segment
 * sealed with `sodium_crypto_box_seal`, a sealed index that hides which
 * tables/files were taken, tamper detection, that finalize() leaves no
 * key able to open the artifact, and rejection of an absent/malformed key.
 *
 * @package Kntnt\Extractor
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Crypto\Invalid_Public_Key;
use Kntnt\Extractor\Crypto\Sealed_Writer;

// Precondition: the sodium API must be reachable (ADR-0007). Production PHP 8.5
// bundles the native extension; WordPress supplies sodium_compat where it is not
// compiled in (as in this WASM harness), so both paths present the same API.
kntnt_extractor_assert( function_exists( 'sodium_crypto_box_seal' ) && function_exists( 'sodium_crypto_secretbox' ), 'The sodium crypto API is available in the harness' );

// The crypto lives behind Sealed_Writer; without the class there is nothing to
// exercise, so record the gap and stop this file cleanly (a red before green).
if ( ! class_exists( Sealed_Writer::class ) ) {
	kntnt_extractor_assert( false, 'Sealed_Writer class is available' );
	return;
}
kntnt_extractor_assert( true, 'Sealed_Writer class is available' );

// A fixed keypair fixture: a constant seed makes the run deterministic while the
// private key stays available in-process to open what the writer sealed.
$seed = str_repeat( "\x2a", SODIUM_CRYPTO_BOX_SEEDBYTES );
$keypair = sodium_crypto_box_seed_keypair( $seed );
$public_key = sodium_crypto_box_publickey( $keypair );
$secret_key = sodium_crypto_box_secretkey( $keypair );

// Distinctive segment names whose leak would matter, paired with plaintext that
// covers SQL text, raw binary, the empty segment, and two identical payloads
// (to prove a fresh key/nonce yields different ciphertext for equal input).
$segments = [
	'wp_users' => "-- MySQL dump\nINSERT INTO `wp_users` VALUES (1,'admin','s3cr3t');\n",
	'wp-content/uploads/2026/07/confidential-report.pdf' => random_bytes( 4096 ),
	'wp_options-empty-part' => '',
	'duplicate-payload-a' => 'THE-SAME-BYTES',
	'duplicate-payload-b' => 'THE-SAME-BYTES',
];

// Builds a readable in-memory stream from a string, the shape add_segment() reads.
$make_stream = static function ( string $data ) {
	$stream = fopen( 'php://temp', 'r+b' );
	fwrite( $stream, $data );
	rewind( $stream );
	return $stream;
};

// Reads a length-prefixed 64-bit-LE field at $offset, advancing it.
$read_length = static function ( string $raw, int &$offset ): int {
	$value = unpack( 'P', substr( $raw, $offset, 8 ) )[1];
	$offset += 8;
	return (int) $value;
};

// Parses a finished container into its header, segment records, and the sealed
// index — the independent reader a client would implement over the wire format.
$parse = static function ( string $raw ) use ( $read_length ): array {
	$magic = Sealed_Writer::MAGIC;
	$header_len = strlen( $magic ) + 1;
	$header_ok = substr( $raw, 0, strlen( $magic ) ) === $magic;
	$version = ord( $raw[ strlen( $magic ) ] );

	// The trailer's final 8 bytes give the sealed-index length; the index sits
	// just before it, and the segment body fills everything in between.
	$trailer_at = strlen( $raw ) - 8;
	$index_len = unpack( 'P', substr( $raw, $trailer_at, 8 ) )[1];
	$sealed_index = substr( $raw, $trailer_at - $index_len, $index_len );
	$body_end = $trailer_at - $index_len;

	// Walk the self-framed segment records: sealed-key length and bytes, nonce,
	// ciphertext length and bytes. No box_seal size constant is trusted.
	$records = [];
	$offset = $header_len;
	while ( $offset < $body_end ) {
		$sk_len = $read_length( $raw, $offset );
		$sealed_key = substr( $raw, $offset, $sk_len );
		$offset += $sk_len;
		$nonce = substr( $raw, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$offset += SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		$ct_len = $read_length( $raw, $offset );
		$ciphertext = substr( $raw, $offset, $ct_len );
		$offset += $ct_len;
		$records[] = [ 'sealed_key' => $sealed_key, 'nonce' => $nonce, 'ciphertext' => $ciphertext ];
	}

	return [ 'header_ok' => $header_ok, 'version' => $version, 'sealed_index' => $sealed_index, 'records' => $records ];
};

// Recovers a segment's plaintext with the private key, or false if authentication
// fails — the exact path a tampered segment must take.
$open_segment = static function ( array $record, string $keypair ): string|false {
	$key = sodium_crypto_box_seal_open( $record['sealed_key'], $keypair );
	if ( $key === false ) {
		return false;
	}
	return sodium_crypto_secretbox_open( $record['ciphertext'], $record['nonce'], $key );
};

// Unseals and splits the length-prefixed index back into its ordered names.
$open_index = static function ( string $sealed_index, string $keypair ): ?array {
	$plain = sodium_crypto_box_seal_open( $sealed_index, $keypair );
	if ( $plain === false ) {
		return null;
	}
	$names = [];
	$offset = 0;
	while ( $offset < strlen( $plain ) ) {
		$len = (int) unpack( 'P', substr( $plain, $offset, 8 ) )[1];
		$offset += 8;
		$names[] = substr( $plain, $offset, $len );
		$offset += $len;
	}
	return $names;
};

// Build one sealed container from the fixtures.
$path = tempnam( sys_get_temp_dir(), 'kntnt-sealed-' );
$writer = new Sealed_Writer( $path );
$writer->open( $public_key );
foreach ( $segments as $name => $data ) {
	$writer->add_segment( $name, $make_stream( $data ) );
}
$writer->finalize();
$raw = (string) file_get_contents( $path );

$container = $parse( $raw );
$names = array_keys( $segments );
$payloads = array_values( $segments );

// The container announces the versioned format and holds one record per segment.
kntnt_extractor_assert( $container['header_ok'] && $container['version'] === Sealed_Writer::FORMAT_VERSION, 'Container carries the magic header and format version' );
kntnt_extractor_assert( count( $container['records'] ) === count( $segments ), 'Container holds one independently-encrypted record per segment' );

// Round-trip: every segment recovers byte-for-byte with the matching private key.
$roundtrip_ok = true;
foreach ( $container['records'] as $i => $record ) {
	if ( $open_segment( $record, $keypair ) !== $payloads[ $i ] ) {
		$roundtrip_ok = false;
	}
}
kntnt_extractor_assert( $roundtrip_ok, 'Every segment decrypts byte-for-byte with the private key' );

// Discriminating-power controls for the round-trip above: the byte-for-byte
// check earns its keep only if a mismatch is genuinely detectable and the seal
// binds to the caller's key, so demonstrate both here rather than leave the
// assertion's teeth to inference. Decrypting one record and comparing it to a
// DIFFERENT segment's plaintext must be unequal — a writer that swapped or
// corrupted a segment would then fail the check — and a foreign keypair must
// open nothing, so the round-trip proves possession of the matching private
// key, not mere decodability of a co-evolved reader.
$mismatch_detected = $open_segment( $container['records'][0], $keypair ) !== $payloads[1];
$foreign_keypair = sodium_crypto_box_seed_keypair( str_repeat( "\x17", SODIUM_CRYPTO_BOX_SEEDBYTES ) );
$foreign_open_fails = $open_segment( $container['records'][0], $foreign_keypair ) === false;
kntnt_extractor_assert( $mismatch_detected && $foreign_open_fails, 'The round-trip check discriminates: a wrong payload is unequal and a foreign key opens nothing (negative controls)' );

// Each segment's key is a distinct 32-byte symmetric key sealed with box_seal.
$recovered_keys = [];
foreach ( $container['records'] as $record ) {
	$key = sodium_crypto_box_seal_open( $record['sealed_key'], $keypair );
	if ( $key !== false ) {
		$recovered_keys[] = $key;
	}
}
$all_keys_present = count( $recovered_keys ) === count( $container['records'] );
$all_keys_full_length = ! in_array( false, array_map( static fn( string $k ): bool => strlen( $k ) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $recovered_keys ), true );
$all_keys_distinct = count( array_unique( $recovered_keys ) ) === count( $recovered_keys );
kntnt_extractor_assert( $all_keys_present && $all_keys_full_length && $all_keys_distinct, 'Each segment is sealed under its own fresh 32-byte symmetric key' );

// Identical plaintext under fresh key+nonce yields different ciphertext.
$dup_a = $container['records'][3]['ciphertext'];
$dup_b = $container['records'][4]['ciphertext'];
kntnt_extractor_assert( $dup_a !== $dup_b && $segments['duplicate-payload-a'] === $segments['duplicate-payload-b'], 'Identical payloads produce different ciphertext (fresh key/nonce per segment)' );

// AC2: the plaintext symmetric key is zeroed after use. add_segment() wipes its
// key and plaintext through the writer's own wipe() primitive, which runs in
// every environment — the native extension scrubs in place, and this
// sodium_compat harness overwrites with zeros — so exercising wipe() directly
// covers the exact code path add_segment() takes here, not a branch that would
// be dead under compat. A closure bound to the class scope reaches the private
// method with real by-reference semantics (ReflectionMethod::invokeArgs would
// not carry the mutation back). The pre-check proves the secret started
// non-zero, so the post-check is not vacuously comparing zero to zero.
$invoke_wipe = \Closure::bind( static function ( Sealed_Writer $writer, string &$secret ): void {
	$writer->wipe( $secret );
}, null, Sealed_Writer::class );
$wipe_writer = new Sealed_Writer( $path );
$wipe_secret = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
$wipe_length = strlen( $wipe_secret );
$wipe_started_nonzero = $wipe_secret !== str_repeat( "\x00", $wipe_length );
$invoke_wipe( $wipe_writer, $wipe_secret );
$wipe_cleared = $wipe_secret === null || $wipe_secret === '' || ( is_string( $wipe_secret ) && $wipe_secret === str_repeat( "\x00", $wipe_length ) );
kntnt_extractor_assert( $wipe_started_nonzero && $wipe_cleared, 'The plaintext symmetric key is zeroed after use (AC2)' );

// AC2 bound to the write path. The isolated wipe() check above proves the
// primitive zeroes a secret, but the key and plaintext add_segment() wipes are
// locals, and a zeroed local is not observable once the call returns — so
// nothing above would notice if the two wipe() calls were dropped from
// add_segment() itself. The suite would stay green while the plaintext key
// lingered in freed memory: the exact regression AC2 exists to prevent. Since
// the values cannot be inspected after the fact, bind the criterion to the
// source instead — require that the fresh symmetric key (drawn from
// sodium_crypto_secretbox_keygen) and the segment plaintext (read from the
// stream) are each passed to wipe(). This is deliberately coupled to the
// inlined write path (ADR-0009 keeps all crypto in this one seam); a refactor
// that relocates the wipe should re-prove AC2 here rather than pass in silence.
$add_segment = new ReflectionMethod( Sealed_Writer::class, 'add_segment' );
$add_segment_lines = file( $add_segment->getFileName() );
$add_segment_source = implode( '', array_slice(
	$add_segment_lines,
	$add_segment->getStartLine() - 1,
	$add_segment->getEndLine() - $add_segment->getStartLine() + 1,
) );
$key_var = preg_match( '/(\$\w+)\s*=\s*sodium_crypto_secretbox_keygen\s*\(/', $add_segment_source, $key_match ) === 1 ? $key_match[1] : null;
$plaintext_var = preg_match( '/(\$\w+)\s*=\s*stream_get_contents\s*\(/', $add_segment_source, $plaintext_match ) === 1 ? $plaintext_match[1] : null;
$key_is_wiped = $key_var !== null && preg_match( '/\$this->wipe\(\s*' . preg_quote( $key_var, '/' ) . '\s*\)/', $add_segment_source ) === 1;
$plaintext_is_wiped = $plaintext_var !== null && preg_match( '/\$this->wipe\(\s*' . preg_quote( $plaintext_var, '/' ) . '\s*\)/', $add_segment_source ) === 1;
kntnt_extractor_assert( $key_is_wiped && $plaintext_is_wiped, 'add_segment() wipes both the fresh symmetric key and the segment plaintext (AC2 bound to the write path, not just the helper)' );

// The index is sealed: no segment name appears in the clear anywhere in the
// artifact, yet the private key recovers the exact ordered name list.
$no_name_leaks = true;
foreach ( $names as $name ) {
	if ( str_contains( $raw, $name ) ) {
		$no_name_leaks = false;
	}
}
$recovered_names = $open_index( $container['sealed_index'], $keypair );
kntnt_extractor_assert( $no_name_leaks, 'No segment name appears in the clear in the artifact (index is sealed)' );
kntnt_extractor_assert( $recovered_names === $names, 'The private key recovers the exact ordered segment names from the sealed index' );

// Positive control for the leak scan: the same str_contains scan must find a
// token that IS in the artifact — the magic header — so the "no name found"
// result above reflects genuine absence rather than a scan that never matches.
kntnt_extractor_assert( str_contains( $raw, Sealed_Writer::MAGIC ), 'The name-leak scan detects a token that is present (positive control)' );

// Tamper detection: a single flipped ciphertext byte, and a corrupted nonce,
// both fail authentication rather than decrypting to garbage.
$tampered_ct = $container['records'][0];
$tampered_ct['ciphertext'][0] = $tampered_ct['ciphertext'][0] ^ "\xff";
$tampered_nonce = $container['records'][1];
$tampered_nonce['nonce'][0] = $tampered_nonce['nonce'][0] ^ "\xff";
kntnt_extractor_assert( $open_segment( $tampered_ct, $keypair ) === false, 'A flipped ciphertext byte fails authentication (no garbage plaintext)' );
kntnt_extractor_assert( $open_segment( $tampered_nonce, $keypair ) === false, 'A corrupted nonce fails authentication (no garbage plaintext)' );

// After finalize(), no value reachable from the writer can open the artifact:
// none of the recovered symmetric keys, and not the private key, is retained.
$collect_strings = static function ( mixed $value, array &$acc, int $depth = 0 ) use ( &$collect_strings ): void {
	if ( $depth > 6 ) {
		return;
	}
	if ( is_string( $value ) ) {
		$acc[] = $value;
		return;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			$collect_strings( $item, $acc, $depth + 1 );
		}
		return;
	}
	if ( is_object( $value ) ) {
		foreach ( ( new ReflectionObject( $value ) )->getProperties() as $property ) {
			if ( $property->isInitialized( $value ) ) {
				$collect_strings( $property->getValue( $value ), $acc, $depth + 1 );
			}
		}
	}
};

// Positive control for the retention walk: the same walk over a writer that is
// still OPEN must surface its held public key. Without this the walk below could
// pass simply by descending into nothing; this proves it genuinely traverses the
// writer's properties and would catch a key stored as one.
$canary_writer = new Sealed_Writer( tempnam( sys_get_temp_dir(), 'kntnt-canary-' ) );
$canary_writer->open( $public_key );
$canary_strings = [];
$collect_strings( $canary_writer, $canary_strings );
$canary_writer->finalize();
kntnt_extractor_assert( in_array( $public_key, $canary_strings, true ), 'The retention walk descends into the writer and surfaces a held key (positive control)' );

$retained = [];
$collect_strings( $writer, $retained );
$leaks_key = false;
foreach ( $retained as $held ) {
	if ( $held === '' ) {
		continue;
	}
	if ( $held === $secret_key || str_contains( $held, $secret_key ) ) {
		$leaks_key = true;
	}
	foreach ( $recovered_keys as $key ) {
		if ( $held === $key || str_contains( $held, $key ) ) {
			$leaks_key = true;
		}
	}
}
kntnt_extractor_assert( ! $leaks_key && count( $recovered_keys ) === count( $segments ), 'After finalize() the writer retains no key able to open the artifact' );

// An absent or malformed public key is rejected before any bytes are written.
$rejects = static function ( callable $fn ): bool {
	try {
		$fn();
		return false;
	} catch ( Invalid_Public_Key ) {
		return true;
	}
};
$reject_path = tempnam( sys_get_temp_dir(), 'kntnt-reject-' );
kntnt_extractor_assert( $rejects( static fn() => ( new Sealed_Writer( $reject_path ) )->open( '' ) ), 'An absent (empty) public key is rejected' );
kntnt_extractor_assert( $rejects( static fn() => ( new Sealed_Writer( $reject_path ) )->open( str_repeat( 'x', SODIUM_CRYPTO_BOX_PUBLICKEYBYTES - 1 ) ) ), 'A too-short public key is rejected' );
kntnt_extractor_assert( $rejects( static fn() => ( new Sealed_Writer( $reject_path ) )->open( str_repeat( 'x', SODIUM_CRYPTO_BOX_PUBLICKEYBYTES + 1 ) ) ), 'A too-long public key is rejected' );

// AC7's load-bearing property, not just the throw: a rejected key writes nothing.
// Validation runs before the destination is opened, so a fresh path that does not
// yet exist must still not exist after a rejected open() — no empty or partial
// container is ever created.
$untouched_path = sys_get_temp_dir() . '/kntnt-no-side-effect-' . bin2hex( random_bytes( 8 ) );
$rejected_empty_key = $rejects( static fn() => ( new Sealed_Writer( $untouched_path ) )->open( '' ) );
$rejected_short_key = $rejects( static fn() => ( new Sealed_Writer( $untouched_path ) )->open( str_repeat( 'x', SODIUM_CRYPTO_BOX_PUBLICKEYBYTES - 1 ) ) );
kntnt_extractor_assert( $rejected_empty_key && $rejected_short_key && ! file_exists( $untouched_path ), 'A rejected public key creates no container file (AC7: no partial artifact reaches disk)' );

// A well-formed 32-byte public key is accepted (the writer opens and finalizes).
$accepts_valid = true;
try {
	$valid_writer = new Sealed_Writer( tempnam( sys_get_temp_dir(), 'kntnt-valid-' ) );
	$valid_writer->open( $public_key );
	$valid_writer->finalize();
} catch ( \Throwable ) {
	$accepts_valid = false;
}
kntnt_extractor_assert( $accepts_valid, 'A well-formed 32-byte public key is accepted' );

// The ordering contract is guarded: adding or finalizing before open() is a
// LogicException, not a fatal on a null handle.
$is_logic_error = static function ( callable $fn ): bool {
	try {
		$fn();
		return false;
	} catch ( \LogicException ) {
		return true;
	}
};
kntnt_extractor_assert( $is_logic_error( static fn() => ( new Sealed_Writer( tempnam( sys_get_temp_dir(), 'kntnt-order-' ) ) )->add_segment( 'x', $make_stream( 'x' ) ) ), 'add_segment() before open() throws a LogicException' );
kntnt_extractor_assert( $is_logic_error( static fn() => ( new Sealed_Writer( tempnam( sys_get_temp_dir(), 'kntnt-order-' ) ) )->finalize() ), 'finalize() before open() throws a LogicException' );

// Reopening an already-open container is a lifecycle violation, not a silent
// handle leak: a second open() before finalize() throws rather than truncating
// the file while keeping the earlier segment names.
kntnt_extractor_assert( $is_logic_error( static function () use ( $public_key ): void {
	$reopened = new Sealed_Writer( tempnam( sys_get_temp_dir(), 'kntnt-reopen-' ) );
	$reopened->open( $public_key );
	$reopened->open( $public_key );
} ), 'A second open() before finalize() throws a LogicException (no handle leak)' );

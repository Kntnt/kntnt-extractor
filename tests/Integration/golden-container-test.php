<?php
/**
 * Integration test: the golden container, and the byte-compatibility claim.
 *
 * ADR-0014 asserts that an artifact produced by a later release is byte-compatible
 * with one produced by 0.5.1, and `docs/container-format.md` §10 recorded that this
 * repository could not check the sentence — only reread the diff it was written
 * from. This file checks it. A container cut from the 0.5.1 writer is committed as
 * `tests/Fixtures/container-0.5.1.b64`; the current writer is driven over the same
 * recipe; and both are read by `tests/Support/Sealed_Reader.php`, a test-only reader
 * written from `docs/container-format.md` §4 alone and never shipped.
 *
 * Two rules make the check worth running, and both are load-bearing rather than
 * stylistic. Every expected number is computed from the recipe by the specification's
 * own arithmetic, never read out of a container, so re-cutting the fixture cannot turn
 * a red assertion green — it can only move which assertion is red. And the fixture is
 * compared for its framing rather than its bytes: the sealed keys, the nonces and the
 * ciphertexts are random by construction and are the one thing two releases must NOT
 * agree about. ADR-0025 settles both, along with why the reader never ships.
 *
 * @package Kntnt\Extractor
 */

declare( strict_types = 1 );

use Kntnt\Extractor\Crypto\Sealed_Writer;
use Kntnt\Extractor\Tests\Sealed_Reader;

// The reader is test-only and lives outside the bootstrap's `*-test.php` glob, so it
// is required by path rather than autoloaded. Guard the require: a bare one for a file
// that does not exist takes the whole Playground run down at exit 255 with no TAP
// output at all, which is the opposite of what a red step is for.
$reader_path = dirname( __DIR__ ) . '/Support/Sealed_Reader.php';
if ( file_exists( $reader_path ) ) {
	require_once $reader_path;
}
if ( ! class_exists( Sealed_Reader::class ) ) {
	kntnt_extractor_assert( false, 'Sealed_Reader class is available' );
	return;
}
kntnt_extractor_assert( true, 'Sealed_Reader class is available' );

// The recipe is the input to both containers — the fixture's, cut from the 0.5.1
// writer, and the fresh one this file builds. Everything the two must agree about is
// derived from it.
require_once dirname( __DIR__ ) . '/Fixtures/container-0.5.1-recipe.php';
$golden_recipe = kntnt_extractor_golden_recipe();
$golden_keypair = kntnt_extractor_golden_keypair();
$recipe_names = array_column( $golden_recipe, 0 );
$recipe_plaintexts = array_column( $golden_recipe, 1 );

// A refusal from the reader is an ordinary failed assertion, not a reason to lose the
// run: an uncaught throw out of a test file takes every later file's assertions down
// with it. Every call into the reader below goes through one of these two.
$attempt = static function ( callable $call ): mixed {
	try {
		return $call();
	} catch ( RuntimeException ) {
		return null;
	}
};
$refused = static function ( callable $call ): bool {
	try {
		$call();
		return false;
	} catch ( RuntimeException ) {
		return true;
	}
};

// Decode the golden container. The fixture is base64 wrapped at 76 columns so a diff
// renders it; nothing but whitespace stands between it and the bytes 0.5.1 wrote.
$golden_raw = (string) base64_decode( (string) preg_replace( '/\s+/', '', (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/container-0.5.1.b64' ) ), true );

// The fixture announces itself as §3 says it must, checked against literals rather
// than against the writer's own constants: a reader that imports its expectations from
// the writer cannot detect the writer changing them.
kntnt_extractor_assert( substr( $golden_raw, 0, 8 ) === 'KNTNTEXT' && ord( $golden_raw[8] ) === 1, 'The golden fixture carries the magic and format version the specification states (compared against literals)' );

// The writer still declares those same two constants. This is the only place this file
// names the writer's class for anything other than building a container, and it earns
// that: were the constants to move, the assertion above would be comparing the fixture
// against a format this plugin no longer writes, and would say nothing at all.
kntnt_extractor_assert( Sealed_Writer::MAGIC === 'KNTNTEXT' && Sealed_Writer::FORMAT_VERSION === 1, 'The writer still declares the magic and format version the specification states' );

// Non-vacuity for everything below. The recipe's whole value is the rules it exercises,
// and a recipe that quietly lost the empty segment or the all-byte-values payload would
// leave every assertion in this file green while covering none of them.
$plaintext_lengths = array_map( strlen( ... ), $recipe_plaintexts );
$recipe_covers_the_rules = in_array( 0, $plaintext_lengths, true )
	&& in_array( 1024, $plaintext_lengths, true )
	&& count( array_keys( $recipe_names, 'wp_options', true ) ) === 3
	&& count( array_unique( $recipe_plaintexts ) ) < count( $recipe_plaintexts );
kntnt_extractor_assert( $recipe_covers_the_rules, 'The recipe still covers the rules this file turns on: an empty segment, an all-byte-values payload, a thrice-named resource, and two identical plaintexts (non-vacuity)' );

// AC1: the current code reads an artifact the 0.5.1 writer produced. Names in order,
// plaintexts byte for byte — including the zero-length segment, whose recovered
// plaintext §5 forbids a reader from treating as end-of-stream, and the 1024-byte
// payload covering every byte value under a non-ASCII name.
$reader = new Sealed_Reader( $golden_keypair );
$golden = $attempt( static fn(): array => $reader->parse( $golden_raw ) );
$golden_plaintexts = $golden === null ? null : $attempt( static fn(): array => array_map( static fn( int $position ): string => $reader->open_segment( $golden, $position ), array_keys( $golden['records'] ) ) );
kntnt_extractor_assert( $golden !== null && $golden['names'] === $recipe_names && $golden_plaintexts === $recipe_plaintexts, 'AC1: the current code reads a 0.5.1 artifact — every name in order, every plaintext byte for byte' );

// §3's constant table, quoted from the specification rather than read off the runtime's
// own `SODIUM_*` constants — and that distinction is not pedantry here. This harness
// supplies WordPress's `sodium_compat`, whose `SODIUM_CRYPTO_BOX_SEALBYTES` is **16**
// where libsodium's, and this document's, is 48; the sealed keys in the container are 80
// bytes over a 32-byte key either way, so the constant is simply wrong and the bytes are
// not. A test that measured the format against the environment's idea of the format
// would have nothing left to say, and §1 settles which one governs: the document does.
$spec_secretbox_key_bytes = 32;
$spec_secretbox_mac_bytes = 16;
$spec_secretbox_nonce_bytes = 24;
$spec_box_seal_bytes = 48;

// AC2: the fixture's framing is the framing the specification requires, computed from
// the recipe by §3's constant table and §4's walk. Nothing here is read out of a
// container, which is what makes re-cutting the fixture useless as a way to go green.
$expected_sk_length = $spec_secretbox_key_bytes + $spec_box_seal_bytes;
$expected_ct_lengths = array_map( static fn( array $segment ): int => strlen( $segment[1] ) + $spec_secretbox_mac_bytes, $golden_recipe );
$expected_index_length = array_sum( array_map( static fn( array $segment ): int => 8 + strlen( $segment[0] ), $golden_recipe ) ) + $spec_box_seal_bytes;
$expected_lengths = array_map( static fn( int $ct_length ): array => [ $expected_sk_length, $ct_length ], $expected_ct_lengths );
$expected_total = 9 + $expected_index_length + 8;
foreach ( $expected_ct_lengths as $ct_length ) {
	$expected_total += 8 + $expected_sk_length + $spec_secretbox_nonce_bytes + 8 + $ct_length;
}
$expected_framing = [ 'version' => 1, 'total' => $expected_total, 'index_length' => $expected_index_length, 'lengths' => $expected_lengths ];
$golden_framing = $golden === null ? null : $attempt( static fn(): array => $reader->framing( $golden ) );
kntnt_extractor_assert( $golden_framing === $expected_framing, 'AC2: the fixture frames exactly as the specification arithmetic says it must, with no number read from the file' );

// AC3: a container written now frames byte-identically to one written by 0.5.1. The
// version byte, the total length, the index length and the ordered sealed-key and
// ciphertext lengths are every byte of a container that is not one of the three
// randomised spans, so equality here is byte-identity of everything the format fixes.
// This is the assertion ADR-0014's claim is worth checking for.
$fresh_path = tempnam( sys_get_temp_dir(), 'kntnt-golden-' );
$writer = new Sealed_Writer( $fresh_path );
$writer->open( sodium_crypto_box_publickey( $golden_keypair ) );
foreach ( $golden_recipe as [ $name, $plaintext ] ) {
	$writer->add_segment( $name, $plaintext );
}
$writer->finalize();
$fresh_raw = (string) file_get_contents( $fresh_path );
$fresh = $attempt( static fn(): array => $reader->parse( $fresh_raw ) );
$fresh_framing = $fresh === null ? null : $attempt( static fn(): array => $reader->framing( $fresh ) );
kntnt_extractor_assert( $fresh_framing !== null && $fresh_framing === $golden_framing, 'AC3: a container written by this release frames identically to one written by 0.5.1 (ADR-0014)' );

// AC4: and is not the same file. Without this, AC3 would pass just as happily on two
// copies of one container and would prove nothing about freshness — and the three spans
// below are exactly the ones two releases must NOT agree about.
$golden_first = $golden['records'][0] ?? [];
$fresh_first = $fresh['records'][0] ?? [];
$randomised_spans_differ = $golden_first !== [] && ( $fresh_first['sealed_key'] ?? null ) !== $golden_first['sealed_key']
	&& ( $fresh_first['nonce'] ?? null ) !== $golden_first['nonce']
	&& ( $fresh_first['ciphertext'] ?? null ) !== $golden_first['ciphertext'];
kntnt_extractor_assert( $fresh_raw !== $golden_raw && $randomised_spans_differ, 'AC4: the fresh container is not the fixture — its first segment draws its own sealed key, nonce and ciphertext' );

// AC5: §5's reassembly rule, which nothing in this repository tests as a rule. Three
// same-named segments concatenate in index order and a once-named one comes back
// alone, with no delimiter inserted at either boundary.
$expected_options = implode( '', array_column( array_filter( $golden_recipe, static fn( array $segment ): bool => $segment[0] === 'wp_options' ), 1 ) );
$reassembled_options = $golden === null ? null : $attempt( static fn(): string => $reader->reassemble( $golden, 'wp_options' ) );
$reassembled_users = $golden === null ? null : $attempt( static fn(): string => $reader->reassemble( $golden, 'wp_users' ) );
kntnt_extractor_assert( $reassembled_options === $expected_options && $reassembled_users === $recipe_plaintexts[0], 'AC5: three same-named segments reassemble in index order, and a single-segment resource comes back alone (docs/container-format.md §5)' );

// Five refusals the specification requires of a reader, each built by mutating a COPY of
// the fixture in memory: the bytes on disk are evidence and nothing here writes to them.
// That the unmutated fixture is *not* refused is the control the five depend on — without
// it they would prove only that the reader refuses whatever it is handed.
$refusal_control = ! $refused( static fn(): array => $reader->parse( $golden_raw ) );
$trailer_at = strlen( $golden_raw ) - 8;

// The trailer's index length locates the sealed index by counting back from the end of
// the file (§4 step 5), and §7 records that no MAC covers it. Move it by one bit and the
// span taken as the index is one byte out of place, so it will not unseal — where a
// reader that trusted the field would report a container of a different shape than the
// one it holds.
$flipped_index_length = $golden_raw;
$flipped_index_length[ $trailer_at ] = chr( ord( $flipped_index_length[ $trailer_at ] ) ^ 0x01 );
kntnt_extractor_assert( $refusal_control && $refused( static fn(): array => $reader->parse( $flipped_index_length ) ), 'Refusal 1: a container whose trailing index length has one bit flipped is refused — and the unmutated fixture is not (control)' );

// §8's MUST, which nothing else in this repository tests: a version above the highest a
// reader implements is refused outright rather than read best-effort, because a later
// version is free to have changed something this reader would misread in silence.
$future_version = $golden_raw;
$future_version[8] = chr( 2 );
kntnt_extractor_assert( $future_version !== $golden_raw && $refused( static fn(): array => $reader->parse( $future_version ) ), 'Refusal 2: a container declaring a format version above the one implemented is refused (docs/container-format.md §8)' );

// §7's MUST: a truncated container is refused rather than read short. Accidental
// truncation and tampering are indistinguishable at the framing level, so both are
// refused identically.
$truncated = substr( $golden_raw, 0, -200 );
kntnt_extractor_assert( $refused( static fn(): array => $reader->parse( $truncated ) ), 'Refusal 3: a truncated container is refused, not read short (docs/container-format.md §7)' );

// The seal binds to the caller's own key pair (§6): a foreign one opens nothing, not even
// the list of names. This is what makes the round trip above proof of possession of the
// matching private key rather than of a co-evolved reader.
$foreign_reader = new Sealed_Reader( sodium_crypto_box_seed_keypair( str_repeat( "\x17", SODIUM_CRYPTO_BOX_SEEDBYTES ) ) );
kntnt_extractor_assert( $refused( static fn(): array => $foreign_reader->parse( $golden_raw ) ), 'Refusal 4: a foreign key pair opens nothing in the container, not even its index (docs/container-format.md §6)' );

// §7 again, one layer in: a flipped ciphertext byte leaves the framing untouched, so the
// container still parses and the refusal has to come from the MAC. The second segment
// opening normally is what makes this a tamper check rather than a reader that refuses
// everything once it has refused anything.
$first_ciphertext_at = 9 + 8 + $expected_sk_length + $spec_secretbox_nonce_bytes + 8;
$flipped_ciphertext = $golden_raw;
$flipped_ciphertext[ $first_ciphertext_at ] = chr( ord( $flipped_ciphertext[ $first_ciphertext_at ] ) ^ 0x01 );
$tampered = $attempt( static fn(): array => $reader->parse( $flipped_ciphertext ) );
$tampered_neighbour = $tampered === null ? null : $attempt( static fn(): string => $reader->open_segment( $tampered, 1 ) );
kntnt_extractor_assert( $tampered !== null && $refused( static fn(): string => $reader->open_segment( $tampered, 0 ) ) && $tampered_neighbour === $recipe_plaintexts[1], 'Refusal 5: a flipped ciphertext byte makes its own segment unrecoverable while its neighbour still opens (docs/container-format.md §7)' );

# Test fixtures

This directory holds evidence, not test code. Nothing here is discovered by `tests/Integration/bootstrap.php`'s `*-test.php` glob; a test that wants a fixture reads it by path.

## `container-0.5.1.b64` — the golden sealed container

A sealed container built by the writer that shipped in **0.5.1**, base64-encoded and line-wrapped at 76 columns so `git diff` renders it. It decodes to exactly **2666** bytes, begins `KNTNTEXT\x01`, and ends in a u64 little-endian **274**.

It exists to make one prose claim checkable by machine. ADR-0014's Consequences assert that "an artifact produced by this release is byte-compatible with one produced by 0.5.1", and `docs/container-format.md` §10 recorded that this repository had no way to check it. `tests/Integration/golden-container-test.php` now does: it reads this container with `tests/Support/Sealed_Reader.php` — a reader written from `docs/container-format.md` §4 alone — and compares its framing against a container the current writer produces from the same recipe.

### Provenance

| What | Value |
|---|---|
| Tag the writer was taken from | `v0.5.1` |
| Commit | `3ffe0793145a3d16298eceb6fa2620a0e5945fbe` |
| `classes/Crypto/Sealed_Writer.php` blob | `6d3447906477a4e52c2890e715d26829d4b893bd` |
| `classes/Crypto/Invalid_Public_Key.php` blob | `df400effe12b5120fd30c421d1333ea07c02bb8e` |
| Cut on | 2026-08-21, local PHP 8.5.9 with the native `sodium` extension |

Every row above is checkable against history that no fixture regeneration touches:

```
git rev-parse v0.5.1^{commit}
git rev-parse v0.5.1:classes/Crypto/Sealed_Writer.php
git rev-parse v0.5.1:classes/Crypto/Invalid_Public_Key.php
```

What this table attests to is what the container is *said* to have been produced by. It cannot attest to what actually produced it: the writer draws a fresh random key and nonce per segment, so the bytes cannot be re-derived and compared against a claimed origin. Provenance here supports an audit; it does not prevent a substitution. What makes a substitution pointless is the arithmetic below, not this table.

### The input, and the procedure

`container-0.5.1-recipe.php` is the input — the ordered `[ name, plaintext ]` pairs and the fixed X25519 seed the container is sealed to. It is deterministic by construction: no randomness, no clock, no host-dependent value. It is also read by the test, which drives the *current* writer over the same pairs.

This document is the procedure, deliberately in prose rather than in a committed script. To reproduce the container, in a scratch directory outside the repository:

1. `git show v0.5.1:classes/Crypto/Sealed_Writer.php` and `git show v0.5.1:classes/Crypto/Invalid_Public_Key.php` into that directory.
2. Require `container-0.5.1-recipe.php` and those two files, construct a `Sealed_Writer` over a scratch path, and `open()` it with `sodium_crypto_box_publickey( kntnt_extractor_golden_keypair() )`.
3. For each recipe pair, wrap the plaintext in a `php://temp` handle and pass it to `add_segment()`. The 0.5.1 writer takes a **stream**; today's takes a string. That difference is a large part of why this fixture is worth having.
4. `finalize()`, then `chunk_split( base64_encode( … ), 76, "\n" )`.

The result will not be byte-identical to the committed fixture and is not meant to be — three spans of every container are random. What it will be is identical in every framing number below.

### The numbers, and where they come from

The test computes each of these from the recipe by `docs/container-format.md` §3 and §4's own arithmetic, and never reads one out of this file:

- `sk_length` = `SODIUM_CRYPTO_SECRETBOX_KEYBYTES` + `SODIUM_CRYPTO_BOX_SEALBYTES` = 32 + 48 = 80, for every segment.
- `ct_length` = `strlen( plaintext )` + `SODIUM_CRYPTO_SECRETBOX_MACBYTES` = plaintext + 16.
- `index_length` = Σ over segments of ( 8 + `strlen( name )` ), + `SODIUM_CRYPTO_BOX_SEALBYTES` = payload + 48.
- `total` = 9 + Σ ( 8 + `sk_length` + 24 + 8 + `ct_length` ) + `index_length` + 8.

For this recipe that resolves to `index_length` 274 and `total` 2666, with `ct_length` 82, 73, 70, 74, 16, 1040, 30, 30 — which is what the committed bytes hold.

## The bytes are never regenerated

**No digest of this fixture is recorded anywhere, and no script that rewrites it is committed.** That is deliberate, and it is what keeps the test worth running.

Every number the test expects is computed from the recipe by the specification's arithmetic. The arithmetic never consults the file, so re-cutting the blob cannot turn a red test green — it only moves which assertion is red. Defeating this test requires editing the arithmetic, which is editing the format's rules in a reviewable diff, under a comment that says so.

**A red golden test has exactly two causes, and neither of them is "the fixture is stale":**

1. The current code no longer honours the format `docs/container-format.md` specifies. Fix the code, or discover that the format moved without anyone deciding it should.
2. The format was moved deliberately — which moves `FORMAT_VERSION` and `api_version` and obliges a coordinated release of this repository and every client that pins a verified ceiling (`docs/release-procedure.md` §8) — and the second fixture that change owes has not been added yet.

When the format legitimately moves: this fixture's bytes stay exactly as they are, a `container-<release>.b64` is added for the release that first shipped the new version, with its own recipe and its own spec-derived arithmetic, and what the reader does with *this* one is decided by the change that moves the version — either it still opens version-1 containers and these assertions stand, or it refuses them and these assertions become an asserted refusal naming the version. Both outcomes are assertions. Deleting the fixture is neither.

The reasoning is settled in `docs/adr/0025-the-byte-compatibility-claim-is-checked-against-a-golden-container-read-by-a-reader-that-never-ships.md`.

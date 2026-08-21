# The sealed container format

## 1. Scope and status

This document is the normative specification of the sealed container written by an extraction job: the byte layout, the reading algorithm, the reassembly rule, and the cryptographic and confidentiality/integrity guarantees the format carries.

It is normative. Where this document and a comment in the code disagree, this document governs; the code is expected to be brought back into line rather than the document reinterpreted.

`classes/Crypto/Sealed_Writer.php` in this repository, `kntnt-extractor`, is the reference implementation — but a write-only one. This repository builds sealed containers; **it does not ship a reader, in any release**, and it cannot: every segment key and the sealed index are sealed to the caller's X25519 public key, whose private half never reaches this site (§6, ADR-0009). Unsealing code exists here only under `tests/`, where the build test asserts it stays (ADR-0025). There are two kinds of it. Most of it is inline in the integration suite (`tests/Integration/sealed-writer-test.php`, `tests/Integration/tick-extraction-test.php`, `tests/Integration/bounded-state-file-test.php`), written to cross-check the writer and not a reusable or documented contract. The exception is `tests/Support/Sealed_Reader.php`, which is a whole reader of this format — written from §4 and §7 of this document alone, without reading `classes/`, and forbidden from naming the writer's class or importing its constants. It is test-only and it is **not normative**: where it and this document disagree, it is wrong. The production reader lives in a separate repository, `kntnt-wp-skills`, which is why this document exists: it is the one place both ends of the contract can point at, independent of either implementation.

A format change is a change to the artifact contract and therefore to `api_version` (see §8); it requires a coordinated release of this plugin and of `kntnt-wp-skills`, plus a manual production install (see §10). Nothing in this document describes an aspiration — every normative statement here is traceable to a line in `classes/Crypto/Sealed_Writer.php` or to the test suite's inline reader, which exercises the format exactly as `kntnt-wp-skills` does.

## 2. Vocabulary

This document reuses `CONTEXT.md`'s terms verbatim and does not invent synonyms.

**Segment** (quoted from `CONTEXT.md`): the artifact's unit of encryption and of reassembly — one bounded chunk of one selected table or file, sealed on its own and recorded in the sealed index under that table's name or that file's installation-root-relative path. Nothing is packaged whole, so a table or file larger than one chunk contributes several segments carrying the same name, and a reader reassembles a resource by concatenating, in index order, every segment that carries its name.

**Download link** (quoted from `CONTEXT.md`): the short-lived, single-use link an extraction job's artifact is fetched through once ready.

Two further terms are used throughout this document as the codebase and its ADRs use them, though `CONTEXT.md` does not head them as separate glossary entries: **artifact**, the finished sealed container file a `ready` job publishes; and **sealed index**, the trailer's `sodium_crypto_box_seal`-encrypted list of segment names.

## 3. Byte layout

All multi-byte integers are unsigned 64-bit, little-endian (`pack('P', …)` / `unpack('P', …)` in PHP). Every variable-length field in the format is preceded by its own length; the format depends on no `sodium` size constant, so a reader needs none of the constants in §6's reference table to *parse* the framing — only to *open* what it finds.

```
MAGIC (8 bytes) | FORMAT_VERSION (1 byte)
repeated per segment, in order:
    sk_length   (8 bytes, unsigned 64-bit little-endian)
    sealed_key  (sk_length bytes, sodium box_seal of the segment's symmetric key)
    nonce       (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES bytes)
    ct_length   (8 bytes, unsigned 64-bit little-endian)
    ciphertext  (ct_length bytes, sodium secretbox output incl. its MAC)
trailer:
    sealed_index (sodium box_seal of the length-prefixed name list)
    index_length (8 bytes, unsigned 64-bit little-endian)
```

| Field | Size | Encoding | Meaning |
|---|---|---|---|
| `MAGIC` | 8 bytes | ASCII literal `KNTNTEXT` | Identifies the file as this container format. |
| `FORMAT_VERSION` | 1 byte | unsigned 8-bit integer | Wire format version. Currently `1`. See §8. |
| `sk_length` | 8 bytes | u64 LE | Byte length of the following `sealed_key`. |
| `sealed_key` | `sk_length` bytes | `sodium_crypto_box_seal` output | This segment's fresh symmetric key, sealed to the caller's X25519 public key. |
| `nonce` | `SODIUM_CRYPTO_SECRETBOX_NONCEBYTES` bytes (24, per libsodium) | raw bytes | The nonce used to encrypt this segment's ciphertext. |
| `ct_length` | 8 bytes | u64 LE | Byte length of the following `ciphertext`. |
| `ciphertext` | `ct_length` bytes | `sodium_crypto_secretbox` output | This segment's encrypted plaintext, MAC included (see §7). May be as short as the MAC alone — see §5's note on empty segments. |
| `sealed_index` | `index_length` bytes (located by counting back from end-of-file, see §4) | `sodium_crypto_box_seal` output | The length-prefixed ordered name list (below), sealed to the caller's X25519 public key. |
| `index_length` | last 8 bytes of the file | u64 LE | Byte length of `sealed_index`. |

Once unsealed, the index payload is itself a flat, repeated structure with no outer count or terminator other than reaching the end of the plaintext:

```
repeated per segment, in order:
    name_length (8 bytes, unsigned 64-bit little-endian)
    name        (name_length bytes, raw)
```

There is one index entry per segment record, in the same order (§5). A name carries no encoding assumption and no delimiter (§5).

For reference, the standard libsodium constants this format's cryptographic operations use (not wire-format constants — the framing above needs none of them to be parsed):

| Constant | Value | Used for |
|---|---|---|
| `SODIUM_CRYPTO_BOX_PUBLICKEYBYTES` | 32 | The caller's X25519 public key, supplied out-of-band with the extraction request. |
| `SODIUM_CRYPTO_SECRETBOX_KEYBYTES` | 32 | Each segment's fresh symmetric key, before it is sealed. |
| `SODIUM_CRYPTO_SECRETBOX_NONCEBYTES` | 24 | Each segment's nonce. |
| `SODIUM_CRYPTO_SECRETBOX_MACBYTES` | 16 | The MAC appended to every `secretbox` ciphertext (included in `ct_length`, not separate). |
| `SODIUM_CRYPTO_BOX_SEALBYTES` | 48 | The overhead `crypto_box_seal` adds over its plaintext (included in `sk_length` and in `index_length`, not separate). |

One caution about that last row, found while building the golden-artifact test: **an environment's own constant may disagree with it.** WordPress's bundled `sodium_compat` — what a site without the native extension runs, and what this repository's integration harness runs — defines `SODIUM_CRYPTO_BOX_SEALBYTES` as **16**, while its `sodium_crypto_box_seal()` produces the same 48-byte overhead libsodium does. The value in this table is the format's, and it is the one a container's bytes actually carry. This costs a conforming reader nothing, because the framing above is self-describing and needs no size constant to be parsed — that is precisely why it is built that way. It costs code that *checks* the framing arithmetically, which must quote this table rather than the runtime; `tests/Integration/golden-container-test.php` does, and says so where it does it.

## 4. Reading algorithm

These steps are precise enough to implement a reader from this section alone, without reading `classes/`.

1. Read the whole file, or open it for random access; its total length is `total_length`.
2. Read the first 8 bytes. They MUST equal `MAGIC` (`KNTNTEXT`); if not, refuse — this is not a container in this format.
3. Read the 9th byte as `FORMAT_VERSION`. If it is higher than the highest version this reader implements, refuse (§8). This document specifies version `1`.
4. Read the last 8 bytes of the file (bytes `total_length - 8` .. `total_length`) as `index_length` (u64 LE).
5. Compute `sealed_index` as the `index_length` bytes immediately before that: bytes `(total_length - 8 - index_length)` .. `(total_length - 8)`. Call the start of that span `body_end`.
6. Unseal `sealed_index` with the recipient's X25519 key pair (`crypto_box_seal_open`, using the caller's own public and private key — the same pair whose public half was supplied when the extraction was created). A failure here means either the wrong key pair or a corrupted/tampered trailer; refuse.
7. Parse the unsealed index payload: starting at offset 0, repeatedly read an 8-byte LE `name_length`, then that many raw bytes as a `name`, until the offset reaches the end of the payload. Collect the names in the order encountered — this is the ordered name list.
8. Starting at offset 9 (immediately after the header) and walking forward to `body_end`, repeatedly parse one segment record: `sk_length` (8 bytes LE), `sealed_key` (`sk_length` bytes), `nonce` (`SODIUM_CRYPTO_SECRETBOX_NONCEBYTES` bytes), `ct_length` (8 bytes LE), `ciphertext` (`ct_length` bytes). Collect the records in the order encountered. If any length field would require reading past `body_end`, refuse — see §7 on framing integrity.
9. The number of segment records from step 8 MUST equal the number of names from step 7. The *n*th name corresponds to the *n*th segment record, by position (§5).
10. To open a segment: unseal its `sealed_key` with the recipient's key pair (`crypto_box_seal_open`) to recover the segment's symmetric key, then open its `ciphertext` with that key and its `nonce` (`crypto_secretbox_open`). Either step failing means tampering or corruption; treat the segment as unrecoverable rather than substituting any placeholder plaintext.
11. To reassemble a named resource: walk the segment records in the order parsed in step 8, filter to those whose corresponding name (from step 9's positional pairing) equals the target name, open each (step 10), and concatenate the recovered plaintexts in that order. See §5.

## 5. Reassembly rules

A table or file larger than one chunk contributes several segments carrying the same name, and a reader reassembles a resource by concatenating, in index order, every segment that carries its name.

- **Segment order and index order are the same order.** The order segment records are laid down in the container (§4 step 8) is the same order their names appear in the sealed index (§4 step 7). This is what makes "concatenate in index order" well-defined: index order and segment order are one order, not two that happen to agree.
- **The name-to-segment correspondence is positional.** The *n*th name in the index belongs to the *n*th segment record. `add_segment()` (`classes/Crypto/Sealed_Writer.php:470-500`) appends one record to the container and one name to the index sidecar in the same call, so the two lists grow in lockstep by construction; there is no separate identifier joining a name to a record.
- **Concatenation, not merging.** Reassembling a resource is byte-concatenation of the recovered plaintexts of every segment carrying its name, in that positional/index order. No delimiter is inserted between segments; a table's chunk boundary and a file's part boundary are chosen by the writer to fall exactly where the concatenated bytes should be adjacent.
- **Empty segments are legal.** A zero-byte file yields exactly one segment whose plaintext is the empty string (confirmed at `tests/Integration/file-packaging-test.php:421-430`, at `tests/Integration/sealed-writer-test.php`'s `add_segment( 'empty-segment', '' )` block, and at `tests/Integration/golden-container-test.php`, whose recipe holds a zero-byte file). A reader MUST NOT treat a zero-length recovered plaintext as an error or as end-of-stream; it is one ordinary segment whose contribution to the reassembled resource is nothing.
- **A name may appear zero, one, or many times.** Zero occurrences is not possible for a name recorded at all (every name in the index has at least the segment that caused it to be added), but a reader should not assume a fixed segment count per name; the count is however many chunks that resource took to package.

## 6. Cryptography

The key handling is asymmetric and one-directional (ADR-0009, `classes/Crypto/Sealed_Writer.php:16-32`). For each segment, the writer:

1. Draws a fresh random 32-byte symmetric key (`sodium_crypto_secretbox_keygen`).
2. Draws a fresh random 24-byte nonce (`random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)`).
3. Encrypts the segment's plaintext with that key and nonce (`sodium_crypto_secretbox`), producing `ciphertext`.
4. Seals that symmetric key to the caller's X25519 public key (`sodium_crypto_box_seal`), producing `sealed_key`.
5. Zeroes the plaintext key and the plaintext itself in memory (`Sealed_Writer::wipe()`, `classes/Crypto/Sealed_Writer.php:659-669`).

Every segment gets its own key and its own nonce — never reused across segments, even for two segments with byte-identical plaintext (demonstrated at `tests/Integration/sealed-writer-test.php`, the `duplicate-payload-a`/`duplicate-payload-b` fixture: identical plaintext, different ciphertext). The index of names is sealed the same way: `sodium_crypto_box_seal(index_payload, public_key)`.

The server retains nothing able to open its own output. No symmetric key is retained past the `add_segment()` call that used it; the writer holds only the caller's *public* key for the duration of the build, and that key cannot decrypt anything it sealed (`tests/Integration/sealed-writer-test.php`'s post-`finalize()` retention walk proves no writer-reachable value can open the artifact). Only the caller's X25519 private key — which the server never receives — can unseal a `sealed_key` or the `sealed_index` and therefore open anything in the container.

## 7. Confidentiality and integrity

**What the framing reveals**, to anyone holding only the artifact bytes, with no key at all: the number of segments; each segment's ciphertext length (`ct_length`, hence its plaintext length too, since `secretbox` adds a fixed-size MAC and no padding); the total artifact size; and `FORMAT_VERSION`. None of this requires the private key to observe.

**What the framing does not reveal**: the segment names (sealed inside `sealed_index`, so which tables or files were extracted is confidential) and every plaintext (sealed per-segment). A holder of only the artifact — for example anyone who obtains a leaked or intercepted download link — learns only that some number of segments of some sizes exist, and nothing about what they contain or are named.

**Integrity, and its limit.** Each segment's ciphertext carries a `secretbox` MAC (`SODIUM_CRYPTO_SECRETBOX_MACBYTES`, 16 bytes, folded into `ct_length` — not a separate field), and `sealed_key` and `sealed_index` are each protected by `crypto_box_seal`'s own MAC. Tampering with a segment's ciphertext, its nonce, its sealed key, or the sealed index is detected on open: `crypto_secretbox_open` and `crypto_box_seal_open` fail closed, returning failure rather than garbage plaintext (`tests/Integration/sealed-writer-test.php`'s flipped-ciphertext-byte and corrupted-nonce assertions).

What is **not** covered by any MAC is the framing itself — `MAGIC`, `FORMAT_VERSION`, and every length field (`sk_length`, `ct_length`, `index_length`, and the index's own `name_length` fields). Nothing in the format authenticates that these bytes were not altered. A reader MUST treat any length field whose value would require reading past the end of the file, or that produces a negative or otherwise inconsistent remaining span, as a corrupt container and refuse to continue parsing — there is no framing-level signal to distinguish accidental truncation from tampering, so both are refused identically. This is a normative reader requirement rather than something the writer enforces, since the writer never re-reads what it wrote.

## 8. Versioning

`MAGIC` is the fixed literal `KNTNTEXT` and does not change across versions; a reader that does not see it at the start of the file is not looking at this container format at all, in any version, and MUST refuse regardless of what follows.

`FORMAT_VERSION` is currently `1`. A reader that encounters a `FORMAT_VERSION` higher than the highest one it implements MUST refuse to parse the container rather than attempt a best-effort read: this format's variable-length, self-framing fields mean a version bump is free to change without breaking parsing of *this* document's rules, but a reader written to this document's version `1` has no way to know a higher version has not also changed something it would silently misinterpret.

`FORMAT_VERSION` and the REST API's `api_version` are different numbers with different jobs, and conflating them is an easy mistake — settled by ADR-0017: `api_version` bounds the artifact contract only — the container's framing, segments per resource, the sealed index, and reassembly order, which is exactly what this document specifies — and is what the consuming client's verified ceiling compares against before it trusts anything the container claims about its own shape. A caller-visible behavioural change that is not a change of this document's rules (for example, `strict` on `POST /extractions`) ships without moving `api_version`; a caller discovers what a build actually does through the `honours` capability list on `GET /status` instead (`CONTEXT.md`'s **API version** and **Honours** entries; ADR-0017). `FORMAT_VERSION` moving is always also a shape change and therefore always accompanies an `api_version` move; the reverse is not guaranteed to be true in principle, though in practice every `api_version` move to date has been exactly a `FORMAT_VERSION`-relevant shape change. `api_version` is what a reader implementation should gate on before it trusts this document's byte layout at all; `FORMAT_VERSION`, read from the container itself per §4, is the belt-and-braces check that the artifact in hand actually matches what the handshake promised.

## 9. Out of scope

- **The `.names` index sidecar.** Build-time working state the writer accumulates in the job's own state directory while a container is in progress (`classes/Crypto/Sealed_Writer.php:58-83`). It is never published, a reader of a finished artifact never sees one, and it is not part of this format — it exists only so an interrupted build can resume. A reader implementer should not go looking for it.
- **The REST surface** — job creation, polling, the download link's issuance and consumption, authentication and authorisation. This document specifies only the bytes of the artifact those endpoints eventually serve.
- **Transport.** How the artifact reaches the caller (HTTP, the one-time download link) is orthogonal to its confidentiality, by design (ADR-0009): the artifact is sealed to the caller's own key, so its secrecy does not depend on the link's secrecy or access control.
- **Segment content interpretation.** What a decrypted segment's bytes mean — that a table's segments concatenate to `mysqldump`-compatible SQL, that a file's segments concatenate to that file's original bytes — is stated for context in ADR-0009 but is not part of this document's contract, which stops at recovering the plaintext bytes named by the index.

## 10. Change process

A change to any rule in this document — the byte layout, the reading algorithm, the reassembly rule, or the cryptographic construction — is a change to the artifact contract and moves `api_version` (§8). Because the only production reader lives in `kntnt-wp-skills`, a separate repository, such a change requires a coordinated release of both repositories and a manual production install; it cannot be shipped and observed to work from this repository alone. Whoever proposes a format change should treat updating this document as part of that change, not a follow-up: a reviewable diff to this file is the mechanism by which the two repositories' understanding of the format is kept in one place rather than two.

ADR-0014's Consequences assert that an artifact produced by one release is byte-compatible with one produced by an earlier release (0.5.1). That claim is now checked mechanically on every `composer gate` rather than asserted in prose alone. `tests/Fixtures/container-0.5.1.b64` is a container the 0.5.1 writer produced, and `tests/Integration/golden-container-test.php` opens it with `tests/Support/Sealed_Reader.php`, drives the current writer over the same recipe, and compares the two.

What that comparison covers is the **framing**: the version byte, the total length, the index length, and the ordered `sk_length`/`ct_length` pairs — every byte of a container that is not a sealed key, a nonce, or a ciphertext. Those three spans are random by construction and are the one thing two releases must *not* agree about, so the test also asserts the two containers are not the same bytes. Every expected number is computed from the recipe by §3's constant table and §4's walk, never read out of a container, and no digest of the fixture is recorded anywhere: re-cutting the fixture cannot turn a red assertion green, it can only move which assertion is red. ADR-0025 settles why the fixture is cut from 0.5.1, why the reader never ships, and why the fixture's bytes are never regenerated.

What it does not cover: the *content* of a segment (a writer that changed what it puts inside one frames identically and is caught by `tests/Integration/table-chunking-test.php`), and whether `kntnt-wp-skills` can read the artifact. It proves the container matches this document. The client's conformance to this document is that repository's to test.

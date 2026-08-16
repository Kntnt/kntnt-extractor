# Plan 009: Put the sealed container's format under one roof as a normative specification

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Crypto/Sealed_Writer.php docs/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW — documentation only; no production code changes
- **Depends on**: plans/004 and plans/006 (both change `Sealed_Writer`; write the spec against the finished code, not against code about to move)
- **Category**: docs
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

This repository defines a binary container format, implements the writer for it,
and cannot read one back. The only production reader lives in a different
repository, `kntnt-wp-skills`.

That split is the origin of the coordination cost that shapes every decision in
this project. A change to the container means a change here, a matching change
there, a release of both, and a manual install on production — and the only
authoritative statement of what the format *is* is prose inside a PHP class
docblock, with the reader's understanding of it living somewhere else entirely.

It also leaves the project making a claim it cannot check. ADR-0014's
Consequences assert that "an artifact produced by this release is
byte-compatible with one produced by 0.5.1". Nothing in this repository can
verify that, because nothing here can open an artifact. The only unsealing code
that exists is inside the test suite, written inline, not reusable and not
documented as a contract.

A normative specification in `docs/` is a day's writing and it buys three
things: one place both ends of the contract point at, a reviewable diff whenever
the format is proposed to change, and the precondition for ever checking
byte-compatibility mechanically.

**This plan writes the specification and stops there.** Building a
`Sealed_Reader` class is explicitly deferred — see "Maintenance notes" for the
condition under which it becomes worth doing.

## Current state

### The format is documented, in a class docblock

`classes/Crypto/Sealed_Writer.php:34-56`:

```
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
```

### The constants

`classes/Crypto/Sealed_Writer.php:88`, `:98`, `:127`:

```php
	public const string MAGIC = 'KNTNTEXT';
	public const int FORMAT_VERSION = 1;
	public const INDEX_SUFFIX = '.names';
```

### What the docblock does not say, and the spec must

These are the gaps a reader implementer would hit. Establish each from the code
before writing it down — do not guess:

1. **Reassembly.** `CONTEXT.md` states the rule but the format docblock does
   not:

   > **Segment**: The artifact's unit of encryption and of reassembly: one
   > bounded chunk of one selected table or file, sealed on its own and recorded
   > in the sealed index under that table's name or that file's
   > installation-root-relative path. Nothing is packaged whole, so a table or
   > file larger than one chunk contributes several segments carrying the same
   > name and a reader reassembles a resource by concatenating, in index order,
   > every segment that carries its name.

   Spell out that segment order and index order are the same order, and that
   this is what makes concatenation well-defined.

2. **The name-to-segment correspondence is positional.** The *n*th name in the
   index belongs to the *n*th segment. Confirm this against
   `add_segment()`, which appends to both in one call.

3. **Empty segments are legal.** A zero-byte file yields one segment whose
   plaintext is empty. A reader must not treat a zero-length plaintext as an
   error or as end-of-stream.

4. **Encoding of names.** Length-prefixed raw bytes, no encoding assumed, no
   delimiter. Say so normatively, and say what a reader should do with a name
   that is not valid UTF-8.

5. **What is and is not confidential.** Segment count, each segment's
   ciphertext length, and the total size are visible in the framing. Names and
   plaintexts are not. State this plainly — it is a security property callers
   reason about.

6. **Integrity.** Each segment's ciphertext carries a `secretbox` MAC, so
   tampering with a segment is detected on open. State what is *not* covered:
   whether the framing itself is authenticated, and what a reader should do on a
   length field that overruns the file.

7. **Version policy.** `FORMAT_VERSION` is 1. Say what a reader must do when it
   encounters a higher one (refuse), and how `FORMAT_VERSION` relates to the
   REST `api_version` — they are different numbers with different jobs, and
   conflating them is an easy mistake. **If plan 014 has landed, state the
   settled division**: `api_version` governs the artifact contract — this
   document — and is what the consuming client's verified ceiling compares
   against; behaviour a caller opts into is discovered through the capability
   list on `GET /status` instead. That division is the reason the ceiling exists
   at all, and this document is the thing it protects.

8. **The `.names` sidecar is not part of the format.** It is build-time working
   state in the server's own directory, never published, and a reader never sees
   one. `classes/Crypto/Sealed_Writer.php:58-77` explains it; the spec should
   say only that it is out of scope, so a reader implementer does not go looking.

### Where a working reader already exists

The test suite unseals artifacts inline, and that code is the best available
cross-check for the spec. Read it while writing:

- `tests/Integration/sealed-writer-test.php` — recovers segments and the sealed
  index with a fixed test keypair, using nothing but PHP-bundled `sodium`. Its
  own comment at `:7-9` says it does this "exactly as the caller
  (kntnt-wp-skills) will".
- `tests/Integration/tick-extraction-test.php` — round-trips a seeded table row
  and a packaged file through a self-generated X25519 keypair.
- `tests/Integration/bounded-state-file-test.php:372-412` — unseals a published
  index and asserts the names it holds.

### Documentation conventions

- Everything in `docs/` is in **English** (`agents.d/coding-standard/general.md`).
- **Keep each paragraph on a single physical line.** Do not hard-wrap prose at a
  column width — this project's Markdown convention, and wrapping newlines
  pollute diffs and show up in some renderers. Blank lines separate paragraphs;
  each list item is its own single line. Fenced code blocks and table rows keep
  their own line structure.
- `docs/adr/*.md` are written as prose arguing a decision. This document is
  different in kind: it is a *reference*, and should be precise and
  enumerable — headings, tables, byte layouts, and MUST/MUST NOT language where
  a reader implementer needs a rule rather than a rationale.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate (nothing should change) | `composer gate` | exit 0 |
| Confirm no code changed | `git status --short` | only files under `docs/`, plus `README.md`, `CHANGELOG.md` |

## Scope

**In scope**:

- `docs/container-format.md` (create)
- A cross-reference to `docs/define-disclosure.md` if plan 008 has landed — the
  two are sibling normative documents and each should point at the other. That
  file specifies the *other* thing the consuming repository asked to have pinned:
  what `null` means on the wire and the per-record `disclosure` contract. Do not
  write or edit it here; plan 008 owns it.
- `classes/Crypto/Sealed_Writer.php` — **comment only**: replace the inline
  wire-format block with a pointer to the new document, or leave it and add a
  cross-reference. Choose one and say which. No code changes.
- `README.md` — one line pointing at the spec, in the Development or
  Contributors area
- `AGENTS.md` — add the spec to the References list, so a cold agent finds it
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Added`)

**Out of scope** (do NOT touch):

- **Any production code behaviour.** This plan changes no bytes and no logic.
  If you find yourself editing anything other than a comment in `classes/`, stop.
- Building a `Sealed_Reader` class. Deferred by design — see "Maintenance notes".
- Changing the format in any way, including "obvious" improvements you notice
  while writing it down. Write down what *is*. If the writing exposes a genuine
  ambiguity or defect, record it in your report as a finding; do not fix it here.
- `docs/adr/0009-*.md`. The decision record stays a decision record; the spec is
  a separate document that it should be cross-referenced from and to.
- The `kntnt-wp-skills` repository.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Specify the sealed container format normatively, so both ends read one document`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Establish every fact from the code

Before writing a line of the spec, read and take notes on:

- `classes/Crypto/Sealed_Writer.php` in full — the class docblock, the
  constants, `open()`, `add_segment()`, `finalize()`, `suspend()`, `resume()`.
- `docs/adr/0009-per-segment-encrypted-artifact-sealed-to-caller-key.md`.
- The three test files listed above.
- The `Segment` and `Download link` entries in `CONTEXT.md`.

For each of the eight gaps listed in "Current state", write down the answer and
**the file and line you established it from**. If any of them cannot be
established from the code, that is a finding — note it and keep going.

**Verify**: you can state, without re-reading, what a reader must do with (a) a
zero-length ciphertext, (b) a `FORMAT_VERSION` of 2, (c) two segments carrying
the same name, and (d) an index length that overruns the file.

### Step 2: Write `docs/container-format.md`

Structure it as a reference document. A shape that works:

1. **Scope and status** — what this document specifies, that it is normative,
   and that the writer in this repository is the reference implementation.
2. **Vocabulary** — reuse `CONTEXT.md`'s terms verbatim (segment, sealed index,
   artifact, download link). Do not invent synonyms; the glossary is
   authoritative and the project's rule is to use its terms in code, docs and
   dialogue.
3. **Byte layout** — the header, the repeated segment record, the trailer. One
   table with field, size, encoding and meaning. Keep the existing ASCII block
   too; it reads well.
4. **Reading algorithm** — numbered steps, from "take the last 8 bytes" to
   "concatenate same-named segments in index order". Precise enough that
   someone could implement a reader from this section alone.
5. **Reassembly rules** — same-name concatenation, index order, empty segments.
6. **Cryptography** — what is sealed to what, that each segment has a fresh
   symmetric key and a fresh nonce, that the server retains nothing able to
   open its own output, and that only the caller's private key can.
7. **Confidentiality and integrity** — what the framing reveals, what the MAC
   covers, what it does not.
8. **Versioning** — `MAGIC`, `FORMAT_VERSION`, the reader's obligation on an
   unknown version, and the relationship to the REST `api_version`.
9. **Out of scope** — the `.names` sidecar, the REST surface, transport.
10. **Change process** — that a format change requires a coordinated release of
    this plugin and `kntnt-wp-skills` plus a production install, which is the
    fact that makes this document worth maintaining.

Write for someone implementing a reader in another language who has not seen
this codebase. Use MUST / MUST NOT where a rule is load-bearing.

**Verify**:
- `test -f docs/container-format.md` → exit 0
- `grep -c 'KNTNTEXT' docs/container-format.md` → at least 1
- Every paragraph is on one physical line: no prose line should end mid-sentence.

### Step 3: Cross-reference in both directions

- In `classes/Crypto/Sealed_Writer.php`, point the class docblock at
  `docs/container-format.md` as the normative statement. Whether you keep the
  inline ASCII block or replace it with the pointer is your call — **say which
  you chose and why in your report**. Keeping it risks two copies drifting;
  removing it costs a reader of the class one hop. If you keep it, add a line
  saying the document governs on any disagreement.
- In `docs/adr/0009-*.md`, add a pointer to the spec. Do not rewrite the ADR.
- In `AGENTS.md`, add `docs/container-format.md` to the References list with a
  one-line description. This matters more than it looks: `AGENTS.md:6-9` tells
  an agent that only that file, the files it references, and the code are
  authoritative, and that narrative docs are to be ignored unless referenced
  there. A spec nobody is pointed at will not be read.
- In `README.md`, one line in the Development or Contributors area.

**Verify**:
- `grep -c 'container-format' AGENTS.md` → at least 1
- `grep -c 'container-format' classes/Crypto/Sealed_Writer.php` → at least 1

### Step 4: Changelog, and confirm nothing behavioural moved

Add one entry under `### Added` in `CHANGELOG.md`'s `[Unreleased]` section
(heading at `CHANGELOG.md:19`). End with `No REST change.`

**Verify**:
- `composer gate` → exit 0 (it should be unaffected; if it is not, you changed
  code)
- `git diff --stat` → only `docs/`, `AGENTS.md`, `README.md`, `CHANGELOG.md`,
  and comment-only lines in `classes/Crypto/Sealed_Writer.php`

## Test plan

No automated tests. This plan produces documentation.

The meaningful verification is a review question, and you should answer it
explicitly in your report: **could someone implement a working reader from
`docs/container-format.md` alone, without reading `classes/`?** Where the answer
is no, say which section is underspecified rather than claiming completeness.

If you want a stronger check and the budget allows, write a throwaway reader
script against the spec only, run it against an artifact produced by the test
suite, and report whether it worked. Do not commit the script — it is not in
scope, and a half-supported reader in the repository is worse than none.

## Done criteria

- [ ] `docs/container-format.md` exists and covers all ten sections from step 2
- [ ] All eight gaps listed in "Current state" are answered in the document
- [ ] `grep -c 'container-format' AGENTS.md` returns at least 1
- [ ] `grep -c 'container-format' classes/Crypto/Sealed_Writer.php` returns at least 1
- [ ] `grep -c 'container-format' README.md` returns at least 1
- [ ] `composer gate` exits 0
- [ ] `git diff --stat classes/` shows comment-only changes
- [ ] Your report answers the "could someone implement a reader from this alone" question
- [ ] Your report lists any ambiguity or defect the writing exposed, as findings rather than fixes
- [ ] `plans/README.md` status row for 009 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The wire format in `classes/Crypto/Sealed_Writer.php` does not match the
  excerpt above.
- You cannot establish one of the eight gaps from the code — for example if
  segment order and index order turn out **not** to correspond positionally.
  That would be a genuine defect and a much bigger deal than this plan; report
  it immediately rather than documenting a guess.
- The writing exposes a real ambiguity in the format. Record it; do not resolve
  it by changing the code.
- You find yourself wanting to change `MAGIC`, `FORMAT_VERSION`, the framing, or
  anything else about the artifact. Out of scope, and it would force a
  coordinated release.
- Plans 004 and 006 have not landed. They change `Sealed_Writer`, and a spec
  written against code that is about to move will be wrong on arrival.

## Maintenance notes

- **When a `Sealed_Reader` becomes worth building**: if writing the spec exposes
  a genuine ambiguity that prose cannot settle, that is the signal that the
  format needs an executable definition in this repository. Until then the spec
  plus the test suite's inline unsealing is enough, and a reader class would be
  a second implementation to keep in step.
- **The claim this makes checkable**: ADR-0014 asserts byte-compatibility across
  releases. With a spec in place, the natural follow-up is a golden-artifact
  test — a small artifact committed as a fixture, unsealed by the suite, and
  asserted to still parse. That was considered and deliberately deferred out of
  this plan; it belongs with a reader, not with the document.
- **What a reviewer should scrutinise**: that the document describes what the
  code does rather than what it ought to do, and that every normative statement
  is traceable to a line in `classes/Crypto/Sealed_Writer.php`.
- **What will make this document stale**: any change to the container. The
  change process section exists so that whoever proposes one knows the document
  is part of the change.

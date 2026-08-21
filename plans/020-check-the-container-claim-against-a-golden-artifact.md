# Plan 020: Check the container's byte-compatibility claim against a golden artifact, with a reader that never ships

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in the "STOP conditions" section occurs, stop and report — do not improvise. When done, update the status row for this plan in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 9ec5b8c..HEAD -- classes/Crypto/Sealed_Writer.php docs/container-format.md tests/Integration/sealed-writer-test.php tests/Build/build-release-zip-test.sh`
> On any change, compare the "Current state" excerpts against the live code before proceeding; on a mismatch, treat it as a STOP condition. `classes/Crypto/Sealed_Writer.php` moving is the one that matters most — this plan pins its output to the byte.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW — tests, fixtures and documentation only. No production code changes at all, not even a comment.
- **Depends on**: plan 009 (DONE, `0b90bde`). `docs/container-format.md` is what the reader is written from; without it there is nothing to write a reader *against*, only code to copy.
- **Category**: tests
- **Planned at**: commit `9ec5b8c`, 2026-08-20
- **Evidence**: a prototype built at this commit and run under local PHP 8.5.9 with the native `sodium` extension. Every byte count in this plan was measured on that prototype, not inferred; the recipe below reproduces them exactly. See "What was measured".

## The three decisions this plan settles

Issue #34 hands the plan three design questions and asks that each be settled with an argument rather than left open. They are settled here, in the order the ticket asks them.

### 1. The golden artifact is cut from `v0.5.1`

**Because that is the release the claim names.** ADR-0014's Consequences (`docs/adr/0014-…:49`) end with: "the container's wire format is untouched, so an artifact produced by this release is byte-compatible with one produced by 0.5.1 and `kntnt-wp-skills` needs no change." `docs/container-format.md:141` repeats it and records that nothing here can check it. A fixture cut from 0.5.1 makes the sentence checkable exactly as written, against exactly the release it names.

**Because it is the newest tag whose writer is not the writer under test.** `git diff v0.6.0..HEAD -- classes/Crypto/Sealed_Writer.php` is empty: the shipped 0.6.0 writer and today's are the same file. A fixture cut from 0.6.0 would therefore be produced by the code it is meant to check — a tautology on the day it lands, and the ticket's own warning ("a golden file that is regenerated whenever it fails proves nothing") applied one step earlier. The 0.5.1 writer is a genuinely different implementation: 0.5.1 → 0.6.0 rewrote it by 304 insertions and 109 deletions across five commits, moving the index from an in-memory list to a sidecar file (`f5a1e0a`) and `add_segment()` from a stream parameter to a string (`a2d7c01`). `f5a1e0a` is the commit ADR-0014 records, so the claim and the rewrite it is a claim about are the same change. That the two still frame identically is the whole content of the claim.

**Because 0.5.1 stands in for every release before it.** `git diff v0.1.0 v0.5.1 -- classes/Crypto/Sealed_Writer.php` is one line, and it is inside a docblock ("PHP 8.5" → "PHP 8.4"). The writer's *code* is byte-identical from 0.1.0 through 0.5.1, so a fixture cut from 0.5.1 is a fixture cut from the entire pre-0.6.0 history. There is nothing further back to gain.

**The general rule this establishes**, for whoever faces the question next: **one fixture per `FORMAT_VERSION`, cut from a release that shipped that version and never from the working tree.** Version 1's fixture is the 0.5.1 one and is now permanent.

### 2. The reader is test-only, and the existing build test already proves it never ships

**Because the server cannot use a reader, in any release, ever.** ADR-0009's construction is one-directional: every segment key and the index are sealed to the caller's X25519 *public* key, and the private half never reaches this site. `docs/container-format.md:110` states the consequence — "The server retains nothing able to open its own output" — and `tests/Integration/sealed-writer-test.php`'s post-`finalize()` retention walk asserts it. A `Sealed_Reader` under `classes/` would therefore be code that no production path could ever call, shipped to every installation, inside the `Crypto` namespace, and audited by every reviewer who wonders what opens artifacts on the server. The answer must stay "nothing does".

**Because a second shipped implementation is the cost the specification exists to avoid.** `docs/container-format.md:9` names one reference implementation here (the writer) and one production reader elsewhere (`kntnt-wp-skills`). Shipping a reader from this repository would create a second implementation that looks normative, in the repository that also owns the format document, and every format change would then need three things kept in step instead of two. The test-only reader carries no such claim: it is written *from* the document, the document governs on any disagreement, and its only job is to hold the writer to what the document says.

**Because it costs nothing to prove.** `tests/Build/build-release-zip-test.sh:102-107` already asserts that no path under `kntnt-extractor/tests/` appears in the distributable, and `composer test:build` is the gate's fourth step. The decision is therefore enforced by an assertion that already exists and that this plan does not have to write.

**What the rejected answer was right about.** A shipped class would be covered by `composer phpcs` and `composer phpstan`, and a test-only one is not: `phpstan.neon.dist`'s `paths` and `phpcs.xml.dist`'s `<file>` list both stop at `classes/` and the three root files, deliberately ("The test suite runs inside WordPress Playground, not under static analysis"). That is a real loss and this plan does not pretend otherwise; it is paid for by the reader being small, having one caller, and being exercised by seven assertions and five negative controls every time the gate runs. If it ever grows a second responsibility, revisit — but revisit by shrinking it, not by shipping it.

### 3. When the format legitimately moves, the fixture is superseded and never regenerated

This is the question with the trap in it, and the trap is real: a golden file that is regenerated whenever it fails proves nothing, because regenerating it is exactly the action a broken writer needs to make its breakage invisible. The answer has three legs, and the first is the one that actually does the work.

**Leg 1 — the expectations are derived from the specification, not recorded from the fixture.** The test computes every framing number from the recipe by `docs/container-format.md`'s own arithmetic, and compares the fixture to that:

- `sk_length` = `SODIUM_CRYPTO_SECRETBOX_KEYBYTES` + `SODIUM_CRYPTO_BOX_SEALBYTES` = 32 + 48 = **80**, for every segment (§3's constant table).
- `ct_length` = `strlen( plaintext )` + `SODIUM_CRYPTO_SECRETBOX_MACBYTES` = plaintext + **16** (§3, §7 — the MAC is folded in, not a separate field).
- `index_length` = Σ over segments of (8 + `strlen( name )`), + `SODIUM_CRYPTO_BOX_SEALBYTES` = payload + **48** (§3's index payload structure).
- `total` = 9 (header) + Σ (8 + `sk_length` + 24 + 8 + `ct_length`) + `index_length` + 8 (§3, §4).

**There is no recorded digest of the fixture anywhere.** A regenerated blob therefore cannot make a red test green: the arithmetic does not consult the blob, so whatever is on disk is measured against the format's rules either way. To make a broken writer pass, someone would have to edit the arithmetic — which is editing the specification's rules in the test file, in a diff, under a comment that says so. That is the whole point: the only way to defeat this test is an act that a reviewer can see. Measured: the arithmetic above reproduces the 0.5.1 fixture to the byte — `total=2666`, `index_length=274` — with no number taken from the file.

**Leg 2 — nothing in this repository can rewrite the fixture, and no script to do so is committed.** The suite never writes to `tests/Fixtures/`; the fixture is produced by checking two class files out of a tag and driving them from a throwaway script, and the procedure is recorded in prose in `tests/Fixtures/README.md` so it can be *audited* without being a one-command reflex when a test goes red. This is a deliberate choice, and it is the one place this plan trades convenience for friction on purpose. Committing a regeneration script would make "re-cut the golden file" a plausible response to a red run; recording the procedure keeps it possible for anyone verifying provenance and awkward for anyone dodging a failure.

**Leg 3 — provenance is pinned to git, which a regeneration cannot quietly restate.** `tests/Fixtures/README.md` records the tag, the commit, and the git blob hashes of the two class files the fixture was produced by, all of which are checkable with `git rev-parse` against history that a fixture regeneration does not touch.

**So what happens when the format legitimately moves?** A format change moves `FORMAT_VERSION` and `api_version` and is a coordinated release of both repositories (§8, §10). The rule this plan writes into the ADR:

1. **The version-1 fixture's bytes never change.** Not on a red test, not on a format change, not on a release. It is evidence, and evidence is superseded rather than edited.
2. **A format change adds a second fixture** — `container-<release>.b64` for the release that first shipped the new version — with its own recipe and its own spec-derived arithmetic, written by the change that moves the version.
3. **What the reader does with the old fixture is decided by that change**, and there are exactly two admissible outcomes, both of them assertions rather than deletions: either the reader still opens version-1 containers, and the old fixture keeps its current assertions; or the reader refuses them, and the old fixture's assertions become an asserted refusal naming the version. `docs/container-format.md:126` requires a reader to refuse a *higher* version than it implements and says nothing about a lower one, which is why this is a decision for that change and not a rule invented here.
4. **A red golden test has exactly two causes**, and neither is "the fixture is stale": the current code no longer honours the format, or the format was changed deliberately and (2) and (3) have not been done yet. Regenerating is not on the list.

## Why this matters

`docs/container-format.md:141` is the finding, stated by the project about itself: ADR-0014 asserts byte-compatibility across releases, and "this document is the precondition for ever checking that claim mechanically … rather than asserting it in prose alone." The precondition has been met since `0b90bde`; the check has not been built. So the repository that defines the format, writes it, and coordinates a two-repository release around it still cannot answer "did this release change the artifact?" with anything but a reading of the diff.

That gap has a cost with a name on it. The consuming client's verified ceiling (`plans/README.md`, "Corrections to earlier drafts") is enforced by a model reading skill prose, not by code — so on the reader's side, "version 7 is artifact-identical to version 6" is checked by a human deciding it is. On this side it is checked by nobody. The two ends of a binary contract are currently held together by two people's reading of two diffs.

A golden artifact closes it for the price of one fixture and one small reader. After this plan, `composer gate` fails if today's writer would produce a container that a 0.5.1 reader could not walk — and it fails on the framing, before anyone gets as far as a production install.

There is a second thing it buys, and it is worth as much: **plan 009's own review question becomes executable.** That plan's test section asked "could someone implement a working reader from `docs/container-format.md` alone, without reading `classes/`?" and left it a matter of the executor's judgement. This plan requires the reader to be written from §4 alone and forbids it from referencing `Sealed_Writer` at all, so the answer stops being an opinion in a report and becomes an assertion in the gate.

## What this does not fix, and does not change

- **It does not touch the wire contract.** No artifact byte changes, no REST field changes. `API_VERSION` stays **7** and `Status_Controller::HONOURED_BEHAVIOURS` gains nothing: no caller can observe this and there is nothing to opt into.
- **It does not make this repository able to read a real artifact.** The reader is test-only, it opens only what a caller's private key opens, and the server still holds no such key. Nothing about ADR-0009 changes, and the plugin gains no capability.
- **It does not prove the consuming client can read the container.** It proves the container matches the document the client is written against. The client's own conformance is `kntnt-wp-skills`'s to test, and this plan does not touch that repository.
- **It does not cover a segment name that is not valid UTF-8.** That is plan 009's third unfixed finding and it is filed separately as **#35**. The fixture's names are deliberately all valid UTF-8 (one of them non-ASCII), so the two tickets stay separable and neither has to wait for the other. Do not widen this plan to cover it.
- **It does not consolidate the suite's seven inline parsers.** `file-packaging`, `resume-and-adapt`, `sealed-writer`, `structure-only`, `table-chunking`, `table-read-failure` and `tick-extraction` each carry their own `$parse` closure over the same framing. Migrating them to the new reader is tempting, is a seven-file diff, and would put this ticket in conflict with anything else touching those files. Left alone deliberately; see "Left on the table".
- **It does not claim the fixture proves the *cryptography* is unchanged.** Keys and nonces are random, so nothing about them is comparable across releases. What is comparable is the framing, and the framing is what a reader parses. §6's properties stay covered by `sealed-writer-test.php`, which is where they belong.

## Current state

### The claim, and the record that it cannot be checked

`docs/container-format.md:141`, the last paragraph of §10:

```
ADR-0014's Consequences assert that an artifact produced by one release is byte-compatible with one produced by an earlier release (0.5.1). This document is the precondition for ever checking that claim mechanically — for example with a golden-artifact fixture unsealed by a future reader implementation — rather than asserting it in prose alone.
```

`docs/container-format.md:9`, the sentence a test-only reader must keep true:

```
`classes/Crypto/Sealed_Writer.php` in this repository, `kntnt-extractor`, is the reference implementation — but a write-only one. This repository builds sealed containers; it does not ship a reader. The only unsealing code that exists here lives inline in the integration test suite (…), written to cross-check the writer, not as a reusable or documented contract.
```

Both sentences move in step 6. Nothing else in that document does.

### The writer, and the two constants the fixture pins

`classes/Crypto/Sealed_Writer.php:96` and `:106`:

```php
	public const string MAGIC = 'KNTNTEXT';
	public const int FORMAT_VERSION = 1;
```

The record and the trailer, `:502-503` and `:553`:

```php
		$this->write( $handle, pack( 'P', strlen( $sealed_key ) ) . $sealed_key . $nonce . pack( 'P', strlen( $ciphertext ) ) . $ciphertext );
		$this->write( $index_handle, pack( 'P', strlen( $name ) ) . $name );
…
		$this->write( $handle, $sealed_index . pack( 'P', strlen( $sealed_index ) ) );
```

`add_segment()` takes `string $plaintext` (`:475`). **The 0.5.1 writer takes a stream instead** — `public function add_segment( string $name, $stream ): void` — which is why step 1's script wraps each plaintext in a `php://temp` handle. That difference is the reason the fixture is worth cutting.

### The harness, and what it can already do

- `tests/Integration/bootstrap.php:79` requires every `*-test.php` in `tests/Integration/` in one process, in `glob()` order. A helper outside that pattern is not auto-loaded and must be `require_once`d by its caller.
- `tests/Integration/opaque-failure-test.php:256` reads a file out of the repository from inside a test (`file_get_contents( dirname( __DIR__, 2 ) . '/classes/…' )`). The plugin root is mounted in Playground, so a fixture under `tests/Fixtures/` is readable the same way. This is the precedent; there is no existing fixtures directory.
- `tests/Integration/sealed-writer-test.php:37-41` already seals to a **fixed seed keypair**, and its comment states why: "A fixed keypair fixture: a constant seed makes the run deterministic while the private key stays available in-process to open what the writer sealed." This plan reuses that seed (`str_repeat( "\x2a", SODIUM_CRYPTO_BOX_SEEDBYTES )`) so there is one test key pair in the repository, not two.
- `tests/Integration/sealed-writer-test.php:124` shows the destination convention for a container built in a test: `tempnam( sys_get_temp_dir(), 'kntnt-sealed-' )`.
- `tests/Build/build-release-zip-test.sh:102-107` already asserts no `kntnt-extractor/tests/` path reaches the distributable.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md` before writing. Load-bearing here: English throughout; `declare( strict_types = 1 )`; a `//` comment above each paragraph stating its *purpose*; tabs and WordPress surface style; a full docblock on the class and on every method. Below 1.0 a new symbol carries no `@since`; do not stamp one and do not read a version off the `Version:` header (ADR-0024). In Markdown, each paragraph is one physical line — never hard-wrapped.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Local PHP with sodium (needed in step 1 only) | `php -r 'var_dump( extension_loaded( "sodium" ) );'` | `bool(true)` |
| Coding standard | `composer phpcs` | exit 0 (unaffected — it does not read `tests/`) |
| Static analysis | `composer phpstan` | exit 0 (likewise) |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Distributable check | `composer test:build` | exit 0 |
| Everything | `composer gate` | exit 0 |

Redirect the gate to a file **whose name is unique to your working tree** and capture `$?` on its own line — `composer gate | tail` reports `tail`'s status, and that has produced one false green in this project already.

## Scope

**In scope**:

- `tests/Fixtures/container-0.5.1.b64` (create) — the golden container, base64
- `tests/Fixtures/container-0.5.1-recipe.php` (create) — the recipe and the seed
- `tests/Fixtures/README.md` (create) — provenance, and the never-regenerate rule
- `tests/Support/Sealed_Reader.php` (create) — the test-only reader
- `tests/Integration/golden-container-test.php` (create) — the test
- `docs/adr/00NN-…md` (create) — the three decisions; **take the next free number at execution time**, do not assume 0022
- `docs/container-format.md` — §1's "the only unsealing code…" sentence and §10's last paragraph
- `docs/testing-strategy.md` — one entry in the "Current tests" list, and one line in "How it is wired" for the two new directories
- `CHANGELOG.md` — one entry under `[Unreleased]` → `### Added`
- `plans/README.md` — this plan's row, its one-line entry, and the finding it closes

**Out of scope** (do NOT touch):

- **`classes/` — anything, including a comment.** This plan changes no production byte. If you find yourself editing a file under `classes/`, stop: the fixture is measuring the writer, and a plan that adjusts the thing it measures is not measuring anything. The single exception is step 4's control, which edits one line and reverts it inside the same step; the Done criteria require the diff against `classes/` to be empty at the end.
- The seven inline `$parse` closures in the existing test files. Left deliberately; see "What this does not fix".
- A segment name that is not valid UTF-8 (**#35**).
- `API_VERSION`, `HONOURED_BEHAVIOURS`, the REST surface, the job record's schema.
- `AGENTS.md`. `docs/container-format.md` and `docs/adr/` are already in its References list, which is where a cold agent will find both the format and this decision.
- `CONTEXT.md`. Considered and declined: R3 asks for the documentation round, and this change adds no domain vocabulary — a test fixture and a test-only helper are not terms a reader of the glossary needs, and the glossary already heads **Segment**, which is the one term the test reasons in. Say in your report that you considered it and why you left it.
- `docs/testing-strategy.md`'s other staleness. It documents about 14 of 35 test files and calls the gate three steps when it is four. Both are known findings in `plans/README.md`; add your entry and fix nothing else.
- The `kntnt-wp-skills` repository.

## Git workflow

Trunk-based: commit straight to `main`, no branch, no pull request. Do not push, tag, or bump a version. One commit; an imperative subject line, no prefix. Suggested: `Check the container's byte-compatibility claim against a golden artifact`.

## Steps

### Step 1: Cut the golden container from `v0.5.1` and commit it with its provenance

Produce it **once**, from the tag, outside the repository, and never from the working tree.

1. Confirm the provenance is what this plan measured, and record what these print:

```
git rev-parse v0.5.1^{commit}                          # 3ffe0793145a3d16298eceb6fa2620a0e5945fbe
git rev-parse v0.5.1:classes/Crypto/Sealed_Writer.php  # 6d3447906477a4e52c2890e715d26829d4b893bd
git rev-parse v0.5.1:classes/Crypto/Invalid_Public_Key.php  # df400effe12b5120fd30c421d1333ea07c02bb8e
```

2. Write `tests/Fixtures/container-0.5.1-recipe.php` **verbatim, except that each `X.Y.Z` placeholder becomes the version from `kntnt-extractor.php`'s `Version:` header** (see "Conventions to match") — the byte counts in this plan are this recipe's, and a changed literal changes them. Those counts come from the returned segments alone, so the docblocks are not among the literals they depend on:

```php
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
```

3. In a scratch directory **outside the repository**, check the two class files out of the tag and drive them. The 0.5.1 `add_segment()` takes a stream, so each plaintext is wrapped:

```
mkdir -p /tmp/golden && cd /tmp/golden
git -C <repo> show v0.5.1:classes/Crypto/Sealed_Writer.php > Sealed_Writer.php
git -C <repo> show v0.5.1:classes/Crypto/Invalid_Public_Key.php > Invalid_Public_Key.php
```

```php
<?php
declare( strict_types = 1 );
require '<repo>/tests/Fixtures/container-0.5.1-recipe.php';
require __DIR__ . '/Invalid_Public_Key.php';
require __DIR__ . '/Sealed_Writer.php';
use Kntnt\Extractor\Crypto\Sealed_Writer;

$writer = new Sealed_Writer( __DIR__ . '/container.sealed' );
$writer->open( sodium_crypto_box_publickey( kntnt_extractor_golden_keypair() ) );
foreach ( kntnt_extractor_golden_recipe() as [ $name, $plaintext ] ) {
	$stream = fopen( 'php://temp', 'r+b' );
	fwrite( $stream, $plaintext );
	rewind( $stream );
	$writer->add_segment( $name, $stream );
	fclose( $stream );
}
$writer->finalize();
echo filesize( __DIR__ . '/container.sealed' ), "\n";
```

4. Encode it into the repository, wrapped so `git diff` renders it:

```
php -r 'echo chunk_split( base64_encode( (string) file_get_contents( $argv[1] ) ), 76, "\n" );' /tmp/golden/container.sealed > tests/Fixtures/container-0.5.1.b64
```

5. Write `tests/Fixtures/README.md`. It must record: what the file is; the tag, the commit and the two blob hashes from (1); that the recipe file is the input and this document the procedure; the four spec-derived numbers from decision 3's Leg 1; and — in its own section, in plain words — **that the bytes are never regenerated, what the two causes of a red golden test are, and that neither is "the fixture is stale"**. Point at the ADR from step 6. Do not commit the driver script.

**Verify**:
- `php -r 'echo strlen( base64_decode( preg_replace( "/\s+/", "", (string) file_get_contents( "tests/Fixtures/container-0.5.1.b64" ) ), true ) );'` → **2666**
- The first eight decoded bytes are `KNTNTEXT` and the ninth is `\x01`
- The last eight decoded bytes, read as u64 LE, are **274**
- `git status --short` lists only the three new files under `tests/Fixtures/`

If the length is not 2666, the recipe was not copied verbatim or the wrong writer was driven. STOP rather than adjusting the expected number.

### Step 2: Write the golden test, and watch it fail

Write `tests/Integration/golden-container-test.php` in full, against a reader that does not exist yet, and run the suite. This is the red step, and it follows the pattern `sealed-writer-test.php:29-35` already established for a missing class: assert `class_exists()` and `return` cleanly.

The test's assertions, in order:

1. **The fixture decodes and is what the specification says it is.** Magic `KNTNTEXT` and version `1`, compared against **literals**, never against `Sealed_Writer::MAGIC` or `Sealed_Writer::FORMAT_VERSION`.
2. **The two constants still hold.** Separately, and explicitly: `Sealed_Writer::MAGIC === 'KNTNTEXT'` and `Sealed_Writer::FORMAT_VERSION === 1`. These are the only two places the test may mention `Sealed_Writer` other than constructing one in assertion 5.
3. **(AC1) The current code reads a 0.5.1 artifact.** Parse the fixture with the reader and the recipe's key pair; assert the names, in order, are the recipe's names in order, and that every segment opens to the recipe's plaintext byte for byte — including the zero-length one and the 1024-byte all-byte-values one.
4. **(AC2) The fixture's framing is the framing the specification requires.** Compute `sk_length`, every `ct_length`, `index_length` and `total` from the recipe by the arithmetic in "Leg 1" above, and assert the fixture matches. Every expected number must be computed in the test; **no literal 2666 or 274 may appear in an assertion**, or the test is recording the file rather than checking it.
5. **(AC3) A container written now frames identically.** Build one with `Sealed_Writer` from the same recipe into `tempnam( sys_get_temp_dir(), 'kntnt-golden-' )`, parse it, and assert its framing equals the fixture's — the version byte, the total length, the index length, and the ordered list of `sk_length`/`ct_length` pairs. Those are every byte of a container that is not one of the three randomised spans, so equality here is byte-identity of the framing. **This is the assertion the ticket exists for.** Wrap the parse so a malformed fresh container fails this assertion rather than aborting the process — a throw escaping a test file takes the whole run down with it, as plan 019 recorded at exit 255.
6. **(AC4) …and is not the same file.** Assert the two containers' bytes are *not* equal, and that the first segment's `sealed_key`, `nonce` and `ciphertext` all differ between them. Without this, AC3 could pass on two copies of one file and would prove nothing about freshness.
7. **(AC5) The reassembly rule works.** Assert `reassemble( 'wp_options' )` returns parts 1, 2 and 3 concatenated in index order, and that `reassemble( 'wp_users' )` returns the single segment. This is §5's rule, and nothing in this repository currently tests it as a rule.

**Verify**: `composer test:integration` → the file's `Sealed_Reader class is available` assertion reports `not ok` and the file returns cleanly, the rest of the suite still passing. **Record the failing TAP line in your report.**

### Step 3: Write the reader — from §4 alone

Write `tests/Support/Sealed_Reader.php`, and `require_once` it from the test.

Namespace it `Kntnt\Extractor\Tests` so it can never collide with a shipped class and the plugin's autoloader (which maps `Kntnt\Extractor\` to `classes/`) can never resolve it into a production request.

The interface, deliberately small:

- `__construct( string $keypair )` — the recipient key pair, as `sodium_crypto_box_keypair()` returns one.
- `parse( string $raw ): array` — the framing and the names, no plaintext: `[ 'version' => int, 'total' => int, 'index_length' => int, 'names' => list<string>, 'records' => list<array{ sk_length: int, ct_length: int, sealed_key: string, nonce: string, ciphertext: string }> ]`.
- `framing( array $parsed ): array` — the version, the total, the index length, and the ordered `[ sk_length, ct_length ]` pairs. Nothing random.
- `open_segment( array $parsed, int $position ): string` — the *n*th segment's plaintext; throws when it will not open.
- `reassemble( array $parsed, string $name ): string` — §5's concatenation.

Four rules, all of them checkable:

- **It may not reference `Sealed_Writer` or any of its constants.** `MAGIC` is the literal `'KNTNTEXT'` in this file and the header is 9 bytes because §3 says so. A reader that imports its expectations from the writer cannot detect the writer changing them.
- **It bounds-checks before every read.** §7 requires a reader to refuse any length field that would read past the end. Refuse *before* calling `unpack()` or `substr()`, not after: measured on the prototype, a reader that unpacks first still reaches the right verdict but gets there through two PHP warnings (`unpack(): Type P: not enough input values`, then `Trying to access array offset on false`). A refusal that depends on PHP's error handling to be correct is not a refusal, and the warnings are emitted onto the same stdout the suite writes its TAP lines to. Reject a negative value too — a u64 above `PHP_INT_MAX` arrives as a negative int, and that is exactly what a 4-byte length field looks like when read as eight (step 4's control).
- **Every refusal throws a `RuntimeException` with a distinct message.** The test asserts on the refusal, and a shared message makes two different failures indistinguishable.
- **Its docblock says what it is**: written from `docs/container-format.md`, not normative, test-only, and the document governs on any disagreement.

**Verify**: `composer test:integration` → exit 0, `Integration suite: PASS`, with assertions 1–7 all `ok`. **Record the passing TAP lines beside step 2's failing one.**

### Step 4: Prove AC3 discriminates — the control that matters

AC3 is the assertion the ticket is buying, so demonstrate that it can go red rather than assuming it.

Temporarily change `classes/Crypto/Sealed_Writer.php:502` to frame the sealed key in four bytes instead of eight — `pack( 'V', strlen( $sealed_key ) )` for `pack( 'P', … )` — which is the smallest possible real drift from the format. Re-run the suite. Then revert, and re-run to green.

Expected, and measured on the prototype: the drifted writer produces a **2634-byte** container instead of 2666; AC3 goes red; **AC1 and AC2 stay green**, because they read the fixture, which the writer cannot touch. That split is the proof: the fixture is old evidence, and the drift is caught in the comparison against it rather than in the fixture itself.

**Record both runs in your report.** If AC3 does not go red, the framing comparison is not comparing the framing — find that before going on.

### Step 5: Pin the reader's own refusals

Five negative controls, all built by mutating a **copy of the fixture in memory** — the file on disk is never written to. Each must be refused, and the unmutated fixture must not be, or the assertion proves only that the reader is broken.

1. **A flipped `index_length` byte** → refused (the index will not unseal). Prototype message: `the sealed index would not unseal`.
2. **A `FORMAT_VERSION` of 2** → refused (§8's MUST). Nothing else in the repository tests this rule.
3. **A truncated container** (drop the last 200 bytes) → refused (§7's MUST), not read short.
4. **A foreign key pair** — the `"\x17"`-seeded one `sealed-writer-test.php:159` already uses → opens nothing.
5. **A flipped ciphertext byte** → `open_segment()` throws rather than returning garbage (§7).

**Verify**: `composer test:integration` → exit 0, with the five refusals in the TAP output and no PHP warning or notice anywhere in the run's output.

### Step 6: Pay the documentation round (R3)

1. **A new ADR**, at the next free number. Title it for the decision, in the house voice — for example "The byte-compatibility claim is checked against a golden container, read by a reader that never ships". It must record all three decisions and their arguments as settled: the fixture is cut from 0.5.1 and one fixture exists per `FORMAT_VERSION`; the reader is test-only and the build test is what enforces it; and the fixture is never regenerated, with the three legs and the four rules from "Decision 3" written out. Its Consequences must include the rule about what a format change adds and what it may do to the old fixture, and must say that `API_VERSION` does not move.
2. **`docs/container-format.md`** — two edits and no more. §1's "The only unsealing code that exists here lives inline in the integration test suite" is no longer true: name `tests/Support/Sealed_Reader.php`, say it is test-only and written from this document, and keep "it does not ship a reader" true by saying so explicitly. §10's last paragraph currently says this document "is the precondition for ever checking that claim mechanically — for example with a golden-artifact fixture unsealed by a future reader implementation": replace the conditional with what now exists, naming the test and the fixture, and state what it checks (the framing, not the ciphertext) and what it does not.
3. **`docs/testing-strategy.md`** — one entry in "Current tests" for `golden-container-test.php` in the voice of the entries around it, and one line in "How it is wired" for `tests/Fixtures/` and `tests/Support/` explaining that neither is auto-discovered by the bootstrap's `*-test.php` glob. Fix nothing else in that file.
4. **`CHANGELOG.md`**, `### Added` under `[Unreleased]`. Say what now exists, what it checks, and — plainly — that the fixture is never regenerated. End with `No REST change.`

**Verify**: `git diff --stat` → only files from the In-scope list, and nothing under `classes/`.

### Step 7: Full gate and the index

Update `plans/README.md`: add this plan's row to the table, add its one-line entry to "What each plan is for", and amend the `009` row so it records that the golden fixture its Maintenance notes deferred has landed here.

**Verify**: `composer gate` → exit 0, with the exit code captured on its own line, from a log path unique to your working tree.

## Test plan

- **New files**: `tests/Integration/golden-container-test.php`, `tests/Support/Sealed_Reader.php`, and three fixture files. No existing test file is modified.
- **New cases**: the fixture's header and framing against spec arithmetic; the recipe round-tripping out of 0.5.1 bytes; a container written now framing identically to it; the two not being the same bytes; the reassembly rule; and five refusals.
- **Controls that must be demonstrated, not assumed**: step 2's failing run before the reader exists, and step 4's `pack( 'V' )` drift turning AC3 red while AC1 and AC2 stay green. Record the TAP lines for both.
- **Existing coverage relied on**: `tests/Build/build-release-zip-test.sh`'s AC3, which is what keeps the reader out of the distributable. Do not weaken it.
- **Known limitations to state in your report**: the fixture is produced under the native `sodium` extension and read under Playground's `sodium_compat`, which is a feature (it is the cross-implementation check the format's users actually make) but means the suite never *writes* a container with the native extension; and the framing check cannot see a change in the *content* of a segment's plaintext, only in its length — a writer that changed what it puts in a segment would pass, and that is `table-chunking-test.php`'s job, not this one's.

## Done criteria

Machine-checkable unless noted. ALL must hold:

- [ ] `tests/Fixtures/container-0.5.1.b64` decodes to exactly **2666** bytes, beginning `KNTNTEXT\x01` and ending in a u64 LE of **274**
- [ ] `tests/Fixtures/README.md` records the tag, the commit `3ffe079…`, both blob hashes, and the never-regenerate rule
- [ ] `grep -c 'Sealed_Writer' tests/Support/Sealed_Reader.php` returns **0** — this is the discriminating grep for "written from the document, not from the code"
- [ ] `grep -n 'Sealed_Writer' tests/Integration/golden-container-test.php` shows only the two constant assertions and the writer used in AC3 — verify by reading
- [ ] `grep -n '2666\|274' tests/Integration/golden-container-test.php` returns nothing — every expected number is computed from the recipe
- [ ] AC1, AC2, AC3, AC4 and AC5 all appear in the TAP output as `ok`
- [ ] The five refusals of step 5 appear in the TAP output, and the run's output contains no PHP warning or notice
- [ ] Step 2's failing run and step 4's `pack( 'V' )` control are both recorded in the report, with AC1 and AC2 shown staying green under the control
- [ ] `git diff --stat 9ec5b8c..HEAD -- classes/` shows **no changes at all**
- [ ] `grep -n 'API_VERSION' classes/Rest/Status_Controller.php` still reads 7
- [ ] The new ADR exists at the next free number and states all three decisions
- [ ] `docs/container-format.md` §1 and §10 both updated; no other section touched
- [ ] `docs/testing-strategy.md` carries one new "Current tests" entry and one "How it is wired" line
- [ ] `CHANGELOG.md` carries the `### Added` entry, ending `No REST change.`
- [ ] `composer gate` exits 0, verified as the gate's own exit code
- [ ] `git status --short` lists only files from the In-scope list
- [ ] `plans/README.md` rows for 020 and 009 both updated

## STOP conditions

Stop and report back (do not improvise) if:

- The drift check reports changes to `classes/Crypto/Sealed_Writer.php` and the excerpts above no longer match the live code.
- **AC3 fails on the unmodified writer** — that is today's writer framing differently from 0.5.1's, which is ADR-0014's claim being false and `kntnt-wp-skills` reading a container this repository no longer produces. It is a far bigger finding than this plan and it is exactly what the plan was built to detect. Report it with the two framings side by side. **Do not "fix" it by re-cutting the fixture or by adjusting the arithmetic.**
- The step-1 fixture is not 2666 bytes. Something other than the recipe or the release differs; do not proceed with a fixture whose provenance you cannot state.
- Step 4's control does not turn AC3 red, or turns AC1 or AC2 red as well. In the first case the comparison is not comparing framing; in the second the fixture is being regenerated somewhere, which is the failure mode this whole plan exists to prevent.
- **The reader needs a rule that `docs/container-format.md` does not state.** That is a genuine gap in the specification and it is plan 009's open review question answering itself. Record exactly which section is short and what you would have had to invent, and stop — do not settle it in the reader, which would put the rule in the least authoritative place available.
- No local PHP with the `sodium` extension is available. The fixture cannot then be cut, and cutting it from the current writer instead is not a fallback — it is the tautology decision 1 rejects.
- You conclude the reader should ship in `classes/` after all, or that the fixture should be regenerated for any reason. Both are decisions this plan settles; if you think one is wrong, say so and stop rather than taking it.
- You find yourself editing anything under `classes/` for any reason other than step 4's temporary control, which is reverted before the step ends.

## Maintenance notes

### What was measured

A prototype built at commit `9ec5b8c` and run under local PHP 8.5.9 (Homebrew, native `sodium`). The 0.5.1 and HEAD writers were each checked out of git into a scratch directory and driven over the recipe above with the same seed key pair; a reader written from `docs/container-format.md` §4 alone parsed both. The prototype was discarded; nothing of it is committed.

| Measurement | Result |
|---|---|
| The `v0.5.1` writer, driven standalone | 2666 bytes, `index_length` 274, 8 segments, `sk_length` 80 throughout, `ct_length` 82, 73, 70, 74, 16, 1040, 30, 30 |
| The `HEAD` writer, same recipe | 2666 bytes, and **every one of those numbers identical** |
| Whole files identical? | **No** — and the first segment's `sealed_key`, `nonce` and `ciphertext` all differ, as they must |
| The recipe recovered out of the 0.5.1 bytes | Every name and every plaintext, byte for byte, in order |
| Spec arithmetic vs the 0.5.1 bytes | Exact: 9 + Σ(8 + 80 + 24 + 8 + ct) + 274 + 8 = 2666, with no number read from the file |
| The `pack( 'V' )` drift control | 2634 bytes; the reader refuses it outright (`a length field exceeds PHP_INT_MAX`), so the framing comparison cannot pass |
| The five refusals | All refused. With the bounds checks placed *before* `unpack()`, no PHP warning is emitted; with them after, the same verdicts arrive but two warnings precede them |
| `git diff v0.1.0 v0.5.1 -- classes/Crypto/Sealed_Writer.php` | One line, inside a docblock — the writer's code is identical across 0.1.0–0.5.1 |
| `git diff v0.6.0..HEAD -- classes/Crypto/Sealed_Writer.php` | Empty — the shipped 0.6.0 writer is the writer under test |

Two things worth carrying forward. First, **the reader must bounds-check before it unpacks**: the naive order reaches the same verdicts but arrives at them through PHP warnings, which means the refusal is really PHP's rather than the reader's. Second, the drift control is refused *at parse* rather than merely framing differently, which is a stronger failure than AC3 strictly needs — do not let that tempt you into dropping the framing comparison for a "does it parse" check. A drift that still parses is precisely the dangerous kind.

### What a reviewer should scrutinise

- That no expected number in the test was read from the fixture. The greps in the Done criteria are the cheap version; reading assertion 4 is the real one.
- That `tests/Support/Sealed_Reader.php` mentions `Sealed_Writer` nowhere, including in a comment that copies a constant's value.
- That the control in step 4 was actually run and that AC1 and AC2 stayed green under it. A control reported but not run is how a non-discriminating assertion ships.
- That nothing under `classes/` changed.
- That the fixture's provenance in `tests/Fixtures/README.md` matches `git rev-parse`, and that no regeneration script was committed alongside it.

### Left on the table

- **Seven inline `$parse` closures** across `file-packaging`, `resume-and-adapt`, `sealed-writer`, `structure-only`, `table-chunking`, `table-read-failure` and `tick-extraction` still duplicate the framing walk. Migrating them to `Sealed_Reader` is the obvious follow-up and is deliberately not done here: it is a seven-file diff with no new coverage, and it would collide with anything else touching those files. Worth doing when one of them next needs editing anyway, one file at a time.
- **A segment name that is not valid UTF-8** — plan 009's third finding, filed as **#35**. The fixture's names are all valid UTF-8 on purpose so the two stay independent. Whoever executes #35 should consider whether its case belongs in a *second* fixture rather than in this one: adding it to the 0.5.1 fixture would mean re-cutting the fixture, which decision 3 forbids.
- **Nothing checks that `kntnt-wp-skills` reads the container.** This plan checks the container against the document; the client's conformance to the same document is that repository's to test, and a golden artifact is exactly as reusable there. Worth proposing when the two repositories next coordinate a release.
- **The framing check sees lengths, not content.** A writer that changed what it puts inside a segment — different SQL, a different chunking rule — passes this test and is caught by `table-chunking-test.php` instead. Do not let the golden test grow into a second copy of that coverage.

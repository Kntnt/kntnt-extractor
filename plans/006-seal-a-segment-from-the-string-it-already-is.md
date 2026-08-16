# Plan 006: Seal a segment directly from the string it already is, so plaintext never reaches the system temp directory

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Crypto/Sealed_Writer.php classes/Artifact_Builder.php tests/Integration/sealed-writer-test.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: plans/004-keep-the-sidecar-until-the-container-is-published.md (same class — land 004 first so the two changes stay separable in review)
- **Category**: security
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

Every segment's plaintext — rows of `wp_users` and `wp_options`, the bytes of
every selected file — is written into a `php://temp` stream and immediately read
straight back out again as the identical string.

`php://temp` keeps its contents in memory only up to 2 MB. Past that, PHP
transparently spills the stream to a real file in the system temp directory.
**Both of this plugin's default chunk budgets are above that threshold** — 8 MiB
for a file part, 4 MiB for a table slice — so on a default installation
essentially every chunk writes production plaintext to an unencrypted file in
`sys_get_temp_dir()`, reads it back, and relies on PHP to unlink it.

On shared hosting that directory may be readable by neighbouring accounts. And
this build is *designed* around the host killing the PHP worker outright — that
is the whole reason it is a resumable tick loop — which is exactly the case
where the spilled file is never unlinked. A stalling run therefore accumulates
orphaned plaintext fragments with nothing to reap them.

The stream buys nothing in exchange. The caller already holds the complete
segment as a PHP string before it opens the stream, and the writer's first act
is to read the whole stream back into another string. It is a round trip
through the disk to arrive where it started, plus a third simultaneous copy of
the chunk in memory.

`Sealed_Writer`'s own class docblock states the invariant this violates:
"plaintext never accumulates on disk". After this plan that sentence is true.

There is a second benefit worth naming: raising `chunk_size` is the obvious
first thing anyone reaches for when packaging is slow, and today doing so makes
every chunk spill. This removes that trap.

## Current state

### The stream that buys nothing

`classes/Artifact_Builder.php:396-421`:

```php
	/**
	 * Wraps a byte string in a rewound in-memory stream for the sealed writer.
	 *
	 * A `php://temp` stream keeps the segment in memory for small chunks and spills to a
	 * temp file only if it grows large, matching the writer's one-segment working set.
	 *
	 * @since 0.1.0
	 *
	 * @param string $data The segment's plaintext — a table dump or a bounded file part.
	 * @return resource A rewound readable stream over the data.
	 *
	 * @throws RuntimeException When the in-memory stream cannot be opened.
	 */
	private function stream_of( string $data ) {

		// Buffer the bounded chunk in memory and rewind it for the streaming writer.
		$stream = fopen( 'php://temp', 'r+b' ); // phpcs:ignore ...
		if ( $stream === false ) {
			throw new RuntimeException( 'Unable to open an in-memory stream for a segment.' );
		}
		fwrite( $stream, $data ); // phpcs:ignore ...
		rewind( $stream );

		return $stream;

	}
```

Note that the parameter is already a complete `string`. The docblock's claim
that it "spills to a temp file only if it grows large" is accurate about the
mechanism and misleading about the consequence, because of the defaults below.

### The three call sites — all pass a string

```
classes/Artifact_Builder.php:209:			$writer->add_segment( $table, $this->stream_of( $slice ) );
classes/Artifact_Builder.php:221:			$writer->add_segment( $table, $this->stream_of( $this->dumper->dump_structure( $table ) ) );
classes/Artifact_Builder.php:227:			$writer->add_segment( $file, $this->stream_of( $part ) );
```

### The defaults that put every chunk over the spill threshold

`classes/Artifact_Builder.php:53`:

```php
	private const int DEFAULT_CHUNK_SIZE = 8388608;
```

`classes/Artifact_Builder.php:97`:

```php
	private const int DEFAULT_TABLE_CHUNK_BYTES = 4194304;
```

8 MiB and 4 MiB, against `php://temp`'s 2 MB in-memory ceiling.

### The writer reads it straight back

`classes/Crypto/Sealed_Writer.php:439-473`, the relevant part of
`add_segment()`:

```php
	public function add_segment( string $name, $stream ): void {

		// Require an open container: this guards the open→add→finalize order and
		// narrows the handles and key away from null for the operations below.
		$handle = $this->handle;
		$index_handle = $this->index_handle;
		$public_key = $this->public_key;
		if ( $handle === null || $index_handle === null || $public_key === null ) {
			throw new LogicException( 'Sealed_Writer::open() must be called before add_segment().' );
		}

		// Read the whole (already bounded) segment from the caller's stream.
		$plaintext = stream_get_contents( $stream );
		if ( $plaintext === false ) {
			throw new RuntimeException( 'Unable to read a segment stream.' );
		}

		// Encrypt under a fresh random symmetric key and seal that key to the
		// caller's public key, then wipe the key and the plaintext so the server
		// keeps nothing able to open its own output (ADR-0009). ...
		$key = sodium_crypto_secretbox_keygen();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		$sealed_key = sodium_crypto_box_seal( $key, $public_key );
		$this->wipe( $key );
		$this->wipe( $plaintext );

		// Append the self-framed segment record and its name's index entry. ...
		$this->write( $handle, pack( 'P', strlen( $sealed_key ) ) . $sealed_key . $nonce . pack( 'P', strlen( $ciphertext ) ) . $ciphertext );
		$this->write( $index_handle, pack( 'P', strlen( $name ) ) . $name );

	}
```

### The class docblock this makes honest

`classes/Crypto/Sealed_Writer.php:20-23`:

```
 * Every `sodium` call and every byte of container framing lives behind this one
 * seam; nothing else in the codebase touches the crypto. The container is built
 * encrypt-as-you-go — each segment is ciphered and written the moment it is
 * added — so plaintext never accumulates on disk and the writer needs to hold
 * only one segment in memory at a time (ADR-0009).
```

### The test that is coupled to the stream, and knows it

`tests/Integration/sealed-writer-test.php:221-233` binds an acceptance criterion
to `add_segment()`'s **source text**, using a regex that looks specifically for
`stream_get_contents`:

```php
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
```

Its own comment at `:218-220` anticipates exactly this change:

> This is deliberately coupled to the inlined write path (ADR-0009 keeps all
> crypto in this one seam); a refactor that relocates the wipe should re-prove
> AC2 here rather than pass in silence.

**You must update this assertion in lockstep.** If you do not, `$plaintext_var`
becomes `null`, `$plaintext_is_wiped` becomes `false`, and the assertion fails —
which is the test doing its job.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
Load-bearing: English throughout; a `//` comment above each paragraph stating
its *purpose*; WordPress surface style (tabs, `snake_case` methods, spaces
inside parentheses); complete PHPDoc on every method with `@since`, `@param`,
`@return`, `@throws`. Both files in scope are densely and carefully commented —
match them.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

## Scope

**In scope**:

- `classes/Crypto/Sealed_Writer.php` — `add_segment()`'s signature and its read
- `classes/Artifact_Builder.php` — the three call sites and the removal of
  `stream_of()`
- `tests/Integration/sealed-writer-test.php` — the AC2 reflection assertion, and
  new assertions
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Fixed`)

**Out of scope** (do NOT touch):

- The container's wire format. Every byte written by `add_segment()` stays
  identical: same framing, same fresh key per segment, same fresh nonce, same
  seal. `api_version` stays 6 and no coordinated client release is needed. If
  your change alters a single byte of the artifact, you have gone wrong.
- `DEFAULT_CHUNK_SIZE` and `DEFAULT_TABLE_CHUNK_BYTES`. **Do not change a
  budget.** This plan removes the spill; it does not re-tune the sizes, and
  this project's rule is that constants are measured, not chosen.
- The `wipe()` helper and the key handling. Both are correct.
- `finalize()`, `resume()`, `suspend()`.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Seal a segment from its string, so plaintext never spills to the system temp directory`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Take a string in `add_segment()`

In `classes/Crypto/Sealed_Writer.php`:

1. Change the signature to `public function add_segment( string $name, string $plaintext ): void`.
2. Delete the `stream_get_contents()` read and its `false` check. The
   `$plaintext` variable now comes from the parameter, so everything below it —
   the keygen, the nonce, the secretbox, the two wipes, the two writes — is
   unchanged. **Do not reorder or otherwise touch those lines**; the AC2
   assertion is bound to them.
3. Update the docblock: the `@param` for the second argument, the `@throws`
   (the `RuntimeException` for an unreadable stream is gone; the one from
   `write()` remains), and the sentence in the method description that refers
   to reading a stream.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -c 'stream_get_contents' classes/Crypto/Sealed_Writer.php` → 0

### Step 2: Pass the strings directly and delete `stream_of()`

In `classes/Artifact_Builder.php`:

1. Rewrite the three call sites to pass the string:
   ```php
   $writer->add_segment( $table, $slice );
   $writer->add_segment( $table, $this->dumper->dump_structure( $table ) );
   $writer->add_segment( $file, $part );
   ```
2. Delete the whole `stream_of()` method and its docblock (`:396-421`).

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -c 'stream_of' classes/Artifact_Builder.php` → 0
- `grep -rc 'php://temp' classes/` → 0

### Step 3: Re-prove AC2 against the new write path

In `tests/Integration/sealed-writer-test.php`, the plaintext variable is no
longer produced by an assignment, so a regex over the body cannot find it. Use
reflection on the parameter instead — that is both simpler and more robust:

```php
$plaintext_var = '$' . $add_segment->getParameters()[1]->getName();
```

Keep everything else about the assertion as it is: `$key_var` is still found by
the `sodium_crypto_secretbox_keygen` regex, and both `wipe()` checks stay. Update
the comment above the block so it describes the new coupling (a string
parameter rather than a stream read) — the existing comment explains *why* the
assertion is source-coupled and that reasoning still holds.

**Verify**: `composer test:integration` → exit 0, and the TAP line
`add_segment() wipes both the fresh symmetric key and the segment plaintext …`
still reports `ok`.

### Step 4: Confirm the artifact is byte-identical

This is the assertion that matters most. The whole suite already round-trips
sealed artifacts and compares recovered plaintext to the originals
(`tests/Integration/tick-extraction-test.php`, `sealed-writer-test.php`,
`table-chunking-test.php` AC2). If any of them fail, the wire format moved and
you must stop.

**Verify**: `composer test:integration` → exit 0, `Integration suite: PASS`,
with no change in the number of passing assertions other than the ones you add.

### Step 5: Add assertions for the property this plan buys

In `tests/Integration/sealed-writer-test.php`, add:

1. **A segment larger than the spill threshold round-trips.** Seal a segment of
   at least 3 MB (above `php://temp`'s 2 MB ceiling, below the 8 MiB default)
   and assert the recovered plaintext is byte-identical. Before this change
   that segment went through a real file on disk; now it does not.
2. **An empty segment still works.** A zero-length plaintext must seal and
   recover as zero-length — this is the path an empty file takes, and a
   `string` parameter must handle it exactly as the empty stream did.
3. **Best-effort: no temp file appears.** Snapshot the entries in
   `sys_get_temp_dir()`, seal the multi-megabyte segment from (1), and assert
   no new entry appeared. If the Playground WASM filesystem makes this
   unreliable, say so in your report and in a comment rather than shipping a
   flaky assertion.

**Verify**:
- `composer test:integration` → exit 0, with your new assertions in the TAP
  output.
- Demonstrate the RED step for assertion 3 if you shipped it: temporarily
  restore `stream_of()` and the stream parameter, re-run, and confirm it
  reports `not ok`. Restore and re-run to green. **Record both runs in your
  report.**

### Step 6: Changelog

Add one entry under `### Fixed` in `CHANGELOG.md`'s `[Unreleased]` section
(heading at `CHANGELOG.md:7`), matching the surrounding entries' style. Name
both halves — that plaintext stopped reaching the system temp directory, and
that the round trip bought nothing because the caller already held the whole
string. End with `No REST change.` and state that the container's bytes are
unchanged.

**Verify**: `git diff --stat` → only the four files from "In scope".

### Step 7: Full gate

**Verify**: `composer gate` → exit 0.

## Test plan

- **File**: `tests/Integration/sealed-writer-test.php` (extend and amend).
- **Amended**: the AC2 reflection assertion, rebound to the parameter.
- **New cases**: a >2 MB segment round-trips byte-identically; an empty segment
  seals and recovers; best-effort, no new file appears in the system temp
  directory.
- **Regression guarded elsewhere**: the byte-identity of the whole artifact is
  already covered by `tick-extraction-test.php` and `table-chunking-test.php`
  AC2. Do not duplicate those.
- **Verification**: `composer test:integration` → all pass, plus the
  demonstrated failing run from step 5 where applicable.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -rc 'php://temp' classes/` returns 0
- [ ] `grep -c 'stream_of' classes/Artifact_Builder.php` returns 0
- [ ] `grep -c 'stream_get_contents' classes/Crypto/Sealed_Writer.php` returns 0
- [ ] `grep -n 'string \$plaintext' classes/Crypto/Sealed_Writer.php` returns a match in the `add_segment` signature
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] The AC2 wipe assertion still reports `ok` in the TAP output
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] `plans/README.md` status row for 006 updated

## STOP conditions

Stop and report back (do not improvise) if:

- Any test comparing recovered plaintext to the original fails, or any
  byte-identity assertion fails. That means the wire format moved, which this
  change must not do. Do not adjust the assertion — report.
- The AC2 wipe assertion cannot be made to pass with the parameter-based
  lookup. Do not delete or weaken it; it is deliberately source-coupled and its
  own comment says a refactor must re-prove it.
- You find a fourth caller of `add_segment()` outside
  `classes/Artifact_Builder.php` and `tests/`.
- PHPStan objects to the signature change in a way that suggests another caller
  exists that you have not found.
- The fix appears to require changing a chunk budget, the framing, or
  `api_version`.

## Maintenance notes

- **What future changes will interact with this**: plan 007 edits `resume()`
  and `suspend()` in the same class. A parked improvement would hold the
  container open across a whole tick instead of per chunk; nothing here blocks
  it.
- **What a reviewer should scrutinise**: that the encryption block in
  `add_segment()` is byte-for-byte the same code as before, only fed from a
  parameter; and that the AC2 assertion was genuinely rebound rather than
  loosened.
- **Memory note worth keeping in mind**: this removes one of three
  simultaneous copies of each chunk (string → stream → string). The remaining
  two are the plaintext and the ciphertext, which is inherent to
  `sodium_crypto_secretbox()`. If memory pressure is ever measured as the thing
  killing the worker, that pair is where to look next — not here.
- **Deliberately not done**: re-tuning `DEFAULT_CHUNK_SIZE`. Removing the spill
  makes a larger chunk size cheaper than it used to be, which may change what
  the right default is. That is a measurement, not a guess, and belongs with
  the project's outstanding per-chunk cost question.

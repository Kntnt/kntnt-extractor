# Plan 004: Keep the index sidecar until the container has been published, so a crash at the finish line is still recoverable

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Crypto/Sealed_Writer.php classes/Artifact_Builder.php tests/Integration/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (independent of 002 and 003; different files)
- **Category**: bug
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

An extraction of a large site runs for hours across many resumable ticks. The
whole design assumes the host can kill the PHP worker at any instruction and
that the next tick picks up where the last one committed.

There is one window where that assumption fails, and it is the worst possible
one: the last few instructions of the whole build. `finalize()` seals the index,
writes the trailer, closes the container — and **deletes the index sidecar**.
The caller then renames the finished container into the served downloads
directory. If the worker dies between those two things, the container is
complete on disk but the sidecar the resume path requires is gone. The next tick
calls `resume()`, which fails closed on a missing sidecar; the throw is caught
opaquely, the job is failed with a null error (and therefore deliberately never
re-driven), and the failure handler then deletes the finished container as
staging residue.

A multi-hour extraction that had actually succeeded is destroyed, and the
operator sees an unexplained failure.

The window is short — the sidecar deletion and the rename are adjacent — but the
cost of landing in it is total, and the fix is to move one call. The sidecar is
pure working state after the trailer is written; keeping it a few instructions
longer costs nothing and makes the finish line as recoverable as every other
chunk boundary already is.

## Current state

### `finalize()` deletes the sidecar

`classes/Crypto/Sealed_Writer.php:520-531`, the tail of `finalize()`:

```php
		// Close the container, drop every reference, and remove the sidecar now that
		// the names it held are sealed into the container for good, so no value able to
		// open the artifact and no plaintext name list survives this call. A failed
		// close can mean buffered trailer bytes never reached disk — a truncated
		// artifact — so it is escalated, but only once the references are already gone.
		$closed = fclose( $handle ); // phpcs:ignore ...
		$this->handle = null;
		$this->public_key = null;
		$this->discard_index();
		if ( $closed === false ) {
			throw new RuntimeException( 'Unable to close the sealed container after writing its trailer.' );
		}
```

`classes/Crypto/Sealed_Writer.php:563-569`:

```php
	private function discard_index(): void {

		if ( is_file( $this->index_path() ) ) {
			unlink( $this->index_path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing the plugin's own index sidecar once its names are sealed into the container.
		}

	}
```

### The caller publishes afterwards

`classes/Artifact_Builder.php:237-241`:

```php
		} else {
			$writer->finalize();
			$this->publish( $build_path, $download_path );
			return new Build_Step( new Build_Progress( $tables_done, $structure_done, $file_index, $file_offset, $container_bytes, $index_bytes, $segment_count, $file_size, $file_mtime, $table_offset, $table_cursor ), true );
		}
```

and the identical pair at `classes/Artifact_Builder.php:250-254`. **There are
exactly two call sites and they are both in this file.**

`classes/Artifact_Builder.php:350-356`:

```php
	private function publish( string $build_path, string $download_path ): void {

		// Move the sealed container into the served directory in one atomic step.
		if ( ! rename( $build_path, $download_path ) ) { // phpcs:ignore ...
			throw new RuntimeException( 'Unable to publish the sealed artifact into place.' );
		}

	}
```

### Why the resume then fails

`classes/Crypto/Sealed_Writer.php:326-329`:

```php
		$index_size = is_file( $this->index_path() ) ? filesize( $this->index_path() ) : false;
		if ( $committed_index_bytes < 0 || $index_size === false || $index_size < $committed_index_bytes ) {
			throw new RuntimeException( 'The in-progress sealed container index is missing or shorter than its committed offset.' );
		}
```

Failing closed here is **correct** and must not change.

### Why the existing crash shortcut does not cover this

`classes/Artifact_Builder.php:172-178`:

```php
			// A prior tick may have finalized and published the container, then died in
			// the window before its ready state was saved; the build file is gone but the
			// finished artifact already sits at the download path. Treat that as complete
			// rather than failing to resume a container that was correctly moved away.
			if ( ! is_file( $build_path ) && is_file( $download_path ) ) {
				return new Build_Step( $progress, true );
			}
```

This covers the window *after* the rename. In the window this plan fixes, the
build file is still present and the download file is still absent, so the
shortcut does not fire.

### Why the fix is complete rather than partial

With the sidecar preserved, a crash in the window recovers through machinery
that already exists and is already tested. The record's progress was not
advanced (the completing `Build_Step` is only returned after `publish()`), so
`resume()` truncates the container back to the committed offset — discarding
both the trailer and the final segment — truncates the sidecar to its own
committed offset, and the build re-packages the last chunk, finalizes, and
publishes again. No duplication, no special case.

### What must remain true

`tests/Integration/bounded-state-file-test.php:298-303` asserts that a
**finished** build leaves no sidecar in the job directory (AC4). That must keep
passing: the sidecar still gets deleted, just after the publish instead of
before it.

`tests/Integration/resume-and-adapt-test.php:398` and `:538` assert that a
**failed** job discards its sidecar. Those go through
`Job_Store::reclaim_staging()`, not `finalize()`, and are unaffected.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
Load-bearing: English throughout; a `//` comment above each paragraph stating
its *purpose*; WordPress surface style (tabs, `snake_case` methods, spaces
inside parentheses). `classes/Crypto/Sealed_Writer.php` is a heavily-documented
file — match its density, and keep every PHPDoc block complete
(`@since`, `@return`, `@throws`).

From `CONTEXT.md`, the vocabulary for comments:

> **Segment**: The artifact's unit of encryption and of reassembly: one bounded
> chunk of one selected table or file, sealed on its own and recorded in the
> sealed index under that table's name or that file's installation-root-relative
> path.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

## Scope

**In scope**:

- `classes/Crypto/Sealed_Writer.php` — `finalize()` and `discard_index()` only
- `classes/Artifact_Builder.php` — the two completion branches at `:237-241` and
  `:250-254` only
- `tests/Integration/sealed-writer-test.php` — new assertions
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Fixed`)

**Out of scope** (do NOT touch):

- `classes/Crypto/Sealed_Writer.php:326-329` — the fail-closed check on a
  missing sidecar. It is correct; the bug is that the sidecar goes missing, not
  that its absence is refused.
- `classes/Artifact_Builder.php:172-178` — the existing published-but-unsaved
  shortcut. Extending it to detect a finished-but-unpublished container by
  reading its trailer was considered and **deliberately rejected**: preserving
  the sidecar recovers the same case through existing, tested machinery, and
  trailer detection would be new complexity guarding a case that no longer
  exists.
- `classes/Job_Store.php` — `reclaim_staging()` and the failure path are
  correct.
- The container's wire format. Nothing in this plan changes a byte of the
  artifact, so the REST `api_version` stays 6 and no coordinated client release
  is needed.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Discard the index sidecar after the container is published, not before`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Stop `finalize()` deleting the sidecar, and let the caller do it

In `classes/Crypto/Sealed_Writer.php`:

1. Remove the `$this->discard_index();` call from `finalize()` (currently
   `:528`). Update the paragraph comment above it — it currently claims the
   sidecar is removed here — and update `finalize()`'s docblock, which says at
   `:481-482` that "the index sidecar is removed". Both must now say that the
   sidecar survives finalize and is the caller's to discard once the container
   is published, with one sentence on why (a crash between the two would
   otherwise strand a finished container that cannot be resumed).
2. Change `discard_index()` from `private` to `public` and give it a docblock
   in the file's style, stating that it is called once the container has been
   published, that it is safe to call when the sidecar is already gone, and
   that the sidecar is working state rather than part of the artifact.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -n 'discard_index' classes/Crypto/Sealed_Writer.php` → the definition
  is `public`, and there is no call to it inside `finalize()`

### Step 2: Discard the sidecar after each publish

In `classes/Artifact_Builder.php`, both completion branches must become
finalize → publish → discard. Target shape, applied at `:237-241` and again at
`:250-254`:

```php
			$writer->finalize();
			$this->publish( $build_path, $download_path );
			$writer->discard_index();
```

Add a short paragraph comment above the first occurrence explaining the
ordering: the sidecar is what a resume needs to roll a crashed tick back, so it
is discarded only once the container it belongs to has been moved into the
served directory and can no longer be resumed. The second occurrence can refer
to the first rather than repeat it — match how this file already handles its
duplicated completion branches.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -c 'discard_index' classes/Artifact_Builder.php` → 2

### Step 3: Confirm the existing suite still passes

In particular, `tests/Integration/bounded-state-file-test.php:298-303` (AC4:
"A finished build leaves no index sidecar in the job directory") must still
pass — it is the assertion that proves you moved the deletion rather than
removed it.

**Verify**: `composer test:integration` → exit 0, `Integration suite: PASS`.

If AC4 fails, you have deleted the call instead of relocating it. Fix that
before continuing.

### Step 4: Add assertions that pin the new property

Extend `tests/Integration/sealed-writer-test.php`. That file drives
`Sealed_Writer` directly with a fixed test keypair, which is the right level:
the property is about the writer's contract, and the crash you are guarding
against cannot be produced inside a Playground request.

Read the file first — it is well commented and its existing `finalize()`
assertions (`:138`, `:293`, `:312`, `:359`) show the shape to follow.

Add assertions proving:

1. **The sidecar survives `finalize()`.** Open a writer on a temp path, add a
   couple of segments, call `finalize()`, and assert the `.names` sidecar still
   exists. (`Sealed_Writer::INDEX_SUFFIX` is `'.names'`, declared at `:127`.)
2. **A finalized-but-unpublished container is still resumable.** This is the
   property the whole plan exists for. On that same container, call `resume()`
   with the committed offsets from *before* the last segment, add the last
   segment again, `finalize()`, and assert the recovered plaintext round-trips
   to the expected bytes. Note the assertion's description should say plainly
   that before this fix `resume()` threw here.
3. **`discard_index()` is idempotent.** Calling it twice does not error.

**Verify**:
- `composer test:integration` → exit 0, with your new assertions in the TAP
  output.
- Demonstrate the RED step the coding standard requires: temporarily restore
  the `$this->discard_index();` call inside `finalize()`, re-run, and confirm
  assertions 1 and 2 report `not ok`. Restore the fix and re-run to green.
  **Record both runs in your report.**

### Step 5: Changelog

Add one entry under `### Fixed` in `CHANGELOG.md`'s `[Unreleased]` section
(heading at `CHANGELOG.md:7`), matching the surrounding entries — full
paragraphs stating what was wrong, what it cost, and whether the contract moved.
End with `No REST change.`

**Verify**: `git diff --stat` → only the four files from "In scope".

### Step 6: Full gate

**Verify**: `composer gate` → exit 0.

## Test plan

- **File**: `tests/Integration/sealed-writer-test.php` (extend, do not create).
- **Cases**: sidecar survives `finalize()`; a finalized-but-unpublished
  container resumes and round-trips correctly; `discard_index()` is idempotent.
- **Pattern to follow**: the existing `finalize()` and `resume()` assertions in
  the same file.
- **Regression guarded elsewhere**: `bounded-state-file-test.php` AC4 already
  proves a finished build leaves no sidecar; do not duplicate it.
- **Verification**: `composer test:integration` → all pass, plus the
  demonstrated failing run from step 4.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'discard_index' classes/Artifact_Builder.php` returns 2
- [ ] `grep -n 'public function discard_index' classes/Crypto/Sealed_Writer.php` returns a match
- [ ] Within `finalize()` there is no call to `discard_index()` — verify by reading the method
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] The TAP output still contains the AC4 line about a finished build leaving no index sidecar
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report contains the output of the deliberately-failing run from step 4
- [ ] `plans/README.md` status row for 004 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at `classes/Crypto/Sealed_Writer.php:520-531` or
  `classes/Artifact_Builder.php:237-241` does not match the excerpts above.
- `tests/Integration/bounded-state-file-test.php` AC4 fails and relocating the
  call does not fix it.
- Making `discard_index()` public trips a coding-standard or PHPStan rule you
  cannot satisfy without changing something out of scope.
- You find a third caller of `finalize()` outside `classes/Artifact_Builder.php`
  and `tests/`. There should be exactly two production call sites.
- The fix appears to require touching the container's wire format, the REST
  surface, or `api_version`. It does not; if it seems to, the diagnosis is
  wrong.

## Maintenance notes

- **What future changes will interact with this**: plan 007 edits `resume()` and
  `suspend()` in the same class, and a parked improvement would hold the
  container open across a whole tick rather than per chunk. If the writer ever
  stays open across chunks, revisit where the sidecar's lifetime ends — the
  invariant to preserve is "the sidecar outlives every state in which a resume
  could still be needed".
- **What a reviewer should scrutinise**: that the deletion is *relocated*, not
  removed, and that both completion branches got it. A build that leaves
  sidecars behind is a slow disk leak, and AC4 is the only thing catching it.
- **Residue this deliberately accepts**: if the worker dies between the rename
  and `discard_index()`, one small `.names` file is left in the job directory.
  The next tick's shortcut at `Artifact_Builder.php:172-178` reports the job
  complete, and the whole job directory is removed on consume, cancel, or the
  TTL sweep. That is a strictly better outcome than the destroyed artifact this
  plan removes.

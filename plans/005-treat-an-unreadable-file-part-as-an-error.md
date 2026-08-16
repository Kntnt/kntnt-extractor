# Plan 005: Treat a failed or empty read below a file's end as an error, instead of spinning forever

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Artifact_Builder.php classes/Extraction_Job.php classes/Dispatcher.php classes/Sweeper.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none (independent of 002–004)
- **Category**: bug
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

This plugin has three independent safeguards that stop a build from running
forever: a stall counter that detects a chunk begun repeatedly and never
finished, a heartbeat that lets another actor take over a job nobody is
tending, and an absolute lifetime ceiling in the TTL sweep measured from the
last real progress.

One line defeats all three at once. When the packager reads a bounded part of a
file, a `false` return from `fread()` is cast to an empty string and never
checked. An empty part advances the file's offset by zero, so the file never
finishes — but the chunk *is* treated as progress, which resets the stall
counter, refreshes the heartbeat, and refreshes the last-progress timestamp. The
job therefore keeps ticking, sealing empty segment after empty segment into a
growing container, holding the single global concurrency slot, and never
tripping any of the three bounds. Nothing stops it short of the operator
noticing.

How easily `fread()` fails on a regular file is environment-dependent — but the
environment this plugin is being tuned for is a managed host whose uploads
directory is suspected of being networked or overlay-mounted, which is exactly
where a transient I/O fault is plausible. The guard costs four lines.

After this plan, a bounded read that returns nothing below the file's end fails
the job loudly instead of wedging it silently.

## Current state

### The unchecked read

`classes/Artifact_Builder.php:311-329`, the tail of `read_part()`:

```php
		// Open the validated path, seek to the part's offset, and read one bounded chunk;
		// past the end this reads nothing, which still yields a single empty part for an
		// empty file. Direct stream I/O is required because a part is read incrementally.
		$handle = fopen( $abs, 'rb' ); // phpcs:ignore ...
		if ( $handle === false ) {
			throw new RuntimeException( 'Unable to open a requested file for packaging.' );
		}
		if ( $offset > 0 && fseek( $handle, $offset ) === -1 ) {
			fclose( $handle ); // phpcs:ignore ...
			throw new RuntimeException( 'Unable to seek a requested file for packaging.' );
		}
		$part = $offset < $size ? (string) fread( $handle, max( 1, $max_bytes ) ) : ''; // phpcs:ignore ...
		fclose( $handle ); // phpcs:ignore ...

		// Report the offset after this part and whether it reached the file's end, so the
		// caller advances to the next file only once the whole file is packaged.
		$next_offset = $offset + strlen( $part );

		return [ $part, $next_offset, $next_offset >= $size, $size, $mtime ];
```

Note the surrounding style: the open and the seek both check their result and
both `fclose` before throwing. The read does neither. `(string) false` is `''`.

**The `$offset < $size` guard is load-bearing and must be preserved.** An empty
(zero-byte) file has `$offset === 0` and `$size === 0`, so it takes the `''`
branch, yields one empty part, and `$next_offset >= $size` is true — which is
how an empty file completes as exactly one segment. Your change must not break
that.

### Why an empty part resets every bound

`classes/Extraction_Job.php:247-251`:

```php
	public function with_progress( Build_Progress $progress ): self {

		return new self( $this->id, $this->state, $this->owner, $this->public_key, $this->tables, $this->structure_only, $this->files, $this->created_at, time(), $this->tick_secret, $this->artifact, $progress, time(), 0, $this->error, ... );

	}
```

The two `time()` values are `updated_at` (the heartbeat) and `progressed_at`
(what the sweep's lifetime ceiling measures from). The `0` is the attempt
counter.

`classes/Dispatcher.php:382-388` calls it on every advancing chunk:

```php
		$advanced = $running->with_progress( $step->progress );
		$this->store->save( $advanced );

		return $advanced;
```

So the three bounds that would otherwise catch a wedged job:

- the stall counter at `classes/Dispatcher.php:342` never reaches
  `max_stall_attempts`, because it is reset to `0` every iteration;
- `needs_advance()` at `classes/Dispatcher.php:591` keeps seeing a fresh
  heartbeat, so the job always looks actively tended;
- `classes/Sweeper.php:150`'s absolute lifetime ceiling measures from
  `progressed_at`, which is refreshed every iteration.

### Where a throw from here lands

`classes/Dispatcher.php:362-368`:

```php
		try {
			$step = $this->builder->advance( $running, $this->store->container_build_path( $running ), $this->store->artifact_path( $running ) );
		} catch ( Throwable ) {
			return $this->persist_failure( $running->with_state( Job_State::Failed ) );
		}
```

The job fails with `error === null` — an opaque failure, which per ADR-0015 is
deliberately never re-driven. That is the right outcome for an I/O fault: a
loud failure the caller can retry, rather than an immortal job. **Do not try to
make this failure resumable.**

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
Load-bearing: English throughout; a `//` comment above each paragraph stating
its *purpose*, not its mechanics; WordPress surface style (tabs, `snake_case`
methods, spaces inside parentheses). Every `throw` in this file carries a
message that names no caller-supplied path — see `:300`, `:308`, `:316`, `:320`.
Match that exactly: a message that is useful and leaks nothing.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

## Scope

**In scope**:

- `classes/Artifact_Builder.php` — the read in `read_part()` only (`:322-323`)
- `tests/Integration/file-packaging-test.php` — new assertions
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Fixed`)

**Out of scope** (do NOT touch):

- `classes/Extraction_Job.php` — making `with_progress()` refuse to reset the
  counters when the progress has not actually advanced is a genuine
  belt-and-braces improvement, and it is **deliberately deferred**. It needs an
  equality notion for `Build_Progress` that does not exist yet, and it would
  change the semantics of a method called on every chunk. See "Maintenance
  notes".
- `classes/Dispatcher.php` — the opaque-failure handling is correct.
- `classes/Sweeper.php` — the lifetime ceiling is correct; it was simply being
  fed a refreshed timestamp.
- The `$offset < $size` guard and the empty-file behaviour it produces.
- The REST surface. Nothing here is caller-visible; `api_version` stays 6.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Fail a file part that cannot be read, never seal it as an empty segment`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Check the read

Replace `classes/Artifact_Builder.php:322-323` (the `$part = …` line and the
`fclose` that follows it). Target shape:

```php
		// Read one bounded part, or nothing at all once the offset has reached the end —
		// which is how a zero-byte file still yields exactly one empty part and completes.
		$part = '';
		if ( $offset < $size ) {
			$read = fread( $handle, max( 1, $max_bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading one bounded file part into the sealed writer; WP_Filesystem has no incremental-read API.

			// A bounded read below the end that yields nothing is never a legitimate
			// outcome, and treating it as one is worse than it looks: the part seals
			// empty, the offset does not move, and the chunk still counts as progress —
			// which clears the stall counter and refreshes both the heartbeat and the
			// last-progress stamp. All three bounds that would stop a wedged build are
			// reset together, so the job would seal empty segments forever while holding
			// the concurrency slot. Fail instead.
			if ( $read === false || $read === '' ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the read handle after a failed part read; see the fopen above.
				throw new RuntimeException( 'Unable to read a part of a requested file for packaging.' );
			}
			$part = $read;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the read handle after one bounded part; see the fopen above.
```

Then add the new failure to `read_part()`'s docblock `@throws` line, alongside
the open, seek and size failures it already documents.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -c '(string) fread' classes/Artifact_Builder.php` → 0

### Step 2: Confirm the existing suite still passes

The suite already packages files end to end, including — check this — at least
one empty or small file. If anything regresses here it will be the empty-file
path, which is the one thing this change must leave alone.

**Verify**: `composer test:integration` → exit 0, `Integration suite: PASS`.

### Step 3: Add assertions for the boundaries this change touches

Extend `tests/Integration/file-packaging-test.php`. Read it first; it already
builds file fixtures and drives them through to a sealed artifact, so the
scaffolding you need is there.

Add assertions proving the guard did not become too aggressive:

1. **A zero-byte file still packages.** A selection containing an empty file
   completes, and the recovered artifact contains that file as an empty entry.
   This is the assertion that would catch a guard placed on the wrong side of
   the `$offset < $size` test.
2. **A file whose size is an exact multiple of the part budget completes
   without a spurious extra part.** Force a small `chunk_size` (the file
   already shows how this project forces budgets) and use a fixture sized to
   exactly two parts. Assert the file completes and the bytes round-trip.

Then attempt, best-effort, to provoke a real read failure:

3. If you can construct a case inside WordPress Playground where `fread()`
   returns `false` or `''` below the file's end, assert the job reaches
   `failed` and no artifact is published. **This is likely not reachable in
   this harness** — a WASM in-memory filesystem does not produce transient I/O
   faults on demand. If you cannot construct it, do not fake it by writing
   state into a job file: say so plainly in your report and in a comment in the
   test file, and ship assertions 1 and 2.

**Verify**:
- `composer test:integration` → exit 0, with your new assertions in the TAP
  output.
- Demonstrate the RED step for assertion 1 at least: temporarily move the
  guard so it also fires on the `$offset >= $size` path, re-run, and confirm
  assertion 1 reports `not ok`. Restore and re-run to green. **Record both runs
  in your report.**

### Step 4: Changelog

Add one entry under `### Fixed` in `CHANGELOG.md`'s `[Unreleased]` section
(heading at `CHANGELOG.md:7`), matching the surrounding entries' style. State
what the empty part did to the three bounds — that is the part a reader will
not reconstruct. End with `No REST change.`

**Verify**: `git diff --stat` → only the three files from "In scope".

### Step 5: Full gate

**Verify**: `composer gate` → exit 0.

## Test plan

- **File**: `tests/Integration/file-packaging-test.php` (extend, do not create).
- **Cases**: zero-byte file still completes as one empty segment; a file sized
  to an exact multiple of the part budget completes without a spurious part;
  best-effort, a failed read fails the job.
- **Pattern to follow**: the existing fixture-building and forced-budget code
  in the same file.
- **Verification**: `composer test:integration` → all pass, plus the
  demonstrated failing run from step 3.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c '(string) fread' classes/Artifact_Builder.php` returns 0
- [ ] `grep -n "Unable to read a part of a requested file" classes/Artifact_Builder.php` returns a match
- [ ] `git diff 8a35b2b..HEAD -- classes/Extraction_Job.php classes/Dispatcher.php classes/Sweeper.php` is empty
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report says explicitly whether assertion 3 was reachable in the harness
- [ ] `plans/README.md` status row for 005 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at `classes/Artifact_Builder.php:311-329` does not match the excerpt
  above.
- An existing test that packages an empty or small file now fails. That means
  the guard is on the wrong side of the `$offset < $size` test — fix that
  before continuing, and if you cannot, report.
- The fix appears to require touching `classes/Extraction_Job.php`,
  `classes/Dispatcher.php` or `classes/Sweeper.php`. It does not.
- You discover the assumption "an empty part still counts as progress and
  resets the stall counter, the heartbeat and `progressed_at`" is false — for
  instance if some other guard already catches this. Report what you found;
  that would make this plan unnecessary and that is worth knowing.

## Maintenance notes

- **Deliberately deferred, and worth revisiting**: `with_progress()` at
  `classes/Extraction_Job.php:247` resets the attempt counter, the heartbeat
  and `progressed_at` on *every* call, including one where the progress is
  byte-for-byte identical to the previous one. This plan removes the one known
  way to reach that state, but the general shape — "a chunk that changed
  nothing still counts as progress" — remains. Making `with_progress()` a no-op
  when the progress has not moved would close the whole class rather than this
  one instance. It needs an equality notion for `Build_Progress`, which does
  not exist yet.
- **What a reviewer should scrutinise**: that the `fclose` on the throw path is
  present (the surrounding code is careful about this and the new branch must
  match), and that the empty-file path is untouched.
- **Known coverage limitation**: the integration harness runs on WordPress
  Playground's in-memory WASM filesystem, which cannot produce a transient read
  fault. The guard is therefore likely to ship with its failure path unexercised
  — that is a real limitation, and it should be stated in the test file and in
  the report rather than papered over.

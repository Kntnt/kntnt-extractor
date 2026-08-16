# Plan 007: Remove four redundant filesystem and database round trips from every packaged chunk

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Crypto/Sealed_Writer.php classes/Table_Dumper.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: plans/002, plans/003 (same file: `Table_Dumper.php`), plans/004, plans/006 (same file: `Sealed_Writer.php`). Land all four first.
- **Category**: perf
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

On the production host this plugin is being tuned against, packaging ran at
about 4.75 files per second — roughly **210 ms per 44 KB file**. The same code
path costs about 0.25 ms on a local NVMe drive. Roughly half of that 210 ms is
attributed to a known cost; the rest is not yet explained, and the leading
hypothesis is that the site's uploads directory is on a networked or
overlay-mounted filesystem where each metadata operation is a synchronous
round trip to a server.

A static count of the per-chunk path supports that hypothesis: packaging one
44 KB file part performs about **28 round-trip-class operations** — opens,
closes, renames, truncates, stats, reads and writes. At 2–5 ms each that is
56–140 ms, which brackets the unexplained portion.

Treat the *count* as a fact and the *millisecond translation* as a hypothesis.
Nobody has yet timed these calls on the real host. What follows does not depend
on the hypothesis being right: each change removes work that is provably
redundant — an operation whose result is already known from a local variable, or
a query whose answer cannot have changed. If the hypothesis is wrong, this is
still four fewer operations for nothing lost.

This plan takes the cheap, obviously-safe subset. It deliberately does **not**
attempt the larger restructure that would hold the sealed container open across
a whole tick instead of reopening it per chunk; that is worth roughly nine of
the 28 and needs a new seam between the builder and the dispatcher. See
"Maintenance notes".

None of this touches the artifact's wire format, the REST surface, or the
adaptation machinery. `api_version` stays 6.

## Current state

### (a) Two `ftruncate` calls that the code already knows are no-ops

`classes/Crypto/Sealed_Writer.php:310-355`, inside `resume()`. The container:

```php
		$header_length = strlen( self::MAGIC ) + 1;
		$size = is_file( $this->destination_path ) ? filesize( $this->destination_path ) : false;
		if ( $committed_bytes < $header_length || $size === false || $size < $committed_bytes ) {
			throw new RuntimeException( 'The in-progress sealed container is shorter than its committed offset.' );
		}
```

and the sidecar:

```php
		$index_size = is_file( $this->index_path() ) ? filesize( $this->index_path() ) : false;
		if ( $committed_index_bytes < 0 || $index_size === false || $index_size < $committed_index_bytes ) {
			throw new RuntimeException( 'The in-progress sealed container index is missing or shorter than its committed offset.' );
		}
```

Both then truncate **unconditionally**:

```php
		if ( ftruncate( $handle, $committed_bytes ) === false || fseek( $handle, $committed_bytes ) === -1 ) {
			fclose( $handle ); // phpcs:ignore ...
			throw new RuntimeException( 'Unable to position the in-progress sealed container for appending.' );
		}
```

```php
		$index_handle = fopen( $this->index_path(), 'r+b' ); // phpcs:ignore ...
		if ( $index_handle === false || ftruncate( $index_handle, $committed_index_bytes ) === false || fseek( $index_handle, $committed_index_bytes ) === -1 ) {
```

By the time each `ftruncate` runs, the check above has already proved
`$size >= $committed_bytes`. In the overwhelmingly common case — a clean
suspend, no crashed tail to discard — the two are *equal*, and the truncation
does nothing. One of them is performed on a file that grows past a gigabyte, on
a mount where an `ftruncate` is a synchronous server round trip and, on some
overlay filesystems, a copy-up trigger.

The truncation is only needed when a crashed tick left bytes past the committed
offset. The values that decide it are already in local variables two lines
above.

### (b) Path stats where an open handle is about to exist anyway

The same `is_file()` / `filesize()` pairs stat a path immediately before the
code `fopen`s that very path. A stat on a path re-walks every component; an
`fstat()` on an already-open handle does not. It also closes a
time-of-check-to-time-of-use gap: today the size is measured on the path and
the anchor is validated against that measurement, while the append happens on a
separately-opened handle.

Honest accounting: PHP keeps a single-slot stat cache, and the container's
`is_file()` here is often served from it because
`classes/Artifact_Builder.php:176` stat'ed the same path a moment earlier. So
this change removes roughly **one** genuinely uncached stat per chunk plus the
path-walk cost of the others — a smaller win than (a), taken mainly because it
is nearly free and it removes the TOCTOU.

### (c) Two catalog queries re-issued on every table slice

`classes/Table_Dumper.php:140-150`, the head of `dump_chunk()`:

```php
		// Refuse anything not in the live catalog before the name reaches a query.
		$this->require_known_table( $table );

		// ... 
		$key = $this->ordering_key( $table );
```

`classes/Table_Dumper.php:196-206`:

```php
		global $wpdb;

		// The catalog is the authoritative allow-list; a name absent from it never
		// reaches a query.
		$existing = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore ...
		if ( ! in_array( $table, $existing, true ) ) {
			throw new RuntimeException( 'Refusing to dump a table that does not exist in the catalog.' );
		}
```

`classes/Table_Dumper.php:290-296`:

```php
		global $wpdb;

		// SHOW KEYS is the engine's own index catalog, one row per key column; pick out
		// the primary key's rows and restore their declared order.
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}`", ARRAY_A ) ?? []; // phpcs:ignore ...
```

A 100,000-row table at the default 1,000-row budget is 100 slices, so that is
100 redundant `SHOW TABLES` and 100 redundant `SHOW KEYS`, plus an `in_array()`
scan of the whole catalog per slice — which on a large multisite is thousands of
entries.

**The security property must be preserved.** A table name cannot be a bound
parameter, so validating it against the engine's own catalog before
interpolation is what makes the interpolation safe (ADR-0003). A memo scoped to
one request keeps that property exactly: the name is still validated against a
catalog read from the live database in this request, before it reaches any
query.

`Table_Dumper` is constructed once per request — `classes/Plugin.php:116`:

```php
		$dispatcher = new Dispatcher( $job_store, $config, new Artifact_Builder( new Table_Dumper(), $config ) );
```

so a private property is request-scoped by construction. It has no constructor
and no properties today.

`dump_chunk()` already treats a primary key that changed mid-dump as fatal
(`classes/Table_Dumper.php:151-153`). A per-request memo does not weaken that:
every tick is a separate request and re-reads the key.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
Load-bearing: English throughout; a `//` comment above each paragraph stating
its *purpose*; WordPress surface style (tabs, `snake_case` methods, spaces
inside parentheses); complete PHPDoc including `@since` on new properties. Both
files are densely documented — match them. New properties need a docblock with
a `@var` line; see `classes/Crypto/Sealed_Writer.php:100-115` for the house
style.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

## Scope

**In scope**:

- `classes/Crypto/Sealed_Writer.php` — `resume()` only
- `classes/Table_Dumper.php` — `require_known_table()` and `ordering_key()`, plus
  two new private properties
- `tests/Integration/table-chunking-test.php` — new assertions
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Changed`)

**Out of scope** (do NOT touch):

- `classes/Artifact_Builder.php` entirely. In particular, do **not** restructure
  the published-but-unsaved shortcut at `:172-178`; plan 004's recovery
  behaviour depends on it, and reordering it here would entangle two changes.
- `classes/Dispatcher.php`. Merging the two per-chunk state saves is a real
  further win (about four more round trips) but it touches the durability
  ordering that the stall counter depends on. **Deliberately not in this plan.**
- Holding the sealed container open across a tick. Nine more round trips, and a
  new seam between `Artifact_Builder::advance()` and `Dispatcher::tick()`.
  **Deliberately not in this plan.** See "Maintenance notes".
- `realpath()` memoisation in `Artifact_Builder::read_part()`. Also a real win,
  also out of scope here, because the per-file memo interacts with a documented
  defence-in-depth rationale that deserves its own decision.
- Any change to what is validated, or to when. The catalog check must still
  happen before the name reaches a query.
- The container's wire format, the REST surface, `api_version`.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Skip a truncation that discards nothing, and read each table's catalog once per request`
- Consider three commits, one per lettered change, so a bisect can separate
  them. That matches this repo's habit of small, single-purpose commits.
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Truncate only when there is something to discard

In `classes/Crypto/Sealed_Writer.php`'s `resume()`, guard both truncations.
Target shape for the container:

```php
		// Discard a crashed tick's unacknowledged tail — but only when there is one. The
		// check above has already proved the file is at least as long as the anchor, so
		// an equal length means nothing to roll back, and truncating anyway would spend a
		// synchronous metadata round trip on a file that grows past a gigabyte, once per
		// chunk, to change nothing. The seek is unconditional: the handle must be
		// positioned to append whether or not anything was discarded.
		if ( $size > $committed_bytes && ftruncate( $handle, $committed_bytes ) === false ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle after a failed rollback; see open().
			throw new RuntimeException( 'Unable to position the in-progress sealed container for appending.' );
		}
		if ( fseek( $handle, $committed_bytes ) === -1 ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the handle after a failed reopen; see open().
			throw new RuntimeException( 'Unable to position the in-progress sealed container for appending.' );
		}
```

Apply the same shape to the sidecar, guarded on
`$index_size > $committed_index_bytes`. Keep the sidecar's existing
`$index_handle === false` check — that is a different failure and must stay.

**The `fseek` must remain unconditional.** It is what positions the handle at
the append point; skipping it would corrupt the container.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `composer test:integration` → exit 0. Pay attention to
  `tests/Integration/bounded-state-file-test.php` AC5, which appends fake
  crashed bytes to both the container and the sidecar and asserts they are
  truncated away. **That test must still pass** — it is the one exercising the
  branch you just made conditional, i.e. the case where `$size > $committed`.

### Step 2: Validate the anchors against the open handles

Still in `resume()`, replace each `is_file()` + `filesize()` pair with an
`fopen()` followed by `fstat( $handle )['size']`, moving the anchor check to
after the open. Keep the two failure messages distinguishable: a file that
cannot be opened and a file shorter than its anchor are different faults and
the existing messages already say so. Preserve both.

Order within the method becomes: open container → fstat → validate anchor →
(conditionally) truncate → seek → read and validate header → open sidecar →
fstat → validate anchor → (conditionally) truncate → seek. Make sure every
early failure path still `fclose`s every handle it has opened — the existing
code is careful about this and yours must be too.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `composer test:integration` → exit 0, `Integration suite: PASS`
- `grep -c 'is_file( $this->destination_path )' classes/Crypto/Sealed_Writer.php` → 0

### Step 3: Read each table's catalog facts once per request

In `classes/Table_Dumper.php`:

1. Add two private properties with docblocks in the file's style:
   - one holding the catalog as a `list<string>|null` (null meaning "not read
     yet in this request"),
   - one holding resolved ordering keys as an array keyed by table name.
2. In `require_known_table()`, read `SHOW TABLES` only when the catalog property
   is null, then check membership against the property. Prefer a lookup keyed by
   name over `in_array()` over a list, so the per-slice cost stops scaling with
   the catalog's size.
3. In `ordering_key()`, return the memoised key when this table already has one;
   otherwise resolve it as today and store it.

Add a paragraph comment on each memo explaining *why* it is safe: the catalog
and a table's primary key cannot change within one request in a way this code
would tolerate anyway — `dump_chunk()` already treats a key that changed
between ticks as fatal — and `Table_Dumper` is constructed per request
(`classes/Plugin.php:116`), so the memo cannot outlive one.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `composer test:integration` → exit 0, `Integration suite: PASS`

### Step 4: Add assertions that pin the safety properties

Extend `tests/Integration/table-chunking-test.php`. Read it first; it already
builds fixtures with all three primary-key shapes (single-column, composite,
keyless), which is what you need.

Add assertions proving the memo did not weaken anything:

1. **An unknown table is still refused**, on the first call and after the
   catalog has already been read for a different table in the same request.
   This is the security property; it must hold whether or not the memo is warm.
2. **All three key shapes still dump correctly with a warm memo** — a table
   dumped across several slices in one tick still emits every row exactly once
   and in key order. The existing multi-slice assertions largely cover this;
   confirm they exercise more than one slice per request, and add an assertion
   if they do not.
3. **A composite key is still paged on all its columns**, not just the first,
   with the memo warm. The file already has a fixture for this.

For the truncation guard, `bounded-state-file-test.php` AC5 is the existing
proof and needs no new assertion — but state in your report that you confirmed
it still passes and that it is the test covering the conditional branch.

**Verify**:
- `composer test:integration` → exit 0, with your new assertions in the TAP
  output.
- Demonstrate a RED step for assertion 1: temporarily make the memo skip the
  membership check, re-run, and confirm the assertion reports `not ok`. Restore
  and re-run to green. **Record both runs in your report.**

### Step 5: Changelog

Add one entry under `### Changed` in `CHANGELOG.md`'s `[Unreleased]` section
(heading at `CHANGELOG.md:25`), matching the surrounding entries' style. Say
what was removed and why it was safe to remove — and be honest that the
per-chunk cost this addresses is a static count, not yet a measurement on a real
host. End with `No REST change.`

**Verify**: `git diff --stat` → only the four files from "In scope".

### Step 6: Full gate

**Verify**: `composer gate` → exit 0.

## Test plan

- **File**: `tests/Integration/table-chunking-test.php` (extend, do not create).
- **New cases**: unknown table still refused with a warm catalog memo; all three
  key shapes dump correctly across slices with a warm memo; composite key still
  paged on every column.
- **Existing coverage relied on**: `bounded-state-file-test.php` AC5 for the
  conditional truncation; the byte-identical-concatenation assertion for
  slicing.
- **Pattern to follow**: the fixtures and assertions already in
  `table-chunking-test.php`.
- **Verification**: `composer test:integration` → all pass, plus the
  demonstrated failing run from step 4.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'is_file( $this->destination_path )' classes/Crypto/Sealed_Writer.php` returns 0
- [ ] `grep -n 'fstat' classes/Crypto/Sealed_Writer.php` returns at least two matches
- [ ] Both `ftruncate` calls in `resume()` are guarded by a size comparison — verify by reading the method
- [ ] Both `fseek` calls in `resume()` are unguarded — verify by reading the method
- [ ] `grep -c "get_col( 'SHOW TABLES' )" classes/Table_Dumper.php` returns 1
- [ ] `git diff 8a35b2b..HEAD -- classes/Artifact_Builder.php classes/Dispatcher.php` shows no changes from this plan
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] The TAP output still contains the AC5 lines about crashed bytes being truncated away
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] `plans/README.md` status row for 007 updated

## STOP conditions

Stop and report back (do not improvise) if:

- `tests/Integration/bounded-state-file-test.php` AC5 fails. That is the test
  proving a crashed tail is rolled back, and it exercises exactly the branch you
  made conditional. Do not weaken it.
- Any assertion about rows being emitted exactly once, or in key order, fails.
- The code in `resume()` does not match the excerpts above.
- Restructuring the anchor checks appears to require changing
  `classes/Artifact_Builder.php:172-178`. It does not, and that code is
  load-bearing for plan 004.
- You cannot preserve distinguishable error messages for "cannot open" versus
  "shorter than its anchor" without widening the change.
- The memo appears to require making `Table_Dumper` a singleton, a static, or
  otherwise longer-lived than one request. It must not be.

## Maintenance notes

- **The two larger wins deliberately left on the table**, both worth revisiting
  once someone has actually timed a tick on the real host:
  - *Merge the two per-chunk state saves.* `classes/Dispatcher.php:357` saves
    the attempt counter and heartbeat before the chunk; `:386` saves the
    progress after it; and the loop immediately iterates with no I/O in
    between. Each save is an open, a write, a close and a rename. Worth about
    four round trips per chunk, but it touches the durability ordering the
    stall detector depends on (the counter must reach disk *before* the work),
    so it needs care and its own decision.
  - *Hold the container open across a tick.* Worth about nine round trips per
    chunk — the largest single removable block. `Sealed_Writer` is already
    shaped for it: adding a `checkpoint()` that does `suspend()`'s
    `fflush`/`ftell` without the `fclose` pair is the whole writer-side change.
    The obstacle is on the builder side: `Artifact_Builder::advance()` owns the
    writer's entire lifecycle inside one per-chunk method while the chunk loop
    lives in `Dispatcher::tick()`, so amortising needs a new seam that
    interleaves correctly with the attempt counting, the progress transition
    and the ready/publish branch. `resume()`'s contract is only
    `file_size >= committed_bytes`, which a flushed-but-open file satisfies, so
    no invariant blocks it.
- **What a reviewer should scrutinise**: that every early-return path in
  `resume()` still closes every handle it opened; that the `fseek` calls stayed
  unconditional; and that the catalog memo cannot outlive a request.
- **Known coverage limitation**: the fast suite runs on SQLite inside WordPress
  Playground, where every filesystem operation costs microseconds against an
  in-memory WASM filesystem. **The suite can never detect a regression in the
  per-chunk round-trip count.** If these savings are to stay saved, that has to
  come from review, not from the gate.

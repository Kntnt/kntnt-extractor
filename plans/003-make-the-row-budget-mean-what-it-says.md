# Plan 003: Make a table slice's row budget bind below 100 rows, and stop it overshooting

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Table_Dumper.php classes/Chunk_Budgets.php docs/adr/0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: plans/002-fail-loudly-when-a-table-page-cannot-be-read.md (same file — land 002 first to keep the two fixes separable in review)
- **Category**: bug
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

When a host kills the PHP worker mid-chunk, this plugin halves the budgets that
chunk spends and retries, walking downward until it finds a size the host can
survive. For a table slice there are two budgets: a **byte** budget bounding what
gets rendered and sealed, and a **row** budget bounding what
`$wpdb->get_results()` materialises in memory. ADR-0015 identifies the row
budget as the half a memory kill most likely happened in, and says both halve
"each down to its own floor", the floor being one.

The row budget does not reach one. It cannot go below **100**. Every value from
1 to 100 fetches exactly 100 rows, because the budget is rounded *up* to a whole
100-row `INSERT` batch before it is used. So on a table of fat rows that runs the
host out of memory while materialising 100 rows, the adaptation search silently
stops working at that point — while continuing to *look* like it is working.
`Chunk_Budgets::halved_for_table()` keeps halving down to 1, so the driver
performs roughly seven further halvings that change the actual fetch by nothing
at all, each costing `max_stall_attempts` full host kills. That is about
fourteen dead ticks, each up to a full execution-time limit, on a host that is
already failing — and then the job fails at the floor anyway.

The same rounding also makes the budget overshoot upward: a budget of 250 rows
fetches 300. During a downward search for a size the host survives, a bound that
exceeds itself by up to 99 rows is the wrong direction to be wrong in.

After this plan the row budget means what ADR-0015 says it means.

**A note on the project's own rules.** The open work queue's rule R1 says
nothing in the adaptation family is *built* until the outstanding measurement
question is answered. This plan does not add a knob, a lever, or a recovery
mechanism to that family — it makes an existing, already-documented bound do
what it already claims to do, and removes roughly fourteen wasted host kills
from a failing run. If the operator reads R1 as covering this too, that is
their call to make: stop and ask rather than proceeding.

## Current state

### The rounding

`classes/Table_Dumper.php:155-159`:

```php
		// Read the next page in the key's own order, render as much of it as the byte
		// budget holds, and prefix the structure block and data header on the first
		// slice only.
		$limit = max( 1, (int) ceil( $max_rows / self::ROWS_PER_INSERT ) ) * self::ROWS_PER_INSERT;
		$rows = $this->fetch_rows( $table, $key, $cursor, $rows_done, $limit );
```

`classes/Table_Dumper.php:62`:

```php
	private const int ROWS_PER_INSERT = 100;
```

Worked examples of the current expression:

| `$max_rows` | `ceil($max_rows / 100)` | resulting `$limit` |
|---|---|---|
| 1 | 1 | **100** |
| 25 | 1 | **100** |
| 50 | 1 | **100** |
| 100 | 1 | 100 |
| 250 | 3 | **300** (overshoots by 50) |
| 1000 | 10 | 1000 |

### The halving that keeps going after it stops mattering

`classes/Chunk_Budgets.php:83-91`:

```php
	public function halved_for_table(): ?self {

		if ( $this->table_bytes <= 1 && $this->table_rows <= 1 ) {
			return null;
		}

		return new self( $this->file_bytes, max( 1, intdiv( $this->table_bytes, 2 ) ), max( 1, intdiv( $this->table_rows, 2 ) ) );

	}
```

This is **correct as written** and is not what you are changing. It returns
non-null until both bounds are at 1, which is exactly what ADR-0015 describes.
The defect is that `Table_Dumper` does not honour the row bound below 100.

### What the driver pays for each of those halvings

`classes/Dispatcher.php:342-349`:

```php
		if ( $job->attempts >= $this->max_stall_attempts() ) {
			$adapted = $this->adapt( $job );
			if ( $adapted === null ) {
				return $this->persist_failure( $job->with_failure( $this->stall_reason( $job ) ) );
			}
			$job = $adapted;
			$this->store->save( $job );
		}
```

Each halving is only reached after `max_stall_attempts` (default 2) attempts
have each been killed by the host.

### The ADR this contradicts

`docs/adr/0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md:23`:

> A file part spends exactly one bound: the bytes read into the seal. A table
> slice spends two, and both have to give. `table_chunk_bytes` caps only what is
> *rendered*, escaped and sealed; the rows themselves are materialised in memory
> by `$wpdb->get_results()` under `table_chunk_rows` alone, which is the half a
> memory kill is likeliest to have happened in. […] So a table stall halves
> both, each down to its own floor, and the slice is at the floor only when both
> are.

### Why the rounding exists at all — do not simply delete it

The round-up is not arbitrary. `insert_statements()` batches rendered rows into
extended `INSERT` statements of `ROWS_PER_INSERT` rows
(`classes/Table_Dumper.php:481`, `array_chunk( $tuples, self::ROWS_PER_INSERT )`).
Keeping a slice's row count on a whole-batch boundary is what makes the
concatenation of several slices byte-identical to the same table dumped in one
slice — an acceptance criterion pinned by `tests/Integration/table-chunking-test.php`
(referred to in `docs/testing-strategy.md:43` as AC2).

That property already only holds when the byte budget does not cut the page
short, which on a fat-rowed table it routinely does. Your change must preserve
alignment wherever it can still be preserved, and give it up only in the regime
where it is already lost.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
Load-bearing here: English identifiers and comments; a `//` comment above each
paragraph stating its *purpose*; WordPress surface style (tabs, `snake_case`
methods, spaces inside parentheses). `classes/Table_Dumper.php` is its own best
exemplar — match its comment density, which is high and explanatory.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

## Scope

**In scope**:

- `classes/Table_Dumper.php` — the `$limit` computation at `:158` and the
  `@param $max_rows` docblock line that describes it (`:129`)
- `tests/Integration/table-chunking-test.php` — new assertions only; see step 3
- `docs/adr/0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md`
  — one Consequences bullet
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Fixed`)

**Out of scope** (do NOT touch):

- `classes/Chunk_Budgets.php` — correct as it stands. Do not change the halving
  or its floors.
- `classes/Table_Dumper.php:481` — the `ROWS_PER_INSERT` batching in
  `insert_statements()`. The batch size is not what is wrong.
- `classes/Table_Dumper.php:335-359` (`fetch_rows()`) — plan 002 owns that
  method.
- `classes/Dispatcher.php` — the attempt counter, `adapt()`, and
  `max_stall_attempts` are all correct. This plan does not touch the adaptation
  machinery, only the bound it drives.
- Any change to `DEFAULT_TABLE_CHUNK_ROWS` or any other default. **Do not pick
  a new constant.** This project's rule R4 is that constants are measured, not
  chosen, and nothing here needs a new one.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Round a slice's row budget down to a batch, so the bound binds below 100 rows`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Round down, and let a sub-batch budget mean itself

Replace the `$limit` computation at `classes/Table_Dumper.php:158`. Target
shape:

```php
		// Size the page to whole INSERT batches while the budget has room for one, so a
		// slice boundary stays on a batch boundary and several slices concatenate to
		// exactly what a single slice would have written. Round DOWN: a budget that is
		// exceeded is not a bound, and this one is walked downward by the stall
		// adaptation looking for a size the host survives (ADR-0015). Below one batch
		// the alignment is unreachable anyway, so the budget is taken literally — which
		// is what lets it shrink to a single row instead of stopping at a hundred.
		$limit = $max_rows >= self::ROWS_PER_INSERT
			? intdiv( $max_rows, self::ROWS_PER_INSERT ) * self::ROWS_PER_INSERT
			: max( 1, $max_rows );
```

Then update the `@param $max_rows` line in `dump_chunk()`'s docblock
(`classes/Table_Dumper.php:129`), which currently reads:

```
	 * @param int                     $max_rows  Upper bound on rows in this slice, rounded up to whole `INSERT` batches.
```

It must no longer say "rounded up".

Confirm the new behaviour by hand before running anything:

| `$max_rows` | new `$limit` | over budget? |
|---|---|---|
| 1 | 1 | no |
| 25 | 25 | no |
| 99 | 99 | no |
| 100 | 100 | no |
| 250 | 200 | no |
| 1000 | 1000 | no |

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -n 'rounded up' classes/Table_Dumper.php` → no match

### Step 2: Run the existing suite and read the result carefully

`tests/Integration/table-chunking-test.php` forces small budgets to drive
multi-slice behaviour. Some of those forced values may not be multiples of 100,
in which case your change alters how that fixture slices — legitimately, but
possibly visibly.

**Verify**: `composer test:integration` → exit 0, `Integration suite: PASS`.

**If a table-chunking assertion now fails**, do not adjust the assertion to make
it pass. Work out which of these it is and report it:

- **The byte-identical-concatenation assertion (AC2) failed** because the forced
  row budget is below 100, so slices are no longer batch-aligned. This is an
  expected consequence in that regime, but whether the fixture should be
  re-pitched onto a multiple of 100 or the assertion narrowed is a design call.
  STOP and report.
- **A row-count or ordering assertion failed.** That would mean rows are being
  skipped or repeated, which this change must not cause. STOP and report — this
  is serious.

### Step 3: Add assertions that pin the new bound

Extend `tests/Integration/table-chunking-test.php` rather than creating a new
file: this is the same behaviour its AC5 block already covers, and the fixtures
you need are already built there. Read the file's AC5 section first — it is the
one that plants a stall by writing the attempt counter into `state.json` and
asserts that a table stall halves both bounds.

Add assertions proving:

1. **The bound binds below a batch.** With `table_chunk_rows` forced to a value
   under 100 (say 10) on a fixture with far more rows than that, a slice renders
   at most that many rows — not 100. Assert on an observable: the number of
   slices the table takes, or `rows_done` after one slice.
2. **The bound is never exceeded.** With `table_chunk_rows` forced to a value
   that is not a multiple of 100 (say 250), a slice fetches at most 250 rows.
3. **The floor is genuinely one.** With `table_chunk_rows` forced to 1, a slice
   still renders exactly one row and the build still advances — it must not
   stall or return an empty slice. `classes/Table_Dumper.php`'s existing
   "always render at least one row" rule is what makes this safe; this assertion
   proves the two rules compose.

Each assertion's description should state the behaviour, in the style the file
already uses (e.g. `'AC5: a row budget below one INSERT batch bounds the fetch to itself, not to 100 (was: silently floored at 100)'`).

**Verify**:
- `composer test:integration` → exit 0, with your new assertions in the TAP
  output.
- Demonstrate the RED step the coding standard requires: temporarily restore
  the old `ceil(...)` expression, re-run, and confirm assertions 1 and 2 report
  `not ok`. Restore the fix and re-run to green. **Record both runs in your
  report.**

### Step 4: Record the consequence in ADR-0015 and the changelog

ADR-0015 already states the intended behaviour correctly, so it needs no
correction — but its Consequences list should record that the bound now reaches
its floor in fact and not only in principle. Add one bullet to the
`## Consequences` section of
`docs/adr/0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md`
(the section begins at `:47`). Match the existing bullets' voice: full
sentences, explaining the consequence rather than the change. Something in the
shape of:

> - A table slice's row budget now binds all the way to one row. It was rounded
>   up to a whole `INSERT` batch before use, so every value from 1 to 100
>   fetched 100 rows: the half of the adaptation this decision identifies as the
>   likeliest site of a memory kill stopped adapting at a hundred rows while
>   continuing to halve, spending roughly seven further halvings and fourteen
>   host kills on a bound that no longer moved. The rounding now goes down and
>   is dropped below one batch, so the slice is at the floor when the decision
>   says it is.

Then add one entry under `### Fixed` in `CHANGELOG.md`'s `[Unreleased]` section
(heading at `CHANGELOG.md:7`), matching the surrounding entries' style. End with
`No REST change.` — the wire contract does not move.

**Verify**: `git diff --stat` → only the four files from "In scope".

### Step 5: Full gate

**Verify**: `composer gate` → exit 0.

## Test plan

- **File**: `tests/Integration/table-chunking-test.php` (extend, do not create).
- **Cases**: budget below one batch binds to itself; a non-multiple budget is
  not exceeded; a budget of one still renders exactly one row and advances.
- **Pattern to follow**: the AC5 block already in that file.
- **Verification**: `composer test:integration` → all pass, plus the
  demonstrated failing run from step 3.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'ceil( $max_rows' classes/Table_Dumper.php` returns 0
- [ ] `grep -n 'intdiv( $max_rows' classes/Table_Dumper.php` returns a match
- [ ] `grep -c 'rounded up' classes/Table_Dumper.php` returns 0
- [ ] `git diff 8a35b2b..HEAD -- classes/Chunk_Budgets.php` is empty (that file must be untouched)
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] `composer gate` exits 0
- [ ] `docs/adr/0015-*.md` has one new Consequences bullet
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report contains the output of the deliberately-failing run from step 3
- [ ] `plans/README.md` status row for 003 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at `classes/Table_Dumper.php:155-159` does not match the excerpt
  above.
- The byte-identical-concatenation assertion (AC2) in
  `tests/Integration/table-chunking-test.php` fails after your change. Report
  which forced budget the fixture uses and what the assertion compared — do not
  loosen the assertion.
- Any assertion about rows being emitted exactly once fails. That is a
  correctness regression this change must not cause.
- The fix appears to require touching `classes/Chunk_Budgets.php`,
  `classes/Dispatcher.php`, or `insert_statements()`.
- The operator's rule R1 (nothing in the adaptation family until the
  outstanding measurement lands) turns out to be intended to cover this fix
  too. If you are unsure, ask rather than proceed — see "A note on the
  project's own rules" above.

## Maintenance notes

- **What future changes will interact with this**: if `ROWS_PER_INSERT` ever
  changes, re-check both the alignment argument and the sub-batch fallback. If
  the stall adaptation ever learns to grow a budget back (a change the project
  has parked as conditional), the overshoot direction matters again.
- **What a reviewer should scrutinise**: the boundary cases in the table in
  step 1, especially `$max_rows` of exactly 100 and exactly 99; and that
  `Chunk_Budgets` is untouched.
- **Known coverage limitation, deliberately not addressed here**: the fast suite
  runs on SQLite inside WordPress Playground. `Table_Dumper`'s MySQL-specific
  SQL — the keyset predicate, `SHOW KEYS` ordering, `LIMIT`/`OFFSET` handling —
  is verified only against SQLite's translation layer, and the MySQL-backed
  DDEV harness does not cover the dumper at all. A separate finding proposed
  fixing that; it was not selected. A green suite here does not prove the
  slicing arithmetic against MySQL.
- **Deliberately deferred**: bounding the *fetch* by bytes rather than by rows
  alone. That is a real and separate improvement, parked in the project's
  conditional list behind the trigger "a site whose rows are genuinely fat turns
  up". Do not build it here.

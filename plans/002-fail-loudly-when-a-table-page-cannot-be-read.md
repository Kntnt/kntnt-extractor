# Plan 002: Fail the build when a table page cannot be read, instead of publishing a silently truncated dump

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Table_Dumper.php tests/Integration/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: plans/001-run-the-gate-in-ci.md (soft — 001 only makes this verifiable in CI)
- **Category**: bug
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

This plugin exists to move a production site's data somewhere else. The worst
thing it can do is not to fail — it is to succeed while quietly dropping rows.

Today, if the database read that fetches a page of rows fails partway through a
table — a deadlock, a lost connection, a killed query, `max_allowed_packet` —
the dumper reads the resulting empty page as "the table has no more rows". It
marks the table complete, moves to the next resource, and eventually publishes
a sealed artifact in state `ready`. That artifact's dump for the affected table
stops at an arbitrary row and reloads into MySQL without a single error. Nobody
finds out until somebody notices missing content on the copied site.

This is not a hypothetical the code is unaware of. `Table_Dumper`'s own class
docblock says exactly this must never happen — and the code closes the hole for
one cause (a page cut short by the byte budget) while leaving it open for
another (a page that came back empty because the read failed). The relevant
risk window is a multi-hour extraction of a large site against a loaded
production database, which is precisely what this plugin is used for.

After this plan, a failed page read fails the job loudly instead of truncating
the table.

## Current state

### The class already states the invariant

`classes/Table_Dumper.php:111-117` — part of `dump_chunk()`'s docblock:

```
	 * Which is why completeness is decided on two facts rather than one. A short page
	 * alone no longer means the end of the table, because a page cut short by the byte
	 * budget is short for an entirely different reason; reading it as the end would
	 * publish a silently truncated table that imports without a single error — far worse
	 * than the loud stall it replaced. The table is finished only when every fetched row
	 * was rendered AND the page came back shorter than asked for, and the cursor handed
	 * back is the last row RENDERED, never the last one fetched.
```

### Where the invariant is broken

`classes/Table_Dumper.php:335-359` — the whole of `fetch_rows()`, ending:

```php
		// Read the page as an associative array so column order follows the table's own
		// definition, the order the column-less INSERT relies on. A failed read yields no
		// rows, which the caller reads as the end of the table.
		return $wpdb->get_results( $query, ARRAY_A ) ?? []; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dumping a table's own rows; see the composition above for why the statement is assembled, and nothing here is cacheable.
```

Read that comment again: *"A failed read yields no rows, which the caller reads
as the end of the table."* The behaviour is stated correctly and is wrong.

### How the empty page becomes "table complete"

`classes/Table_Dumper.php:158-171`, the tail of `dump_chunk()`:

```php
		$limit = max( 1, (int) ceil( $max_rows / self::ROWS_PER_INSERT ) ) * self::ROWS_PER_INSERT;
		$rows = $this->fetch_rows( $table, $key, $cursor, $rows_done, $limit );
		[ $data, $rendered ] = $this->insert_statements( $table, $rows, $max_bytes );
		$sql = ( $rows_done === 0 ? $this->structure_sql( $table ) . $this->data_header( $table ) : '' ) . $data;

		// ... (comment omitted)
		$fetched = count( $rows );

		return [ $sql, $this->cursor_of( array_slice( $rows, 0, $rendered ), $key ), $rows_done + $rendered, $rendered === $fetched && $fetched < $limit ];
```

With a failed read: `$rows === []`, so `$fetched === 0` and `$rendered === 0`.
The fourth element — "is this table now fully rendered" — evaluates
`0 === 0 && 0 < $limit`, which is `true`. `classes/Artifact_Builder.php:211-214`
then advances `tables_done`, clears the cursor, and the build moves on.

### The mechanism you must verify rather than assume

`wpdb::get_results()` is documented as returning `null` on failure, and the
`?? []` above is written for that. **But in `ARRAY_A` mode WordPress core builds
and returns a fresh array, so a failed query can come back as `[]` rather than
`null`** — in which case the `?? []` never fires and the empty page arrives by a
different route. The observable outcome is identical either way.

Do not resolve this from documentation. The fix below does not depend on which
shape core returns, and the test in step 3 proves the *outcome* (the job fails,
no truncated artifact is published) rather than the mechanism. If you find
yourself needing to know which branch core takes, that is a sign you are
testing the wrong thing.

The reliable signal is `$wpdb->last_error`. `wpdb::query()` flushes it at the
start of every query, so immediately after the call it reflects only this query.

### Where a throw from here lands

`classes/Dispatcher.php:362-368`:

```php
		try {
			$step = $this->builder->advance( $running, $this->store->container_build_path( $running ), $this->store->artifact_path( $running ) );
		} catch ( Throwable ) {
			return $this->persist_failure( $running->with_state( Job_State::Failed ) );
		}
```

A `RuntimeException` from `fetch_rows()` therefore fails the job with
`error === null` — an *opaque* failure. Per ADR-0015 an opaque failure is
deliberately never re-driven by the resume path, because resuming a permanent
fault would loop forever. **That is the correct outcome here and you must not
try to make this failure resumable.** A loud failure the caller can see and
retry deliberately is the goal; a silently truncated success is what we are
removing.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`
before editing. The load-bearing ones for this change:

- `declare( strict_types=1 );` is already at the top of the file — leave it.
- Comments and identifiers in **English**.
- Code is grouped into short paragraphs, each with a `//` comment above it
  explaining the block's *purpose*, not its mechanics. Match the density of the
  surrounding code exactly — `classes/Table_Dumper.php` is a good exemplar of
  the house style.
- This is a WordPress project: tabs for indentation, `snake_case` methods,
  spaces inside parentheses (`if ( $x === null )`). Match the file you are in.
- Every `throw` in this class carries a message that names no caller-supplied
  path and no fragment of SQL — see `:152` (`'A table primary key changed while
  the table was being dumped.'`) and `:206`. Match that: a message that is
  useful and carries no data.

From `CONTEXT.md`, the vocabulary to use in comments and messages:

> **Segment**: The artifact's unit of encryption and of reassembly: one bounded
> chunk of one selected table or file […]

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

The integration suite takes about five minutes. It needs Node and network
access on first run.

## Scope

**In scope** (the only files you should modify or create):

- `classes/Table_Dumper.php` — `fetch_rows()` only
- `tests/Integration/table-read-failure-test.php` (create)
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Fixed`)
- `docs/testing-strategy.md` — **only** if you add the new test to its list; see
  the note in step 4

**Out of scope** (do NOT touch, even though they look related):

- `classes/Table_Dumper.php:158` — the `ceil()` rounding of the row budget is a
  separate, real bug covered by plan 003. Leave it exactly as it is; touching
  it here would make two independent fixes indistinguishable in review.
- The completeness expression at `:171`. It is correct *given* a trustworthy
  page. Do not add an error flag to it; the fix belongs in `fetch_rows()`.
- `classes/Dispatcher.php` — the opaque-failure handling is correct as it
  stands (see "Where a throw from here lands").
- `classes/Artifact_Builder.php`.
- Anything to do with `$wpdb->suppress_errors()`. Whether wpdb prints an HTML
  error into a REST response on a failed query is a real and separate question;
  it is not this plan's.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message style is an imperative sentence with no prefix, e.g.
  `Treat a traversal that fails to resolve as out of bounds, never as a skip`.
  Suggested: `Fail the dump when a table page cannot be read, never call it the end of the table`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Make a failed page read throw

In `classes/Table_Dumper.php`, replace the final statement of `fetch_rows()`
(currently `classes/Table_Dumper.php:356-357`, the comment and the `return`)
with a read, an error check, and a return. Target shape:

```php
		// Read the page as an associative array so column order follows the table's own
		// definition, the order the column-less INSERT relies on.
		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- dumping a table's own rows; see the composition above for why the statement is assembled, and nothing here is cacheable.

		// A read that failed and a page that is genuinely empty are indistinguishable in
		// the return value, so the error flag is what separates them. Reading a failed
		// read as the end of the table is what would publish a silently truncated dump
		// that imports without a single error — the one outcome this class exists to
		// prevent. wpdb clears the flag at the start of every query, so it describes
		// this read alone.
		if ( $wpdb->last_error !== '' ) {
			throw new RuntimeException( 'Unable to read a page of rows while dumping a table.' );
		}

		return is_array( $rows ) ? $rows : [];
```

Then update the method's docblock `@throws` line to record the new failure, and
delete the sentence *"A failed read yields no rows, which the caller reads as
the end of the table."* from wherever it survives — it is now false.

Check the file's `use` statements at the top: `RuntimeException` is already
thrown at `:152` and `:206`, so the import (or the leading `\`) is already
whatever this file uses. **Match the existing style in this file rather than
adding a new import.**

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -n 'reads as the end of the table' classes/Table_Dumper.php` → no match

### Step 2: Confirm the existing suite still passes

Nothing in the current suite should change behaviour, because no existing test
provokes a query error.

**Verify**: `composer test:integration` → exit 0, `Integration suite: PASS`.

If any existing test now fails, STOP — it means something in the suite was
relying on a failed read being swallowed, which is information the operator
needs.

### Step 3: Add a test that provokes a real read failure

Create `tests/Integration/table-read-failure-test.php`.

**How the harness works** (you have not seen it): every `*-test.php` in
`tests/Integration/` is discovered and `require`d by `tests/Integration/bootstrap.php:79-81`
inside one WordPress process. There is no test class and no framework. You
assert with one global helper:

```php
kntnt_extractor_assert( bool $passed, string $description ): void
```

Model the new file's structure on `tests/Integration/vanished-file-test.php` —
it is one of the shorter, more readable files and it drives the REST layer the
same way you will need to.

**How to plant the failure.** Do *not* write into a state file. WordPress's
`wpdb::query()` passes every statement through the `query` filter, so you can
make one specific read fail through the real code path:

1. Create a fixture table with enough rows to need more than one slice (the
   forced-small-budget technique used in `tests/Integration/table-chunking-test.php`
   shows how this project shrinks budgets for a test — reuse it rather than
   inventing your own).
2. Register a `query` filter that rewrites **only** the second page's
   `SELECT * FROM \`<fixture>\`` into a statement that is guaranteed to fail
   (referencing a table that does not exist is enough), and leaves every other
   query untouched. Use a counter so the first page succeeds and the second
   fails — that is what makes it a *mid-table* failure rather than an empty
   table.
3. Wrap the run in `$wpdb->suppress_errors( true )` and restore the previous
   value afterwards, so a deliberately failing query does not print an error
   into the test output.
4. Remove the filter with `remove_filter()` at the end of the file. **This is
   not optional**: the bootstrap runs all test files in one process in
   alphabetical order, and a leaked filter changes the behaviour of every file
   that runs after yours.

**What to assert** (four assertions, each with a description naming the
behaviour):

1. The job reaches state `failed`, not `ready`.
2. No artifact was published — the download path does not exist.
3. With the filter removed, the same fixture dumps to completion and the
   recovered SQL contains every row. This is the control that proves assertion
   1 was caused by the planted failure and not by a broken fixture.
4. **The regression assertion**: before this fix, the run in (1) produced a
   `ready` job. State that in the assertion's description so a future reader
   knows what it is guarding.

**Verify**:
- `composer test:integration` → exit 0, `Integration suite: PASS`, and the TAP
  output contains your four new assertion lines.
- Now prove the test can fail (the RED step the coding standard requires — a
  test never observed to fail is of unknown value). Temporarily revert step 1's
  change, re-run `composer test:integration`, and confirm assertions 1 and 2
  now report `not ok`. Restore the fix and re-run to green. **Record both runs
  in your report.**

### Step 4: Changelog, and the testing-strategy list

Add one entry under `### Fixed` in `CHANGELOG.md`'s `[Unreleased]` section (the
heading is at `CHANGELOG.md:7`). Match the surrounding entries — they are full
paragraphs stating what was wrong, what it caused, and whether the REST
contract moved. End with `No REST change.`

On `docs/testing-strategy.md`: its "Current tests" list is already substantially
out of date (it documents roughly 14 of the 35 test files). Reconciling it is a
separate finding that was **not** selected, so do not rewrite the list. Adding
one line for your new test is optional — if you add it, match the existing
entries' level of detail; if you do not, say so in your report.

**Verify**: `git diff --stat` → only the files named in "In scope".

### Step 5: Full gate

**Verify**: `composer gate` → exit 0.

## Test plan

- **New file**: `tests/Integration/table-read-failure-test.php`.
- **Cases**: mid-table read failure → job `failed` and no artifact published;
  the same fixture without the planted failure → dumps completely (the control).
- **Structural pattern to follow**: `tests/Integration/vanished-file-test.php`
  for shape and REST driving; `tests/Integration/table-chunking-test.php` for
  building a multi-slice fixture and forcing small budgets.
- **Verification**: `composer test:integration` → all pass, including the new
  assertions; plus the demonstrated failing run described in step 3.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -n 'last_error' classes/Table_Dumper.php` returns at least one match inside `fetch_rows()`
- [ ] `grep -c 'reads as the end of the table' classes/Table_Dumper.php` returns 0
- [ ] `tests/Integration/table-read-failure-test.php` exists
- [ ] `grep -c 'remove_filter' tests/Integration/table-read-failure-test.php` is at least as large as its `add_filter` count
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report contains the output of the deliberately-failing run from step 3
- [ ] `plans/README.md` status row for 002 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at `classes/Table_Dumper.php:335-359` does not match the excerpt
  above (the file has drifted since this plan was written).
- `composer gate` was already failing before you changed anything. Report it;
  do not fix it here.
- You cannot make a query fail through the `query` filter inside WordPress
  Playground — for example if the SQLite integration layer swallows the error
  and never sets `last_error`. **This is the most likely thing to go wrong and
  it is important.** If it happens, report exactly what you observed. Do not
  fall back to writing a precondition straight into a state file and asserting
  on it: that would test only that the code reacts to a state it can no longer
  be shown to reach, which is the weaker half of the loop. Ship the production
  fix with a clear note that the harness could not exercise it, and say so
  plainly.
- Making the test pass appears to require changing `dump_chunk()`'s
  completeness expression at `:171`, or anything in `Dispatcher` or
  `Artifact_Builder`. That means the diagnosis in this plan is wrong; report
  rather than widening the change.
- You discover the assumption "a failed query leaves `$wpdb->last_error`
  non-empty" is false in this harness.

## Maintenance notes

- **What future changes will interact with this**: plan 003 edits `dump_chunk()`
  in the same file, and plan 007 memoises the catalog and key lookups in the
  same class. Land them in the order given in `plans/README.md`.
- **What a reviewer should scrutinise**: that the throw is *not* caught and
  softened anywhere between `fetch_rows()` and `Dispatcher::advance_one_chunk()`,
  and that the new test genuinely removes its filter.
- **Known coverage limitation, deliberately not addressed here**: the fast suite
  runs on SQLite inside WordPress Playground, so all of `Table_Dumper`'s
  MySQL-specific SQL — including this read — is exercised only against SQLite's
  translation layer. The MySQL-backed DDEV harness contains a single test and
  does not cover the dumper at all. A separate finding proposed moving the
  dumper's fixtures into that harness; it was not selected. Note it in review:
  a green suite here does not prove the behaviour on MySQL, only that the
  error path is wired correctly.
- **Deliberately deferred**: giving this failure a non-null `error` so it could
  carry a reason to the poll. That would make it eligible for resume, which is
  wrong for a database fault — see "Where a throw from here lands".

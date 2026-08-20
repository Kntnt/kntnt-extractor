# Plan 019: Read each table's catalog facts once per request, with a memo that re-reads before it refuses

> **Executor instructions**: Follow this plan step by step. Run every verification command and confirm the expected result before moving to the next step. If anything in the "STOP conditions" section occurs, stop and report — do not improvise. When done, update the status row for this plan in `plans/README.md`, and amend plan 007's row so it no longer says step (c) needs a plan.
>
> **Drift check (run first)**: `git diff --stat 8544fa8..HEAD -- classes/Table_Dumper.php classes/Plugin.php tests/Integration/table-chunking-test.php tests/Integration/bootstrap.php`
> On any change, compare the "Current state" excerpts against the live code before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: nothing. Plan 007's steps (a) and (b) already landed (`e08ea3d`); this is its dropped step (c), replanned with the decision that dropping it deferred.
- **Category**: perf
- **Planned at**: commit `8544fa8`, 2026-08-20
- **Evidence**: four measured runs of `composer test:integration` against a prototype, recorded under "What was measured" below. The counts in "Why this matters" are measured, not inferred.

## The decision this plan settles

`plans/007-remove-redundant-per-chunk-round-trips.md` landed its first two changes and dropped the third. The reason was concrete: `tests/Integration/bootstrap.php` `require`s all 37 test files into one PHP process, and `classes/Plugin.php:116` constructs one `Table_Dumper` for that whole process, so a memo that is never invalidated hides every fixture table created after the first catalog read. Two answers were open, and picking one was the whole content of issue #33:

1. **Let the memo re-read the catalog once on a miss before refusing** — the memo becomes an optimisation rather than an authority.
2. **Stop sharing one `Table_Dumper` across test files** — fix the harness rather than weakening the memo.

**This plan takes the first, and the second is not a fallback: it is the wrong fix for this finding.** The split at triage had already leaned the same way — issue #40, which carries the harness hazard on its own, records that #33 "chose to make the table-catalog memo self-correcting rather than to fix the harness" — so what follows is the argument for that choice and, more usefully, the tests that make it hold. Three reasons, in order of weight.

**The re-reading memo is the only one of the two that preserves the current contract.** `require_known_table()` today accepts exactly the set of names in the live catalog at the moment of the call and refuses everything else. A memo that refuses on a miss changes that: a table that exists but was created after the first read in this request is refused although it is there. That is a behaviour change nobody asked for and nothing announces. The re-reading memo changes nothing observable at all — every name is still accepted or refused against a `SHOW TABLES` this request took from the database.

**Framing it as "weakening the memo" inverts what the suite found.** The harness did not fail because it is fragile; it failed because it exercised a real difference between "the catalog as of some earlier moment in this request" and "the catalog". Fixing the harness would remove the only place that difference is currently observed, leaving the behaviour change in the code and no test on it. The suite was right.

**The security property is preserved verbatim, and the refusal path is strictly fresher than today's.** ADR-0003's rule is that a table name — which cannot be a bound parameter — is validated against the site's own catalog before it is interpolated. Under this design a *hit* is served from a listing read from the live database in this request, and a *miss* re-reads before refusing, so every refusal is now backed by a `SHOW TABLES` issued *after* the name was seen rather than possibly before it. Nothing in ADR-0003 changes.

The cost of the miss path is bounded and small: a miss can happen at most once per distinct name per request, because a name that misses twice throws and ends the job. In production the steady state is one `SHOW TABLES` per tick.

**What the rejected answer was right about.** One `Table_Dumper` shared across 37 test files really is a shared-state hazard, and it is not the only one — `tests/Integration/authorizer-tables-test.php:175-176` leaks two global filters into every file that runs after it. That is a genuine finding about the harness, and it is filed on its own as **issue #40**. It must not be bundled here, and #40 says so from its side too: "Neither ticket may be made to depend on the other." It is a test-infrastructure change with no production behaviour attached, and letting it gate a two-property memo would be the tail wagging the dog. Note also what #40 rules out as its own solution — converting the suite to one process per file — which is the expensive reading of answer 2 and is not on the table there either.

## Why this matters

`classes/Table_Dumper.php` re-asks the database two questions on **every single slice of every table**: `SHOW TABLES`, to prove the name is real, and `SHOW KEYS FROM …`, to find the columns to page by. Neither answer can change between two slices of one tick in a way this code would survive anyway.

Measured, not counted on paper: a 500-row fixture at a 100-row budget dumps in **6 slices** and issues **6 `SHOW TABLES` and 6 `SHOW KEYS`**. With the memo it issues **1 and 1**. (Recipe and numbers under "What was measured".)

A tick is not one slice. `Dispatcher::DEFAULT_TICK_BUDGET` is 15 seconds of wall clock and the loop packages chunks until it is spent, so a tick working through a large table runs many slices and pays both queries on every one of them. Plan 007's estimate — about a hundred redundant `SHOW TABLES` and a hundred redundant `SHOW KEYS` for a hundred-slice table — holds as a total across the run; the memo's scope is one request, so what it actually removes is `(slices in a tick − 1) × 2` per tick, which sums to nearly all of it.

There is also a per-slice `in_array()` scan of the whole catalog, which on a large multisite is thousands of entries compared string by string to find one. The memo replaces it with a keyed lookup.

**Unlike plan 007's other two changes, this one is verifiable by the gate.** Plan 007 states as a known limitation that the suite "can never detect a regression in the per-chunk round-trip count", because those were filesystem operations against an in-memory WASM filesystem. These are *database* round trips, and WordPress's `query` filter sees every one of them. This plan therefore ships a real regression guard, and the executor must ship it: without the assertion, the saving is one refactor away from being silently given back.

## What this does not fix, and does not change

- **It does not make anything measurably faster in the test suite.** SQLite in Playground answers `SHOW TABLES` in microseconds. The win is on a real host, and no one has timed it there. Do not claim a millisecond figure anywhere in the changelog or the ADR.
- **It does not touch the harness's shared-state hazard.** One `Table_Dumper`, and the leaked filters in `authorizer-tables-test.php`, are still there afterwards.
- **It does not touch the wire contract.** No artifact byte changes, no REST field changes. `API_VERSION` stays **7** and `Status_Controller::HONOURED_BEHAVIOURS` gains nothing — a caller cannot observe this and has nothing to opt into.
- **It narrows one guard by one scope, and you must say so rather than repeat plan 007's claim.** `dump_chunk()` refuses to resume when the cursor's arity no longer matches the table's key. Plan 007 wrote that "every tick is a separate request and re-reads the key", which is true about ticks and silent about slices: with the key memoised, a primary key that changes *between two slices of one tick* is no longer caught by that arity check. It is still caught across ticks, which is the guarantee production actually rests on (step 5 pins it), and a mid-tick key change fails the page read anyway under plan 002's rule. State the narrowing in the ADR addendum; do not let it be discovered later as an unrecorded regression.

## Current state

### The two queries, re-issued per slice

`classes/Table_Dumper.php:206-218`, reached from both `dump_chunk()` and `dump_structure()`:

```php
		global $wpdb;

		// The catalog is the authoritative allow-list; a name absent from it never
		// reaches a query.
		$existing = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore ...
		if ( ! in_array( $table, $existing, true ) ) {
			throw new RuntimeException( 'Refusing to dump a table that does not exist in the catalog.' );
		}
```

`classes/Table_Dumper.php:298-309`, reached from `dump_chunk()` on every slice:

```php
		global $wpdb;

		// SHOW KEYS is the engine's own index catalog, one row per key column; pick out
		// the primary key's rows and restore their declared order.
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}`", ARRAY_A ) ?? []; // phpcs:ignore ...
```

`Table_Dumper` has no constructor and no properties. It is constructed once per request at `classes/Plugin.php:116`:

```php
		$dispatcher = new Dispatcher( $job_store, $config, new Artifact_Builder( new Table_Dumper(), $config ) );
```

so a private property on it is request-scoped by construction. **It must stay that way** — see STOP conditions.

### What the harness does with that instance

`tests/Integration/bootstrap.php:79-81` `require`s every `*-test.php` in one process, in `glob()` order — 37 files. The plugin is activated once at the top, so `Plugin::__construct()` runs once and every REST-driven tick in every test file goes through the *same* `Table_Dumper`. `tests/Integration/table-chunking-test.php:210-212` and `tests/Integration/table-read-failure-test.php:220` then create fixture tables from the 28th and 29th files, long after the first REST-driven table dump has warmed anything a dumper memoises.

Four of the suite's files construct a `Table_Dumper` of their own — `table-chunking`, `resume-and-adapt`, `integration-hardening`, `watchdog` — as does `DDEV/table-dumping-test.php`, which the Playground suite does not run at all. Those instances are fresh and are not what breaks. The shared one is.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md` before writing. Load-bearing here: English throughout; a `//` comment above each paragraph stating its *purpose*, not its mechanism; WordPress surface style (tabs, `snake_case`, spaces inside parentheses); a full docblock with `@var` on each new property. New symbols in unreleased work carry `@since 0.6.1` — that is the convention `3084e81` set for this cycle. `classes/Crypto/Sealed_Writer.php:100-115` is the house style for a property docblock.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, `[OK] No errors` |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

Redirect the gate to a file and capture `$?` on its own line — `composer gate | tail` reports `tail`'s status, and that has produced one false green in this project already.

The suite takes a few minutes and needs network on its first run (`npx` fetches the pinned Playground CLI).

## Scope

**In scope**:

- `classes/Table_Dumper.php` — two new private properties, `require_known_table()`, `ordering_key()`
- `tests/Integration/table-chunking-test.php` — new assertions (extend; do not create a file)
- `docs/adr/0003-generic-manifest-and-table-list-no-server-side-categorisation.md` — an addendum
- `CHANGELOG.md` — one entry under `[Unreleased]` → `### Changed`
- `plans/README.md` — this plan's row, and plan 007's

**Out of scope** (do NOT touch):

- `tests/Integration/bootstrap.php` and the shared-instance question. That is the rejected answer and a separate issue.
- `classes/Plugin.php`. The construction site is quoted above because the memo's lifetime depends on it, not because it changes.
- `classes/Artifact_Builder.php`, `classes/Dispatcher.php`. Untouched by this.
- **What is validated, or when.** The catalog check still happens before the name reaches a query, on every call.
- `API_VERSION`, `HONOURED_BEHAVIOURS`, the container format, the REST surface, the job record's schema.
- `CONTEXT.md`. Deliberate: R3 asks for the documentation round, and this change adds no domain vocabulary — a memo inside one class is not a term a reader of the glossary needs. The round is paid by the ADR addendum and the changelog. Say in your report that you considered it and why you left it.

## Git workflow

Trunk-based: commit straight to `main`, no branch, no pull request. Do not push, tag, or bump a version. One commit is right for this; an imperative subject line, no prefix. Suggested: `Read each table's catalog facts once per request`.

## Steps

### Step 1: Pin the round trips with an assertion that fails today

Write the test before the memo, and watch it fail. This is the assertion that makes the saving stay saved, and the gate genuinely can enforce it.

Extend `tests/Integration/table-chunking-test.php`. Read it first — it already builds fixtures for all three primary-key shapes and already constructs its own `$dumper` at `:238`, which is what you want: a fresh instance whose memo you control.

Add an AC that counts catalog queries across a multi-slice dump:

```php
$counts = [ 'tables' => 0, 'keys' => 0 ];
$probe = static function ( string $query ) use ( &$counts ): string {
	if ( str_starts_with( $query, 'SHOW TABLES' ) ) {
		$counts['tables']++;
	}
	if ( str_starts_with( $query, 'SHOW KEYS' ) ) {
		$counts['keys']++;
	}
	return $query;
};
add_filter( 'query', $probe );
```

Then drive one fresh `Table_Dumper` across several slices of one fixture table, `remove_filter( 'query', $probe )`, and assert the counts are **1 and 1** while the slice count is greater than one. Asserting the slice count in the same breath is what stops the assertion passing vacuously if the fixture stops being chunked.

Two things to get right, both of which this repository has been bitten by:

- **Remove the filter.** `authorizer-tables-test.php` leaks two, and every file after it pays. Do not add a third.
- **Count the slices too.** `1 SHOW TABLES` is also what a one-slice dump produces.
- **Drop any fixture table you add**, in the trailing cleanup block at the end of the file (`:627-635`), where the four existing fixtures are already dropped. This applies to steps 4 and 5 as well. The file's own comment there — "Leave the suite state clean for later files" — is the standard to meet, and it is the same standard this whole plan turns on.

**Verify**: `composer test:integration` → the new assertion reports `not ok`, with counts equal to the slice count rather than to one. **Record the failing TAP line in your report.** If it passes before you have written any production code, STOP — the assertion is not measuring what it claims.

### Step 2: Memoise the catalog, re-reading on a miss

Add to `classes/Table_Dumper.php` a private `array<string, true>|null $catalog`, null meaning "not read yet in this request", and rewrite `require_known_table()` so that:

- the memo is consulted first, and a **hit** returns without a query;
- a **miss — including the first call, when the memo is null — re-reads `SHOW TABLES`** and rebuilds the memo;
- the refusal is decided only after that, against the rebuilt memo.

The shape below is the one this plan was verified against; `composer phpcs` and `composer phpstan` both pass on it.

```php
		// The catalog is the authoritative allow-list; a name absent from it never
		// reaches a query. The memo is an optimisation and never an authority: it answers
		// a hit, and a miss re-reads the live catalog before anything is refused, so a
		// name is accepted or refused on a listing this request took from the database
		// either way. A re-read also drops any key resolved for that name, since a name
		// that has just reappeared may not be the table whose key was memoised.
		if ( $this->catalog === null || ! isset( $this->catalog[ $table ] ) ) {
			$existing = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a schema listing is the authoritative existence check (ADR-0003); nothing to prepare or cache.
			$this->catalog = [];
			foreach ( $existing as $name ) {
				if ( is_string( $name ) ) {
					$this->catalog[ $name ] = true;
				}
			}
			unset( $this->ordering_keys[ $table ] );
		}
		if ( ! isset( $this->catalog[ $table ] ) ) {
			throw new RuntimeException( 'Refusing to dump a table that does not exist in the catalog.' );
		}
```

Notes on the shape, so you do not "simplify" it back into a bug:

- **The `is_string()` filter is not defensive noise.** It reproduces the old `in_array( $table, $existing, true )` exactly: under a strict comparison against a `string` parameter, a non-string entry was never a match. Do not replace the loop with `array_map( 'strval', … )` — PHPStan rejects it (`argument.type`, "Parameter #1 $callback of function array_map expects (callable(mixed): mixed)|null, 'strval' given"), and a cast to silence it would be exactly the widening the standard forbids.
- **The `unset()` is load-bearing**, and it is the one line whose reason is not obvious. It is the only path on which a name can go from absent to present within a request, so it is the only place a key memoised for an earlier table of the same name can be stale. It costs nothing and closes that case.
- Keep the existing `phpcs:ignore` comment on the `get_col()` line, and keep the `global $wpdb;` paragraph and its docblock where they are.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `composer test:integration` → exit 0. Step 1's `SHOW TABLES` count is now 1; its `SHOW KEYS` count is still the slice count, which step 3 fixes. Split the assertion in two if that is easier to read.

### Step 3: Memoise each table's ordering key

Add a private `array<string, array<int, string>> $ordering_keys`, and at the head of `ordering_key()`:

```php
		// A table's key is resolved once per request. Every slice of one table in one tick
		// asks the same question of a schema that cannot answer differently without the
		// table having been recreated underneath the dump.
		if ( array_key_exists( $table, $this->ordering_keys ) ) {
			return $this->ordering_keys[ $table ];
		}
```

and store the result before returning it. Use `array_key_exists()`, not `isset()` — a keyless table resolves to an empty list, and `isset()` cannot tell "resolved to no key" from "not resolved", which would re-query every slice of exactly the tables the fallback walk already makes expensive.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `composer test:integration` → exit 0, `Integration suite: PASS`, and step 1's assertion now reports **1 and 1**. **Record the passing TAP line beside the failing one from step 1.**

### Step 4: Prove the memo is an optimisation and not an authority

This is the step that encodes the decision. Three assertions, still in `table-chunking-test.php`, all using **one** `Table_Dumper` instance so the memo is warm.

1. **A table created after the memo is warm is accepted.** Dump an existing fixture through the instance, then `CREATE TABLE` a new one, then dump *that* through the same instance and assert it succeeds and carries its rows. **This is the harness failure in miniature**, and it is the reason the decision is testable at all: under the rejected design it fails inside one file, instead of being discovered fifteen files later as a broken artifact.
2. **An unknown table is still refused with the memo warm.** Assert `dump_chunk()` throws for a name that is not a table, with the memo already populated by a successful dump. Assert on the throw, and — using the step-1 probe — that a fresh `SHOW TABLES` was issued before the refusal. The refusal must never be decided on stale knowledge.
3. **The re-read happens once, not once per slice.** After assertion 1, drive the newly created table across several slices with the probe attached and assert the `SHOW TABLES` count for that stretch is 1. Without this, "re-read on a miss" could degenerate into "re-read every slice" and give the whole saving back on any table whose name arrives late.

Then confirm the existing assertions still hold with a warm memo: all three key shapes still dump byte-identically across slices (AC2), every row still exactly once (AC3), the composite key still paged on every column. Those are already in the file; check they run against the memoised instance, and add one if a shape only ever gets a fresh dumper.

**Verify**: `composer test:integration` → exit 0, with the three new assertions in the TAP output.

**Demonstrate a RED step for assertion 1.** Temporarily change the memo to refuse on a miss (drop the second disjunct — `|| ! isset( $this->catalog[ $table ] )` — so the guard reads `if ( $this->catalog === null )` and a warm memo never re-reads), re-run, confirm assertion 1 reports `not ok`, restore, re-run to green. **Record both runs in your report.** That control is what proves assertion 1 discriminates between the two answers rather than merely passing.

### Step 5: Pin the guarantee the key memo narrows

`dump_chunk()`'s arity check is the guard against resuming a table whose primary key changed. No test covers it today — `grep -rn "primary key changed" tests/` returns nothing. Add one, because the key memo narrows its scope and an unpinned guarantee that gets narrowed is how a regression becomes invisible.

The recipe below has been run in this harness and works under Playground's SQLite; a table's primary key is changed by dropping and recreating it, which is portable across both engines:

1. Create a fixture with `PRIMARY KEY (a)`, seed it, and dump one slice through dumper A. Assert the returned cursor has arity 1.
2. Drop it and recreate it with `PRIMARY KEY (a, b)`, reseeded.
3. Construct a **second** `Table_Dumper` — that is what the next tick does — and call `dump_chunk()` with the arity-1 cursor from step 1.
4. Assert it throws, with the message `A table primary key changed while the table was being dumped.`

Add a comment in the test saying what it does *not* pin: the same change between two slices of one tick is no longer caught by this guard once the key is memoised. That is the honest scope, and the ADR addendum in step 6 must agree with it.

**Verify**: `composer test:integration` → exit 0, with the new assertion passing.

### Step 6: Pay the documentation round (R3)

1. **`docs/adr/0003-…`** — add an addendum, in the style of ADR-0013's. It must say four things and no more: that the catalog is now read once per request rather than once per slice; that the memo is explicitly an optimisation and never an authority, so a miss re-reads before refusing and the validation rule of the original decision is unchanged; that a refusal is now decided on a listing read *after* the name was seen; and that the ordering-key memo narrows the arity guard from per-slice to per-tick, with step 5's test named as what pins what remains. Do not claim a performance figure.
2. **`CHANGELOG.md`**, `### Changed` under `[Unreleased]`, matching the surrounding entries' voice. Say what was removed (two catalog queries per table slice), why removing it is safe (the memo re-reads before it refuses, so nothing is accepted or refused on stale knowledge), and be explicit that the benefit is a round-trip count on a real host and has not been timed there. End with `No REST change.`

**Verify**: `git diff --stat` → only files from the In-scope list.

### Step 7: Full gate and the index

Update `plans/README.md`: add this plan's row, and amend plan 007's row so it records that step (c) landed here rather than still needing a plan.

**Verify**: `composer gate` → exit 0, with the exit code captured on its own line.

## Test plan

- **File**: `tests/Integration/table-chunking-test.php` (extend, do not create).
- **New cases**: the round-trip count (1 `SHOW TABLES`, 1 `SHOW KEYS` across a multi-slice dump); a table created after the memo is warm is still dumped; an unknown table is still refused, and refused only after a fresh read; the re-read happens once and not per slice; the cross-tick primary-key-change guard still fires.
- **Existing coverage relied on**: this file's AC2 byte-identity assertion and AC3 exactly-once assertion, both re-run against a warm memo.
- **Controls that must be demonstrated, not assumed**: step 1's failing run before the memo exists, and step 4's refuse-on-miss control. Record the TAP lines for both.
- **Known limitation to state in your report**: the counts are measured against SQLite in Playground. That they are *counts* is the point — the number of queries is engine-independent even though their cost is not.

## Done criteria

Machine-checkable unless noted. ALL must hold:

- [ ] `grep -n 'in_array( $table' classes/Table_Dumper.php` returns nothing — this is the discriminating grep; it matches once today
- [ ] `grep -c "get_col( 'SHOW TABLES' )" classes/Table_Dumper.php` **still** returns 1, and `grep -c 'SHOW KEYS FROM' classes/Table_Dumper.php` **still** returns 1 — the queries were memoised, not duplicated into a second call site
- [ ] The round-trip TAP assertion reports 1 `SHOW TABLES` and 1 `SHOW KEYS` across a dump of more than one slice — this, not a grep, is what proves the saving is real
- [ ] `require_known_table()` re-reads the catalog on a miss before it throws — verify by reading the method
- [ ] `ordering_key()` uses `array_key_exists()`, not `isset()`, for its memo — verify by reading the method
- [ ] Both memos are instance properties. `grep -n 'private static\|self::$' classes/Table_Dumper.php` returns nothing
- [ ] `git diff 8544fa8..HEAD -- classes/Plugin.php tests/Integration/bootstrap.php` shows no changes from this plan
- [ ] `grep -n 'API_VERSION' classes/Rest/Status_Controller.php` still reads 7
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] The TAP output contains the round-trip assertion, the created-after-warm assertion, the still-refused assertion, and the key-change assertion
- [ ] The step-1 failing run and the step-4 control run are both recorded in the report
- [ ] `composer gate` exits 0, verified as the gate's own exit code
- [ ] ADR-0003 carries the addendum; `CHANGELOG.md` carries the entry
- [ ] `git status --short` lists only files from the In-scope list
- [ ] `plans/README.md` rows for 019 and 007 both updated

## STOP conditions

Stop and report back (do not improvise) if:

- The drift check reports changes and the excerpts above no longer match the live code.
- **The memo appears to need to be static, a singleton, or otherwise longer-lived than one request.** It must not be. If `Table_Dumper` is no longer constructed per request at `classes/Plugin.php:116`, that is a different plan.
- Step 1's assertion passes before any production code is written. It is then not measuring the round trips.
- Step 4's control (refuse on a miss) does **not** turn assertion 1 red. The assertion would then not discriminate between the two answers, and the decision this plan settles would be untested.
- **Making the suite green appears to require editing `tests/Integration/bootstrap.php` or the way `Table_Dumper` is shared.** It does not. If it seems to, the memo has become an authority somewhere; find that instead. Issue #40 exists for the harness and neither ticket may be made to depend on the other.
- Any assertion about rows being emitted exactly once, in key order, or byte-identically across slices fails.
- You conclude the ordering-key memo should be dropped to keep the arity guard per-slice. That is a defensible position and it halves the win; it is a decision, not an implementation detail, so report it rather than taking it.

## Maintenance notes

### What was measured

Four full runs of `composer test:integration` at commit `8544fa8`, on the prototype this plan is written from. The prototype was reverted; nothing of it is committed.

| Run | Result |
|---|---|
| Baseline, unmodified | 851 assertions, `Integration suite: PASS` |
| **Refuse-on-miss memo** (the rejected design) | **exit 255.** The run does not merely go red — it aborts at assertion 638, inside `table-chunking-test.php`, and 213 assertions never run. Two `not ok` lines precede the abort: `A selection of three oversized tables reaches ready (AC1)` and `The ready job publishes a sealed container (AC1)`. Playground reports only `exit code 255` and an empty stderr, so the throwing statement is inferred from position rather than read from a message: the next statement after the last assertion emitted is `$parse( $raw )` at `:307`, operating on the empty artifact of a job that failed at tick time. What failed the job is not inferred — it is the only refusal on that path, `Refusing to dump a table that does not exist in the catalog.`, reached because the shared dumper's catalog predates the fixtures. |
| **Re-read-on-miss memo** (this plan) | 851 assertions, `Integration suite: PASS`, identical to baseline |
| Round-trip probe, 500-row fixture at a 100-row budget, one fresh `Table_Dumper` | before: `slices=6 SHOW_TABLES=6 SHOW_KEYS=6`; after: `slices=6 SHOW_TABLES=1 SHOW_KEYS=1` |

`composer phpcs` and `composer phpstan` both pass on the shape quoted in steps 2 and 3. The key-change recipe in step 5 was run and produced `A table primary key changed while the table was being dumped.` as expected.

Two things worth carrying forward from that: the rejected design's failure is a **process abort**, not a set of red assertions, so anyone who reasons about it as "a few tests would go red" is underestimating it; and `SHOW TABLES`/`SHOW KEYS` counts are visible to WordPress's `query` filter, which is why this plan can carry a regression guard where plan 007's filesystem steps could not.

### What a reviewer should scrutinise

- That the memo is instance state and that `Table_Dumper` is still constructed per request.
- That the refusal path re-reads. A memo that refuses on stale knowledge is the whole rejected design, and it looks almost identical in a diff.
- That `array_key_exists()` and not `isset()` guards the key memo, so a keyless table does not re-query every slice.
- That the round-trip assertion also asserts the slice count, so it cannot pass vacuously.
- That the `query` filter probe is removed after use.

### Left on the table

- **The harness shares one `Table_Dumper` across 37 test files**, and `authorizer-tables-test.php:175-176` leaks two global filters into every file after it. That is issue **#40**, deliberately not fixed here and deliberately not depended on. This plan is correct with or without it; if #40 lands first, nothing in these steps changes.
- **Nobody has timed a `SHOW TABLES` on the production host.** The round-trip count is a fact and its cost is not. This is the same shape as plan 007's own honest caveat and the queue's rule R4, and it is why nothing here claims milliseconds.
- **`Extractions_Controller::validate_payload()` issues its own catalog reads** at create time, on a different instance and a different question. Untouched, and out of scope: it is one request's worth of reads, not a per-slice cost, and bounding it was plan 015's job.

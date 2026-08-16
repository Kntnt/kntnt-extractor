# Plan 012: Cover the table dumper's MySQL-specific SQL against a real MySQL, not SQLite's translation of it

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Table_Dumper.php tests/Integration/DDEV/ docs/testing-strategy.md`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW — adds tests only; changes no production code
- **Depends on**: plans/001-run-the-gate-in-ci.md (soft)
- **Blocks**: plans/002 and plans/003 should land *after* this, so their changes are verifiable on MySQL
- **Category**: tests
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

`Table_Dumper` is the most MySQL-specific code in this plugin. It reads the
engine's own index catalog with `SHOW KEYS`, restores the primary key's declared
column order from `Seq_in_index`, builds a lexicographic keyset predicate to
page through a table without `OFFSET`, falls back to `LIMIT`/`OFFSET` on a table
with no key at all, and emits `mysqldump`-compatible DDL from
`SHOW CREATE TABLE`.

None of it is ever executed against MySQL.

The fast suite runs inside WordPress Playground, which is WASM PHP on **SQLite**.
Every one of those statements reaches the database through a translation layer.
`docs/testing-strategy.md:44` is candid about how thin the ice is — it notes the
DDL check passes because "the SQLite harness happens to translate faithfully".
A MySQL-backed harness does exist, but it holds exactly one test, about
`SHOW TABLE STATUS`, and it never touches the dumper.

So the acceptance criteria that matter most — every row emitted exactly once,
in the key's own order, across slice boundaries, on single-column, composite and
keyless primary keys — are verified only against a database engine this plugin
never runs on in production.

Three things make closing this worth doing now rather than later:

1. **Plans 002 and 003 both modify `Table_Dumper`**, and one of them changes the
   slicing arithmetic. Their only automated verification today runs on SQLite.
2. **A multi-hour extraction against a live client site is about to be
   re-run from scratch.** A row-skipping bug in the keyset seek would surface
   there, hours in, on real data — and a keyset bug is exactly the kind that
   produces a plausible-looking artifact rather than an error.
3. **MySQL can prove something SQLite cannot**: that the emitted SQL actually
   *reloads*, and that the reloaded table is row-for-row identical to the
   original. That is the assertion that catches a silently truncated or
   misordered dump, and it is unavailable in the fast suite.

## Current state

### The harness already exists and is extensible

`tests/Integration/DDEV/run.sh` provisions a throwaway DDEV WordPress project on
a real MySQL-family server, installs and activates the plugin, seeds content,
then runs **every** `*-test.php` in its own directory:

```bash
status=0
for test_file in "${script_dir}"/*-test.php; do
	cp "${test_file}" "${project_dir}/$( basename "${test_file}" )"
	set +e
	ddev wp eval-file "$( basename "${test_file}" )"
	rc=$?
	set -e
	if [[ "${rc}" -ne 0 ]]; then
		status="${rc}"
	fi
done
```

So **adding a file to `tests/Integration/DDEV/` is the whole wiring change.**
The runner tears the project down on any exit (`trap cleanup EXIT`), leaving the
machine state-neutral.

It is invoked as `composer test:integration:mysql` (`composer.json:40`) and is
**deliberately not part of `composer gate`** — it needs Docker and DDEV.

### How a test in that directory is written

Unlike the Playground suite, these files run through `wp eval-file`, not through
`tests/Integration/bootstrap.php`. **There is no `kntnt_extractor_assert()`
available.** Each file defines its own TAP helper. From
`tests/Integration/DDEV/tables-size-test.php:33-40`:

```php
$failed = 0;
$assert = static function ( bool $passed, string $description ) use ( &$failed ): void {
	printf( "%s - %s\n", $passed ? 'ok' : 'not ok', $description );
	if ( ! $passed ) {
		++$failed;
	}
};
```

and the file must `exit( 1 )` at the end when `$failed > 0`, so the runner turns
red. Read that file in full before writing yours — it is short and it is the
pattern.

### The code under test

`classes/Table_Dumper.php`. The MySQL-specific parts, by line:

- `:196-206` `require_known_table()` — `SHOW TABLES` as the allow-list
- `:284-308` `ordering_key()` — `SHOW KEYS`, filtering on `Key_name === 'PRIMARY'`
  and restoring `Seq_in_index` order
- `:335-359` `fetch_rows()` — composes `SELECT * FROM \`table\`` with either the
  keyset `WHERE` and `ORDER BY`, or `LIMIT`/`OFFSET` when the table has no key
- `:361-409` `keyset_predicate()` — the lexicographic expansion of
  `(k1, …, kn) > (v1, …, vn)`, with each cursor value passed through
  `$wpdb->prepare( '%s', … )`
- `:424-438` `cursor_of()` — casts each key column's value to `string`
- `:140-171` `dump_chunk()` — the slice loop and the two-fact completeness rule
- `structure_sql()` — `SHOW CREATE TABLE` into `DROP TABLE IF EXISTS` +
  `CREATE TABLE`

### The fixtures that already exist, on SQLite

`tests/Integration/table-chunking-test.php` builds four fixture tables for the
shapes a real site presents — a single-column key, a composite key (the shape
WordPress core ships on `wp_term_relationships`), no key at all, and a
few-fat-rows table. `docs/testing-strategy.md:43` describes what each proves.

**Read that file thoroughly before writing anything.** Your job is not to invent
coverage; it is to re-establish the same acceptance criteria against a real
engine, plus the reload check that only MySQL makes possible.

### What `docs/testing-strategy.md` currently claims

`:52-56` scopes the DDEV exclusion to `SHOW TABLE STATUS` alone:

> The row-count and byte-size estimates in `GET /tables` are the storage
> engine's own `SHOW TABLE STATUS` figures. WordPress Playground runs on SQLite,
> whose translation of that statement reports `Rows`, `Data_length`, and
> `Index_length` as zero, so the fast suite can only verify the listing's shape

That understates the gap, and correcting it is part of this plan.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
Load-bearing: `declare( strict_types = 1 );` at the top; English throughout; a
`//` comment above each paragraph stating its *purpose*; WordPress surface style
(tabs, `snake_case`, spaces inside parentheses); a complete file-level docblock
with `@package Kntnt\Extractor` and `@since`. Match
`tests/Integration/DDEV/tables-size-test.php` exactly — it is the only existing
example of this file type.

Markdown convention for `docs/`: **keep each paragraph on a single physical
line.** Do not hard-wrap prose.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Fast suite | `composer test:integration` | exit 0, `Integration suite: PASS` |
| **MySQL suite** | `composer test:integration:mysql` | exit 0, `DDEV integration check: PASS` |
| Coding standard | `composer phpcs` | exit 0 |
| Static analysis | `composer phpstan` | exit 0 |
| Gate (unchanged by this plan) | `composer gate` | exit 0 |

The MySQL suite needs **Docker and DDEV running**. It provisions and tears down a
whole WordPress project, so it takes several minutes. Confirm it passes *before*
you change anything — see step 1.

## Scope

**In scope**:

- `tests/Integration/DDEV/table-dumping-test.php` (create)
- `docs/testing-strategy.md` — correct the scope of the DDEV exclusion
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Added`)

**Out of scope** (do NOT touch):

- **Any production code.** This plan adds tests. If a test fails, that is a
  finding to report, not a bug to fix here — plans 002 and 003 own
  `Table_Dumper` changes.
- `tests/Integration/DDEV/run.sh` — it already discovers `*-test.php`
  automatically. Do not modify it unless the fixtures genuinely cannot be built
  from inside `wp eval-file`, and if so, report first.
- `composer.json` — do **not** add the MySQL suite to `composer gate`. Keeping
  Docker out of the fast gate is a deliberate decision recorded in
  `docs/testing-strategy.md:56`.
- `tests/Integration/table-chunking-test.php` — leave the SQLite coverage as it
  is. This plan adds a second harness, it does not move the first.
- The `.github/workflows/gate.yml` from plan 001. Running DDEV in CI is a
  separate, much heavier question; see "Maintenance notes".

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Verify the dumper's keyset paging and DDL against a real MySQL, not SQLite's translation`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Prove the harness works before you add to it

**Verify**: `composer test:integration:mysql` → exit 0, prints
`DDEV integration check: PASS`.

If it fails or cannot start, STOP and report — you need a working baseline
before adding anything, and a pre-existing failure is information the operator
needs.

### Step 2: Build the fixtures against real MySQL

Create `tests/Integration/DDEV/table-dumping-test.php`. Start with the file-level
docblock, `declare( strict_types = 1 );`, `global $wpdb;`, and the TAP helper
copied from `tables-size-test.php`.

Create four fixture tables with real MySQL DDL via `$wpdb->query()`, mirroring
the shapes in `tests/Integration/table-chunking-test.php`:

1. **Single-column primary key** — `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
   plus a couple of data columns.
2. **Composite primary key** — two columns, `PRIMARY KEY (a, b)`, populated so
   that the *second* column varies within a repeated first column. This is the
   fixture that discriminates: a keyset paged on only the first column skips or
   repeats rows here, and cannot on a fixture where the first column is unique.
3. **No primary key at all** — forces the `LIMIT`/`OFFSET` fallback.
4. **Few fat rows** — perhaps 20 rows of roughly 50 KB, so the byte budget cuts
   a page short well inside the row budget.

Populate each with enough rows to need several slices at the budgets you will
force. Include values that stress escaping on a real engine and not on SQLite:
a `NULL`, an empty string, a string containing a single quote, a backslash, a
newline, a multi-byte UTF-8 character, and a numeric-looking string. Also give
at least one fixture a `DEFAULT`, a `NOT NULL` and an index other than the
primary key, so `SHOW CREATE TABLE` has something real to render.

Drop every fixture table at the end of the file, whatever the outcome — the
runner disposes of the whole project anyway, but a test that cleans up reads
correctly and does not depend on that.

**Verify**: `composer test:integration:mysql` → exit 0, and your file's TAP
lines appear.

### Step 3: Assert the acceptance criteria against MySQL

Drive `Kntnt\Extractor\Table_Dumper` **directly** for the slicing assertions —
that is the level the behaviour lives at, and it keeps the fixtures independent
of job records and budgets plumbing. Call `dump_chunk()` in a loop, feeding each
returned cursor into the next call, until it reports the table complete.

Assert, per fixture where applicable:

1. **Every row exactly once.** Count the rendered rows across all slices and
   compare with `SELECT COUNT(*)`. Then compare the *set* of primary-key values
   emitted with the set in the table — a count alone would not catch a row
   emitted twice while another was skipped.
2. **Key order.** For the keyed fixtures, the emitted order matches
   `ORDER BY` the primary key's declared columns.
3. **The composite key is paged on all its columns.** State this in the
   assertion's description explicitly; it is the one most likely to regress.
4. **Concatenation is byte-identical to a single-slice dump.** Dump the same
   table again with budgets large enough for one slice and compare the strings.
5. **A short page the byte budget cut is not read as the end of the table**, and
   **a slice always renders at least one row** however small the byte budget —
   the fat-row fixture is what exercises both.
6. **The keyless fixture completes** via the offset walk and emits every row
   exactly once.

Then the assertion that only MySQL can make:

7. **The dump reloads, and the reloaded table matches the original.** Take the
   concatenated SQL for a fixture, run it against the same database (its
   `DROP TABLE IF EXISTS` + `CREATE TABLE` recreate the table), and assert the
   reloaded table's rows are identical to what was there before — including the
   awkward values from step 2. Do this on a *copy* of the table name if
   recreating the original would disturb a later assertion; be explicit about
   which. **This is the highest-value assertion in the plan**: it is what would
   catch a silently truncated or misordered dump, which is precisely the failure
   mode that reloads without an error and is discovered months later.

**Verify**: `composer test:integration:mysql` → exit 0, `DDEV integration check: PASS`.

**If any assertion fails, STOP and report it.** A failure here means the dumper
has a real MySQL-specific defect that SQLite has been hiding, which is exactly
what this plan was written to find out — and it is far more important than
finishing the plan. Do not fix it here; report it with the fixture, the
assertion and the observed output.

### Step 4: Prove the tests can fail

A test never observed to fail is of unknown value, and that is doubly true for a
harness that has to be invoked by hand.

Temporarily break the dumper in a way that only MySQL would catch — for example
make `ordering_key()` return only the first column of a composite key — re-run
`composer test:integration:mysql`, and confirm assertion 3 (and probably 1)
report `not ok`. Restore the code and re-run to green.

**Record both runs in your report.** Confirm with `git diff classes/` that the
restoration is complete and no production file is left modified.

### Step 5: Correct what `docs/testing-strategy.md` claims

Two edits:

1. Extend the DDEV section (`:50-66`) to say plainly that **all** of the
   dumper's SQL — `SHOW KEYS` and `Seq_in_index` ordering, the keyset predicate,
   the `LIMIT`/`OFFSET` fallback, `SHOW CREATE TABLE` DDL and value escaping —
   is exercised there and only there, not merely `SHOW TABLE STATUS`. Add a line
   for the new test in the same voice as the existing entry.
2. Add a short, honest paragraph naming what remains structurally unreachable in
   the fast Playground suite: real filesystem latency, real concurrency between
   a tick and the watchdog, symlink handling, multi-process cron, and — until
   this plan — MySQL dump SQL. That paragraph gives future gaps somewhere to be
   recorded instead of being discovered twice.

Do **not** attempt to reconcile the whole "Current tests" list; it documents
about 14 of 35 files and fixing it is a separate, unselected finding.

**Verify**: `grep -c 'table-dumping-test' docs/testing-strategy.md` → at least 1.

### Step 6: Changelog and gate

Add one entry under `### Added` in `CHANGELOG.md`'s `[Unreleased]` section
(heading at `CHANGELOG.md:19`). End with `No REST change.`

**Verify**:
- `composer gate` → exit 0 (unchanged; the MySQL suite is outside it by design)
- `composer test:integration:mysql` → exit 0
- `git status --short` → only the three files from "In scope"

## Test plan

- **New file**: `tests/Integration/DDEV/table-dumping-test.php`.
- **Cases**: the six acceptance criteria in step 3 across four fixtures, plus
  the reload-and-compare assertion.
- **Structural pattern**: `tests/Integration/DDEV/tables-size-test.php` for the
  file shape and its own TAP helper; `tests/Integration/table-chunking-test.php`
  for the fixtures and what each one discriminates.
- **Verification**: `composer test:integration:mysql` → all pass, plus the
  demonstrated failing run from step 4.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `tests/Integration/DDEV/table-dumping-test.php` exists
- [ ] `grep -c 'declare( strict_types = 1 );' tests/Integration/DDEV/table-dumping-test.php` returns 1
- [ ] The file defines its own TAP helper and exits non-zero on failure — verify by reading it
- [ ] `composer test:integration:mysql` exits 0 and prints `DDEV integration check: PASS`
- [ ] The TAP output contains assertions for all four fixture shapes
- [ ] The TAP output contains a reload-and-compare assertion
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer gate` exits 0
- [ ] `git diff --stat classes/` is **empty**
- [ ] `grep -c 'table-dumping-test' docs/testing-strategy.md` returns at least 1
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report contains the output of the deliberately-failing run from step 4
- [ ] `plans/README.md` status row for 012 updated

## STOP conditions

Stop and report back (do not improvise) if:

- Docker or DDEV is unavailable, or `composer test:integration:mysql` fails
  before you change anything.
- **Any assertion in step 3 fails against real MySQL.** Report the fixture, the
  assertion and the output. Do not fix `Table_Dumper` here — that is a finding,
  and depending on what it is, it may outrank every other plan in this
  directory.
- The fixtures cannot be created from inside `wp eval-file` (for instance if
  `$wpdb->query()` refuses the DDL). Report before modifying `run.sh`.
- Building the reload assertion would require dropping or recreating a table
  WordPress itself needs. Use your own fixture names only; never touch a `wp_`
  core table.
- You find yourself editing anything under `classes/`.

## Maintenance notes

- **Execution order**: land this **before** plans 002 and 003. Both modify
  `Table_Dumper`, and 003 changes the slicing arithmetic — after which the
  byte-identical-concatenation assertion here may legitimately need its forced
  budgets re-pitched onto a whole `INSERT` batch. 003's STOP conditions cover
  that case; having the MySQL baseline in place first is what makes the
  difference visible at all.
- **This suite is not in the gate and will therefore not be run by habit.** That
  is a deliberate trade (Docker in a five-minute PR loop is a bad bargain), but
  it means the coverage decays unless someone runs it. Two ways to bind it, both
  out of scope here and worth a decision: add it to the release procedure in
  `CLAUDE.md`'s release configuration, so it runs before a version is cut; or
  add a second CI workflow triggered on demand or on a schedule rather than on
  every push. **Recommend one in your report.**
- **What a reviewer should scrutinise**: that the composite-key fixture really
  does repeat its first column (otherwise assertion 3 proves nothing), and that
  the reload assertion compares values rather than just row counts.
- **What this deliberately does not cover**: the stall and adaptation machinery,
  which is planted by writing counters into a state file and is untestable
  against a real host kill in either harness. That limitation is real and
  belongs in the paragraph you add to `docs/testing-strategy.md`.

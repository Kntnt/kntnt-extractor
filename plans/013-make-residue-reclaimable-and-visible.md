# Plan 013: Make leftover extraction residue reclaimable by the server and visible to its owner

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Rest/Extractions_Controller.php classes/Job_Store.php classes/Sweeper.php classes/Dispatcher.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED — touches the concurrency lock and the sweep, both load-bearing
- **Depends on**: plans/014 (the `state` parameter is announced through the capability list, not through a version bump)
- **Category**: bug + direction
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

Nobody owns the question "what is left on this site, and why".

Three distinct gaps make that true, and they compound. **First**, `consume` and
`cancel` delete a job's working directory without taking the per-job tick lock
that every other actor takes — so a cancel landing mid-tick can delete the
directory under a live driver, and if the driver publishes its finished artifact
in that same window, the artifact lands in the *web-served downloads directory*
for a job whose record has just been deleted. **Second**, nothing can ever
reclaim that artifact: the TTL sweep walks the job records, and this one has
none. **Third**, the caller cannot see any of it — `GET /extractions` lists only
non-terminal jobs owned by the caller, so a failed job's residue is invisible
from the API and the sweep's own code comments say so.

The consumer feels this directly. Its clone and pull flows have a
belt-and-braces sweep built on `GET /extractions`, which therefore cannot see
the one case that matters. What it actually needs to answer, before or after a
run, is: **is there sealed data of mine still sitting on this site?** Today no
call answers that honestly, and the honest answer matters — sealed production
data left on a live client site is an exposure window that nobody is measuring.

This plan makes reclamation reliable and gives the owner a way to see their own
residue. It deliberately does **not** build a job-management surface: the
consuming repository asked for exposure, not administration, and reclamation
stays the server's business.

## Current state

### `consume` and `cancel` skip the lock

`classes/Rest/Extractions_Controller.php:556` (consume) and `:593` (cancel) both
call `$this->store->purge( $job )` with no lock. `cancel`'s own docblock says it
"applies to a job in any state it owns — queued, running, or ready".

Compare `classes/Sweeper.php:159-168`, which takes `Job_Store::lock()` and defers
a job it cannot lock, so that "an in-flight build is never deleted out from under
itself". And `classes/Job_Store.php:604-618`, whose `lock()` docblock calls
itself "the single owner of the per-job advisory lock… so every actor that must
not touch a job another is actively building through takes it the same way" —
and then lists the tick driver and the TTL sweep. Consume and cancel are not
among them, yet `purge()`'s own docblock at `classes/Job_Store.php:400-413` names
consume, cancel and the sweep as its three callers.

Two things follow from a cancel landing mid-tick:

- The tick's next save at `classes/Dispatcher.php:357` writes into a directory
  that no longer exists. `write_file()` throws, and **that save is outside** the
  `try/catch` at `classes/Dispatcher.php:364-368`, so the throw escapes `tick()`
  uncaught and the endpoint returns a 500.
- If `cancel`'s artifact deletion runs before `Artifact_Builder::publish()`'s
  `rename()` at `classes/Artifact_Builder.php:353`, the tick publishes a sealed
  artifact into the served directory for a job whose record is gone.

### Nothing reclaims an artifact with no record

`classes/Sweeper.php:145` iterates `$this->store->all()`. An artifact in the
served downloads directory with no corresponding job record is therefore
unreachable by the sweep, has no owner to scope a listing by, and survives until
uninstall.

### The listing cannot show terminal jobs

`classes/Rest/Extractions_Controller.php:286-296`:

```php
	public function list_jobs(): WP_REST_Response {

		// Scope the enumeration to the caller's own live jobs: skip another user's job
		// and any job that has reached a terminal state.
		$owner = get_current_user_id();
		$jobs = [];
		foreach ( $this->store->all() as $job ) {
			if ( $job->owner !== $owner || $job->state->is_terminal() ) {
				continue;
			}
```

`classes/Sweeper.php:184-185` names the consequence itself: failed-job residue is
"invisible to `GET /extractions`, which lists only non-terminal jobs, and cleared
only by uninstall".

### The privacy constraint

`classes/Rest/Extractions_Controller.php:695-712` (`resolve_owned_job()`) keeps
the non-owner refusal uniform so no endpoint becomes an existence oracle, and
ADR-0012 calls that out. **This plan does not touch it.** The listing is already
owner-scoped, and "is there sealed data of *mine* still here" is answered
entirely within that scope. Do not widen the listing to another user's jobs, and
do not report an owner's identity anywhere.

### The one record the sweep must never touch

`classes/Sweeper.php:203-207` spares the pre-adaptation stall, because that
record is the input the resume path re-drives (ADR-0015). Whatever you change in
the sweep must keep sparing it.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
English throughout; a `//` comment above each paragraph stating its *purpose*;
WordPress surface style (tabs, `snake_case`, spaces inside parentheses); complete
PHPDoc. `classes/Job_Store.php` and `classes/Sweeper.php` are heavily documented —
match their density.

From `CONTEXT.md`, the vocabulary to use:

> **Job state**: […] `cancelled` and `consumed` are distinct terminal ends:
> consume confirms a delivered artifact and is audited, whereas cancel discards
> the job at the caller's request and writes no audit record.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0 |
| Static analysis | `composer phpstan` | exit 0 |
| Integration suite | `composer test:integration` | exit 0, `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

## Scope

**In scope**:

- `classes/Rest/Extractions_Controller.php` — `consume()`, `cancel()`,
  `list_jobs()` and the route's args
- `classes/Job_Store.php` — an enumeration of artifacts with no record
- `classes/Sweeper.php` — reclaiming those artifacts
- `tests/Integration/consume-cancel-ttl-test.php` and
  `tests/Integration/extractions-list-test.php` — new assertions
- `docs/adr/` — one new ADR
- `CONTEXT.md`, `README.md`, `CHANGELOG.md`

**Out of scope** (do NOT touch):

- **`resolve_owned_job()` and the uniform non-owner refusal.** ADR-0012.
- **The pre-adaptation-stall exemption** at `classes/Sweeper.php:203-207`.
- The concurrency ceiling, `max_active_jobs`, or `has_free_slot()`. The
  check-then-act on the create path is a real, separate finding and is **not**
  this plan's.
- `API_VERSION`. The `state` parameter and the artifact-reclamation are additive
  and do not change the container, so under plan 014's split they are announced
  through the capability list. **Do not bump the integer.**
- Any job-management verb. No "delete all", no "force cancel", no admin surface.
- The audit log.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Suggested commits, one per step so a bisect can separate them:
  `Take the tick lock before purging a job, like every other actor`
  `Reclaim a published artifact whose job record is gone`
  `Let a caller list their own terminal jobs, so residue is visible`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Take the lock in `consume` and `cancel`

Wrap both `purge()` calls in `Job_Store::lock()` / `unlock()` with `try/finally`,
matching the discipline in `classes/Sweeper.php:159-168`.

Decide and implement the contract for a lock you cannot take:

- **cancel** on a job being ticked right now: answer `409` with a code naming the
  reason ("being built; retry"). That is honest and the caller can retry. Do not
  block waiting.
- **consume**: a consume can only race a tick on a `ready` job, which is already
  terminal for the builder, so taking the lock should not normally fail. If it
  does, `409` is right there too.

Add a paragraph comment on each explaining why the lock is taken — the reader
needs to know it guards a live driver's directory, not just concurrent callers.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `composer test:integration` → exit 0. `consume-cancel-ttl-test.php` must still
  pass unchanged.

### Step 2: Reclaim an artifact whose record is gone

In `classes/Job_Store.php`, add a method that enumerates artifacts in the served
downloads directory that have no corresponding job record. It must:

- resolve and pin every path inside the downloads directory the way
  `delete_artifact()` (`:711-727`) and `reclaim_staging()` (`:547-575`) already
  do, including the null-byte guard;
- never follow a symlink out of that directory;
- be bounded — do not build an unbounded list on a site with thousands of files.

In `classes/Sweeper.php`, reclaim them on the sweep's existing schedule. **Give
the orphan a grace period** rather than deleting on sight: an artifact can exist
momentarily before its record is saved, and deleting inside that window would
destroy a live job's output. Derive the grace period from an existing knob
(the TTL is the natural one) rather than inventing a constant — this project's
rule is that constants are measured, not chosen.

**Verify**:
- `composer phpcs`, `composer phpstan` → exit 0
- `composer test:integration` → exit 0

### Step 3: Let a caller see their own terminal jobs

Add a `state` query parameter to the `GET /extractions` route
(`classes/Rest/Extractions_Controller.php:127-131`). Register it with a proper
`args` schema — an enum validated by a `validate_callback`, not a free string.

- **Default: unchanged.** With no parameter, the listing returns exactly what it
  returns today — the caller's own non-terminal jobs. Every existing client keeps
  working and every existing test keeps passing.
- `state=all` additionally admits terminal jobs owned by the caller.
- Consider accepting a specific state name too, but only if it costs nothing; the
  question this exists to answer is "what of mine is still here", and `all`
  answers it.

Keep the owner scope exactly as it is. A terminal job of *another* user must
still be skipped.

For each terminal job listed, report enough to answer the exposure question and
no more: id, state, and the timestamps already carried. **Do not add a
`download_url`** — `list_jobs()` deliberately never returns one.

**Verify**:
- `composer phpcs`, `composer phpstan` → exit 0
- `composer test:integration` → exit 0, and `extractions-list-test.php` passes
  **unchanged**, which is the proof the default did not move

### Step 4: Announce the capability

Plan 014 adds the list of behaviours this build honours. Add the `state`
parameter's name to it, so a client can discover the filter rather than probing
for it.

If plan 014 has not landed, STOP — see STOP conditions. Do not bump
`API_VERSION` as a substitute.

**Verify**: `grep` the capability name in `classes/Rest/Status_Controller.php` →
present.

### Step 5: Add the assertions

Extend the two existing test files rather than creating new ones.

In `tests/Integration/consume-cancel-ttl-test.php`:

1. **A cancel cannot delete a job's directory while the lock is held.** Take the
   lock in-process (`flock` is per-open-file-description, so a second `fopen` +
   `flock(LOCK_EX|LOCK_NB)` in the same process genuinely fails — no second
   process needed), then cancel, and assert the `409` and that the directory
   survives.
2. **The same cancel succeeds once the lock is released** — the control that
   proves assertion 1 failed for the reason claimed and not by accident.
3. **An orphaned artifact is reclaimed.** Publish an artifact, delete its record
   directly, advance past the grace period, run the sweep, assert the artifact is
   gone.
4. **An artifact inside the grace period is NOT reclaimed.** This is the
   assertion that stops the fix from eating live jobs, and it matters more than
   assertion 3.
5. **The pre-adaptation stall is still spared** by the sweep. A regression guard
   on the one record that must never be reclaimed.

In `tests/Integration/extractions-list-test.php`:

6. **The default listing is byte-identical to today's** for the same fixtures.
7. **`state=all` includes the caller's terminal jobs** and still excludes another
   user's, terminal or not.
8. **No `download_url` appears** on any listed entry.
9. **A malformed `state` is a 400**, decided by the args schema.

**Verify**:
- `composer test:integration` → exit 0, with your new assertions in the TAP output
- Demonstrate the RED step for assertions 1 and 4: temporarily remove the lock,
  then temporarily remove the grace period, re-running each time and confirming
  the matching assertion reports `not ok`. Restore and re-run to green. **Record
  all runs in your report.**

### Step 6: ADR, glossary, README, changelog

- **ADR**: next-numbered in `docs/adr/`, existing naming convention, prose in the
  house voice. It must cover: that the lock has one owner and every purging actor
  now takes it; that an artifact with no record has no owner and therefore cannot
  be a listing's job, which is why reclamation is server-side; why the grace
  period exists; why the listing stayed owner-scoped rather than becoming an
  administrative view (cite ADR-0012); and that the `state` parameter is
  announced through the capability list rather than a version bump.
- **`CONTEXT.md`**: the **Job state** entry says `failed` "is also the only
  terminal state that leaves a record on disk". Check that against what you have
  built and update it if it has stopped being true.
- **`README.md`**: a short paragraph — how a caller asks what of theirs is still
  on the site.
- **`CHANGELOG.md`**: entries under `### Fixed` (the lock, the orphan) and
  `### Added` (the filter). State plainly that `api_version` does not move.

**Verify**: `composer gate` → exit 0; `git status --short` → only in-scope files.

## Test plan

- **Files**: `tests/Integration/consume-cancel-ttl-test.php` and
  `tests/Integration/extractions-list-test.php` (extend both).
- **Cases**: the nine assertions in step 5.
- **Pattern to follow**: the existing fixtures in both files; `Sweeper`'s
  existing tests for advancing time.
- **Verification**: `composer test:integration` → all pass, plus the two
  demonstrated failing runs.

## Done criteria

- [ ] `consume()` and `cancel()` both take and release the per-job lock — verify by reading
- [ ] A lock that cannot be taken yields 409, not a silent skip and not a wait
- [ ] The sweep reclaims an artifact with no record, and does not reclaim one inside the grace period
- [ ] The grace period is derived from an existing knob, not a new literal
- [ ] `tests/Integration/extractions-list-test.php` passes **unchanged** for the default listing
- [ ] `state=all` returns the caller's terminal jobs and no other user's
- [ ] No listed entry carries a `download_url`
- [ ] The pre-adaptation-stall exemption still holds, asserted by a test
- [ ] `grep -n 'API_VERSION = 6' classes/Rest/Status_Controller.php` still matches (unless plan 008 moved it; this plan does not)
- [ ] `composer phpcs`, `composer phpstan`, `composer test:integration`, `composer gate` all exit 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report contains both demonstrated failing runs
- [ ] `plans/README.md` status row for 013 updated

## STOP conditions

Stop and report back (do not improvise) if:

- Plan 014 has not landed. The `state` parameter needs somewhere to be
  announced, and bumping `API_VERSION` instead would trip the consuming client's
  ceiling for a REST-only change — exactly the conflation plan 014 exists to end.
- Taking the lock in `consume` or `cancel` deadlocks, or makes an existing test
  hang. That would mean the lock is already held somewhere you did not expect;
  report the call path rather than working around it.
- You cannot construct a grace period from an existing knob without inventing a
  constant.
- Reclaiming orphans deletes an artifact belonging to a live job in any test.
  Stop immediately — that is worse than the bug being fixed.
- You find yourself widening the listing beyond the caller's own jobs, reporting
  another user's identity, or adding an administrative verb.
- The pre-adaptation-stall exemption stops holding.

## Maintenance notes

- **What the consuming repository is building against this.** Their plan 005
  closes the cleanup handoff on every failure path, and it is deliberately
  written to work against production's current 0.4.0 — the `state` parameter is
  an optional, skippable step there. So this plan makes their sweep *better*, not
  possible. Do not assume they depend on it.
- **Their case analysis is worth knowing before you review this.** They
  distinguish a job that never reached ready (cancel it, nothing is lost), a
  ready job whose download succeeded but whose unseal failed (consume it, the
  local copy already exists), and a ready job whose **download** failed (do
  nothing — the production artifact may be the only copy of hours of extraction).
  Anything this plan does to reclamation must not make the third case worse; the
  grace period is what protects it.
- **Deliberately not fixed here**: the check-then-act on the concurrency slot at
  `classes/Rest/Extractions_Controller.php:230` versus `:241`, where the resume
  path already does the correct take-then-re-check dance at
  `classes/Dispatcher.php:853-858`. Real, verified, and a separate change.
- **What a reviewer should scrutinise**: that the default listing genuinely did
  not move; that every lock acquisition has a `finally`; and that the grace
  period cannot be zero.

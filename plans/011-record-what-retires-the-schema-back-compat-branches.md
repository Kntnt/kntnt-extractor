# Plan 011: Write down the single condition that retires the record-schema back-compatibility branches

> **Executor instructions**: Follow this plan step by step. **This plan deletes
> no code.** Its deliverable is an inventory and a written retirement condition.
> If anything in the "STOP conditions" section occurs, stop and report — do not
> improvise. When done, update the status row for this plan in
> `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Extraction_Job.php classes/Build_Progress.php classes/Sweeper.php classes/Crypto/Sealed_Writer.php docs/adr/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW — documentation only; no code is removed or changed
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

The persisted job record is at schema version 8, and `from_array()` carries
tolerant branches for every older shape — around ten of them across
`Extraction_Job` and `Build_Progress`, plus a whole migration path in
`Sealed_Writer::seed_index()` and its consumer in `Artifact_Builder`.

Ordinarily that debt retires itself: records are short-lived, so once no old
record can still exist on disk, the branches can go. Here one thing prevents
that. The TTL sweep deliberately **spares** a particular failed record — the
"pre-adaptation stall", identified by the absence of the schema-8 budget keys —
because that record is the input the resume path re-drives. A spared record has
no expiry, so the old shapes it may be written in stay reachable indefinitely.

The result is a cluster of branches that must be removed **together, on one
condition**, and nothing in the repository records what that condition is. The
code comment closest to saying it —
`classes/Crypto/Sealed_Writer.php:224`, "Schema 6 is the last shape that carried
the names, so this can be dropped once no such record can still be live" —
states the shape of the answer without saying who decides "no such record can
still be live", or that half a dozen other places must go at the same moment.

Deleting any one of them early silently abandons an in-flight upgrade: a
production site mid-recovery would have its stranded build made unrecoverable,
which is precisely what the resume path was built to prevent.

So the deliverable here is not a deletion. It is an inventory and one written
condition, so that whoever eventually does the deletion does it once, wholly,
and deliberately.

## Current state

### The exemption that keeps the old shapes alive

`classes/Sweeper.php:203-207`:

```php
	private function reclaimable( Extraction_Job $job ): bool {

		return ! $job->state->is_terminal()
			|| ( $job->state === Job_State::Failed && ! $job->is_pre_adaptation_stall() );

	}
```

`classes/Extraction_Job.php:384-388`:

```php
	public function is_pre_adaptation_stall(): bool {

		return $this->state === Job_State::Failed && $this->error !== null && ! $this->budget_keys_present;

	}
```

`classes/Sweeper.php:189-196` explains why the exemption exists:

> The one failure this must never touch is the stranded pre-adaptation stall
> ({@see Extraction_Job::is_pre_adaptation_stall()}): its container and progress
> are the input a resume re-drives (ADR-0015), and it is by definition older
> than any TTL, having been left behind by a release that is being upgraded away
> from. Sweeping it would delete the very thing the resume path was built to
> recover.

### The branches

`classes/Extraction_Job.php`, inside `from_array()` — each with a comment naming
the schema it tolerates:

- `:503` — `structure_only`, a schema-5 addition
- `:511` — `progress`, a schema-3 addition
- `:518` — `progressed_at`, a schema-4 addition
- `:526-527` — `attempts` and `error`, schema-6 additions
- `:538-543` — the schema-8 budget keys and `budget_keys_present`
- `:548-551` — the first-tick limit pair, a later addition to unreleased schema 8
- `:556` — `skipped_files`, a later addition to unreleased schema 8
- `:561` — `attempt_log`, a later addition to unreleased schema 8

`classes/Build_Progress.php`:

- `:159`/`:161` — the schema-6 `segment_names` (`legacy_names`)
- `:171` — the schema-6 `table_cursor`

`classes/Crypto/Sealed_Writer.php`:

- `:224` and the `seed_index()` method — the schema-6 migration that
  reconstructs the sidecar from names carried in the record. Its consumer is
  `classes/Artifact_Builder.php:193`.

### The reachability argument you must evaluate — not assume

There is an argument that the schema-3, schema-4 and schema-5 branches are
already unreachable in production: `is_pre_adaptation_stall()` requires
`error !== null`, and `error` is itself a schema-6 addition, so a record older
than schema 6 can never be spared and must therefore fall to the sweep's
absolute lifetime ceiling.

**Test that argument rather than repeating it.** In particular:

- The ceiling is only applied by a sweep that actually runs. It is driven by
  WP-Cron (`classes/Sweeper.php`, the recurring hook). On a site with cron
  disabled, or one where the plugin was deactivated for a long period, does
  anything else reclaim the record? Establish this from the code.
- `max_lifetime` is derived from the TTL (`classes/Sweeper.php:87`, `:101`) and
  measured from `progressed_at` — which is *itself* a schema-4 addition, so
  work out what the ceiling measures from for a schema-3 record.
- Is `from_array()` reached by anything other than reading a record from disk?

If the argument survives, say so and record it. If it does not, that is more
interesting and you should say why. **Either way, delete nothing.**

### The relevant rule

The project's working rule is that a change of substance costs a documentation
round — `CHANGELOG.md`, `CONTEXT.md`, an ADR, and on the client side a spec and
two skill documents — and that an item not worth paying that for should be
struck rather than carried. This plan is close to the cheapest end of that: one
ADR paragraph and one changelog line, buying a deletion that can then be done
safely and in one pass.

### Documentation conventions

- English throughout.
- **Keep each paragraph on a single physical line** in Markdown. Do not
  hard-wrap prose. Blank lines separate paragraphs; each list item is one line.
- ADRs are prose arguing a decision, not filled-in templates. Read two or three
  before writing.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate (nothing should change) | `composer gate` | exit 0 |
| Confirm no code changed | `git diff --stat classes/` | empty |

## Scope

**In scope**:

- `docs/adr/0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md`
  — one new Consequences bullet naming the retirement condition
- `CHANGELOG.md` (one entry under `[Unreleased]` → `### Changed`, or omit if you
  judge a documentation-only bookkeeping note does not warrant one — say which
  and why)
- Your report — the inventory

**Out of scope** (do NOT touch):

- **Deleting any branch, method, or migration path.** Not one. Not even the
  three that look provably dead. The whole point of this plan is that the
  deletion happens later, once, on a stated condition.
- `classes/Sweeper.php` — the exemption is correct and load-bearing.
- `classes/Extraction_Job.php`, `classes/Build_Progress.php`,
  `classes/Crypto/Sealed_Writer.php`, `classes/Artifact_Builder.php` — no code
  changes. You may **not** add comments to them either; the ADR is the single
  place this is recorded, and scattering the same note across five files is how
  it goes stale.
- `SCHEMA_VERSION` (`classes/Extraction_Job.php:58`). Do not bump it.
- Any behaviour, any test.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Name the one condition that retires the record-schema back-compatibility branches`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Build the inventory

For every branch listed above — and any you find that is not listed; search with
`grep -n 'schema-' classes/` and read `from_array()` in both classes in full —
record:

- the `file:line`
- which schema version it tolerates
- what a record missing that key reads as
- **whether a record of that shape can still exist on disk**, and the argument
  either way

Classify each into exactly one of:

- **Dead** — no record of that shape can still exist. Give the argument.
- **Load-bearing** — a spared pre-adaptation stall can be in that shape, so the
  branch is reachable for as long as the exemption exists.
- **Vestigial** — reachable only through a record written by an intermediate
  development build that was never released.

**Verify**: every branch in the list has exactly one classification and a
one-line argument.

### Step 2: Establish the retirement condition

Work out the single condition that makes the whole cluster removable at once.
It will have the shape "once no un-resumed pre-adaptation stall can remain on
any site running this plugin" — but state it in terms someone can actually
check, not in terms of a belief. Consider:

- What observable fact would tell an operator that no such record remains? A
  released version having been installed for longer than some interval? A poll
  returning nothing? Something else?
- Who can check it — the operator on their own sites, or nobody in general
  because the plugin ships to sites the author does not control?
- Is there a version after which the exemption itself can simply be deleted,
  making the question moot?

Be honest if the condition turns out to be uncheckable in general. That is a
useful finding: it would mean the cluster is effectively permanent unless the
exemption is given an expiry, and *that* would be the real recommendation.

**Verify**: the condition is stated in one or two sentences and names what
would be observed.

### Step 3: Write it into ADR-0015

Add one bullet to the `## Consequences` section of
`docs/adr/0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md`
(the section begins at `:47`). Match the existing bullets' voice — full
sentences explaining a consequence, not an instruction.

It must name:

- that the sweep exemption keeps the pre-adaptation record alive without expiry,
  and that this is what keeps the pre-schema-8 branches reachable;
- the full list of things that retire together — the exemption at
  `Sweeper::reclaimable()`, `Extraction_Job::is_pre_adaptation_stall()`, the
  resume path that reads it, `Sealed_Writer::seed_index()`, `Build_Progress`'s
  `legacy_names`, and the schema branches in `from_array()`;
- the condition from step 2;
- that they must go **together**, and what happens if one goes early — a site
  mid-upgrade loses a stranded build the resume path exists to recover.

ADR-0015 is where this belongs because the exemption is its decision. Do not
create a new ADR.

**Verify**:
- `grep -c 'seed_index' docs/adr/0015-*.md` → at least 1
- `git diff --stat classes/` → empty

### Step 4: Changelog and gate

If you judge a bookkeeping note warrants a changelog entry, add one under
`### Changed` in `[Unreleased]` (heading at `CHANGELOG.md:25`). If you judge it
does not, say so in your report with your reason. Either is defensible; what is
not defensible is doing it without deciding.

**Verify**:
- `composer gate` → exit 0
- `git status --short` → only `docs/adr/0015-*.md` and possibly `CHANGELOG.md`

## Test plan

No automated tests. This plan changes no behaviour.

The verification that matters is the inventory's correctness, and the way to
check it is adversarial: for each branch you classified **Dead**, try to
construct a scenario in which a record of that shape survives on disk. Cron
disabled, plugin deactivated for months, a site restored from an old backup, a
job directory copied between environments. If you can construct one, the branch
is not dead. Report what you tried.

## Done criteria

- [ ] Every schema branch in `Extraction_Job::from_array()` and `Build_Progress::from_array()` appears in the inventory with a `file:line`, a schema version and a classification
- [ ] `Sealed_Writer::seed_index()` and its consumer in `Artifact_Builder` appear in the inventory
- [ ] Each **Dead** classification carries an argument, and your report says what you tried in order to falsify it
- [ ] The retirement condition is stated in one or two sentences and names an observable
- [ ] `docs/adr/0015-*.md` has one new Consequences bullet naming the whole cluster and the condition
- [ ] `git diff --stat classes/` is **empty**
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only `docs/adr/0015-*.md` and possibly `CHANGELOG.md`
- [ ] `plans/README.md` status row for 011 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at `classes/Sweeper.php:203-207` or
  `classes/Extraction_Job.php:384-388` does not match the excerpts above.
- You conclude the retirement condition is uncheckable in general — that a
  plugin shipped to sites the author does not control can never establish that
  no spared record remains. Report it as the finding it is, together with what
  giving the exemption an expiry would cost. **Do not design that expiry
  here**; it is a behaviour change in the adaptation family, which the project's
  own rules put behind an outstanding measurement.
- You find a branch that is reachable and *also* incorrect — that is, a record
  shape that parses but produces a wrong job. That is a bug, not bookkeeping,
  and it outranks this plan.
- You find yourself deleting code, bumping `SCHEMA_VERSION`, or editing a file
  under `classes/`.

## Maintenance notes

- **What this plan sets up**: a later deletion that can be done in one pass,
  once, against a stated condition. Expect it to *remove* considerably more than
  it adds — the exemption, the predicate, the resume path, `seed_index()`,
  `legacy_names`, and the schema branches all go together.
- **What a reviewer should scrutinise**: whether each **Dead** classification
  survives an adversarial reading, particularly the assumption that the TTL
  sweep has actually run on every site.
- **Related, deliberately out of scope**: the record grows a new tolerant branch
  every time a field is added, and three of the current ones exist only for
  unreleased development builds. Whether those three can be dropped at the
  moment of the next release — when no released version ever wrote them — is a
  smaller and much easier question than the cluster above, and worth asking
  separately.

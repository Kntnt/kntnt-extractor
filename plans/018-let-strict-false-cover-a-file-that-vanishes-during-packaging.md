# Plan 018: Let `strict: false` cover a file that vanishes *during* packaging, not only before

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan in
> `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat <the SHA in "Planned at">..HEAD -- classes/Artifact_Builder.php classes/Dispatcher.php classes/Extraction_Job.php classes/Rest/Extractions_Controller.php`
> On any change, compare the "Current state" excerpts against the live code
> first; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED
- **Depends on**: `plans/017-record-what-an-opaque-failure-actually-was.md` — **soft, but do 017 first.** 017 is what turns the next occurrence of this from an inference into a fact, and it is the smaller change. This plan is written from an inference; 017 is what would have made it evidence.
- **Category**: bug
- **Planned at**: commit `00f7532`, 2026-08-19
- **Evidence**: `docs/measurements/2026-08-18-production-run.md` §3

## Why this matters

A production extraction failed on 2026-08-18 after six hours, at 47,504 of 48,559 files — **97.8 %** — and everything packaged was discarded at fail time. The last chunk begun, nine seconds before the job died, was `wp-content/uploads/2026/08/kntnt-extractor.zip`. That file was present in the `GET /files` walk at the start of the run and returns `404` today while its neighbours in the same directory return `200`. The most likely cause is WordPress's own upgrade cleanup removing the release archive that installed the very Extractor build the run depended on — hours into the run, on a live site that does not stop being live because an extraction is in progress.

`strict: false` exists precisely so that a vanished file is a reported skip rather than a fatal error. It was submitted, and it did not help, because it is applied **only when the job is created**. A file that disappears between creation and the moment its chunk is packaged is outside its reach.

The asymmetry is the whole finding: a manifest is a snapshot, a live site is not, and the longer the run the wider the window. On a six-hour extraction of 48,559 files that window is the entire run. The create-time filter closes the gap between the `GET /files` walk and the `POST`; nothing closes the much larger gap between the `POST` and the last chunk.

## What this does not fix

- It does not make the failed run recoverable. That container is gone.
- It does not stop a file from vanishing. Nothing server-side can.
- **It does not make a vanished file's absence harmless to the copy.** A skipped file is missing from the artifact, and the caller learns which — that is the contract `strict: false` already sets, and this plan extends its reach rather than improving its guarantee.
- It does not address the per-part cost in `docs/measurements/2026-08-18-production-run.md` §1, which is the other half of what that run found and is untouched here.

## Current state

### The contract as it stands

`POST /extractions` accepts `strict`. Under `strict: false` the create path drops files that no longer exist and returns them in the `201` body's `skipped_files`; a missing *table* is still a hard `404`, because silence there would be data loss. The job record carries the skipped list and the poll re-reports it. `skipped_files` is named in `Status_Controller::HONOURED_BEHAVIOURS`, so a caller can discover the behaviour by name.

Read `CHANGELOG.md`'s `[0.6.0]` entry for `strict` and the `404`-naming entry beside it before starting — they state the intended semantics, and this plan must not change them, only widen where they apply.

### What you must find first

This plan deliberately does not name the line to edit. Find the packaging path that reads a file part and decide what it does today when the file is gone:

```
grep -n "fopen\|fread\|filesize\|is_readable\|realpath" classes/Artifact_Builder.php
grep -rn "strict" classes/ | grep -v Rest/
```

Two things must be established before you write anything, and both are STOP conditions if they come out differently from the expectation below:

1. **Where the read fails.** Plan 005 made a failed or empty file read an error rather than a silently empty segment — deliberately, and that decision stands. This plan does not undo it: an unreadable file is still an error when `strict` is true. What must change is only the `strict: false` case.
2. **Whether the packaging path can even see `strict`.** The flag is a create-time concern today. If the job record does not carry it, the record needs the field — and the record is at schema 8 and **released** as of 0.6.0, so that is a schema bump and no longer free (rule R5). If a bump is required, STOP and report rather than taking it silently.

   **Amended 2026-08-21 by the session executing this plan (#31), and this is the one amendment that changed what was built.** The record does not carry `strict`, so a field was needed — and no bump was required, so the STOP condition below did not fire. Its premise was overtaken by the two decisions that landed between this plan being written (commit `00f7532`, 2026-08-19) and being executed. **ADR-0024** removed compatibility with this plugin's own earlier records as a constraint entirely — one site, no migration, ever — so a bump would signal to a reader that does not exist. And **#25**, this plan's own named dependency, had already added `thrown` to schema 8 *after* 0.6.0 shipped, on a reading `SCHEMA_VERSION`'s own docblock now states: an additive key read tolerantly, whose absence is the ordinary shape of a record that never had it, does not move the number. `strict` is written only when `false`, and an absent or ill-typed key reads as `true` — the hard fail every record written before this change already had. `rule R5`, cited above, exists nowhere in this repository. The reasoning is in ADR-0026; `SCHEMA_VERSION` stays `8`.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Gate | `composer gate` | exit 0 (about five minutes) |
| MySQL suite | `composer test:integration:mysql` | exit 0 (needs Docker + DDEV) |
| Lint | `composer phpcs` | exit 0 |

Redirect the gate to a file and capture `$?` on its own line — `composer gate | tail` reports `tail`'s status, and that has produced one false green in this project already.

## Scope

**In scope**:

- `classes/Artifact_Builder.php` — the packaging read path
- `classes/Dispatcher.php` — if the skip must be recorded from there
- `classes/Extraction_Job.php` — only if `skipped_files` needs to be appendable mid-run
- `tests/Integration/` — new coverage
- `docs/adr/` — an amendment to whichever ADR states the `strict` contract
- `CHANGELOG.md`

**Out of scope**:

- The `strict: true` behaviour. A vanished file under `strict` stays a hard failure; that is the point of the flag.
- Missing **tables**. Unchanged, always fatal, both at create time and during packaging.
- The traversal case. A path that fails to resolve is treated as out of bounds and is **never** a skip — that is a settled decision and this plan must not erode it. Check how the create path distinguishes "gone" from "out of bounds" and preserve exactly that distinction in the packaging path.
- `API_VERSION`. This widens where an announced behaviour applies; it adds no member and removes none. **The integer stays 7.**
- The fail-time discard of the container.

## Git workflow

Trunk-based: commit straight to `main`, no branch, no PR. Do not push, tag, or bump a version.

## Steps

### Step 1: Skip a vanished part instead of failing the job

In the packaging read path, distinguish **"this file no longer exists"** from **"this file exists and could not be read"**. They are different facts today only by accident; make the difference explicit.

Under `strict: false`, a file that no longer exists is skipped: no segment is written for it, the job continues to the next chunk, and the path is appended to the job's skipped list so the poll reports it exactly as a create-time skip is reported. Under `strict: true`, and for an existing-but-unreadable file in either mode, the current failure behaviour is unchanged.

Two details that decide whether this is correct or merely plausible:

- **A partially packaged file.** If the file vanishes between two parts — which is exactly what a multi-part file makes possible, and the observed run had three of them — a segment for the earlier parts is already in the container. Decide what a skip means there and say so in the code: either the partial segments stay and the file is reported skipped (the artifact then holds a truncated file, which a reader cannot detect), or the file's already-written parts make it un-skippable and the job fails. **Prefer the second**, and state the reason in the docblock: a silently truncated file in the artifact is the failure mode this project has repeatedly chosen to fail loudly rather than accept, and `docs/container-format.md` gives a reader no way to notice one.
- **The skip must be durable.** It is recorded mid-run, so it must survive the tick that records it. Follow how the record's other mid-run mutations are persisted rather than inventing a path.

**Verify**: `composer phpcs` → exit 0; `composer phpstan` → exit 0.

### Step 2: Cover it

Add integration coverage under `tests/Integration/`. Find the pattern first — `grep -ln "strict" tests/Integration/*.php` — and model on the closest existing `strict` test.

Assert, at minimum:

1. A job submitted with `strict: false` whose file is deleted **after** the job is created, before its chunk is packaged, reaches `ready` rather than `failed`.
2. The vanished path appears in the poll's `skipped_files`.
3. The artifact contains every other selected file — the skip removed one segment, not the tail of the run.
4. The same deletion under `strict: true` still fails the job.
5. A file that exists but cannot be read still fails, in both modes — the plan-005 guarantee is intact.
6. If you took the "un-skippable once partially packaged" branch in step 1, a file deleted between two parts fails rather than producing a truncated segment.

Assertion 1 is the regression this plan exists to prevent. Assertion 5 is the one that proves it did not erode an earlier decision to get there.

**Verify**: `composer gate` → exit 0. Then `composer test:integration:mysql` → exit 0, since the packaging path is shared with the table dumper and this is a required pre-tag step regardless.

### Step 3: Pay the documentation round

1. **The ADR that states the `strict` contract.** Amend it: the flag's reach now extends from create time to the whole run, with the partial-file decision from step 1 recorded and argued, not merely stated.
2. **`CHANGELOG.md`**, `### Fixed` under `[Unreleased]`. Give the concrete cost — a six-hour run failed at 97.8 % because a file vanished mid-run and `strict: false` did not reach that far — and name what it does not fix, per this plan's own section. Reference `docs/measurements/2026-08-18-production-run.md`.
3. **`Status_Controller::HONOURED_BEHAVIOURS`** — read it and decide whether `skipped_files` still describes what this build honours, or whether a caller needs a way to tell a build whose skip reaches the whole run from one whose skip stops at create time. **This is a real question, not a formality**: an old client cannot distinguish the two, and the release procedure's §3 step 3 exists because exactly this kind of omission is invisible to every automated check. If you conclude a name is needed, add it; if you conclude the existing name suffices, say why in the ADR.

**Verify**: `composer gate` → exit 0, exit code captured on its own line.

## Done criteria

ALL must hold:

- [ ] `composer gate` exits 0, verified as the gate's own exit code
- [ ] `composer test:integration:mysql` exits 0
- [ ] The new tests cover assertions 1–5 (and 6 if that branch was taken)
- [ ] `grep -n "API_VERSION" classes/Rest/Status_Controller.php` still reads 7
- [ ] `git diff --stat` lists only files in the In-scope list
- [ ] The `strict` ADR carries the amendment; `CHANGELOG.md` carries the entry
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The drift check reports changes and the excerpts no longer match.
- **The job record does not carry `strict` and would need a new field.** Schema 8 is released; that is a bump, and it is the operator's call. — **Did not fire (2026-08-21, #31).** The field was needed and the bump was not; see the amendment in "What you must find first" above and ADR-0026.
- You cannot distinguish "gone" from "out of bounds" in the packaging path without weakening the traversal rule. That rule is settled and this plan must not touch it.
- An existing plan-005 assertion changes behaviour — an unreadable-but-present file must still fail.
- You conclude a partially packaged file should be skipped with its earlier segments left in the container. That produces a silently truncated file in the artifact, and it is a decision against this project's stated preference for loud failure; report rather than shipping it.

## Maintenance notes

- **What a reviewer should scrutinise**: that `strict: true` is byte-for-byte unchanged; that an unreadable-but-present file still fails; that the traversal case is still never a skip; and that the mid-run skip is persisted the same way every other mid-run record mutation is.
- **The window this narrows but does not close.** A file can still vanish between the moment the packaging path checks for it and the moment it reads it. That race is unavoidable without holding an open handle across the check, and the practical remedy is the same skip, reached one layer further in — worth knowing before someone reports it as a new bug.
- **Related and unaddressed**: the per-part cost that made this run six hours long in the first place, `docs/measurements/2026-08-18-production-run.md` §1. Three files took 44 % of the wall clock. A shorter run is also a smaller window for this bug.

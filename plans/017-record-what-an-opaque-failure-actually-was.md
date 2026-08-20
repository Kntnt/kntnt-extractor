# Plan 017: Record what an opaque failure actually was

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan in
> `plans/README.md`.
>
> **Drift check (run first)**:
> `git diff --stat <the SHA in "Planned at">..HEAD -- classes/Dispatcher.php classes/Extraction_Job.php classes/Rest/Extractions_Controller.php`
> On any change, compare the "Current state" excerpts against the live code
> before proceeding; on a mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `000abca`, 2026-08-19
- **Evidence**: `docs/measurements/2026-08-18-production-run.md` §3

## Why this matters

A production extraction failed on 2026-08-18 after six hours, at 47,504 of 48,559 files — 97.8 % — and the entire record of why is one sentence: `The extraction failed.` That sentence is not a message the plugin wrote. It is `Extractions_Controller::error_of()`'s fallback for a job whose `error` is null, which is what an unexpected throw leaves behind.

Three things follow from that null, and they compound:

- No stall reason was written, so the two-pair limit reading ADR-0015 built — the host's configured `memory_limit` and `max_execution_time` against the ones in force after the plugin asked for more — does not exist for this failure. That pair is the project's cheapest diagnostic and its only means of telling a PHP ceiling from a kill above PHP, and **the failure mode that actually ends runs on this host bypasses it entirely.**
- ADR-0015 never re-drives an opaque failure, correctly, because it would retry a permanent error forever. So the run is not resumable.
- This release discards the container and its sidecar at fail time, so six hours of packaging were destroyed by a failure that recorded nothing about itself.

The adaptation family worked during that run — part sizes in the `attempts` log were 4 MiB, exactly half the default, so a stall was detected and the budget halved, and three large files were survived rather than fatal. What killed the run was something else, and the plugin has no opinion about what.

This plan does not diagnose that failure. It makes the next one diagnosable, which is a different and much smaller job.

## What this does not fix

Read this before believing the plan closes the finding.

- It does not prevent the failure, identify it, or make the run resumable. A recorded throw is still a failed run and still a discarded container.
- It does not recover the 2026-08-18 failure. That record is gone and its container was discarded.
- It records what the plugin's own code can see. A failure that kills the PHP process outright — an OOM kill from the container, a worker reaped by LiteSpeed — never reaches a `catch` block, so it will still record nothing. **This plan improves the case where PHP threw and the plugin caught it; it cannot improve the case where PHP died.** Distinguishing the two is exactly what the recorded message will let a reader do for the first time: a failure with a recorded throwable is the former, a failure still showing a null `error` is the latter, and today those two are indistinguishable.

## Current state

### Where the null comes from

`classes/Rest/Extractions_Controller.php`, `error_of()`:

```php
	return $job->state === Job_State::Failed
		? [ 'message' => $job->error ?? __( 'The extraction failed.', 'kntnt-extractor' ) ]
		: null;
```

So the poll's `error.message` is `$job->error` when set, and a fallback string when not. The observed production failure used the fallback.

### Where a diagnosed failure is written

`classes/Dispatcher.php` builds the stall reason at roughly `:621-628` — a `__()` string with six placeholders naming the two limit pairs, then written onto the job. Read that method and its neighbours before starting: **the failing path you are adding to must write `error` the same way the stall path does**, so both reach `error_of()` identically and no caller needs to learn a second shape.

### What you must find first

This plan deliberately does not name the `catch` block to edit, because naming one from outside the code is how a plan sends an executor to the wrong place. Find it:

```
grep -n "catch" classes/Dispatcher.php classes/Artifact_Builder.php classes/Job_Store.php
grep -rn "Job_State::Failed" classes/ | grep -v Rest/
```

There is a path that marks a job failed without composing a reason — that is the one. If there is more than one, they all need the same treatment, and if there is none, the throw is escaping to somewhere else entirely and that is a STOP condition, because it changes what this plan is.

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Gate | `composer gate` | exit 0 (about five minutes) |
| Lint only | `composer phpcs` | exit 0 |
| Static analysis | `composer phpstan` | exit 0 |

Redirect the gate to a file and capture `$?` on its own line — `composer gate | tail` reports `tail`'s status, and that has already produced one false green in this project.

## Scope

**In scope**:

- The class carrying the unhandled-throw path you identified (expected: `classes/Dispatcher.php`)
- `classes/Extraction_Job.php` if the record needs a field it does not have
- `tests/Integration/` — one new test file, or an addition to the nearest existing failure test
- `docs/adr/0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md`
- `CHANGELOG.md`

**Out of scope**:

- The poll contract. The member is already `error.message` and already carries one string; this plan fills a field it resolves rather than changing its shape. **`api_version` does not move.** *(Amended 2026-08-21: `Extractions_Controller::error_of()` itself is in scope after all, by one `??` — step 1's first rule puts the thrown reason in a field of its own, so the method resolves the plugin's own diagnosis, then that one, then the same fallback. The contract it answers is what stays out of scope, and it does.)*
- The resume path. An opaque failure stays un-re-driven; recording what it was does not make it safe to retry, and arguing otherwise is a separate decision against ADR-0015.
- The fail-time discard of the container. Whether a diagnosable failure should keep its staging is a real question and a different plan — see "Maintenance notes".
- Anything in the adaptation family. Rule R1 in `~/Projects/kntnt-transfer-engine-open-work.md` still binds, and this plan is deliberately outside it: it adds no knob, no lever, and no recovery.

## Git workflow

Trunk-based: commit straight to `main`, no branch, no PR. Do not push, tag, or bump a version.

## Steps

### Step 1: Record the throwable on the job

At the path identified in "Current state", compose a reason from the caught throwable instead of recording nothing. It must carry, at minimum, the throwable's **class**, its **message**, and the **file and line** it came from. Follow the stall reason's own convention for how the string is *built*, so `error_of()` resolves it with no change of shape.

Four rules on content and destination, all load-bearing:

1. **Write it to a field of its own; `error` stays null for a throw.** *(Amended 2026-08-21, from #25's verification — the first execution of this plan wrote it into `error` and failed verification on exactly this.)* `error` is contractually the reason **the plugin diagnosed itself**, and its nullity is read as a signal by two subsystems that have nothing to do with the poll: `Extraction_Job::is_pre_adaptation_stall()` treats a non-null `error` as "a stall was diagnosed here", and both `Dispatcher::is_resumable()` and `Sweeper::reclaimable()` act on the answer. A record rebuilt from a 0.5.1-or-earlier write keeps the schema-8 budget keys absent for the rest of its life, so a throw that filled `error` turned exactly that record into the one failure this release re-drives. "Follow the stall reason's own convention" above is about how the string is *composed*, and reads naturally as "store it where the stall stores it" — which is the mistake.
2. **The message is operator-facing and may be read by a caller over REST.** A throwable's message can carry a path or a query fragment. Truncate to a bounded length and do not include a stack trace — `Job_Store` already treats what rides on the record as caller-visible, and the poll returns this field to anyone who can read the job. What the relayed message may disclose is decided, deliberately and in writing, in ADR-0022.
3. **Name the origin relative to the installation root.** *(Amended 2026-08-21, same source.)* "The file and line" is satisfied exactly as well by a root-relative path, at no cost, and an absolute one would have the same sentence disclose one file root-relative — the chunk, via `stalled_chunk()` — and another absolutely, by two rules with a reason for neither.
4. **Say what it is.** Prefix the recorded reason so a reader can tell this class of failure from a stall at a glance, in the same register as the stall reason's opening: the extraction failed with an unexpected error rather than by exhausting its budgets, this is not a resumable state, and the container has been discarded.

Also record the chunk being attempted when the throw happened, if the failing path has it in hand. The `attempts` log is bounded at eight entries and is purged with the record, so the reason is the only place this survives — and on the observed failure, knowing it died at byte 0 of a specific `.mp4` was the single most useful fact available.

**Verify**: `composer phpstan` → exit 0, and `composer phpcs` → exit 0.

### Step 2: Test that a throw is recorded, not swallowed

Add an integration test in the style of the existing failure tests under `tests/Integration/`. Find the pattern first — `grep -ln "Job_State::Failed\|'failed'" tests/Integration/*.php` — and model the new test on the closest match rather than inventing a harness.

It must assert:

1. A job whose packaging throws reaches `failed`.
2. Its poll body's `error.message` is **not** the `The extraction failed.` fallback.
3. The message names the throwable's class and its own message text.
4. The message does not contain a stack trace, and is within the bound step 1 set.

Assertion 2 is the regression this plan exists to prevent, and it is the one that would have failed before the change. Write it so it fails for the right reason: assert on the absence of the fallback string specifically, not merely on the message being non-empty.

**Verify**: run the integration suite. Expect the new assertions to pass and no existing test to change behaviour — a stall's reason is untouched by this plan, so every existing stall assertion must still hold verbatim.

### Step 3: Pay the documentation round

1. **ADR-0015.** It already distinguishes an opaque failure from a diagnosed stall, and already says why the former is never re-driven. Add a consequence recording that an opaque failure now records what it was, that this does not make it resumable, and that a failure still showing a null `error` after this change means PHP died rather than threw — the distinction the recorded message buys, and the reason the fallback string must stay rather than being replaced with something that looks diagnostic.
2. **`CHANGELOG.md`.** A `### Fixed` entry under `[Unreleased]`. State the concrete cost: a production run failed at 97.8 % after six hours and recorded one sentence about why, because the field the poll reads was null. Reference `docs/measurements/2026-08-18-production-run.md`. Say plainly what it does not fix — a process killed outright still records nothing — rather than letting the entry imply every failure is now diagnosable.

**Verify**: `composer gate` → exit 0, with the exit code captured on its own line.

## Done criteria

ALL must hold:

- [ ] `composer gate` exits 0, verified as the gate's own exit code
- [ ] `grep -n "The extraction failed." classes/Rest/Extractions_Controller.php` still returns the fallback — it is kept deliberately
- [ ] The new integration test asserts the fallback string is absent from a thrown failure's message
- [ ] `grep -rn "API_VERSION" classes/Rest/Status_Controller.php` still reads 7
- [ ] `git diff --stat` lists only files in the In-scope list
- [ ] ADR-0015 carries the new consequence; `CHANGELOG.md` carries the entry
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report if:

- The drift check reports changes to any in-scope file and the excerpts no longer match.
- **You cannot find a path that marks a job failed without composing a reason.** That would mean the throw escapes the plugin entirely, which makes this a different plan — report what you found instead of inventing a `catch`.
- ~~Recording the throwable requires a new field on the job record. The record is at schema 8 and released as of 0.6.0, so a new field is a schema bump and no longer free — report rather than bumping.~~ **This condition fired, was reported, and is settled on #25** *(2026-08-21)*: it does require one, because step 1's first rule above says `error` is not the destination. The field is added to schema 8 without a bump, because its absence is the ordinary shape of a record that has not failed that way rather than a signal about the release that wrote it — the same tolerant, non-legacy-signalling branch `skipped_files` and `attempt_log` already are, which ADR-0015's own Consequences bullet lists as outside the cluster that retires together. `API_VERSION` does not move either (ADR-0017/0018): nothing about the artifact's shape changes and the poll still returns one string.
- An existing stall test changes behaviour. The stall path must be untouched.
- You conclude the container should be kept for a diagnosable failure. That is a real question and a defensible position, and it is out of scope here — report it.

## Maintenance notes

- **What a reviewer should scrutinise**: that the recorded message is bounded and trace-free, since it leaves the server over REST; that the stall reason's own path is byte-identical to before; and that the fallback string survives, because its appearance after this change is now itself a diagnostic signal.
- **The follow-up this plan deliberately does not take**: whether a failure that recorded a diagnosis should keep its container instead of discarding it at fail time. The 2026-08-18 run destroyed six hours of packaging on a failure it could not describe; a failure it *can* describe might be worth resuming from, but that reopens ADR-0015's resume condition and needs its own plan and its own argument.
- **Related and larger**: the per-part cost in `docs/measurements/2026-08-18-production-run.md` §1 — three files consuming 44 % of a run — is the other half of what that measurement found, and is not addressed here at all.

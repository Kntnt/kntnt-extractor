# Plan 010: Decide what a refused create should tell the caller about the occupied slot

> **Executor instructions**: This is a **design plan**. Its deliverable is a
> written decision and a recommendation, not a feature. Do not implement the
> change. Follow the steps, answer every question in "Questions the decision
> must answer", and produce the artifact described in step 5. If anything in
> the "STOP conditions" section occurs, stop and report. When done, update the
> status row for this plan in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Rest/Extractions_Controller.php classes/Job_Store.php classes/Sweeper.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S (the design); the implementation it recommends is S–M and is **not** part of this plan
- **Risk**: LOW — produces a document, changes no code
- **Depends on**: none
- **Category**: direction
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

This plugin allows one extraction at a time, site-wide. When a create is
refused because the slot is taken, the caller gets a bare 429 with a sentence of
prose, and no endpoint that can tell them what is holding the slot or how far
along it is.

The asymmetry is structural, not accidental: the **ceiling is global** — it
counts every job on the site, whoever owns it — while the **listing is
owner-scoped and live-only**, skipping another user's jobs and every terminal
job. So an administrator refused with 429 has no way, through the API, to see
what they are waiting for. Diagnosing it requires filesystem access to the
server.

The primary consumer runs unattended: a clone or pull driven by an agent over
hours. For that caller a 429 is a dead end — it cannot tell "someone else's job
is 80% done, wait" from "a wedged job is holding the slot, intervene".

The repository has noticed the same shape from the other side.
`classes/Sweeper.php:184-185` records that failed-job residue was "invisible to
`GET /extractions`, which lists only non-terminal jobs, and cleared only by
uninstall".

**This is a design question, not a defect.** There are at least two reasonable
answers with different costs, and one of them has a privacy constraint that is
easy to get wrong. Deciding it on paper first, and cheaply, is the point. It is
entirely possible that the right answer is "do nothing" — the demand here is
inferred from the code's own comments rather than from a stated user need, and
"not worth doing" is a welcome outcome.

## Current state

### The ceiling counts every job on the site

`classes/Job_Store.php:350-354`:

```php
	public function has_free_slot( int $already_taken = 0 ): bool {

		return ( $this->count_active() - $already_taken ) < $this->max_active_jobs();

	}
```

`count_active()` walks every job directory on disk. It is not scoped to a user.

### The listing is scoped to the caller's own live jobs

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

### The refusal

`classes/Rest/Extractions_Controller.php:228-236`:

```php
		// Enforce the global concurrency ceiling: a create beyond it is a 429, the
		// caller's cue to poll or consume the active job before starting another.
		if ( ! $this->store->has_free_slot() ) {
			return new WP_Error(
				'kntnt_extractor_too_many_jobs',
				__( 'Another extraction is already in progress. Wait for it to finish before starting another.', 'kntnt-extractor' ),
				[ 'status' => 429 ],
			);
		}
```

Note the comment's advice — "poll or consume the active job" — is advice the
caller often cannot act on, because the active job may not be theirs and
therefore does not appear in their listing.

### The privacy constraint that shapes every option

`classes/Rest/Extractions_Controller.php:695-712` (`resolve_owned_job()`)
deliberately keeps the non-owner refusal uniform so that no endpoint becomes an
existence oracle, and ADR-0012 calls this out explicitly. Any option that
reports *who* owns the occupying job weakens that. Reporting the job's
**existence, state and progress** without its owner's identity does not — but
this must be reasoned about, not assumed.

Note also that the job id is `bin2hex( random_bytes( 16 ) )`
(`classes/Job_Store.php:189`) and is used as an unguessable handle elsewhere.
Whether disclosing another user's job id to a capable caller matters is one of
the questions below.

### The two candidate designs

**(a) Enrich the 429.** Add the occupying job's id, state and `progressed_at`
to the error's `data`. Small, answers the actual question, and additive — under
the rule ADR-0003 and ADR-0016 apply, new members on an existing response do not
move `api_version`, so **no coordinated client release**.

**(b) Admit terminal jobs to `GET /extractions` behind a `state` query
parameter.** Larger. Also solves the invisible-failed-residue problem
`Sweeper.php:184` names. Still additive if the default behaviour is unchanged.

These are not exclusive. (a) is the smaller answer to the narrower question.

## Questions the decision must answer

Answer each explicitly and with a reason. These are the substance of the
deliverable.

1. **Is there a real demand?** The evidence in this repository is two code
   comments noticing the asymmetry, not a reported user problem. Check whether
   `kntnt-wp-skills` actually hits this — does its clone or pull flow ever
   receive a 429, and what does it do with one? If it never does, the honest
   answer to this whole plan may be "not worth doing", and that is a fine
   outcome. Say so plainly if you conclude it.
2. **What does the caller actually need to decide?** Distinguish "wait" from
   "intervene". Which fields answer that, minimally? Is `progressed_at` enough,
   or is a chunk counter needed?
3. **Does disclosing the occupying job's id to a capable non-owner weaken
   anything?** Both capabilities are required to reach this endpoint at all, so
   the caller is already an administrator. Reason about it against ADR-0012 and
   the uniform-refusal rule, and state the conclusion.
4. **Owner identity: in or out?** Recommendation is out. Justify whichever you
   pick against ADR-0012's Consequences.
5. **Does either option move `api_version`?** Work it through against
   `CONTEXT.md`'s definition and ADR-0016's precedent (a new optional member on
   an existing response did *not* move it). If the answer is that it does, the
   cost calculus changes completely, because a bump forces a coordinated release
   of both repositories plus a manual production install.
6. **Does option (b) subsume the failed-residue visibility problem**
   `Sweeper.php:184` names, and does that change the recommendation?
7. **What is the documentation tax?** This project's rule is that a change of
   substance touches `CHANGELOG.md`, `CONTEXT.md`, an ADR, and on the client
   side a spec and two skill documents. Price each option including that tax,
   and say whether the payoff justifies it. An item not worth paying it for
   should be struck rather than carried.

## Scope

**In scope**:

- Reading code, ADRs, `CONTEXT.md`, and the consumer's flow
- `docs/adr/` — **only if** the outcome is a decision to implement; see step 5
- `plans/010-decide-what-a-429-tells-the-caller.md` — append your findings, or
  produce a separate short document; say which

**Out of scope** (do NOT touch):

- **Any production code.** This plan implements nothing. If you find yourself
  editing `classes/`, stop.
- Changing the concurrency ceiling, `max_active_jobs`, or how it is counted.
- Making the listing unscoped by default. Owner-scoping is deliberate.
- Revealing an owner's `user_login` anywhere.
- The `kntnt-wp-skills` repository. Read it if it is available at
  `~/Projects/kntnt-wp-skills` to answer question 1; change nothing in it.

## Steps

### Step 1: Establish the facts

Read, and note file and line for each conclusion:

- `classes/Rest/Extractions_Controller.php` — `create()`, `list_jobs()`,
  `poll()`, `resolve_owned_job()`
- `classes/Job_Store.php` — `has_free_slot()`, `count_active()`, `all()`
- `classes/Sweeper.php:178-207` — the residue reasoning
- `docs/adr/0003`, `docs/adr/0012`, `docs/adr/0016`
- `CONTEXT.md` — the `Job state`, `API version` and `Extraction job` entries

### Step 2: Check whether the consumer ever hits this

If `~/Projects/kntnt-wp-skills` is readable, find where it submits an extraction
and what it does with a 429. Report what you found. If the repository is not
available, say so — do not speculate about its behaviour.

### Step 3: Answer the seven questions

Each with a reason and, where applicable, a `file:line` citation.

### Step 4: Recommend one option, or none

State the recommendation in one sentence, then the reasoning, then the cost
including the documentation tax. If the recommendation is "do nothing", say why
and what would have to change for it to become worth doing — a written trigger,
so nobody re-audits this from scratch.

### Step 5: Produce the artifact

- **If the recommendation is to implement**: write a new ADR in `docs/adr/`,
  next-numbered, following the existing naming convention (zero-padded number,
  hyphenated statement of the decision). Read two or three existing ADRs first
  for voice — they are prose arguing a decision, not a filled-in template. It
  must state the decision, the alternative rejected, the privacy reasoning, the
  `api_version` conclusion, and the consequences. **Do not implement it.**
  Recommend that a follow-up plan be written.
- **If the recommendation is to do nothing**: do not write an ADR. Report the
  reasoning and the trigger, and propose the finding be recorded as "considered
  and rejected" in `plans/README.md` so it is not re-audited.

## Done criteria

- [ ] All seven questions in "Questions the decision must answer" are answered with reasons
- [ ] Question 5 (`api_version`) is answered with an explicit yes or no and the rule it follows from
- [ ] The consumer's 429 handling is either reported from its code or explicitly stated as unavailable
- [ ] A single-sentence recommendation exists
- [ ] Either a new ADR exists in `docs/adr/`, or the report states why none was written and names the trigger that would change the answer
- [ ] `git diff --stat classes/` is **empty**
- [ ] `composer gate` exits 0 (unchanged)
- [ ] `plans/README.md` status row for 010 updated

## STOP conditions

Stop and report back (do not improvise) if:

- You conclude the change requires an `api_version` bump. That converts a small
  additive improvement into a coordinated release of two repositories plus a
  manual production install, which is a different decision and the operator's to
  make.
- You find that `GET /extractions` already exposes another user's job in some
  path, or that the 429 already carries occupant data. That would mean the
  premise is wrong.
- Answering question 3 leads you to think ADR-0012's uniform-refusal rule would
  have to be weakened. Report it; do not design around it.
- You find yourself writing production code.

## Maintenance notes

- **The related problem deliberately not solved here**: failed-job records are
  invisible to `GET /extractions` and are cleared only by the TTL sweep or
  uninstall (`classes/Sweeper.php:184`). Option (b) would solve both at once;
  option (a) solves neither. Whoever takes this decision should weigh that
  explicitly rather than treating them as unrelated.
- **Why this is a design plan rather than a build plan**: the demand is inferred
  from the repository's own comments, not from a reported problem, and the
  project's stated rule is that an item whose payoff does not justify its
  documentation tax should be struck rather than carried. Deciding that on paper
  costs an hour; discovering it after building costs a release.

## Execution record (2026-08-16)

**Drift**: the drift check fired — `classes/Rest/Extractions_Controller.php`,
`classes/Job_Store.php`, and `classes/Sweeper.php` all changed since `8a35b2b`
(plan 013 / ADR-0019 landed in the interim, commits `2d93c50`..`a6de808`). The
code was read as it now stands rather than stopped on. The material change:
`GET /extractions` now takes `state=all` and additionally lists the caller's
own **terminal** jobs (id, state, `created_at`, `updated_at`, never
`progress` or `download_url`); owner-scoping stayed unconditional and
precedes the state filter (ADR-0019, confirmed at
`classes/Rest/Extractions_Controller.php:313-359`). A served artifact whose
job record is gone is now reclaimed server-side by the sweep after a TTL
grace period (`classes/Sweeper.php:247-256`, `classes/Job_Store.php:641-717`,
ADR-0019). `consume()` and `cancel()` now take the per-job tick lock before
purging (`classes/Rest/Extractions_Controller.php:606-614`, `660-668`).

### Answers to the seven questions

**1. Is there real demand?** Modest, and narrower than the plan assumed.
`~/Projects/kntnt-wp-skills` does hit 429 in practice — it is handled as a
named, expected case in three places, not speculated about:
`agents/extract-transfer.md:41` ("`429` (a job is already active — the sweep
or a bootstrap did not finish) is a hard stop: return `FAILED` with the
status and body, never a retry"), `agents/discovery-classify.md:52` ("A `429`
means a job is still active — do not force it; stop and return `FAILED`"),
and `docs/adr/0018-poll-discipline-and-two-chunk-preflight.md:17` ("The `429`
hard stop on `POST /extractions` is untouched: a still-active job is a
sequencing fault, not a transport fault, and is never retried"). But every
evidenced source of a 429 is **intra-client sequencing**, not a genuine
inter-administrator conflict: the client's own bootstrap job racing its own
main-extraction job, or a stranded job left by the client's own earlier
aborted run — and the client already runs a pre-flight "sweep stranded jobs"
step (`GET /extractions` + `DELETE /extractions/{id}` on its own non-terminal
jobs, now followed by an unconditional `GET /extractions?state=all` call to
*report* — never act on — its own terminal residue; see
`skills/clone/SKILL.md:69` and the identical text in `skills/pull/SKILL.md:70`)
specifically to prevent this before it happens. No evidence anywhere in the
consumer of a scenario where *another* administrator's job is what trips the
429. Crucially, the client's handling of a 429 it does hit is, and was always
designed to be, "log status and body, stop, never retry" (ADR-0018) — it does
not wait, poll, or reason about the occupant at all. So whatever data the 429
carries today goes completely unused by the automation; only a human
triaging a `FAILED` run afterward would ever read it, and that human
currently has to reach the filesystem to learn what is holding the slot,
same as the plan states.

**2. What does the caller actually need to decide?** `id`, `state`, and
`progressed_at` (or `updated_at`) is enough — the same heartbeat pair
`Sweeper::sweep()` itself already judges a job by
(`classes/Sweeper.php:156-157`): a recent timestamp reads as "still
advancing, wait"; one stale past the site's TTL/max-lifetime reads as
"wedged, will be reclaimed (or intervene)." A full `chunks_done`/progress
breakdown is not needed to make that binary call and would be strictly more
disclosure than the question requires (see Q3).

**3. Does disclosing the occupying job's id to a capable non-owner weaken
anything?** No, provided owner identity is never included. Both capabilities
are already required to reach `POST /extractions` at all, so the caller
disclosing this data to is already a site administrator. The job id by
itself unlocks nothing further: presenting it to `GET/DELETE
/extractions/{id}` as a non-owner still gets the same uniform 403 from
`resolve_owned_job()` (`classes/Rest/Extractions_Controller.php:770-787`)
that any other id gets, so the id is not an extra key to anything — it
reveals only what the bare fact of the 429 already implied, that some job
exists. State and a heartbeat timestamp are likewise not identity: they say
nothing about who owns the job. ADR-0012's uniform-refusal rule protects
against an endpoint becoming an *existence oracle* for an arbitrary
caller-supplied id; here the caller isn't probing an id, the server is
proactively describing the one job it already told the caller exists (by
refusing the create). That is a different disclosure shape and does not
weaken the rule, as long as no path from this data ever reaches owner
identity.

**4. Owner identity: in or out?** Out. `resolve_owned_job()`'s uniform 403 for
a non-owner and the "Identity report" glossary entry's scoping of
`authenticated_as` to the caller's own identity both establish that this
plugin never discloses one user's identity to another (ADR-0012
Consequences: a non-owner "never learns a job's state" let alone who owns
it). The plan's own scope already forbids revealing a `user_login` anywhere.
Nothing in this analysis found a reason to weaken that; the enriched-429
option under discussion never included owner identity.

**5. Does either option move `api_version`? Explicit no.** The rule it
follows from is ADR-0017 as amended by ADR-0018: `api_version` bounds only
the artifact contract (container framing, segments per resource, the sealed
index, reassembly order), and moves on a second, narrower ground only when a
change would make an *already-shipped* client's *existing* logic unsafe,
undetectably and without its consent. Enriching the 429's `data` with
`id`/`state`/`progressed_at` touches neither: it is a REST-only additive
field on an existing error response, and by ADR-0017's own stated tolerance
("a client should ignore any name it does not recognise, the same tolerance
it already extends to unknown response keys") an old client is unaffected —
and concretely, the one shipped client's actual 429 handling
(`agents/extract-transfer.md:41`) already just logs "the status and body"
verbatim without parsing meaning into `data`'s shape, so added fields are
inert to it today. It would ship as a new `Status_Controller::HONOURED_BEHAVIOURS`
entry, exactly like `strict`, `attempts`, `chunks_done`, `disclosure`, and
`state` before it (ADR-0017, ADR-0019).

**6. Does option (b) subsume the failed-residue problem, and does that change
the recommendation? Yes, and decisively.** Plan 013 / ADR-0019 already
implemented what this plan called option (b): `GET /extractions?state=all`
now admits the caller's own terminal jobs, closing exactly the gap
`Sweeper.php:184`'s comment named (now `Sweeper.php:196-197`, same
observation, already fixed). That removes option (b) from the table
entirely — there is nothing left to build there. It also sharpens what
remains: owner-scoping in `list_jobs()` is unconditional and precedes the
state filter (ADR-0019's own "Why the listing stayed owner-scoped" section,
and confirmed in the current code at
`classes/Rest/Extractions_Controller.php:313-324`), so `state=all` cannot and
was never meant to help an administrator see *another* user's job. The only
question actually left standing is the narrow one — enrich the 429 itself
(what used to be called option (a)) — and question 1's finding that its real
consumer is a human, not the automation, weighs directly on it.

**7. What is the documentation tax?** For the narrow surviving option — add
`id`, `state`, `progressed_at` to the 429's `data` — the tax is real on both
repositories, not merely nominal: server-side, `CHANGELOG.md`, the `Job
state`/429-refusal language in `CONTEXT.md`, a new ADR (this decision), a
`HONOURED_BEHAVIOURS` entry, and an integration test asserting the new
shape; client-side, `kntnt-wp-skills` binds its prose specs to *enforced*
consistency tests across multiple documents (see
`tests/test_health_check_sweep_order.py`, `test_poll_discipline_consistency.py`
in that repo) — updating `agents/extract-transfer.md:41` and
`agents/discovery-classify.md:52`'s 429 wording, `docs/spec.md`, and likely a
short ADR of its own to explain *using* the new data would all be needed
for the enrichment to be more than dead weight on the wire, since today's
client explicitly never retries and never reasons about a 429's payload.
Given question 1's finding — the payoff is "a slightly friendlier error
message for a human already reading logs after a `FAILED` run," not a
change in what the unattended automation does — that tax is not earned. It
should be struck, not carried.

### Recommendation

**Do nothing.** Reasoning: the concrete demand evidenced in the consuming
repository is self-inflicted sequencing between the client's own bootstrap
and main-extraction jobs, already mitigated by the client's own pre-flight
stranded-job sweep; no evidenced case is inter-administrator contention; the
client's own 429 handling is deliberately "stop and let a human sort it out,"
never an automated wait/retry decision, so an enriched 429 would change
nothing about how the unattended run behaves — it would only make a human's
post-mortem slightly more convenient, at the cost of a coordinated
documentation and test change on two repositories. Plan 013/ADR-0019 already
delivered the larger and clearer win (the caller's own terminal-job
visibility) that this plan's maintenance note had bundled in as option (b);
what is left is the smaller, lower-payoff half, and it does not clear the
bar on its own.

**Trigger** — revisit this if either becomes true:

- `kntnt-wp-skills` changes its 429 handling from "hard stop, never retry"
  (ADR-0018) to an automated wait/retry decision that needs to distinguish
  "someone else's job is nearly done" from "a wedged job is holding the
  slot." At that point the enrichment stops being cosmetic for a human and
  starts being data an automated decision consumes, which justifies the tax.
- A genuine inter-administrator (or inter-tool) contention case is actually
  reported — two independent callers on the same site both hitting the
  single-slot ceiling against each other, rather than one client's own
  multi-step flow tripping over itself. Nothing in this review found such a
  case; the plan's motivating scenario is currently hypothetical.

No ADR was written (the recommendation is "do nothing"). Propose recording
this in `plans/README.md` as considered and rejected, with the trigger above
noted so it is not re-audited from scratch.

**STOP conditions**: none fired. No `api_version` bump was found necessary
(Q5); `GET /extractions` does not expose another user's job on any path
(confirmed at `classes/Rest/Extractions_Controller.php:313-324`, unconditional
owner scope); the 429 does not currently carry occupant data (confirmed at
`classes/Rest/Extractions_Controller.php:244-252`, `data` carries nothing);
and answering Q3 did not require weakening ADR-0012's uniform-refusal rule.

`git diff --stat main..HEAD -- classes/` is empty; `composer gate` exited 0.

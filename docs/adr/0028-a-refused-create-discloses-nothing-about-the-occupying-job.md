# A refused create discloses nothing about the occupying job, and the trigger that would change that

A `POST /extractions` refused by the concurrency ceiling answers `429 kntnt_extractor_too_many_jobs` with a sentence of prose and a `data` payload carrying nothing but the status. It does not name the job holding the slot, its state, or when that job last advanced, and it is not going to. That is a decision taken on 2026-08-16 after the question was worked through in full, not a gap nobody has got to yet.

The asymmetry that made the question worth asking is real and structural rather than accidental. The ceiling is global — `Job_Store::has_free_slot()` counts every job on the site, whoever owns it — while the listing is not: `Extractions_Controller::list_jobs()` scopes to the caller's own jobs unconditionally, and that scoping precedes the state filter, so not even `state=all` widens it ([0019](./0019-every-purging-actor-takes-the-tick-lock-an-ownerless-artifact-is-reclaimed-server-side.md)). An administrator refused because *another* administrator's job holds the slot therefore has no route through the API to what they are waiting for, and diagnosing it takes filesystem access to the server. The plugin had noticed the same shape from the other side too: `Sweeper` recorded that failed-job residue was invisible to a listing that showed only non-terminal jobs.

## What was on the table, and what is left of it

Two designs were weighed. **Enrich the refusal** — add the occupying job's id, state and heartbeat timestamp to the error's `data`. **Admit terminal jobs to the listing** behind a `state` query parameter — larger, and it would also close the invisible-residue problem `Sweeper` named.

The second is already built. `GET /extractions?state=all` landed with [0019](./0019-every-purging-actor-takes-the-tick-lock-an-ownerless-artifact-is-reclaimed-server-side.md) and admits the caller's own terminal jobs, closing exactly that gap — but owner-scoping stayed unconditional there and was meant to, so it never was and never will be the answer to *another* administrator's job. What remains open is only the narrow half: the refusal itself. This decision closes that half and nothing else.

## Why the enrichment is not worth its price

**Every 429 anyone has evidenced is the caller's own sequencing, not contention between administrators.** `kntnt-wp-skills` does meet the refusal in practice and treats it as a named, expected case in three separate places rather than speculating about it. But every evidenced source of one is intra-client: its own bootstrap job racing its own main extraction, or a job its own earlier aborted run stranded — and its pre-flight sweep clears exactly those before it starts, specifically so this does not happen. Nothing anywhere in that repository evidences a case where a second administrator's job is what trips the ceiling. The motivating scenario is, so far, hypothetical.

**The one client's handling never reads the body.** Its 429 discipline is a deliberate hard stop — log the status and the body, fail, never retry — and it neither waits, polls, nor reasons about the occupant. Whatever the refusal carries today already goes unused by the automation, and whatever it carried after an enrichment would go unused in exactly the same way. The reader who would gain is a human triaging a failed run afterwards, and what they would gain is convenience, not a capability they lack.

**The tax is real on two repositories, and it is what settles it.** On this side: `CHANGELOG.md`, `CONTEXT.md`'s account of the refusal, a decision record, a `Status_Controller::HONOURED_BEHAVIOURS` entry so a client can tell a build that carries the data from one that does not, and an integration test pinning the new shape. On the client's side, the prose specs that bind its 429 handling are held consistent by enforced tests across several documents, so making the new fields more than dead weight on the wire means editing those documents and arguing the change there as well. A slightly friendlier post-mortem for someone already reading logs does not earn that, and this project's own rule is that an item whose payoff does not justify its documentation tax is struck rather than carried.

## The privacy reasoning, recorded so it is not re-derived

The enrichment was never blocked on privacy, and it matters to say why, because "it would leak something" is the first objection anyone re-deriving this will reach for.

Disclosing the occupying job's **id, state and heartbeat to a capable non-owner weakens nothing, provided owner identity is never included**. Both capabilities are already required to reach `POST /extractions` at all, so the recipient is already a site administrator. The id on its own unlocks nothing further: presented to `GET`/`DELETE /extractions/{id}` by a non-owner it earns the same uniform refusal from `resolve_owned_job()` that any other id earns, so it is not a key to anything — it discloses only what the bare fact of the 429 already told the caller, that some job exists. A state and a timestamp are not identity either.

Nor does it weaken [0012](./0012-identity-dependent-responses-are-never-cacheable.md)'s uniform-refusal rule. That rule stops an endpoint becoming an existence oracle for an arbitrary caller-supplied id; here the caller probes no id, and the server describes the one job it has just told the caller exists by refusing the create. Different disclosure shape, and the rule survives it — **as long as no path from this data ever reaches owner identity**, which is the constraint any future enrichment inherits. Owner identity stays out: this plugin never discloses one user's identity to another, and nothing in this analysis found a reason to start.

## No version move would have been involved

Explicitly: enriching the refusal's `data` would **not** move `api_version`. The rule it follows from is [0017](./0017-api-version-bounds-the-artifact-contract-honours-reports-what-a-build-does.md) as amended by [0018](./0018-a-defines-value-discloses-only-from-an-allow-list-with-a-per-record-discriminator.md) — `api_version` bounds the artifact contract, and moves on the second, narrower ground only where a change would make an already-shipped client's existing logic unsafe undetectably. An additive member on an existing error response is neither: an old client ignores a name it does not recognise, and the one shipped client concretely logs the body verbatim without parsing meaning into `data`'s shape, so added fields are inert to it. It would ship as a `honours` entry instead, exactly as `strict`, `attempts`, `chunks_done`, `state` and `disclosure` did.

This is worth recording because the cost calculus above depends on it. Had the answer been yes, the enrichment would have forced a coordinated release of both repositories and a manual production install, which is a different decision entirely and the operator's rather than an implementer's.

## What would reopen this

Revisit on either of these, and not otherwise:

- **`kntnt-wp-skills` changes its 429 handling from a hard stop to an automated wait-or-intervene decision.** At that point the occupant's state and heartbeat stop being cosmetic for a human and become data an unattended run consumes to tell "someone else's job is nearly done, wait" from "a wedged job holds the slot, intervene" — which is precisely the distinction the enrichment exists to serve, and precisely what justifies the tax.
- **A genuine inter-administrator contention case is actually reported** — two independent callers on one site hitting the single-slot ceiling against each other, rather than one client's own multi-step flow tripping over itself.

## Consequences

- The 429 stays as it is: status, code, message, and a `data` carrying only the status. `Status_Controller::HONOURED_BEHAVIOURS` gains nothing, and no test asserts an occupant payload — `tests/Integration/extractions-test.php` cites this decision where it asserts that a create losing the check-to-take race earns the ordinary ceiling refusal byte for byte, so a later reader can see the omission is settled rather than overlooked.
- An administrator refused by another administrator's job still needs filesystem access to learn what holds the slot. That cost is accepted, on the evidence that the case has never occurred.
- Any future enrichment inherits one constraint unchanged: owner identity never appears, and no path from the disclosed data may reach it.
- This decision was taken as plan 010's deliverable and recorded there and in that directory's rejected-findings list, on the reasoning that a decision to do nothing should not also buy an ADR. The directory is retiring (#57), and the record moves here rather than being lost — the reasoning, the evidence, the privacy analysis and the triggers are the ones taken on 2026-08-16, restated in this document's form and changed in none of their substance.

# A stall shrinks the chunk, and a failed stall can be re-driven from persisted progress

A chunk that dies outside PHP — the host killing the worker at a memory or execution-time limit, so no `catch` runs — is no longer a death sentence for the job. The attempt counter [0013](./0013-tables-are-dumped-in-keyset-paginated-slices-across-ticks.md) already detects exactly this shape: the same offset begun repeatedly, finished never. On that signal the driver halves the budget of the chunk that died (the file-part size, or the table-slice byte budget), persists the new size on the job, clears the attempt counter, and continues. The job stays `running`. Only a budget already at one byte still fails the job, because retrying that chunk would reproduce the kill forever.

A job that *did* leave `running` — a stall that reached the floor, or a stall recorded by an earlier release that failed at the first wall — can be re-driven from the progress it already persisted. The next tick, or the watchdog, re-enters the job into `running` with a fresh attempt window and the adapted budget. `Sealed_Writer::resume()` already truncates the in-progress container (and its index sidecar) back to the committed byte lengths, so a chunk that was appended but never acknowledged cannot be duplicated. What was missing was permission, not information.

The two halves belong together. Resume without adaptation walks straight back into the wall that spent the attempt counter; adaptation without resume still discards everything packaged the moment the job is marked `failed`. Together a crash becomes "carry on" instead of "start over".

## Why automatic, and why not every failure

Whether resume is automatic or operator-initiated is a real design question. Automatic risks an invisible infinite retry. Operator-initiated needs a REST verb and a client that knows to call it. This change must not touch the REST contract or the container format — the two repositories share a verified API-version ceiling — so there is no new verb, and the installed skills do not change.

The existing tick path and the watchdog are therefore the trigger. A status poll is deliberately not: `continue_after_response()` still ignores anything past `running`, so a client reading a failed job cannot accidentally revive it. The watchdog already walks every job on disk, including ones `GET /extractions` does not list.

Automatic resume is safe only for a *diagnosed stall*. An unexpected throw stays failed and is never re-driven: its `error` is null (the message is never captured, because it could carry a path or a fragment of SQL), and that absence is the signal. Resuming a permanent bug — a file that changed mid-build, a path that no longer resolves — would loop forever. The floor is the other bound: a stall whose budget is already one byte cannot shrink, so `is_resumable` is false and a further tick is a no-op.

Re-entering `running` occupies the concurrency slot again. A failed job frees that slot, and a new `POST /extractions` may already have taken it. The resume therefore honours the same `max_active_jobs` ceiling the create path does, and no-ops rather than sneaking a second live build past it.

## Why the budget lives on the job

The file-part size and the table-slice byte budget were Config knobs, process-wide. A constant chosen without measuring the host is what killed the production run: `DEFAULT_CHUNK_SIZE` is 8 MiB, the host packaged 28,021 files none of which needed a part that large, and then died three times at byte 0 of the first file that did. Picking a smaller constant would repeat the mistake. The attempt counter is the measurement. Persisting the adapted size on the job — `chunk_size` and `table_chunk_bytes`, zero meaning "use the Config default" — is what makes the next tick, and a resume after the job has left `running`, package the size that survived rather than rediscovering the ceiling. The record's schema version goes to 8. An older record parses unchanged: an absent budget reads as zero.

The failing unit is the *chunk*, not the file. Every file at or above the current part size stresses the host identically, so the adaptation is on the part size, never on a sort of the selection.

## Consequences

- A host whose real ceiling sits between 3.87 MB and 8 MiB — unmeasured, and not explained by either reported limit — calibrates itself on the first file that finds the wall, and the 28,021 files already packaged are kept.
- A job that has already failed from a diagnosed stall completes from where it stopped, once the watchdog or a further tick reaches it. An opaque failure does not.
- The stall bound is still a bound on retries at a given size, not a diagnosis. It cannot tell which host limit did the killing. Adaptation removes the need for the operator to guess a new constant; the floor is what still asks them to raise the host limits.
- The REST API is unchanged and its version stays 6. The poll still reports `failed` with a reason when the floor is hit, and still omits failed jobs from `GET /extractions`. No client has a new verb to learn.
- The sealed container's wire format is untouched.

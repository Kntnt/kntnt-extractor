# A job carries a bounded attempt log, distinct from the audit log

"What was attempted" is a debug question about a long or stuck run. It is not the audit log's question. `GET /audit-log` records the `kntnt_extractor_job_ready` transition and nothing else, because that is the instant an artifact becomes downloadable — the event the log exists to bind to a user ([0004](./0004-background-job-and-one-time-download-link.md), [0006](./0006-file-based-audit-log-random-name-age-rotated.md)). A running job has published nothing; a failed job publishes nothing at all. Recording attempts there would make the log's central claim ("this data left the site, taken by this user") false. That behaviour stays, and is still pinned by `tests/Integration/audit-log-test.php` AC7.

The gap is a separate surface on the job itself. Each tick already increments a stall counter *before* the chunk runs, so a host kill still leaves evidence ([0013](./0013-tables-are-dumped-in-keyset-paginated-slices-across-ticks.md)). That counter is a number, reset on every real advance and on every adaptation. It cannot answer which chunks were begun. The attempt log is the last N of those beginnings, persisted on `state.json` as four scalars per entry — when, which kind of chunk, the index into that selection, and the row or byte offset — and projected onto `GET /extractions/{id}` as `attempts?`, with the caller-facing name resolved from the job's own selection at read time.

## Why last-N on the state file

An unbounded list of attempts would grow with the build the way `segment_names` once did, and `job.json` already suffered a half-terabyte write problem from exactly that shape ([0014](./0014-the-persisted-record-is-split-so-a-save-is-bounded.md)). The log therefore lives on the file a save already rewrites, is capped at eight, and never holds a selected path. Eight is enough to tell a stuck chunk retrying from a run still advancing. The stall counter is untouched: this is a debug surface, not new adaptation machinery.

## Why not a new endpoint, and why not schema 9

A dedicated `/attempts` route would be a second read path for a last-N array the poll already returns. The member is additive — a queued job omits it, an old client ignores unknown keys — so the REST contract stays at api_version 6. Schema 8 is still unreleased; the field joins the other schema-8 additions (`skipped_files`, the first-tick host-limit pair) rather than forcing a bump.

## Consequences

- A poll of a running or failed job can name the chunks recently begun, including the one a host kill never let finish.
- `GET /audit-log` is unchanged. A failed extraction still appends nothing there.
- `state.json` remains O(1) in the selection. The persisted entry has no path, no SQL, and no Application Password.
- The sealed container's wire format is untouched. `kntnt-wp-skills` needs no change.

# kntnt-extractor

A WordPress plugin exposing a minimal REST API for extracting a selection of database tables and/or files from a site. Independent of any other Kntnt project; also the intended replacement control channel for `kntnt-wp-skills`'s `clone`/`pull` skills ([kntnt-wp-skills#24](https://github.com/Kntnt/kntnt-wp-skills/issues/24)).

## Language

### Access control

**Operate capability**:
The plugin-defined WordPress capability `kntnt_extractor_operate`. Grants the right to call the REST API at all — the plugin's "on switch", inert until deliberately granted. Necessary but not sufficient: every listing and extraction request also requires the caller to hold `manage_options`, the administrator capability. The two compose — Operate opens the door, `manage_options` authorises the data — so a user with Operate but without `manage_options` reaches the API surface yet can neither list nor extract anything.
_Avoid_: API access, permission

**Identity report**:
The `authenticated_as` and `capabilities` members `GET /status` adds to its response when — and only when — the request's credentials resolved to a WordPress user. Names the user's `user_login` and whether that user holds each of the two composing capabilities, so a caller can answer "who am I here, and what may I do" in one call rather than inferring it from the pattern of refusals other endpoints return.
_Avoid_: whoami, login check

### Extraction

**Manifest**:
The unfiltered, recursive listing (path, size, mtime) of every file from the WordPress installation root downward. Carries no categorisation of what any file is for — that judgement belongs to the caller, not the plugin.
_Avoid_: file list, scan

**Restricted path**:
A file path matching the fixed deny-list of credential-bearing patterns (`wp-config.php` and its variants, `.env` files, root-level database dumps and key material). Listed in the manifest like any other file but never extractable: a selection containing one is rejected at job creation, naming the offending paths.
_Avoid_: blocked file, sensitive file

**Table list**:
The enumeration of tables that exist in the site's database, the file-side manifest's counterpart.

**Extraction job**:
The background job, created from an explicit, already-resolved list of table names and/or file paths, that packages and encrypts the requested selection. Runs detached and polled rather than inline in the request, so it is not bound by a single HTTP request's timeout.
_Avoid_: export, backup, dump

**Job state**:
The stage an extraction job is in, reported verbatim to a polling caller. Seven states span the lifecycle: `queued` and `running` while the job is live and holds the single concurrency slot; `ready` once the sealed artifact awaits its download link; and four terminal states that release the slot — `consumed` (the caller confirmed the download and the artifact was deleted), `cancelled` (the caller aborted the job with a `DELETE` before it was consumed), `failed` (the run could not produce an artifact), and `expired` (the time-to-live sweep reclaimed a job never consumed). `cancelled` and `consumed` are distinct terminal ends: consume confirms a delivered artifact and is audited, whereas cancel discards the job at the caller's request and writes no audit record. `failed` is terminal for every purpose that counts one — it frees the slot, and the sweep passes over it — but is not strictly one-way: a stall recorded by a release too old to adapt around it re-enters `running` when a tick or the watchdog reaches it (ADR-0015), which is how an upgrade recovers a stranded run rather than restarting it.
_Avoid_: status

**Job record**:
An extraction job's own persisted state, held as two files in its working directory rather than one: the *selection file* carries the requested tables and files and is written once, and the *state file* carries everything a tick changes and is what every save rewrites. The split is on what is unbounded, not on what is immutable — a selection runs to tens of thousands of paths and a save happens twice per packaged chunk, so keeping the two apart is what makes a save's cost independent of how much was selected.
_Avoid_: job file, job.json

**Segment**:
The artifact's unit of encryption and of reassembly: one bounded chunk of one selected table or file, sealed on its own and recorded in the sealed index under that table's name or that file's installation-root-relative path. Nothing is packaged whole, so a table or file larger than one chunk contributes several segments carrying the same name and a reader reassembles a resource by concatenating, in index order, every segment that carries its name.
_Avoid_: block, piece

**Stalled build**:
A build that has begun the same chunk repeatedly and finished none of those times — the shape left behind when a host limit kills the PHP worker outright, since no failure path gets to run. Recognised by counting begun-but-unfinished attempts on the job record rather than by any wall clock. On that signal the driver halves the bounds the chunk that died actually spends — one for a file part, both of a table slice's, since its byte budget caps only the render while its row budget caps the fetch — and retries, so the run calibrates to whatever the host can actually package; only a chunk whose bounds are all at their floor is terminal. A job stalled and failed by a release too old to do any of that can be re-driven from its persisted progress, with adapted budgets and a fresh attempt window. Neither an opaque failure — an unexpected throw, which carries no reason — nor a stall this release already adapted around is resumed.
_Avoid_: timeout, crash, hang

**Download link**:
The short-lived, single-use link an extraction job's artifact is fetched through once ready. Consumed (deleted server-side) after a verified download, rather than waiting on an expiry timer.

### Operations

**API version**:
The REST contract's own version number, distinct from the plugin's release version. Increments only when a caller-visible behaviour changes — including a subtler, purely behavioural change, not only a change to endpoints or arguments — never for a fix that leaves the contract as callers already understood it.
_Avoid_: plugin version (as a synonym)

**No-cache guarantee**:
The plugin's unconditional promise that no response under `kntnt-extractor/v1` may be retained by any cache, stated once at `rest_post_dispatch` and applied regardless of status code or authentication state. It exists because a refusal that resolved to no user looks anonymous to WordPress and therefore cacheable, and one such refusal held in a page cache is replayed to every later caller, correct credentials included.
_Avoid_: cache headers (as a synonym for the guarantee)

**Audit log**:
The append-only record of every successful extraction (user, tables/files, timestamp). Stored as a randomly-named file, not a database table; read only through its own REST endpoint, gated on `manage_options`.

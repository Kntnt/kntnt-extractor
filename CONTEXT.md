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

**Selection limits**:
The two caps a create request is measured against before anything else: a combined element count across `tables`, `tables_structure_only` and `files`, and the raw request body's size in bytes. Both are checked ahead of the restricted-path check, the existence check and the capability gate, so an oversized request is refused before an unauthenticated caller can spend a `realpath()` per path or a catalog query on it; each refusal reports the limit it was measured against and the caller's own count or size. They bound what one request costs, not how often one may be sent (ADR-0020).
_Avoid_: quota, rate limit, throttle

**Table list**:
The enumeration of tables that exist in the site's database, the file-side manifest's counterpart.

**Extraction job**:
The background job, created from an explicit, already-resolved list of table names and/or file paths, that packages and encrypts the requested selection. Runs detached and polled rather than inline in the request, so it is not bound by a single HTTP request's timeout.
_Avoid_: export, backup, dump

**Job state**:
The stage an extraction job is in, reported verbatim to a polling caller. Seven states span the lifecycle: `queued` and `running` while the job is live and holds the single concurrency slot; `ready` once the sealed artifact awaits its download link; and four terminal states that release the slot — `consumed` (the caller confirmed the download and the artifact was deleted), `cancelled` (the caller aborted the job with a `DELETE` before it was consumed), `failed` (the run could not produce an artifact), and `expired` (the time-to-live sweep reclaimed a job never consumed). `cancelled` and `consumed` are distinct terminal ends: consume confirms a delivered artifact and is audited, whereas cancel discards the job at the caller's request and writes no audit record. `failed` is terminal for every purpose and in both directions: it frees the slot, and no tick, watchdog, or other path re-enters it into `running`. It is also the only terminal state that leaves a record on disk, since it must stay pollable, so the sweep reclaims it on the usual windows like any unfinished job, with no exemption of any kind (ADR-0024, and ADR-0015's addendum).
_Avoid_: status

**Job record**:
The extraction job's own persisted state, held as two files in its working directory rather than one: the *selection file* carries the requested tables and files — and, when a `strict: false` create dropped vanished paths, those skipped names — and is written once, and the *state file* carries everything a tick changes and is what every save rewrites. The split is on what is unbounded, not on what is immutable — a selection runs to tens of thousands of paths and a save happens twice per packaged chunk, so keeping the two apart is what makes a save's cost independent of how much was selected.
_Avoid_: job file, job.json

**Orphaned artifact**:
A sealed artifact in the served downloads directory that no job record claims — the residue of a cancel or consume that once raced a live tick without holding its lock, or of a crash between an artifact's publish and its own job record settling (ADR-0019). Has no id to be addressed by and no owner to scope a caller's request to, so it is never a listing's job and is reclaimed only by the sweep, after the same grace period (the TTL) a never-consumed but still-recorded artifact is judged by.
_Avoid_: leftover file, dangling artifact

**Skipped file**:
A file named in a `strict: false` submission that no longer exists on disk at job creation. Dropped from the selection rather than failing the job, recorded on the job record, and reported on the create and poll responses so the caller can see what was omitted. A missing table is never skipped: silence there is data loss. A path that resolves outside the installation root is not vanished either, and still 404s.
_Avoid_: ignored file, optional file

**Segment**:
The artifact's unit of encryption and of reassembly: one bounded chunk of one selected table or file, sealed on its own and recorded in the sealed index under that table's name or that file's installation-root-relative path. Nothing is packaged whole, so a table or file larger than one chunk contributes several segments carrying the same name and a reader reassembles a resource by concatenating, in index order, every segment that carries its name.
_Avoid_: block, piece

**Chunk budget**:
The three bounds that decide how much one packaging chunk may spend: `chunk_size` for a file part's bytes, `table_chunk_bytes` for a table slice's rendered bytes, and `table_chunk_rows` for the rows that slice fetches into memory. Resolved per job — the job's own persisted value when it carries one, otherwise the site's Config knob — and floored at one, so a misconfigured knob cannot disable packaging rather than merely slow it. A stall halves the bounds the chunk that died actually spends and persists them on the job, which is how a run calibrates itself to the host instead of to a constant. `chunk_size` is additionally the one a caller may choose per run, as an optional member of `POST /extractions`: what a host survives is measured and host-specific, so a value that is a starting point on one site is an impossible run on another. A requested size is exactly that starting point — the adaptation is free to walk down from it, and it is never a floor.
_Avoid_: chunk size (as the name of all three), tick budget

**Stalled build**:
A build that has begun the same chunk repeatedly and finished none of those times — the shape left behind when a host limit kills the PHP worker outright, since no failure path gets to run. Recognised by counting begun-but-unfinished attempts on the job record rather than by any wall clock. Every tick first asks the host to raise its own execution-time and memory limits, since a granted ask removes the cause outright and costs nothing; the stall reason reports what was asked beside what was granted, reading both pairs from the job record where the first tick persisted them, so a refusal and a kill from above PHP read differently and a later tick still names the numbers in force when the chunk died. On that signal the driver halves the bounds the chunk that died actually spends — one for a file part, both of a table slice's, since its byte budget caps only the render while its row budget caps the fetch — and retries, so the run calibrates to whatever the host can actually package; only a chunk whose bounds are all at their floor is terminal, and a build that reaches `failed` stays there. A throw is not silent about itself: it records the throwable's class, its own message truncated to a bound and trace-free, where it was thrown — named relative to the installation root — and the chunk being packaged, in a field of its own rather than in the one a diagnosed stall writes (see **Failure reason**). What that leaves is a third shape neither the stall counter nor a `catch` can see, and the only one that still records nothing: a failed job carrying neither reason, and whose poll therefore reads as the generic fallback, is one where PHP was killed outright rather than throwing. A failure discards its in-progress container and sidecar, keeping only the record a poll still reads — which the TTL sweep then reclaims like any other job.
_Avoid_: timeout, crash, hang

**Failure reason**:
The account a failed extraction job gives of why it ended: the record's own, and the single string its poll reports as `error.message`. Two exist on the job record and exactly one ever reaches the caller. The *diagnosed* reason is the one the plugin worked out itself — the stalled build's, composed from the caller's own selection and two settings `GET /environment` already discloses. The *relayed* reason is the one an unexpected throw composes around the throwable it caught: its class, its own message truncated to a bound and carrying no stack trace, the origin named relative to the installation root, and the chunk being packaged. They are separate fields because what each may disclose differs, never because a caller distinguishes them — the poll resolves the diagnosed one, then the relayed one, then a deliberately generic fallback sentence whose appearance is itself the third diagnosis: a run PHP killed outright, which reached no `catch` and recorded neither. That difference is decided rather than left to a bound to imply: the diagnosed reason carries nothing the plugin did not write, while the relayed one **may** carry a filesystem path or a fragment of SQL, since the bound limits the size of that disclosure and not its kind (ADR-0022).
_Avoid_: error message, exception, stack trace

**Download link**:
The short-lived, single-use link an extraction job's artifact is fetched through once ready. Consumed (deleted server-side) after a verified download, rather than waiting on an expiry timer.

### Operations

**API version**:
The REST contract's own version number, distinct from the plugin's release version. Bounds the artifact contract — the sealed container's framing, segments per resource, the sealed index, and reassembly order — and moves on one further, narrower ground: a change that would make an already-shipped client's own existing behaviour unsafe against the new server, in a way that client cannot detect and did not opt into (ADR-0018, amending ADR-0017, amending ADR-0005). Never a general "any caller-visible change" counter: a behavioural change an old client can safely keep ignoring, such as `strict` or `chunk_size` on `POST /extractions`, ships as a `honours` entry alone, with no bump. The `disclosure` field the define-disclosure allow-list adds is also named in `honours`, like any other caller-visible surface — but the allow-list itself bumped the version too, because an old client does not ignore an unrecognised `null` define value, it acts on it (ports it into a local file), so the second ground applied on top.
_Avoid_: plugin version (as a synonym); a general behaviour-change counter

**Honours**:
The sorted list of caller-visible behaviour names a build implements, reported on `GET /status` to an authenticated caller only, distinct from the anonymous `api_version` handshake and from the existing `capabilities` member — which stays the caller's own WordPress capabilities (ADR-0017). Absence is the only signal: a name not in the list is not implemented, and there is no boolean map. Answers "what does this build do?", a question the API version cannot answer because a behaviour can ship additively without moving it.
_Avoid_: features, capabilities (as a synonym — that name is taken)

**Verified ceiling**:
The highest `api_version` a client has verified its own behaviour against, pinned client-side: meeting a server that reports a higher number, the client refuses to run at all rather than proceed on a contract it has never been checked against. It is a property of any conforming client and never a server-side gate — this plugin cannot read a ceiling, cannot enforce one and is never refused by one; raising a ceiling moves nothing on the wire and only widens what that one client will accept. It is nonetheless the only thing in this contract with the teeth to make "update the client before it touches this server" binding, which is why a version move an already-shipped client could not safely ignore leans on it, and why such a move obliges a coordinated release of both sides (see **API version**; `docs/release-procedure.md` §8). `kntnt-wp-skills` is the client that pins one today, as an instance of the term and not its definition: any client pointed at this plugin may pin its own.
_Avoid_: version check, compatibility gate, client pin

**No-cache guarantee**:
The plugin's unconditional promise that no response under `kntnt-extractor/v1` may be retained by any cache, stated once at `rest_post_dispatch` and applied regardless of status code or authentication state. It exists because a refusal that resolved to no user looks anonymous to WordPress and therefore cacheable, and one such refusal held in a page cache is replayed to every later caller, correct credentials included.
_Avoid_: cache headers (as a synonym for the guarantee)

**Audit log**:
The append-only record of every successful extraction (user, tables/files, timestamp). Stored as a randomly-named file, not a database table; read only through its own REST endpoint, gated on `manage_options`. Records the instant an artifact becomes downloadable, never an attempt — a failed or still-running job publishes nothing, so nothing is logged. "What was attempted" is the attempt log's question.
_Avoid_: extraction history, activity log

**Attempt log**:
The bounded last-N of packaging chunks an extraction job has begun, persisted on the job record and reported on `GET /extractions/{id}` as `attempts?`. A debug surface for a long or stuck run, not an audit trail and not the stall counter: it names what was started, drops the oldest entry past eight, and never grows with the selection. Distinct from the audit log, which records only a ready transition.
_Avoid_: audit, history, observability


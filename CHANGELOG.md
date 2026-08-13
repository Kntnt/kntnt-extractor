# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.4.0] – 2026-08-13

### Added

- `GET /status` answers the identity question directly (ADR-0012). When a request's credentials resolve to a WordPress user, the response carries `authenticated_as` (that user's `user_login`) and `capabilities` (a map of `kntnt_extractor_operate` and `manage_options` to whether the user holds it) alongside the API version. An anonymous request receives byte-for-byte the handshake it always did, so the version check stays reachable without credentials.

### Changed

- A selected table is now packaged in bounded slices of rows across as many ticks as it takes, exactly as an oversized file is packaged in bounded parts (ADR-0013). Each slice is its own sealed segment recorded in the sealed index under the table's own name, so **a reader must concatenate every segment carrying a name, in index order, to reassemble a table** — the rule file parts have always required. A reader that expected exactly one segment per table, or that kept only the last segment it saw for a given name, will silently lose all but a table's final slice. **Breaking for such a reader.** The rows of a slice are read by keyset pagination on the table's primary key, using the whole key so a composite one (WordPress ships one on `wp_term_relationships`) is paged like any other; a table with no primary key at all falls back to an offset walk. Rows of a keyed table are therefore emitted in primary-key order rather than in whatever order the engine chose, so a dump reloads identically but is not byte-for-byte comparable with one from an earlier release. The slice size is the new `table_chunk_rows` knob (`KNTNT_EXTRACTOR_TABLE_CHUNK_ROWS` or its filter, default 1000 rows).
- Bumped the REST API version to `5` for the artifact's new segment shape above.
- The shared authorization gate names each refusal instead of returning one opaque code (ADR-0012). A request that resolved to no WordPress user is now `401 kntnt_extractor_not_authenticated`; an authenticated caller missing a capability is `403 kntnt_extractor_missing_operate_capability` or `403 kntnt_extractor_missing_manage_capability`. This reverses the earlier choice to answer an anonymous caller 403 on the grounds that it is definitionally missing the Operate capability: the two failures have different remedies — send credentials versus grant a capability — and a code that cannot separate them is what made a stripped `Authorization` header, an unknown username, a revoked capability, and a cached error response indistinguishable. `GET /audit-log` uses the same two codes for its own `manage_options` gate. **Breaking for a client that tested for 403 to mean "not permitted".**
- Bumped the REST API version to `4` for the coordinated pair above: the status endpoint's new members and the gate's new refusal codes are one caller-visible contract change.

### Fixed

- A table larger than what one PHP request can carry no longer makes an extraction impossible, and no longer hangs forever pretending to run (ADR-0013). A table used to be packaged whole, in a single tick, from one unbounded `SELECT *` that materialised every row in memory; on a host whose `memory_limit` or `max_execution_time` that exceeded, the tick was killed outright — neither limit raises anything PHP can catch — so the failure path never ran, the stall watchdog restarted the job, and the cycle repeated without bound while the caller polled `state: "running"` with a frozen table counter and no error, indefinitely. Measured on a production site (Extractor 0.3.0, PHP 8.4, MariaDB 11.4), a 12 MB / 2,909-row `wp_posts` completed while a 56 MB / 119,674-row `wp_relevanssi` made no progress in five minutes and a 493 MB / 100,893-row `wp_postmeta` none in forty — which made the site unclonable, since `wp_postmeta` is content and cannot be dropped from a clone. Tables are now dumped in bounded slices resumed across ticks, so the per-tick working set is one slice rather than a whole table.
- A build whose chunk cannot complete now reports `failed` with a usable reason instead of being retried forever (ADR-0013). A counter incremented before each chunk and cleared by every real advance is what survives a kill that leaves nothing else behind; once a chunk has been begun `max_stall_attempts` times (default 3, `KNTNT_EXTRACTOR_MAX_STALL_ATTEMPTS` or its filter) without ever finishing, the job fails and its poll's `error.message` names how many attempts died, which table and row — or file and byte — they died on, and the host's `memory_limit` and `max_execution_time`, so the cause is diagnosable on a site with no debug log. A job that is merely slow is untouched: every completed chunk resets the counter.
- A page cache can no longer strand the API on a single failed authentication (ADR-0012). Every response under `kntnt-extractor/v1` — payloads, refusals, and unmatched routes alike, whatever the authentication state — now carries `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Vary: Authorization`, and fires `litespeed_control_set_nocache` because LiteSpeed's page cache decides through its own control API rather than the response headers. WordPress sends its own no-cache headers only for a request it considers authenticated, and an attempt that fails to resolve a user is not: `wp_authenticate_application_password()` short-circuits silently on an unknown login, so the resulting refusal went out looking like a cacheable anonymous error. On a LiteSpeed production site that refusal was cached against the URL and replayed to every later caller, including ones presenting entirely correct credentials — one mistyped username locked the endpoint out until the cache was purged, and only a unique query parameter on the URL made the identical request succeed. The guarantee is applied at `rest_post_dispatch`, the one seam every response in the namespace passes through, so no endpoint can be added outside it.

## [0.3.0] – 2026-07-23

### Added

- `POST /extractions` rejects a selection naming a credential-bearing restricted path — `wp-config.php` and its backup/editor-droppings siblings (`wp-config-sample.php` excepted), `.env` and its siblings anywhere in the tree, and root-level database dumps and key material (#21, ADR-0011). The rejection is a `422 kntnt_extractor_restricted_path` naming every offending path, decided before the existence check and the capability gate, so a misconfigured client learns its selection is wrong rather than silently receiving — or missing — the site's secrets. `GET /files` is unchanged: a restricted path stays listed, unannotated. Bumped the REST API version to `3` for this caller-visible contract change.

### Fixed

- `GET /extractions/{id}` no longer returns a spurious `404 kntnt_extractor_no_such_job` for a live, progressing job (#20). The per-job `job.json` was rewritten in place with a bare `file_put_contents()`, so it was momentarily zero-length or partial on every save; a poll that read it inside that window saw an unparseable file and reported the job as vanished — which the client poll discipline treats as terminal, aborting a healthy clone. The write burst from the time-budgeted tick (#18) made the window easy to hit. The state file is now published atomically, written to a sibling temp file and `rename()`d over `job.json` (the same discipline the sealed artifact already uses), so a concurrent reader sees either the whole previous record or the whole new one — never a torn one. As defence in depth, `find()` now re-reads a present-but-unparseable file a bounded few times before it concludes the job is absent, so a 404 means a confirmed on-disk absence, never a transient partial read. Tick, sweep, and consume behaviour is unchanged.

## [0.2.1] – 2026-07-23

### Fixed

- Heavy extractions no longer crawl at the cron watchdog's cadence on hosts where the self-loopback continuation never completes (#18): a tick is now time-budgeted, packaging as many bounded chunks as fit in a configurable wall-clock budget (`tick_budget`, default 15 s; zero preserves the previous one-chunk-per-tick behaviour) within a single PHP invocation, so one tick or one watchdog patrol can carry a multi-chunk job all the way to ready instead of one chunk per cron cycle. The continuation nudge now fires once per tick, after the per-job lock is released and only while work remains, and its delivery is hardened (`ignore_user_abort`, a bounded cURL connect phase) so a dead loopback can neither stall the nudging process nor kill the tick it spawned mid-chunk.
- `GET /extractions/{id}` and `POST /extractions` no longer block on the best-effort loopback nudge (#19): the continuation that keeps a queued or stalled job's driver alive now runs after the response has been sent, not before it, so a poll that should cost milliseconds is never held for tens of seconds on a host where loopback HTTP is slow to fail. Once the response is out, the worker drives the job in-process where it can detach from the client (`fastcgi_finish_request`/`litespeed_finish_request`), and otherwise falls back to the same guarded, hard-bounded nudge — now paid after the body is echoed. The REST responses are byte-identical and the API version is unchanged.

## [0.2.0] – 2026-07-22

### Added

- Structure-only table extraction (#16): `POST /extractions` accepts a `tables_structure_only` sibling list alongside `tables`, dumping those tables' `DROP`/`CREATE TABLE` DDL into the sealed artifact without any rows, so an artifact can carry every selected table's structure while carrying only some tables' data. A table may appear in `tables` or `tables_structure_only` but not both (422); an unknown structure-only table is a 404 decided before the capability gate; structure-only tables count toward the poll's table progress totals and are recorded in the sealed index and audit log like any other table.
- Authorized `GET /kntnt-extractor/v1/environment` endpoint (#15) returning read-only site and runtime facts about the host — no extraction is created — so a caller can inspect the environment behind the same capability gate that guards the operational endpoints.
- Authorized `GET /kntnt-extractor/v1/extractions` endpoint (#17) listing the caller's own non-terminal jobs (queued / running / ready), each with the same id, state, and timestamps a create and poll report and `progress` on the jobs that have advanced. A caller never sees another user's job, a terminal job is the audit log's concern and is omitted, and the listing discloses no `download_url`.

### Changed

- Bumped the REST API version to `2` for the coordinated #15/#16/#17 trio: the `tables_structure_only` request field and its structure-only artifact segments (#16) and the two new read endpoints `GET /environment` (#15) and `GET /extractions` (#17) are one caller-visible contract change shipped under a single version bump rather than one bump each.

## [0.1.1] – 2026-07-22

### Changed

- Lowered the minimum PHP requirement from 8.5 to 8.4. No code depended on a PHP 8.5-only feature, so the plugin now installs and runs on PHP 8.4 hosts as well.

## [0.1.0] – 2026-07-22

### Added

- Walking-skeleton plugin scaffold: main plugin file with a PHP 8.5 requirement guard, a hand-written PSR-4 autoloader, and a `Plugin` singleton bootstrap.
- Unauthenticated `GET /kntnt-extractor/v1/status` endpoint returning the REST contract's API version (`{ "api_version": 1 }`), separate from the plugin release version.
- A `Config` seam that resolves a value from a constant, overridable by a filter (the filter wins).
- WordPress Playground integration-test harness dispatching `GET /status` through the live REST server, plus the Composer `gate` (phpcs, PHPStan, integration suite).
- Per-segment sealed encryption container: an extraction is written to disk one encrypted segment at a time, each sealed to the caller's ephemeral X25519 public key, so no plaintext dump ever touches the disk and only the holder of the matching private key can open the result.
- Capability-gated access: an `Operate` capability and a two-capability authorizer guard every operational endpoint, so an anonymous or under-privileged caller is refused with `403`.
- Authorized `GET /kntnt-extractor/v1/tables` endpoint listing the database tables available for extraction, each with an estimated row count and size.
- Authorized `GET /kntnt-extractor/v1/files` endpoint returning the recursive file Manifest (`path`, `size`, `mtime`) from the WordPress installation root downward, with no categorisation of what any file is for. The listing is delivered complete but paged through an opaque, path-ordered cursor the caller loops over to exhaustion, and the page size is a `Config` knob (`files_page_size`).
- Authorized `POST /kntnt-extractor/v1/extractions` endpoint that creates a background extraction job from a selection of tables and files and returns it queued, then pollable for its state (queued / running / ready / failed) — the poll reports table-and-file progress counters while the job runs and a failure reason once it fails. A null-byte or out-of-root file path is rejected as a `404` rather than crashing.
- Tick-driven execution: an extraction runs as bounded background chunks driven by an internal tick endpoint authenticated by the job's own secret; when the job is ready a Download link serves the sealed artifact statically, without exposing job state.
- Caller-driven and time-based cleanup: a caller consumes a ready artifact — the server deletes it on confirmation — or cancels a job outright, and a TTL sweep reclaims any job left unconsumed, deleting its artifact and working directory.
- File-based audit log: each extraction is recorded the moment it becomes ready, readable through the authorized `GET /kntnt-extractor/v1/audit-log` endpoint, with retention bounded by a configurable number of days. Each entry's timestamp is published as an ISO-8601 UTC string.
- Bounded, resumable file packaging: a large file selection is packaged in size-bounded parts across many ticks, so an extraction survives an interruption between chunks and resumes exactly where it stopped. Ticks on one job are serialized by a per-job lock and fail closed on an inconsistent resume, so a duplicate or racing driver can never corrupt the in-progress container.
- Unattended drivers, so a queue completes with no visitor traffic and on hosts where loopback requests do not work: each chunk fires a non-blocking loopback to schedule the next, a cron watchdog restarts a stalled queue one chunk per cycle, and a status poll nudges an untended job. An absolute lifetime ceiling — measured from the last real progress, not raw age — bounds restarts of a job whose chunk fails uncatchably every attempt while sparing a slow-but-advancing large extraction, and the sweep honours the per-job tick lock so a live build is never deleted underneath itself.
- Uninstall cleanup: removing the plugin purges the audit log and every working directory, leaving no residue behind.
- Self-hosted update checker: bundles the YahnisElsts Plugin Update Checker (under `lib/`) pointed at the plugin's own GitHub releases, so an available update shows on the Plugins screen and installs in place with no manual file replacement. The release asset is matched by name, and `build-release-zip.sh` produces the distributable `kntnt-extractor.zip` under that same name.

[Unreleased]: https://github.com/Kntnt/kntnt-extractor/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.4.0
[0.3.0]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.3.0
[0.2.1]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.2.1
[0.2.0]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.2.0
[0.1.1]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.1.1
[0.1.0]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.1.0

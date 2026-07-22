# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/Kntnt/kntnt-extractor/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.2.0
[0.1.1]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.1.1
[0.1.0]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.1.0

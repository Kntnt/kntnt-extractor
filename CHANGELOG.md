# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Walking-skeleton plugin scaffold: main plugin file with a PHP 8.5 requirement guard, a hand-written PSR-4 autoloader, and a `Plugin` singleton bootstrap.
- Unauthenticated `GET /kntnt-extractor/v1/status` endpoint returning the REST contract's API version (`{ "api_version": 1 }`), separate from the plugin release version.
- A `Config` seam that resolves a value from a constant, overridable by a filter (the filter wins).
- WordPress Playground integration-test harness dispatching `GET /status` through the live REST server, plus the Composer `gate` (phpcs, PHPStan, integration suite).
- Per-segment sealed encryption container: an extraction is written to disk one encrypted segment at a time, each sealed to the caller's ephemeral X25519 public key, so no plaintext dump ever touches the disk and only the holder of the matching private key can open the result.
- Capability-gated access: an `Operate` capability and a two-capability authorizer guard every operational endpoint, so an anonymous or under-privileged caller is refused with `403`.
- Authorized `GET /kntnt-extractor/v1/tables` endpoint listing the database tables available for extraction, each with an estimated row count and size.
- Authorized `GET /kntnt-extractor/v1/files` endpoint returning the recursive file Manifest (`path`, `size`, `mtime`) from the WordPress installation root downward, with no categorisation of what any file is for. The listing is delivered complete but paged through an opaque, path-ordered cursor the caller loops over to exhaustion, and the page size is a `Config` knob (`files_page_size`).
- Authorized `POST /kntnt-extractor/v1/extractions` endpoint that creates a background extraction job from a selection of tables and files and returns it queued, then pollable for its queued / running / ready state. A null-byte or out-of-root file path is rejected as a `404` rather than crashing.
- Tick-driven execution: an extraction runs as bounded background chunks driven by an internal tick endpoint authenticated by the job's own secret; when the job is ready a Download link serves the sealed artifact statically, without exposing job state.
- Caller-driven and time-based cleanup: a caller consumes a ready artifact — the server deletes it on confirmation — or cancels a job outright, and a TTL sweep reclaims any job left unconsumed, deleting its artifact and working directory.
- File-based audit log: each extraction is recorded the moment it becomes ready, readable through the authorized `GET /kntnt-extractor/v1/audit-log` endpoint, with retention bounded by a configurable number of days.
- Bounded, resumable file packaging: a large file selection is packaged in size-bounded parts across many ticks, so an extraction survives an interruption between chunks and resumes exactly where it stopped. Ticks on one job are serialized by a per-job lock and fail closed on an inconsistent resume, so a duplicate or racing driver can never corrupt the in-progress container.
- Unattended drivers, so a queue completes with no visitor traffic and on hosts where loopback requests do not work: each chunk fires a non-blocking loopback to schedule the next, a cron watchdog restarts a stalled queue one chunk per cycle, and a status poll nudges an untended job. An absolute lifetime ceiling — measured from the last real progress, not raw age — bounds restarts of a job whose chunk fails uncatchably every attempt while sparing a slow-but-advancing large extraction, and the sweep honours the per-job tick lock so a live build is never deleted underneath itself.
- Uninstall cleanup: removing the plugin purges the audit log and every working directory, leaving no residue behind.
- Self-hosted update checker: bundles the YahnisElsts Plugin Update Checker (under `lib/`) pointed at the plugin's own GitHub releases, so an available update shows on the Plugins screen and installs in place with no manual file replacement. The release asset is matched by name, and `build-release-zip.sh` produces the distributable `kntnt-extractor.zip` under that same name.

## [0.1.0] – 2026-07-20

### Added

- Initial release.

[Unreleased]: https://github.com/Kntnt/kntnt-extractor/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.1.0

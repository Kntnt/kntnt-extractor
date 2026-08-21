# Kntnt Extractor

[![License](https://img.shields.io/github/license/Kntnt/kntnt-extractor)](LICENSE)
[![Latest release](https://img.shields.io/github/v/release/Kntnt/kntnt-extractor)](https://github.com/Kntnt/kntnt-extractor/releases/latest)

A WordPress plugin exposing a minimal, capability-gated REST API for downloading a selection of database tables and files from a site.

<!--
This README follows the canonical, audience-layered structure: the sections run
from the least committed reader (Users) to the most committed (Contributors), so
each reader can stop where their interest ends. Fill the prose under each
heading; keep the order. Sections marked optional may be dropped when the
project does not warrant them. The two boilerplate blocks (Questions, bugs, and
feature requests; the Changelog line) are fixed wording — leave them as written,
substituting only the owner and repository name.
-->

## Description

Kntnt Extractor is a WordPress plugin for site owners, agencies and tooling authors who need a controlled way to pull a subset of a site's database tables or files out over HTTP — a migration script, a staging-to-local sync tool, a support technician who needs one table without a full backup. It replaces ad hoc `mysqldump`/SFTP access with a REST API that a WordPress user's existing permissions already govern.

### Key features

- REST API only, no admin screen — install it and grant a capability, nothing to configure
- every request authenticates as a real WordPress user (an application password) and is authorised by WordPress's own administrator capability — no separate access list to maintain
- a fixed, minimal set of endpoints: list tables, list files, extract a named selection, check status
- large extractions run as a background job and are fetched through a one-time download link, so nothing sits open on the server
- self-hosted update checks against GitHub releases, so the Plugins screen shows updates without a WordPress.org listing

### The problem

Getting a subset of a WordPress site's data out — for a migration, a local copy, or a single table a support case needs — usually means either full server access (SSH, SFTP, phpMyAdmin) or a bespoke one-off script. Both are more access, and more code, than the task calls for, and neither gives a site owner a way to see afterwards who took what.

### How this project helps

The plugin exposes exactly the operations this kind of task needs — list what exists, extract a named selection, fetch the result — behind WordPress's own permission model, and records every extraction so it can be reviewed later.

## Requirements

- WordPress 6.0 or later
- PHP 8.4 or later

## Installation

Download the latest release from [the releases page](https://github.com/Kntnt/kntnt-extractor/releases/latest/download/kntnt-extractor.zip) and install it like any other WordPress plugin (**Plugins → Add New → Upload Plugin**). Once active, grant the `kntnt_extractor_operate` capability to whichever WordPress user should be allowed to call the API — an administrator has it by default.

## Usage

Authenticate with an [application password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for a user holding both `kntnt_extractor_operate` and `manage_options` (an administrator has both), then call the plugin's REST namespace (`kntnt-extractor/v1`) to list the available tables and files, request an extraction, and fetch the result once it is ready. Both capabilities are required: `kntnt_extractor_operate` is the plugin's on switch, and `manage_options` authorises the data — a user with Operate but without `manage_options` can reach the API yet neither list nor extract anything.

### Restricted paths

`GET /files` lists every file in the installation, including credential-bearing ones, but `POST /extractions` refuses to extract a selection that names one — a `422 kntnt_extractor_restricted_path` naming every offending path, decided before the request is even checked for whether the files exist. The deny-list, matched case-insensitively against the installation-root-relative path, is the normative list a caller mirrors when assembling its own exclusion set:

- `wp-config.php` and its backup/editor-droppings siblings — `wp-config.php.*`, `wp-config.php~`, `wp-config-*.php` — anywhere in the tree. `wp-config-sample.php` is explicitly excepted; it holds no secrets.
- `.env` and `.env.*`, anywhere in the tree.
- Directly in the installation root only: `*.sql`, `*.sql.gz`, `*.sql.zip`, `*.pem`, `*.key`, `id_rsa*`.

### `GET /environment`

Returns generic runtime and configuration facts a migration or staging caller needs — PHP version, the database engine's flavour/version/collation, WordPress URLs/paths/prefix/core version, the active-plugins option, the drop-ins present, and every `define()` name found in `wp-config.php`. Define **names** are always listed; a define's **value** is disclosed only from a small, curated allow-list of layout and behaviour facts (`DB_NAME`, `WP_CONTENT_DIR`, `WP_DEBUG`, and similar) plus a heuristic backstop that withholds anything shaped like a credential (a name containing `KEY`, `SECRET`, `TOKEN`, `PASS`, `SALT`, `NONCE`, `AUTH`, `CREDENTIAL`, `PRIVATE`, `LICEN`, or `API`), applied after the allow-list and never overridden by it. Everything else — SMTP passwords, API keys, licence keys, secondary database credentials, and any other third-party secret a site happens to define in `wp-config.php` — stays on the server by default, the same way `DB_PASSWORD` and the auth keys and salts always have.

Each entry in `defines` carries a third member, `disclosure`, naming why: `included` (the value is disclosed), `secret` (withheld — the name is shaped like a credential), or `not_allow_listed` (withheld — the name simply isn't on the curated list). It is present on every entry, disclosed ones included, so a caller never has to guess whether `null` means "withheld" or "this define's real value is null" — see `docs/define-disclosure.md` for the full protocol.

To opt a specific unlisted define in on your own site, set `KNTNT_EXTRACTOR_DISCLOSABLE_DEFINES` in `wp-config.php` (or the `kntnt_extractor_config_disclosable_defines` filter) to a list of its name(s). This is a per-site, explicit decision — there is no way to disclose everything at once.

### Large tables and files

Nothing is packaged whole. A selected table is dumped in bounded slices of rows and a selected file is read in bounded parts, one chunk per background tick, so a table or a file far larger than a single PHP request could carry still completes — it simply takes more ticks. Each chunk is sealed on its own and recorded in the artifact's sealed index under the table's name or the file's installation-root-relative path, which means a resource larger than one chunk appears in the index several times.

**To reassemble a table or a file, concatenate every segment carrying its name, in index order.** A reader that assumes one segment per name keeps only the last chunk of anything large.

Three knobs bound the chunks, all settable as a `wp-config.php` constant or through the matching `kntnt_extractor_config_*` filter:

- `KNTNT_EXTRACTOR_TABLE_CHUNK_BYTES` — bytes of rendered rows per table slice, default 4 MiB. This is the bound that matters on a table of few fat rows, which fits any row budget and still exceeds what a request can do.
- `KNTNT_EXTRACTOR_TABLE_CHUNK_ROWS` — rows per table slice, default 1000. The coarser bound; a slice ends at whichever of the two is reached first.
- `KNTNT_EXTRACTOR_CHUNK_SIZE` — bytes per file part, default 256 KB. This is the one bound the project has measured against a real host, and the number is smaller than it looks: on the site it was measured on, a 36 MB file packaged in 85 s at 256 KB, while at 4 MiB the same file managed two parts in twelve minutes before the run was abandoned — about forty minutes for the whole file, extrapolated. The two directions do not cost the same, which is why the default errs small: too small a part only slows a run down, while too large a part killed one production run outright — before a stalled chunk halved itself, as it now does — and left the run after that spending 44 % of its wall clock on three files. It is not claimed optimal — nothing smaller was tested — and it is not claimed to be right for your host, because the right value is host-specific. If large files crawl on your site, measure before you raise it: vary this knob against one large file and read the part count off `GET /extractions/{id}`, which takes minutes. The method and the numbers are in `docs/measurements/2026-08-19-chunk-size-curve.md`.

The file-part budget can also be chosen per run, without touching the site at all: send `"chunk_size": 262144` in the `POST /extractions` body and that job packages its file parts at that size. It must be a whole number of at least 1 — the same range the constant and the filter already resolve to — and anything else is a `422 kntnt_extractor_malformed_body` like any other malformed member. Omit it and the job uses the constant, the filter, or the default exactly as before. This matters because the value that lets a long extraction survive is host-specific and has to be measured: on one production host, lowering it from the then-8 MiB default to 256 KB was the only deliberate change between a clone that died after six hours and one that finished in 3.6. An authenticated `GET /status` names `chunk_size` in `honours`, which is how a client tells a build that accepts the member from one that ignores it.

Before packaging anything, every tick asks the host for room: `KNTNT_EXTRACTOR_MAX_EXECUTION_TIME` seconds (default 900) and `KNTNT_EXTRACTOR_MEMORY_LIMIT` bytes (default 1 GiB). Both are requests, and both are free to be refused — many managed hosts lock the directives, and a container's own memory cap overrules PHP either way. The raise never lowers a limit the host already grants, and setting either knob to `0` asks for nothing. This is the cheap remedy, so it runs first.

If a chunk is still too big for the host, the job neither hangs nor dies: after `KNTNT_EXTRACTOR_MAX_STALL_ATTEMPTS` attempts (default 2) that begin the same chunk and never finish it, the job halves the bounds that chunk spends and carries on, keeping everything it has already packaged. A file part halves its byte budget; a table slice halves both of its own, because the byte budget caps only what is rendered while the row budget caps what is fetched into memory. So the run calibrates itself to the host instead of asking you to guess a constant, and the knobs above are a starting point rather than something you must get right.

Only a chunk whose bounds have all reached their floor — one byte, one row — still fails the job. Then the poll's `error.message` names the table and row (or file and byte) it stalled on, and reports two pairs of limits: what the host is configured with, and what the plugin is actually running under after asking for more. Read the difference. Equal pairs mean the host refused the request, so raise the limits in the host configuration. Differing pairs mean the raise was granted and the chunk died anyway, so the kill came from the web server or the container rather than from PHP, and no chunk size will help.

### How large a selection one request may carry

`POST /extractions` is validated before the caller's capability is checked — that is what lets it answer "no such table" rather than "not permitted" to a request for something that does not exist — so two caps bound what an anonymous request can cost before it is bounded by anything else. Both are settable as a `wp-config.php` constant or through the matching `kntnt_extractor_config_*` filter:

- `KNTNT_EXTRACTOR_MAX_SELECTION_ELEMENTS` — combined entries across `tables`, `tables_structure_only` and `files`, default 500,000. Over it, the request is `422 kntnt_extractor_selection_too_large`.
- `KNTNT_EXTRACTOR_MAX_BODY_BYTES` — raw request body size, default 50 MiB. Over it, the request is `413 kntnt_extractor_payload_too_large`, decided before the plugin parses the body. WordPress itself parses an `application/json` body earlier still, so an oversized body that is not valid JSON is refused `400 rest_invalid_json` by WordPress and never reaches this cap.

Both defaults are about ten times a real production selection (186 tables and 49,116 files, encoding to 4.47 MiB), so no ordinary clone should ever meet either. If one does, raise the constant on that site — each refusal reports the `limit` it was measured against and your own `count` or `bytes` in the error's `data`, so you can split the selection or raise the knob knowing the exact number. An authenticated `GET /status` names `selection_limits` in `honours`, which is how a client tells a build that enforces these caps from one that does not.

These bound one request, not a sequence of them. Rate limiting is your web server's or host's job; the plugin has no knob for it.

### Telling a slow job from a stuck one

A poll of a running or ready job carries `progress`:

```json
{ "tables_done": 3, "tables_total": 186, "files_done": 0, "files_total": 49228, "chunks_done": 412 }
```

The four table and file counters advance only when a whole table or a whole file is finished, so a job working steadily through one large table reports the same counters for minutes at a time. `chunks_done` counts packaging chunks — one table slice, one structure-only table, or one file part — so it moves on every chunk the build seals. **Watch `chunks_done` for liveness and the other four for completion.** It has no total, because how many slices a table takes is not knowable before it is dumped; on a ready job it equals the number of segments the artifact holds.

### Checking what of yours is still on the site

`GET /extractions` normally lists only your own live jobs — queued, running, or ready — so a stranded job can be found and cancelled. Add `?state=all` and the same call additionally lists your own terminal jobs: `consumed`, `cancelled`, `failed`, and `expired`. It answers "is there sealed data of mine still on this site", the question that matters before or after a run: a terminal entry carries only its id, state, and timestamps — never `progress`, never a `download_url`, since there is nothing left to fetch for it. Another user's job is never listed, terminal or not; the owner scope is exactly as narrow with `state=all` as it is by default.

### Checking who you are authenticated as

`GET /status` is unauthenticated and returns the REST contract's API version. Send credentials with it anyway and it also tells you who they resolved to:

```json
{
  "api_version": 7,
  "authenticated_as": "your-wp-user-login",
  "capabilities": { "kntnt_extractor_operate": true, "manage_options": true }
}
```

`authenticated_as` is the WordPress `user_login` — often an email address, since that is what many sites' logins are. If those two members are missing from the response, your credentials did not reach WordPress or did not name an existing user, and no capability grant will fix that.

### Discovering what this build honours

`api_version` answers one question — may a client proceed with this artifact contract at all — and answers it before you have credentials, because a wrong-version server should be refused on the cheapest possible call. It does not answer whether a particular behaviour, such as `strict: false` on `POST /extractions`, exists in this build: a behaviour can ship without moving `api_version`, so the version number alone cannot tell you. Send credentials with `GET /status` and the response also carries `honours`, a sorted list of the caller-visible behaviour names this build implements. A name absent from the list is a behaviour this build does not have; check for it before depending on it, in either direction, since production can run a release older than the client expects.

### When a request is refused

Each refusal names its own cause, so the remedy is never a guess:

- `401 kntnt_extractor_not_authenticated` — the request resolved to no WordPress user. The `Authorization` header did not arrive, named a user that does not exist, or carried a wrong application password. Check `GET /status` with the same credentials.
- `403 kntnt_extractor_missing_operate_capability` — you are authenticated, but your user lacks `kntnt_extractor_operate`. Grant it, or deactivate and reactivate the plugin to restore the default administrator grant.
- `403 kntnt_extractor_missing_manage_capability` — you are authenticated and hold the plugin's on switch, but not `manage_options`.

### Caching

No response from the `kntnt-extractor/v1` namespace may be cached: every one of them depends on who asked, and the refusals most of all. The plugin sends `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Vary: Authorization` on every response whatever its status code, and additionally marks the response uncacheable to LiteSpeed through that plugin's own control API.

This matters because a cached refusal is indistinguishable from a real one. Before this was enforced, a single request with a wrong username on a site behind a page cache could have its 401 stored against the URL and replayed to every later caller — correct credentials included — until the cache was purged. If you ever suspect an intermediary you do not control is still doing this, add a unique query parameter to the request; if the identical request then succeeds, a cache was answering it.

## Questions, bugs, and feature requests

Have a usage question or something to discuss? Please use [Discussions](https://github.com/Kntnt/kntnt-extractor/discussions).

Found a bug or want to request a feature? Please [open an issue](https://github.com/Kntnt/kntnt-extractor/issues). Search the existing issues first to avoid duplicates.

## Development

Clone the repository and install PHP dependencies with `composer install`. The coding standard this project follows is materialised under `agents.d/coding-standard/` — read `general.md` plus `php.md` and `wordpress.md` before changing any code. The sealed extraction artifact's byte format is specified normatively in [`docs/container-format.md`](docs/container-format.md); read it before changing `classes/Crypto/Sealed_Writer.php` or anything that reads its output. Cutting a release follows [`docs/release-procedure.md`](docs/release-procedure.md), including the two pre-tag guards it exists to record.

## How you can contribute

Contributions are welcome, small or large. Before you start, read [`CONTRIBUTING.md`](CONTRIBUTING.md) — it covers which kinds of change are likely to be merged and how inbound licensing works.

## License

Licensed under the GNU General Public License v2.0. The full licence text is in [`LICENSE`](LICENSE).

## Changelog

Release notes for each version live in [`CHANGELOG.md`](CHANGELOG.md).

The project follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).

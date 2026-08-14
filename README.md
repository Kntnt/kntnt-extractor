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

### Large tables and files

Nothing is packaged whole. A selected table is dumped in bounded slices of rows and a selected file is read in bounded parts, one chunk per background tick, so a table or a file far larger than a single PHP request could carry still completes — it simply takes more ticks. Each chunk is sealed on its own and recorded in the artifact's sealed index under the table's name or the file's installation-root-relative path, which means a resource larger than one chunk appears in the index several times.

**To reassemble a table or a file, concatenate every segment carrying its name, in index order.** A reader that assumes one segment per name keeps only the last chunk of anything large.

Three knobs bound the chunks, all settable as a `wp-config.php` constant or through the matching `kntnt_extractor_config_*` filter:

- `KNTNT_EXTRACTOR_TABLE_CHUNK_BYTES` — bytes of rendered rows per table slice, default 4 MiB. This is the bound that matters on a table of few fat rows, which fits any row budget and still exceeds what a request can do.
- `KNTNT_EXTRACTOR_TABLE_CHUNK_ROWS` — rows per table slice, default 1000. The coarser bound; a slice ends at whichever of the two is reached first.
- `KNTNT_EXTRACTOR_CHUNK_SIZE` — bytes per file part, default 8 MiB.

If a chunk is still too big for the host, the job does not hang: after `KNTNT_EXTRACTOR_MAX_STALL_ATTEMPTS` attempts (default 3) that begin the same chunk and never finish it, the job reports `failed` and its poll's `error.message` names the table and row (or file and byte) it stalled on, together with the host's `memory_limit` and `max_execution_time`. Lower the relevant knob and request the extraction again.

### Telling a slow job from a stuck one

A poll of a running or ready job carries `progress`:

```json
{ "tables_done": 3, "tables_total": 186, "files_done": 0, "files_total": 49228, "chunks_done": 412 }
```

The four table and file counters advance only when a whole table or a whole file is finished, so a job working steadily through one large table reports the same counters for minutes at a time. `chunks_done` counts packaging chunks — one table slice, one structure-only table, or one file part — so it moves on every chunk the build seals. **Watch `chunks_done` for liveness and the other four for completion.** It has no total, because how many slices a table takes is not knowable before it is dumped; on a ready job it equals the number of segments the artifact holds.

### Checking who you are authenticated as

`GET /status` is unauthenticated and returns the REST contract's API version. Send credentials with it anyway and it also tells you who they resolved to:

```json
{
  "api_version": 6,
  "authenticated_as": "your-wp-user-login",
  "capabilities": { "kntnt_extractor_operate": true, "manage_options": true }
}
```

`authenticated_as` is the WordPress `user_login` — often an email address, since that is what many sites' logins are. If those two members are missing from the response, your credentials did not reach WordPress or did not name an existing user, and no capability grant will fix that.

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

Clone the repository and install PHP dependencies with `composer install`. The coding standard this project follows is materialised under `agents.d/coding-standard/` — read `general.md` plus `php.md` and `wordpress.md` before changing any code.

## How you can contribute

Contributions are welcome, small or large. Before you start, read [`CONTRIBUTING.md`](CONTRIBUTING.md) — it covers which kinds of change are likely to be merged and how inbound licensing works.

## License

Licensed under the GNU General Public License v2.0. The full licence text is in [`LICENSE`](LICENSE).

## Changelog

Release notes for each version live in [`CHANGELOG.md`](CHANGELOG.md).

The project follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/).

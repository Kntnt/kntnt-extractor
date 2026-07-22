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

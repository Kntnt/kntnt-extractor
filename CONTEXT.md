# kntnt-extractor

A WordPress plugin exposing a minimal REST API for extracting a selection of database tables and/or files from a site. Independent of any other Kntnt project; also the intended replacement control channel for `kntnt-wp-skills`'s `clone`/`pull` skills ([kntnt-wp-skills#24](https://github.com/Kntnt/kntnt-wp-skills/issues/24)).

## Language

### Access control

**Operate capability**:
The plugin-defined WordPress capability `kntnt_extractor_operate`. Grants the right to call the REST API at all — the plugin's "on switch", inert until deliberately granted. Necessary but not sufficient: every listing and extraction request also requires the caller to hold `manage_options`, the administrator capability. The two compose — Operate opens the door, `manage_options` authorises the data — so a user with Operate but without `manage_options` reaches the API surface yet can neither list nor extract anything.
_Avoid_: API access, permission

### Extraction

**Manifest**:
The unfiltered, recursive listing (path, size, mtime) of every file from the WordPress installation root downward. Carries no categorisation of what any file is for — that judgement belongs to the caller, not the plugin.
_Avoid_: file list, scan

**Table list**:
The enumeration of tables that exist in the site's database, the file-side manifest's counterpart.

**Extraction job**:
The background job, created from an explicit, already-resolved list of table names and/or file paths, that packages and encrypts the requested selection. Runs detached and polled rather than inline in the request, so it is not bound by a single HTTP request's timeout.
_Avoid_: export, backup, dump

**Download link**:
The short-lived, single-use link an extraction job's artifact is fetched through once ready. Consumed (deleted server-side) after a verified download, rather than waiting on an expiry timer.

### Operations

**API version**:
The REST contract's own version number, distinct from the plugin's release version. Increments only when a caller-visible behaviour changes — including a subtler, purely behavioural change, not only a change to endpoints or arguments — never for a fix that leaves the contract as callers already understood it.
_Avoid_: plugin version (as a synonym)

**Audit log**:
The append-only record of every successful extraction (user, tables/files, timestamp). Stored as a randomly-named file, not a database table; read only through its own REST endpoint, gated on `manage_options`.

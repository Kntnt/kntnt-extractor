# A file-based audit log, randomly named, age-rotated, readable only through its own REST endpoint

Every successful extraction is recorded — user, tables/files, timestamp — in a single append-only log file rather than a database table. A dedicated table was considered and rejected as overkill for what the operator wants: a lightweight, no-schema-migration record, not a queryable audit system. The file lives under `wp_upload_dir()`, a location the plugin can always write to, but that directory is ordinarily web-reachable, and there is no server-config-based way to lock it down that works identically on both Apache (`.htaccess`) and Nginx (which ignores `.htaccess` entirely). The file's name is therefore a long random string, unguessable by construction, and the log's *sanctioned* read path is exclusively its own authenticated REST endpoint — the random name is defence in depth against direct-URL discovery, not the primary access control.

Reading the log requires `manage_options`: an audit trail that only shows a user their own actions doesn't serve its purpose, so full visibility is kept administrator-only rather than filtered per user.

Rotation is by age, not entry count, since the log's purpose is "what happened in a given period," not a fixed capacity. The retention window defaults to 90 days, overridable by both a constant (`KNTNT_EXTRACTOR_LOG_RETENTION_DAYS`) and a filter (`kntnt_extractor_log_retention_days`, which wins if both are set) — a constant for `wp-config.php`-level control without code, a filter for programmatic control. When the log empties out through rotation, the file (and its containing directory, if also then empty) is deleted rather than left behind empty; the next logged event creates a fresh file under a newly generated random name, not the old one. The file and its directory are also deleted on uninstall.

## Consequences

- Nothing about the log depends on the web server's own access-control mechanism (`.htaccess`, `Require all denied`, or equivalent) — it works identically regardless of server software.
- A leaked filename from before a rotation-triggered deletion is worthless afterwards, since the replacement file gets a new random name.

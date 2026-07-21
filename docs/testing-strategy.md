# Testing strategy

## Integration suite (WordPress Playground)

The plugin's behaviour is verified end-to-end against a real WordPress, dispatching requests through the live REST server exactly as an HTTP client would reach them. The harness runs inside a [WordPress Playground](https://wordpress.github.io/wordpress-playground/) instance — WASM PHP plus SQLite — which is the default integration harness in `agents.d/coding-standard/wordpress.md`. It needs no MySQL server and no local web server, so it runs the same on a laptop and in CI.

MySQL-backed tooling (DDEV, the WordPress core PHPUnit suite) is the standard's explicit fallback, reserved for behaviour Playground cannot exercise — MySQL-specific SQL, locking semantics, or multi-process cron. `GET /tables` is the first behaviour that needs it: its row-count and byte-size estimates come from `SHOW TABLE STATUS`, whose `Rows`, `Data_length`, and `Index_length` columns SQLite stubs to zero, so their magnitudes can only be verified against a real MySQL-family engine. See the DDEV harness below.

### Running it

```
composer test:integration
```

or directly:

```
bash tests/Integration/run.sh
```

The runner pins the Playground CLI version for reproducibility, boots WordPress with the plugin mounted, and runs the suite. It exits non-zero if any assertion fails, and prints a TAP report of every check.

Node.js (for `npx`) and network access are required the first time, to fetch the pinned Playground CLI and the WordPress build.

### How it is wired

- `tests/Integration/blueprint.json` — the Playground blueprint; its single `runPHP` step requires the bootstrap.
- `tests/Integration/bootstrap.php` — boots WordPress, activates the plugin, defines a minimal TAP assertion helper (`kntnt_extractor_assert()`), then runs every `*-test.php` in the directory and fails the process on any failed assertion.
- `tests/Integration/*-test.php` — one file per concern. A new test is added by dropping another `*-test.php` here; the bootstrap discovers it with no further wiring.

Current tests:

- `activation-test.php` — the plugin activates and deactivates cleanly, and the autoloader resolves its classes.
- `config-seam-test.php` — the `Config` seam resolves a value from a constant and lets a filter override it (filter wins).
- `rest-status-test.php` — `GET /kntnt-extractor/v1/status` returns `{ "api_version": 1 }` unauthenticated, the namespace appears in the REST index, and no plugin release version leaks.
- `tables-test.php` — `GET /tables` answers an authorized caller, its entries are well formed, and the listing is exactly the site's own `SHOW TABLES` (neither padded nor filtered). It does not assert the row/byte magnitudes: SQLite stubs those engine statistics to zero, so that check lives in the DDEV harness below.
- `files-manifest-test.php` — drives the `Manifest` directly against a controlled, adversarial temporary tree (a directory and a sibling file colliding on a prefix). It proves the canonical depth-first, per-component path ordering, that only leaf files are emitted with paths relative to the root, and that paging at sizes 1/2/3/5 reassembles the whole listing in order with no gaps or duplicates, plus opaque- and malformed-cursor handling.
- `files-endpoint-test.php` — `GET /files` reuses the both-capabilities Authorizer (403 otherwise), returns `path`/`size`/`mtime` entries with no categorisation, honours the `Config` page-size knob, round-trips the opaque cursor through the REST layer, and answers 400 for a malformed cursor. The exhaustive no-gaps/no-duplicates reassembly proof lives in `files-manifest-test.php` so this suite need not page the entire install.
- `extractions-test.php` — `POST /extractions` creates a queued job and `GET /extractions/{id}` polls it. Pins the whole validation ladder from an unauthorized caller so each precedes the capability gate: a well-formed-but-wrong-shape body is 422, an absent or malformed public key 400, and an unknown table or a file resolving outside the installation root a 404 decided before the 403 (ADR-0003). It proves the job state persists as JSON in a randomly-named directory both under uploads by default and at the `KNTNT_EXTRACTOR_WORK_DIR` override (hardened with index.html and an .htaccess/web.config deny), that a job is bound to its creator (a capable non-owner polling it is 403), and that the one-non-terminal-job concurrency rule answers a second create with 429 unless the `max_active_jobs` knob raises it. A syntactically invalid JSON body is a 400 owned by WordPress core, one layer below this contract.
- `tick-extraction-test.php` — the tick-driven execution tracer bullet end to end (ADR-0004/0007/0009). It drives a two-table-plus-one-file selection to a sealed artifact through the internal `POST /extractions/{id}/tick` endpoint and proves: the tick is authenticated by the job's own secret alone, so an outsider — even the capable owner — without it is refused 403 while an anonymous caller holding it drives the job (AC1); a single tick takes the job queued → running → ready, the running phase observed mid-tick through the `kntnt_extractor_job_running` action, and dumps each of the two tables as its own sealed segment so "each table" is exercised with a plurality, not just the first (AC2); each table dumps as mysqldump-compatible SQL — `DROP TABLE`, `CREATE TABLE`, `INSERT INTO`, asserted for both tables (AC3, whose real `SHOW CREATE TABLE` DDL the SQLite harness happens to translate faithfully); a ready poll returns a `download_url` that a web server serves directly — the artifact lives in a separate served downloads directory that no deny-all `.htaccess`/`web.config` governs (the check walks the artifact's directory ancestry and uses the deny-hardened state directory as a positive control) — at an unguessable per-artifact path that discloses no job id and beside which no `job.json` sits, so the state file (tick secret, plaintext selection) is neither served nor derivable from a leaked link (AC4); a self-generated X25519 keypair round-trips both a seeded table row and a packaged file byte-for-byte through the seal (AC5); the persisted job state holds none of the private key or the recovered per-segment keys, only the harmless public key (AC6); and a status poll nudges a queued or stalled job's tick endpoint with its secret but never one currently being ticked or already ready (AC7). Loopback nudges are short-circuited through `pre_http_request` so they are asserted without touching the network.

## MySQL-backed integration check (DDEV)

The row-count and byte-size estimates in `GET /tables` are the storage engine's own `SHOW TABLE STATUS` figures. WordPress Playground runs on SQLite, whose translation of that statement reports `Rows`, `Data_length`, and `Index_length` as zero, so the fast suite can only verify the listing's shape — never that a populated table reports a plausible, positive estimate. That verification is the standard's DDEV fallback for MySQL-specific SQL.

`tests/Integration/DDEV/run.sh` provisions a throwaway DDEV WordPress project on a real MySQL-family (InnoDB) database in a temporary directory, activates the plugin, seeds a little content, and asserts through `tests/Integration/DDEV/tables-size-test.php` that the options, users, and posts tables report a positive byte-size estimate and a positive estimated row count. It tears the whole project down again on exit, so the machine is left state-neutral.

It requires Docker and DDEV, and is deliberately **not** part of `composer gate` — MySQL-backed tests are the exception, kept out of the fast PR-time suite. Run it on demand:

```
composer test:integration:mysql
```

or directly:

```
bash tests/Integration/DDEV/run.sh
```

## Static analysis and coding standard

- `composer phpstan` — PHPStan at `level: max`, with the `Requires PHP` floor enforced through `phpVersion`.
- `composer phpcs` — the WordPress Coding Standards with the project's documented deviations.

## The gate

`composer gate` runs `phpcs`, `phpstan`, and the integration suite in sequence — the full pre-integration check.

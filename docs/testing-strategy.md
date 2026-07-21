# Testing strategy

## Integration suite (WordPress Playground)

The plugin's behaviour is verified end-to-end against a real WordPress, dispatching requests through the live REST server exactly as an HTTP client would reach them. The harness runs inside a [WordPress Playground](https://wordpress.github.io/wordpress-playground/) instance — WASM PHP plus SQLite — which is the default integration harness in `agents.d/coding-standard/wordpress.md`. It needs no MySQL server and no local web server, so it runs the same on a laptop and in CI.

MySQL-backed tooling (DDEV, the WordPress core PHPUnit suite) is the standard's explicit fallback, reserved for behaviour Playground cannot exercise — MySQL-specific SQL, locking semantics, or multi-process cron. The walking skeleton needs none of that, so Playground is the whole harness for now.

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

## Static analysis and coding standard

- `composer phpstan` — PHPStan at `level: max`, with the `Requires PHP` floor enforced through `phpVersion`.
- `composer phpcs` — the WordPress Coding Standards with the project's documented deviations.

## The gate

`composer gate` runs `phpcs`, `phpstan`, and the integration suite in sequence — the full pre-integration check.

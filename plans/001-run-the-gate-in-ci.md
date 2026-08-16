# Plan 001: Run `composer gate` automatically on every push and pull request

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- composer.json tests/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: dx
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

This repository has an unusually thorough verification gate — a coding-standard
sniff, PHPStan at `level: max`, a ~5-minute end-to-end integration suite driving
a real WordPress REST stack, and a release-archive build test. Nothing runs it
automatically. There is no `.github/` directory at all.

That matters more here than in most projects because of how this plugin ships.
The release archive is built with `git archive … HEAD` straight from committed
content, and it is then installed onto a live production site by hand through
wp-admin. A separate client repository pins a verified ceiling on this plugin's
REST `api_version` and refuses to run against a higher one. So a commit that
breaks the gate is discovered late, on production, and is expensive to roll
back.

After this plan, every push and every pull request runs the same four steps a
developer runs locally, and a red result is visible before anyone builds a
release from that commit.

## Current state

There is no CI configuration of any kind in this repository:

```
$ ls -a .github
ls: .github: No such file or directory
```

The gate is defined entirely in Composer scripts. `composer.json:35-49`:

```json
    "scripts": {
        "phpcs": "phpcs --standard=phpcs.xml.dist",
        "phpcbf": "phpcbf --standard=phpcs.xml.dist",
        "phpstan": "phpstan analyse --memory-limit=1G",
        "test:integration": "bash tests/Integration/run.sh",
        "test:integration:mysql": "bash tests/Integration/DDEV/run.sh",
        "test:build": "bash tests/Build/build-release-zip-test.sh",
        "test": "@test:integration",
        "gate": [
            "@phpcs",
            "@phpstan",
            "@test:integration",
            "@test:build"
        ]
    },
```

Facts that shape the workflow file:

- **Runtime requirement** is `"php": ">=8.4"` (`composer.json:13-15`). The plugin
  header declares `Requires PHP: 8.4` (`kntnt-extractor.php:8`).
- **There are no runtime Composer dependencies.** Everything in `require-dev` is
  tooling: PHPStan 2, PHP_CodeSniffer 3, WPCS 3, phpstan-wordpress 2.
- **The integration suite needs Node and network access.** `tests/Integration/run.sh:24`
  invokes `npx --yes "@wp-playground/cli@3.1.46" run-blueprint`, which downloads
  the pinned Playground CLI and a WordPress build on first run. GitHub-hosted
  runners have Node preinstalled and network access, so no extra setup step is
  needed beyond what is listed below.
- **The suite runs WASM PHP 8.5 inside Playground** (`tests/Integration/run.sh:25`,
  `--php=8.5`), independently of the PHP version that runs Composer and PHPStan.
  Do not try to make these two match — they are deliberately separate. The
  runner's own header comment documents the 8.5 choice.
- **The suite takes about five minutes.** `composer.json:29` sets
  `"process-timeout": 1800` so Composer does not kill it at the default 300 s.
- `composer.lock` is committed, so the CI install must be `--no-interaction` and
  should use the lockfile.
- `tests/Integration/DDEV/run.sh` (the MySQL harness) is **deliberately excluded**
  from `composer gate` — it needs Docker and DDEV. Do not add it to CI.

Repository conventions this plan must honour:

- All comments and documentation are in **English**
  (`agents.d/coding-standard/general.md`, "Language").
- `CHANGELOG.md` follows Keep a Changelog. The current `[Unreleased]` section
  already has `### Fixed`, `### Added` and `### Changed` subsections at
  `CHANGELOG.md:7`, `:19` and `:25`.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Install deps | `composer install --no-interaction` | exit 0 |
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Build test | `composer test:build` | exit 0 |
| Everything | `composer gate` | exit 0 |
| Lint the workflow locally | `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/gate.yml'))"` | exit 0, no output |

## Scope

**In scope** (the only files you should create or modify):

- `.github/workflows/gate.yml` (create)
- `CHANGELOG.md` (one line under `[Unreleased]` → `### Added`)

**Out of scope** (do NOT touch, even though they look related):

- `composer.json` — the gate's definition is correct as it stands. Do not add,
  reorder, or rename scripts.
- `tests/Integration/run.sh` — in particular, do **not** change `--php=8.5`.
  A separate finding covers the declared-versus-tested PHP floor and was not
  selected; changing it here is out of scope and would confuse that decision.
- `tests/Integration/DDEV/**` — the MySQL harness is intentionally outside the
  gate. Adding it to CI would require Docker and DDEV on the runner.
- Any change to `classes/**`. This plan adds no production code.

## Git workflow

- This project is trunk-based: commit straight to `main`. Do not create a
  branch and do not open a pull request.
- Commit message style, from `git log --format=%s`: an imperative sentence, no
  prefix, often two clauses. Examples from this repo:
  - `Record a bounded last-N of begun chunks on the job, not the audit log`
  - `Treat a traversal that fails to resolve as out of bounds, never as a skip`
  Suggested message: `Run the gate in CI on every push and pull request`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Create the workflow file

Create `.github/workflows/gate.yml` with this content:

```yaml
# Runs the project's full verification gate — phpcs, phpstan, the WordPress
# Playground integration suite, and the release-archive build test — on every
# push and pull request, so a commit a release could be built from is never
# unverified.
name: Gate

on:
  push:
    branches: [ main ]
  pull_request:

permissions:
  contents: read

concurrency:
  group: gate-${{ github.ref }}
  cancel-in-progress: true

jobs:
  gate:
    name: composer gate
    runs-on: ubuntu-latest
    timeout-minutes: 20

    steps:
      - name: Check out the repository
        uses: actions/checkout@v4

      # The gate's phpcs and phpstan steps run on this PHP; the integration
      # suite runs its own WASM PHP inside WordPress Playground and is
      # unaffected by this version.
      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
          tools: composer:v2

      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ runner.os }}-php8.4-${{ hashFiles('composer.lock') }}
          restore-keys: composer-${{ runner.os }}-php8.4-

      - name: Install dependencies
        run: composer install --no-interaction --no-progress

      # The integration suite fetches the pinned Playground CLI and a WordPress
      # build through npx on first run, so this step needs network access.
      - name: Run the gate
        run: composer gate
```

**Verify**: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/gate.yml'))"`
→ exit 0, no output. (This only proves the YAML parses. Step 3 proves the gate
itself passes.)

### Step 2: Record the change in the changelog

Add one entry under the existing `### Added` heading in the `[Unreleased]`
section of `CHANGELOG.md` (the heading is at `CHANGELOG.md:19`). Match the
surrounding entries' style — full sentences, explaining what and why, ending
with a note on whether the REST contract moved. Suggested wording:

> - A GitHub Actions workflow runs `composer gate` — phpcs, PHPStan, the
>   WordPress Playground integration suite and the release-archive build test —
>   on every push to `main` and every pull request. The gate was already
>   comprehensive but ran only when someone remembered to run it, while the
>   release archive is built straight from committed content with no
>   verification step in between. No REST change.

**Verify**: `git diff --stat CHANGELOG.md` → exactly one file changed, and the
diff adds lines only inside the `[Unreleased]` section.

### Step 3: Prove the gate is actually green at this commit

Run the whole gate locally exactly as CI will run it. If it is red at HEAD, the
workflow you just added would be red on its first run, and you need to report
that rather than commit a workflow that is known-failing.

**Verify**: `composer gate` → exit 0. The integration step must print
`Integration suite: PASS`.

If `composer gate` fails, see STOP conditions.

### Step 4: Confirm nothing outside scope changed

**Verify**: `git status --short` → exactly two entries, one new file
`.github/workflows/gate.yml` and one modified `CHANGELOG.md`.

## Test plan

This plan adds no production code, so it adds no test to `tests/Integration/`.
Its verification is the gate itself:

- The workflow file parses as YAML (step 1).
- `composer gate` exits 0 at this commit (step 3), which is what the workflow
  will run.
- After pushing, the first CI run on `main` is the end-to-end proof. Do not
  push unless the operator asked you to; if you do not push, say so in your
  report so the operator knows the workflow has not yet had a real run.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `.github/workflows/gate.yml` exists and parses as YAML
- [ ] `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/gate.yml'))"` exits 0
- [ ] `grep -c 'composer gate' .github/workflows/gate.yml` returns at least 1
- [ ] `grep -q "php-version: '8.4'" .github/workflows/gate.yml` succeeds
- [ ] `grep -q 'DDEV' .github/workflows/gate.yml` **fails** (the MySQL harness must not be in CI)
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only `.github/workflows/gate.yml` and `CHANGELOG.md`
- [ ] `plans/README.md` status row for 001 updated

## STOP conditions

Stop and report back (do not improvise) if:

- `composer gate` fails at this commit. Report which of the four steps failed
  and its output verbatim. Do **not** "fix" the failure as part of this plan
  and do **not** commit a workflow you know is red — a pre-existing gate
  failure is information the operator needs before anything else lands.
- `composer install` cannot reach the network, so you cannot verify the gate at
  all. Report that the workflow was written but never verified.
- `.github/` already exists with workflows in it (the drift check should have
  caught this) — merging into an existing CI setup is a different task.
- The integration suite's `npx` step fails because the pinned Playground CLI
  version `@wp-playground/cli@3.1.46` is no longer available. That is a real
  problem worth reporting, but pinning a different version is out of scope here.

## Maintenance notes

- **What will interact with this**: every later plan in this series adds tests
  to `tests/Integration/`. They all run inside this one workflow step, and the
  suite is already ~5 minutes; the 20-minute job timeout leaves room but is
  worth watching.
- **What a reviewer should scrutinise**: that `composer gate` in CI is the same
  four steps as locally, and that the DDEV/MySQL harness was not smuggled in.
- **Deliberately deferred out of this plan**: a matrix over several PHP
  versions; running the DDEV harness on a schedule; any change to the
  declared-versus-tested PHP floor (`Requires PHP: 8.4` in the header versus
  `--php=8.5` in the Playground runner). Those were considered and not selected.

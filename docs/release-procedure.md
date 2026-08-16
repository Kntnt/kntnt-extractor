# Release procedure

## 1. Scope and status

This document is the durable, evidence-based statement of how a release of this plugin is cut, verified, and published. Every step below is traceable to a file and line in this repository or to a settled ADR; where the repository is silent on a step a real release needs, that is recorded under §9 rather than invented. Nothing here changes code or behaviour — it describes what the repository already does and what its own decisions already require.

Before this document existed, the procedure lived only in the maintainer's head. Two guards identified during a recent review had nowhere to be written down — §3 steps 2 and 3 — which is the immediate reason this document was written.

## 2. What a release is here

There is no WordPress.org listing (ADR-0005). A release is a tagged commit whose distributable — `kntnt-extractor.zip`, built by `build-release-zip.sh` — is attached to a GitHub release on `Kntnt/kntnt-extractor`, so the bundled self-hosted update checker (`classes/Update_Checker.php`) can find it. The checker points at `REPOSITORY_URL` (`classes/Update_Checker.php:41`), selects the release asset **by name** against `ASSET_NAME = 'kntnt-extractor.zip'` (`classes/Update_Checker.php:60`), and is configured with `GitHubApi::REQUIRE_RELEASE_ASSETS` (`classes/Update_Checker.php:132`) — it never falls back to GitHub's auto-generated source archive. A release published without an asset named exactly `kntnt-extractor.zip` attached is therefore not a release this plugin's own update mechanism can see: existing installs simply never show an update, with no error anywhere.

A release is not a WordPress.org submission, not a `readme.txt` "Stable tag" (there is no `readme.txt` in this repository), and not a `composer.json` version bump (`composer.json` carries no `version` field at all). The plugin's release version lives in exactly one place: the `Version:` header at `kntnt-extractor.php:6`.

The REST contract's own version, `API_VERSION` (`classes/Rest/Status_Controller.php:112`), is a separate number with a separate lifecycle — see §4.

## 3. Pre-tag checks, in order

These run against the commit that will be tagged, in this order, before the tag is created.

1. **`composer gate`** (mechanical). Runs `phpcs`, `phpstan`, `test:integration`, and `test:build` in sequence (`composer.json`'s `scripts.gate` array). This is the same command `.github/workflows/gate.yml` runs on every push to `main` and every pull request (`.github/workflows/gate.yml:51-52`), so the tip of `main` has normally already passed it in CI; running it again locally on the exact commit being tagged is what actually certifies *that* commit, since CI's last green run and the tag target are not guaranteed to be the same SHA once other work has landed. `test:build` here already exercises `build-release-zip.sh` and asserts the archive's contents (`tests/Build/build-release-zip-test.sh`) — see §6.

2. **`composer test:integration:mysql` must pass** (mechanical, but must be run by hand — see below). This runs `tests/Integration/DDEV/run.sh`, which provisions a throwaway DDEV WordPress project on real MySQL/InnoDB and runs every `*-test.php` in `tests/Integration/DDEV/`, including `table-dumping-test.php` (`tests/Integration/DDEV/run.sh:82-91`; file listing confirmed under `tests/Integration/DDEV/`). It is **deliberately excluded from `composer gate`** and therefore from CI (`docs/testing-strategy.md:60`, `.github/workflows/gate.yml` has no DDEV/Docker step) because it needs Docker and DDEV, which CI does not provision. This matters because the fast Playground suite that *does* run in `gate` executes on WASM PHP with SQLite, and SQLite's translation of `Table_Dumper`'s `SHOW KEYS`, its keyset predicate, its `LIMIT`/`OFFSET` fallback, and its `SHOW CREATE TABLE` DDL is not the same engine production runs on (`docs/testing-strategy.md:7,56`) — only the DDEV run proves the dumped SQL reloads row-for-row identical against real MySQL. Because this step runs only when someone remembers to run it, and this project releases deliberately and infrequently rather than continuously, a required pre-tag step is the correct binding here — a scheduled CI job would mostly run against a `main` that has not changed, which is not where the risk is.

3. **`Status_Controller::HONOURED_BEHAVIOURS` must name every caller-visible behaviour this build honours** (judgement call — requires reading the diff since the last release, not a command). The constant lives at `classes/Rest/Status_Controller.php:136-143`. ADR-0017 establishes that absence from this list is the only signal a client has that a behaviour is not implemented — there is no boolean map and no explicit `false` entry (ADR-0017 "Consequences", second bullet). A behaviour that ships without being named here is undiscoverable to a caller except by trying it and failing, which is exactly the failure ADR-0017 was written to close (`strict` shipped silently once, before this list existed). This is the same class of failure as `API_VERSION` shipping without a bump one layer up (§4) — an omission here is invisible in every automated check, because nothing verifies the list against the diff; it can only be caught by a person reading what changed and asking whether a name belongs.

## 4. The version decision

Two numbers move independently, and a release may move one, the other, both, or neither.

**The plugin version** (`kntnt-extractor.php:6`) follows Semantic Versioning (`CHANGELOG.md:3`: "the project uses Semantic Versioning"). It is bumped by hand in the one place it lives, to match whatever `CHANGELOG.md`'s `[Unreleased]` section, once resolved into a version heading (§5), actually contains.

**`API_VERSION`** (`classes/Rest/Status_Controller.php:112`) moves only on the two grounds ADR-0017 and ADR-0018 establish, and this document does not restate their arguments:

- ADR-0017's ground: the artifact's shape moves — the sealed container's framing, segments per resource, the sealed index, or reassembly order (ADR-0017 "Consequences", fourth bullet; `docs/container-format.md` §8 and §10 are the normative description of what counts as shape).
- ADR-0018's ground, which amends ADR-0017: an already-shipped, unmodified client's own existing behaviour would become unsafe against the new server in a way it cannot detect and did not opt into — a narrower, second condition that is not a shape change (ADR-0018, "The ground is compatibility, not shape" section).

Every other caller-visible change — a new field, a new parameter, a new optional behaviour an old client is free to keep ignoring — is a `HONOURED_BEHAVIOURS` addition instead (§3 step 3), never a version bump on its own (ADR-0017 "Consequences", third bullet: "If a REST-only change ever seems to need the integer bumped, that is a signal this ADR needs revisiting, not a signal to bump"). Deciding which of these two grounds, if either, applies to the set of changes going into a release is a judgement call against the two ADRs' own tests, not a mechanical check.

## 5. The changelog step

(Mechanical, once the version decision in §4 is settled.) `CHANGELOG.md` follows Keep a Changelog (`CHANGELOG.md:3`). Resolving a release means:

1. Renaming the `## [Unreleased]` heading (`CHANGELOG.md:5`) to `## [X.Y.Z] – YYYY-MM-DD`, matching the exact form of every prior release heading (e.g. `CHANGELOG.md:49`: `## [0.5.1] – 2026-08-14`) — ISO 8601 date, en dash.
2. Adding a fresh, empty `## [Unreleased]` heading above it for the next round of changes.
3. Adding the new version's link-reference line beside the others at the bottom of the file (e.g. `CHANGELOG.md:154`: `[0.5.1]: https://github.com/Kntnt/kntnt-extractor/releases/tag/v0.5.1`) and updating the `[Unreleased]` compare link (`CHANGELOG.md:153`) to `.../compare/vX.Y.Z...HEAD`.

No tooling in this repository automates any of these three steps; they are hand edits, matched against the existing entries' format.

## 6. Building and verifying the archive

`build-release-zip.sh` (repo root) builds the distributable from `git archive --prefix=kntnt-extractor/ --format=zip -o "$OUTPUT_FILE" HEAD -- "${KEEP[@]}"` (`build-release-zip.sh:158`), so **only committed content at `HEAD` ships** — no untracked or ignored file can leak in, at any depth (`build-release-zip.sh:5-7`). `KEEP` (`build-release-zip.sh:45-54`) is `autoloader.php`, `classes`, `kntnt-extractor.php`, `languages`, `lib`, `LICENSE`, `README.md`, `uninstall.php` — everything else the repository tracks (tests, `agents.d/`, `docs/`, dotfiles, Composer manifests, this document included) is excluded simply by not appearing in that list. The archive name carries no version segment (`build-release-zip.sh:9-13`) so the GitHub "latest/download" URL stays stable across releases.

`tests/Build/build-release-zip-test.sh` already asserts, as part of `composer gate` → `test:build` (§3 step 1): that the build script exists; that `Update_Checker::ASSET_NAME` reads `kntnt-extractor.zip`; that the built archive contains exactly one top-level `kntnt-extractor/` directory; that every runtime file needed to load and self-update is present (the bootstrap, the autoloader, `Plugin.php`, `Update_Checker.php`, the bundled update-checker library, `README.md`, `LICENSE`); and that every development-only path is absent (`tests/Build/build-release-zip-test.sh:86-119`). Passing `composer gate` therefore already certifies the archive's *shape*. It does not, by itself, produce the archive at a path ready to attach to a release — `test:build` writes into a throwaway `mktemp -d` (`tests/Build/build-release-zip-test.sh:20-21`) — so run `bash build-release-zip.sh` once more (mechanical) on the tagged commit to produce `dist/kntnt-extractor.zip` for §7.

## 7. Tagging and publishing

(Mechanical, given §4–§6 are settled.) Tag the release commit `vX.Y.Z` — every existing tag in this repository follows that form (`v0.1.0` through `v0.5.1`), matching the release links `CHANGELOG.md` already writes (e.g. `CHANGELOG.md:154`). Push the tag. Create the GitHub release from it, with `dist/kntnt-extractor.zip` (§6) attached under exactly that filename — the update checker's `REQUIRE_RELEASE_ASSETS` selection (§2) will not find it under any other name — and release notes drawn from the `CHANGELOG.md` entry just closed (§5).

**Who runs this step, and by what mechanism (`gh release create`, the GitHub web UI, or otherwise), is not established anywhere in this repository** — see §9.

## 8. The coordinated case

When §4 decides `API_VERSION` moves, this plugin's release cannot be considered complete on its own. `kntnt-wp-skills`, the only production reader of the sealed container (`docs/container-format.md` §1, §10), pins a **verified ceiling** against `api_version` and refuses to run against anything above it until that ceiling is raised in a coordinated release of both repositories (ADR-0018 "Consequences", first bullet; `CHANGELOG.md:41`). `docs/container-format.md` §10 states the same requirement for any change to the byte format specifically: "it requires a coordinated release of this plugin and of `kntnt-wp-skills`, plus a manual production install; it cannot be shipped and observed to work from this repository alone" (`docs/container-format.md:11,139`).

Concretely, for the change that last moved `API_VERSION` (6 → 7, ADR-0018): `kntnt-wp-skills` had to be made correct against the wider allow-list in the same coordinated release, and it was — not by moving its own mirrored deny-list to an allow-list (`is_secret_define()` at `scripts/discovery.py` and `define_class()`'s name-based families at `scripts/classify.py` are unchanged), but by deciding on the **value** instead: `scripts/classify.py`'s `classify_defines()` now auto-excludes any define whose value came back `null` under a new `WITHHELD_CLASS`, so the client stays correct whatever names the server's allow-list holds, present or future (`kntnt-wp-skills` commit `66e42e9`, "Refuse to port a define whose value the Extractor withheld"; its own `docs/adr/0020-withheld-define-values-are-never-ported.md`). Its verified ceiling on `api_version` was then raised from 6 to 7 in a second commit (`7eb6b90`; its own `docs/adr/0021-verified-against-extractor-api-version-7.md`). **An old client — one without that value-based refusal and the raised ceiling — must not be pointed at a server carrying the new `API_VERSION` until both have landed and been installed** (ADR-0018 "Consequences", second bullet, as corrected) — the risk runs in the direction of a stale client silently misreading a new server, not the other way round. `CHANGELOG.md:41` is explicit that writing or merging this plugin's change is not itself blocked; only *installing it where an extraction run is pending* is, until `kntnt-wp-skills` is updated and released in step and both are installed.

The ordering that must hold, therefore: decide whether §4 moves `API_VERSION`; if it does, do not install this plugin's release into any environment that will run an extraction until the corresponding `kntnt-wp-skills` release, with its ceiling raised and its own compatibility fix landed, is also installed there.

## 9. Open questions for the maintainer

These could not be established from the repository and are recorded here rather than guessed at:

- **Who publishes the GitHub release, and how.** No workflow file, script, or document in this repository names an actor or a command for §7. `.github/workflows/` contains only `gate.yml`; there is no release workflow. `README.md` and `ADR-0005` both assume a GitHub release exists once published, but neither says who creates it.
- **The "Cross-repo release order" document does not exist in this repository.** Both `CHANGELOG.md:41` and `docs/adr/0018-*.md:32` point to `plans/README.md` → "Cross-repo release order" for the coordinated-release sequencing. There is no `plans/` directory anywhere in this repository's history (`git log --all -- plans/` is empty) and nothing named `plans/README.md` is tracked. Either that document lives only in `kntnt-wp-skills`, or it was never committed here, or the reference is stale. §8 above is built from what ADR-0018 and `docs/container-format.md` §10 state directly, which is all this repository can currently support. The likely explanation: the `plans/` directory exists on disk in the maintainer's own working tree but is untracked, so it is invisible to a clone, to CI, and to this repository's history — which turns this from a mystery into a decision the maintainer still needs to make: track `plans/` so the reference resolves, or inline what `CHANGELOG.md:41` and ADR-0018 actually need from it directly into the documents that cite it.
- **How a production site actually receives the update.** `README.md` documents that the self-hosted update checker makes an update show on the Plugins screen (`README.md:28`), but nothing in this repository says whether production auto-updates, whether someone clicks "Update Now" manually, or who is responsible for doing so and when, relative to the coordinated ordering in §8.

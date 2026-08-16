# Plan 008: Disclose a `wp-config` define's value only from an allow-list, so third-party secrets stay on the server

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Rest/Environment_Controller.php classes/Rest/Status_Controller.php tests/Integration/environment-test.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED — this is a caller-visible contract change requiring a coordinated release. Read "The coordination cost" before starting.
- **Depends on**: none to write or merge. To reach **production** it requires `kntnt-wp-skills` plans 001 (merged) and 004 (ceiling raised to 7). See `plans/README.md` → "Cross-repo release order".
- **Category**: security
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

`GET /environment` reads the site's `wp-config.php`, harvests every `define()`
name it finds, resolves each one's live value, and returns them all — except for
a deny-list of six shapes: four exact names (`DB_PASSWORD`, `AUTH_KEY`,
`SECURE_AUTH_KEY`, `LOGGED_IN_KEY`), anything ending `_SALT`, and anything
beginning `NONCE_`.

That deny-list covers WordPress core's own secrets completely. It covers nothing
else. And `wp-config.php` is precisely where the WordPress world conventionally
puts everything else: SMTP passwords, JWT signing secrets, S3 and other API
keys, licence keys, secondary database credentials, third-party service tokens.
None of those match any of the six shapes, so all of them are returned in
plaintext.

This is not theoretical. A real extraction run against a client site carried a
third-party API key (`KNTNT_PAPAPI_KEY`) off the server through this endpoint.

The endpoint's own docblock states the invariant it is breaking
(`classes/Rest/Environment_Controller.php:27-30`): resolving the defines here
"keeps the database password and salts on the server (the reason this endpoint
exists)". And ADR-0011 — the decision that refuses to let a caller *download*
`wp-config.php` at all — argues that "the server is the last party that can
guarantee the site's secrets stay on the site, and an invariant delegated
entirely to every client's correctness is not an invariant". This endpoint hands
over most of that file's contents while the file layer refuses to hand over the
file. That is drift from a settled decision, not a different tradeoff.

A deny-list of secrets fails open: every define nobody thought of is disclosed.
An allow-list fails closed.

**This is not an argument from code. It is an observed outcome.** A saved
`GET /environment` response from a real run against the client site returned 24
defines. Every core secret was correctly `null` — `DB_PASSWORD`, all four keys,
all four salts, `NONCE_KEY`. `DB_NAME`, `DB_USER` and `DB_HOST` carried values,
which is correct and stays correct under the allow-list below: they are layout
facts a migration needs and none of them is a credential. Exactly one real
third-party secret came through in cleartext, a 40-character API key, and it
then sat in a local scratchpad for three days. That single response is the whole
case for this plan, and it also validates the allow-list's shape against reality
rather than against a belief about reality.

## The second half of this plan: `null` on the wire is overloaded

The allow-list alone is not enough, and the reason is worth reading carefully
because an earlier draft of this plan got it wrong.

That draft explicitly ruled a discriminator **out of scope**, arguing that a
name present with a `null` value already carries the information. That reasoning
is false, and the consuming client proves it. In `kntnt-wp-skills`, a define
whose value is `null` is classified on its **name alone**, comes back
"portable", is offered to the operator at the gate, and is then rendered into
the local `wp-config.php` as `define('X', null);`. `php -l` passes. The smoke
test does not check define values. And the result is worse than the define being
absent, because `defined('X')` now returns `true`, so the plugin that owns that
constant never falls back to its default and runs with a null key. The operator
is told nothing.

That is latent today only by coincidence: every name currently masked also
happens to be auto-excluded on the client side. **This plan ends that
coincidence** — the 40-character API key above matches none of the client's
auto-excluded families.

So `null` means two things at once on this wire: "withheld by policy" and "the
value is literally null". A version number cannot fix it. A version tells a
caller what the *server* does; it can never tell them a *per-record* fact, so
even a client that knows the allow-list is active still cannot tell a withheld
value from a legitimately-null one for a given define.

The fix is a per-record discriminator, and its contract was negotiated with the
consuming repository rather than guessed:

- It is a **closed enum**, not a boolean and not free text, because the client
  reports withheld names to a human and "not on the disclosure allow-list" reads
  better than a bare name.
- It is a **member on the existing record**, not a separate list of withheld
  names. A separate list is a join, and a hand-join on define records has
  already caused one bug in the consuming repository.
- It is **present on every record**, including disclosed ones. This is the
  load-bearing constraint. If the member appeared only when something was
  withheld, then "member absent" would become a third state meaning "old
  server", and the caller would be right back to inferring — which is the exact
  failure this plan exists to remove.

## The coordination cost — read this before starting

**This change bumps the REST API version, and that has consequences you must
not discover halfway through.**

`CONTEXT.md` defines when the version moves:

> **API version**: The REST contract's own version number, distinct from the
> plugin's release version. Increments only when a caller-visible behaviour
> changes — including a subtler, purely behavioural change, not only a change to
> endpoints or arguments — never for a fix that leaves the contract as callers
> already understood it.

Which defines come back with a value is caller-visible behaviour. The response
*shape* does not change — a redacted define is still `{ name, value: null }`,
exactly as `DB_PASSWORD` already is — but which names land in that state does.
So the version moves from 6 to 7.

The consumer, `kntnt-wp-skills`, pins a **verified ceiling** on this plugin's
`api_version` and stops the run rather than proceeding against a higher one. So
after this lands, that client must be updated and released in step, and both
must be installed, before any extraction runs. There is also a duplicated
deny-list on the client side — `classes/Rest/Environment_Controller.php:45`
records that the family here "Mirrors `kntnt-wp-skills`'s
`is_secret_define()`" — which should be brought into line in the same
coordinated release.

**Both halves of that coordination are now planned in the consuming repository**
(`~/Projects/kntnt-wp-skills/plans/`), and two of them are hard constraints on
this plan:

- **Their 001 must be merged before this reaches production.** It makes the
  client refuse to port a define whose value was withheld, instead of writing
  `define('X', null);` into the local `wp-config.php`. Without it, shipping the
  allow-list turns a latent client bug into a live one. It does **not** block
  you from writing or merging this plan — only from installing it on a site the
  client will then run against.
- **Their 004 raises the verified ceiling from 6 to 7.** Until it lands, a client
  meeting a version-7 Extractor refuses to run at all — which is the ceiling
  working as designed, not a fault. It is a six-literal edit across their repo
  that their own consistency suite refuses until every surface follows, so it is
  theirs to make, not yours. Do not attempt it from here.

The agreed cross-repo order is recorded in `plans/README.md` under "Cross-repo
release order". Follow it rather than re-deriving it.

The consuming client's own fix decides on the **value**, so it is correct
against the allow-list with or without the discriminator this plan adds. The
discriminator is therefore a refinement, not a release blocker: it buys back the
legitimately-null case and gives the operator a real reason instead of a bare
name.

**If the operator has an extraction run pending that must not be blocked, this
plan should wait.** Confirm that before you start; see STOP conditions.

## Current state

### The deny-list

`classes/Rest/Environment_Controller.php:40-52`:

```php
	/**
	 * The exact define names whose value is a secret and is never read.
	 *
	 * The suffix `*_SALT` and the prefix `NONCE_*` families are matched
	 * separately in {@see self::is_secret_define()}; this list is the fixed-name
	 * remainder. Mirrors `kntnt-wp-skills`'s `is_secret_define()`, giving defence
	 * in depth at both ends of the boundary.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const array SECRET_DEFINE_NAMES = [ 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY' ];
```

`classes/Rest/Environment_Controller.php:385-389`:

```php
	private function is_secret_define( string $name ): bool {
		return in_array( $name, self::SECRET_DEFINE_NAMES, true )
			|| str_ends_with( $name, '_SALT' )
			|| str_starts_with( $name, 'NONCE_' );
	}
```

### Where every name is harvested and every non-denied value resolved

`classes/Rest/Environment_Controller.php:306-337`:

```php
	private function defines(): array {

		// Read the located wp-config source; an unreadable or absent file yields no
		// defines rather than an error — the rest of the facts still stand.
		$path = $this->wp_config_path();
		// phpcs:ignore ...
		$source = ( $path !== '' && is_readable( $path ) ) ? (string) file_get_contents( $path ) : '';

		// Extract each defined name in source order, without duplicates. Only the
		// name is taken from the source; the value is resolved live below.
		preg_match_all( '/\bdefine\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $source, $matches );
		$names = array_values( array_unique( $matches[1] ) );

		// Resolve each name to its live value, redacting the secret family to null
		// and never reading its constant. A non-scalar value collapses to null,
		// keeping the contract to scalar-or-null.
		$defines = [];
		foreach ( $names as $name ) {
			$value = null;
			if ( ! $this->is_secret_define( $name ) && defined( $name ) ) {
				$resolved = constant( $name );
				$value = is_scalar( $resolved ) ? $this->relativise_define_value( $resolved ) : null;
			}
			$defines[] = [
				'name' => $name,
				'value' => $value,
			];
		}

		return $defines;

	}
```

### The version constant

`classes/Rest/Status_Controller.php:87`:

```php
	public const int API_VERSION = 6;
```

### The Config seam, for the escape hatch

`classes/Config.php:66-75`:

```php
	public function get( string $name, mixed $fallback = null ): mixed {

		// Start from the constant when defined, else the caller's fallback.
		$constant = self::CONSTANT_PREFIX . strtoupper( $name );
		$value = defined( $constant ) ? constant( $constant ) : $fallback;

		// Let a filter have the final word; its return value is authoritative.
		return apply_filters( self::FILTER_PREFIX . $name, $value, $name );

	}
```

The controller already holds a `Config` (`classes/Rest/Environment_Controller.php:66-69`)
and already reads one knob through it (`wp_config_path`, at `:406`).

### The existing test

`tests/Integration/environment-test.php` points the controller at a fixture
`wp-config.php` through the `wp_config_path` knob and seeds constants at
runtime, then asserts the redaction. It already covers `DB_PASSWORD`,
`AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `AUTH_SALT`, `NONCE_SALT`,
`NONCE_KEY`, a custom `*_SALT`, a custom `NONCE_*`, and a non-secret define
that must resolve (`KNTNT_ENV_TEST_DEFINE`) plus a path-valued one that must be
relativised (`KNTNT_ENV_TEST_ABS_PATH`).

**Those last two are exactly the assertions your change will break**, because
`KNTNT_ENV_TEST_DEFINE` and `KNTNT_ENV_TEST_ABS_PATH` are not core defines and
will not be on the allow-list. Handling that is part of this plan, not a
surprise.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
Load-bearing: English throughout; a `//` comment above each paragraph stating
its *purpose*; WordPress surface style (tabs, `snake_case` methods, spaces
inside parentheses); complete PHPDoc with `@since` on every new constant and
method. Note this file uses `private const array` / `public const int` typed
constants — match that.

From `CONTEXT.md`, the vocabulary for the changelog and the ADR:

> **Restricted path**: A file path matching the fixed deny-list of
> credential-bearing patterns […] Listed in the manifest like any other file but
> never extractable.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Integration suite | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

## Scope

**In scope**:

- `classes/Rest/Environment_Controller.php`
- `classes/Rest/Status_Controller.php` — the `API_VERSION` constant only
- `tests/Integration/environment-test.php`
- `tests/Integration/rest-status-test.php` — wherever it asserts the version
- `tests/Integration/attempt-log-test.php` — it asserts "the REST API version
  stays 6"; find every such assertion with
  `grep -rn 'API_VERSION\|api_version' tests/` and update them all
- `docs/adr/` — a new ADR recording this decision (see step 5)
- `docs/define-disclosure.md` (create) — the normative protocol (see step 5)
- `README.md` — a short section on `GET /environment` and the allow-list
- `CHANGELOG.md` (entries under `[Unreleased]` → `### Fixed` and `### Changed`)

**Out of scope** (do NOT touch):

- Emitting the define *names*. Every name found in the source must still be
  emitted for every record, redacted or not. The names are not secret and the
  caller needs them; only values are gated.
- Emitting the define *names*. Every name found in the source must still be
  emitted. The names are not secret and the caller needs them; only values are
  gated.
- `relativise_define_value()` and the least-disclosure path handling. Correct as
  it stands.
- `wp_config_path()` and the harvesting regex.
- The other members of the response (`php_version`, `wordpress`, `database`,
  `active_plugins`, `dropins`).
- `classes/Restricted_Path.php`. The file-layer deny-list has its own gaps; they
  are a separate finding and were not selected.
- The `kntnt-wp-skills` repository. This plan changes only the plugin. The
  client half is the operator's to schedule.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Disclose a define's value only from an allow-list, so an unlisted secret fails closed`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Replace the deny-list with an allow-list plus a heuristic backstop

In `classes/Rest/Environment_Controller.php`:

1. Add a `private const array` holding the names whose values are safe to
   disclose. These are the layout and behaviour facts a migration caller
   genuinely needs. Start from this list and adjust only if the tests show
   something essential missing:

   `ABSPATH`, `WP_CONTENT_DIR`, `WP_CONTENT_URL`, `WP_PLUGIN_DIR`,
   `WP_PLUGIN_URL`, `UPLOADS`, `WP_TEMP_DIR`, `WP_HOME`, `WP_SITEURL`,
   `WP_DEFAULT_THEME`, `WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`,
   `SCRIPT_DEBUG`, `SAVEQUERIES`, `WP_ENVIRONMENT_TYPE`, `WP_CACHE`,
   `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, `AUTOMATIC_UPDATER_DISABLED`,
   `WP_AUTO_UPDATE_CORE`, `DISABLE_WP_CRON`, `WP_CRON_LOCK_TIMEOUT`,
   `WP_POST_REVISIONS`, `AUTOSAVE_INTERVAL`, `EMPTY_TRASH_DAYS`,
   `WP_MEMORY_LIMIT`, `WP_MAX_MEMORY_LIMIT`, `FS_METHOD`, `DB_NAME`, `DB_USER`,
   `DB_HOST`, `DB_CHARSET`, `DB_COLLATE`, `WP_ALLOW_MULTISITE`, `MULTISITE`,
   `SUBDOMAIN_INSTALL`, `DOMAIN_CURRENT_SITE`, `PATH_CURRENT_SITE`,
   `SITE_ID_CURRENT_SITE`, `BLOG_ID_CURRENT_SITE`, `NOBLOGREDIRECT`,
   `WP_ACCESSIBLE_HOSTS`, `WP_PROXY_HOST`, `WP_PROXY_PORT`, `CONCATENATE_SCRIPTS`,
   `COMPRESS_SCRIPTS`, `COMPRESS_CSS`, `WP_LANG_DIR`, `WPLANG`,
   `IMAGE_EDIT_OVERWRITE`, `MEDIA_TRASH`, `RELOCATE`, `FORCE_SSL_ADMIN`,
   `FORCE_SSL_LOGIN`, `COOKIE_DOMAIN`, `COOKIEPATH`, `SITECOOKIEPATH`,
   `ADMIN_COOKIE_PATH`, `PLUGINS_COOKIE_PATH`, `TEMPLATEPATH`, `STYLESHEETPATH`.

   Note `DB_USER` and `DB_HOST` are on the list and `DB_PASSWORD` is not — the
   first two are layout facts a migration needs, the third is the credential.

2. Add a second `private const array` of substring patterns that force
   redaction regardless: `KEY`, `SECRET`, `TOKEN`, `PASS`, `SALT`, `NONCE`,
   `AUTH`, `CREDENTIAL`, `PRIVATE`, `LICEN`, `API`. Match these
   case-insensitively against the name. This is the backstop for a future core
   define nobody has added to the allow-list — it must fail closed.

3. Replace `is_secret_define()` with a positive predicate, e.g.
   `is_disclosable_define( string $name ): bool`, that returns true only when
   the name is on the allow-list **and** matches none of the redaction
   substrings. Give it a docblock explaining the inversion and why: a deny-list
   of secrets fails open on every name nobody anticipated, and `wp-config.php`
   is where third-party credentials conventionally live.

4. Add an escape hatch through the existing `Config` seam so an operator can
   disclose a specific extra define deliberately. Read a knob —
   `disclosable_defines` — whose value is a list of additional names, defaulting
   to the empty list, and union it with the allow-list. Resolve it exactly the
   way `wp_config_path` is resolved at `:406`. Guard against a non-list value.
   Document it: the constant is `KNTNT_EXTRACTOR_DISCLOSABLE_DEFINES` and the
   filter is `kntnt_extractor_config_disclosable_defines`.

5. In `defines()`, invert the condition: resolve the value only when
   `is_disclosable_define( $name )` and the constant is defined. Update the
   paragraph comment above the loop — it currently describes redacting a
   "secret family", which is no longer the model.

6. **Emit the per-record discriminator.** Every record becomes
   `{ name, value, disclosure }`, where `disclosure` is one of exactly three
   strings:

   | Value | Meaning |
   |---|---|
   | `included` | The value is disclosed and is the define's real value. |
   | `secret` | Withheld because the name matched the redaction substrings. |
   | `not_allow_listed` | Withheld because the name is not on the allow-list. |

   **Present on every record, including `included` ones.** No fourth value, no
   `null`, no omission. Back the enum with a `private const array` of the three
   strings so the closed set is stated once in code rather than as three
   string literals scattered through the method, and reference it from the
   docblock.

   When `disclosure` is not `included`, `value` stays `null` — the two members
   agree, and the discriminator says *why*.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -c 'is_secret_define' classes/Rest/Environment_Controller.php` → 0
- `grep -c 'not_allow_listed' classes/Rest/Environment_Controller.php` → at least 1

### Step 2: Bump the API version

`classes/Rest/Status_Controller.php:87` — change `API_VERSION` from `6` to `7`,
and update its docblock to record what moved: which `wp-config` defines
disclose a value.

Then find every place the suite pins the version and update it:

```
grep -rn 'API_VERSION\|api_version' tests/ classes/ README.md docs/
```

At minimum `tests/Integration/rest-status-test.php` and
`tests/Integration/attempt-log-test.php` assert it. Update each, and where an
assertion's *description* says "stays 6", reword it rather than leaving a
misleading string in the TAP output.

**Verify**:
- `grep -rn 'api_version.*6\b' tests/` → no stale assertions remain
- `composer test:integration` → exit 0 (some environment assertions will still
  fail at this point; that is expected and step 3 fixes them)

### Step 3: Update the environment test to the new model

In `tests/Integration/environment-test.php`:

1. The existing redaction assertions for the core secret family must all still
   pass — they are now covered by the allow-list's absence rather than by the
   deny-list's presence, and asserting the same outcome through a different
   mechanism is exactly right. Leave them.
2. The two assertions that expect a *non-core* define to resolve
   (`KNTNT_ENV_TEST_DEFINE`, and the path-valued `KNTNT_ENV_TEST_ABS_PATH`)
   will now fail. Rework them: prove the relativisation behaviour using a define
   that **is** on the allow-list and is path-valued — `WP_CONTENT_DIR` is the
   natural choice — so the least-disclosure behaviour stays pinned without
   depending on an unlisted name resolving.
3. Add the assertions this plan exists for:
   - **An unlisted, innocuous-looking define is redacted.** Seed something like
     `ACME_SMTP_PASSWORD` and `ACME_WIDGET_ENDPOINT` with distinctive literals
     and assert neither literal appears anywhere in the response body, while
     both *names* do. The name-present/value-absent pair is the whole contract.
   - **The heuristic backstop fires even for a name on the allow-list**, if you
     can construct such a case; otherwise assert it fires for an unlisted name
     containing `TOKEN`.
   - **The escape hatch works.** With the `kntnt_extractor_config_disclosable_defines`
     filter adding one specific name, that define's value is disclosed and
     nothing else changes. **Remove the filter at the end of the file** — the
     bootstrap runs every test file in one process in alphabetical order, and a
     leaked filter changes later files' behaviour.
   - **A regression assertion** naming the real incident: a define like
     `KNTNT_PAPAPI_KEY` is redacted. State in the description that this value
     previously left the server, was observed doing so, and sat in cleartext on
     an operator's laptop for three days.
   - **The discriminator is present on every record**, including disclosed
     ones — assert that no record in the response lacks the member. This is the
     assertion the consuming client depends on; without it "member absent"
     silently becomes a third state.
   - **The discriminator is one of exactly three values**, and it agrees with
     `value`: `included` implies a non-null value is *possible*, while `secret`
     and `not_allow_listed` both imply `value === null`. Assert the agreement
     rather than the two members separately.
   - **A define withheld for being unlisted reports `not_allow_listed`, and one
     withheld by the heuristic reports `secret`** — the two reasons must be
     distinguishable, because that is the reason the enum is not a boolean.

**Verify**:
- `composer test:integration` → exit 0, `Integration suite: PASS`
- Demonstrate the RED step: temporarily restore `is_secret_define()` as the
  predicate, re-run, and confirm the new redaction assertions report `not ok`.
  Restore and re-run to green. **Record both runs in your report.**

### Step 4: Confirm no secret literal can appear in a response

Add one broad assertion, if the test file does not already have one: encode the
whole `/environment` response to JSON and assert that none of the seeded secret
literals appears anywhere in it. This catches a value leaking through a member
other than `defines`.

**Verify**: `composer test:integration` → exit 0.

### Step 5: Write the ADR

This is a reversal of a shipped behaviour and a contract bump, so it needs its
own decision record. Create the next-numbered file in `docs/adr/` following the
existing naming convention — a zero-padded number and a hyphenated statement of
the decision, e.g.
`docs/adr/0017-the-environment-endpoint-discloses-a-define-only-from-an-allow-list.md`.

Read two or three existing ADRs first for voice — they are written as prose
arguing the decision, not as a template. `docs/adr/0011-*.md` is the closest
neighbour and should be cross-referenced.

It must cover: why a deny-list of secrets fails open; that `wp-config.php` is
conventionally where third-party credentials live; the real incident; why the
names are still disclosed while the values are not; why the heuristic backstop
exists alongside the allow-list; the escape hatch and why it is per-site and
explicit; **why `null` alone was insufficient and a per-record discriminator
was added, including that a version number can never answer a per-record
question**; **why the discriminator is present on every record rather than only
on withheld ones**; and, under Consequences, that `api_version` moves to 7, that
the client's mirrored deny-list must move with it, and that an operator relying
on an unlisted define must now opt in.

Then write the **normative protocol section**. The consuming repository asked
for the meaning to be specified rather than the membership — a test pinning
their name list against this one would make them wrong the moment this one
changes correctly. So specify the protocol, in `docs/define-disclosure.md`, in
the same reference register as the container-format specification (plan 009
writes that; match its voice and use MUST / MUST NOT where a rule is
load-bearing):

- What `null` means on this wire, stated once and unambiguously.
- The `disclosure` enum: the three values, what each means, that the set is
  closed, and that a reader MUST treat an unknown fourth value as withheld.
- **The present-on-every-record rule, as a MUST**, with the reason: an absent
  member would become a third state meaning "old server".
- That names are disclosed for every record regardless of `disclosure`.
- That the allow-list's *membership* is deliberately not part of the contract
  and may change without a version bump, while the *protocol* above may not.
- The escape hatch, and that it is per-site and operator-set.

Do not enumerate the allow-list in that document. The list is policy; the
protocol is contract.

**Verify**: `ls docs/adr/` → the new file is present and numbered one above the
current highest.

### Step 6: README and changelog

- `README.md` has no section on `GET /environment` at all
  (`grep -n 'environment' README.md` returns nothing). Add a short one in the
  Usage area, next to the existing "Restricted paths" section, which is the
  right structural neighbour: state what the endpoint returns, that define names
  are always listed, that values are disclosed only from a published allow-list,
  and how to opt a specific define in. Keep each paragraph on a single physical
  line — that is this project's Markdown convention.
- `CHANGELOG.md`: one entry under `### Fixed` (the disclosure) and one under
  `### Changed` (the version bump), matching the surrounding entries' style.
  State plainly that `api_version` moves from 6 to 7 and that a coordinated
  client release is required.

**Verify**: `git diff --stat` → only the files from "In scope".

### Step 7: Full gate

**Verify**: `composer gate` → exit 0.

## Test plan

- **File**: `tests/Integration/environment-test.php` (extend and amend).
- **Amended**: the two assertions expecting an unlisted define to resolve.
- **New cases**: an unlisted define is redacted while its name is still listed;
  the heuristic backstop redacts a `TOKEN`-shaped name; the Config escape hatch
  discloses one named define and nothing more; a `KNTNT_PAPAPI_KEY`-shaped name
  is redacted (the regression); no seeded secret literal appears anywhere in the
  serialised response.
- **Pattern to follow**: the fixture-seeding and assertion style already in that
  file.
- **Also update**: every assertion pinning `api_version` to 6.
- **Verification**: `composer test:integration` → all pass, plus the
  demonstrated failing run from step 3.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `grep -c 'is_secret_define' classes/Rest/Environment_Controller.php` returns 0
- [ ] `grep -n 'API_VERSION = 7' classes/Rest/Status_Controller.php` returns a match
- [ ] `grep -rn "api_version.*=== 6" tests/` returns nothing
- [ ] `grep -c 'disclosable_defines' classes/Rest/Environment_Controller.php` returns at least 1
- [ ] `grep -c 'not_allow_listed' classes/Rest/Environment_Controller.php` returns at least 1
- [ ] Every record in the `/environment` response carries a `disclosure` member — asserted by a test, not only by reading
- [ ] `docs/define-disclosure.md` exists and states the present-on-every-record rule as a MUST
- [ ] `docs/define-disclosure.md` does **not** enumerate the allow-list's membership
- [ ] A new ADR file exists in `docs/adr/`, numbered above 0016
- [ ] `grep -c 'environment' README.md` returns at least 1
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report contains the output of the deliberately-failing run from step 3
- [ ] `plans/README.md` status row for 008 updated

## STOP conditions

Stop and report back (do not improvise) if:

- **The operator has not confirmed that bumping `api_version` to 7 is
  acceptable right now.** This blocks the client until a coordinated release
  ships, and there may be an extraction run pending. Ask before starting; this
  is the single most important stop condition in this plan.
- The code at `classes/Rest/Environment_Controller.php:306-337` or `:385-389`
  does not match the excerpts above.
- Building the allow-list turns out to require disclosing something you judge
  sensitive in order to keep an existing test passing. Report which define and
  which test — do not widen the allow-list to make a test pass.
- You find that some other member of the `/environment` response also carries a
  `wp-config` value (so the fix is incomplete). Report it; that is a real
  finding.
- The heuristic backstop redacts so many allow-listed names that the endpoint
  becomes useless. That would mean the two lists are fighting; report rather
  than quietly dropping the backstop.

## Maintenance notes

- **The client half is planned, not done, and it is not yours.**
  `classes/Rest/Environment_Controller.php:45` records that this family
  "Mirrors `kntnt-wp-skills`'s `is_secret_define()`". The consuming repository
  has that copy at `scripts/discovery.py:193-205` over `:88-100`, plus a second,
  wider list at `scripts/classify.py:64-92` serving a different question ("may
  this be ported" rather than "may this value enter the document") — the two are
  duplicated but have not diverged in effect, so do not describe them as
  drifted. What is genuinely unbound is that the `_SALT` suffix and `NONCE_`
  prefix patterns are written out verbatim in both places with no test tying
  them. **Deliberately not bound by a cross-repo test**: pinning their list
  against this one would make them wrong the moment this one changes correctly.
  The binding is the protocol document from step 5, not the membership.
- **Consuming the discriminator is deferred on the client side, by agreement.**
  Their fix decides on the *value*, so they are correct against the allow-list
  with or without `disclosure`. They will consume it later to recover the
  legitimately-null case and to put a real reason in the operator's report.
  That means this plan is not on the release's critical path even though it
  bumps the version.
- **What a reviewer should scrutinise**: that the allow-list contains no name
  that could plausibly hold a credential on some site; that the heuristic
  backstop is applied *after* the allow-list, not before; that the escape hatch
  cannot be set to "everything"; and that define names are still emitted for
  redacted entries.
- **What will interact with this**: any future addition to the response. The
  rule to keep is that a value crosses the boundary only when something says it
  may, never when nothing says it may not.
- **Deliberately not done here**: the file-layer deny-list
  (`classes/Restricted_Path.php`) has parallel gaps — editor droppings such as a
  Vim swap file beside `wp-config.php` are extractable today, and the deny-list
  is evaluated on the caller's unresolved path string rather than the resolved
  target. Those are real and were left out of this plan's scope.

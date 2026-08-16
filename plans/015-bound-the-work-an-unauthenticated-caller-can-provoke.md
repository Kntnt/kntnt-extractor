# Plan 015: Bound the work an unauthenticated caller can provoke on `POST /extractions`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat a6de808..HEAD -- classes/Rest/Extractions_Controller.php classes/Rest/Status_Controller.php classes/Plugin.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED — touches the request-validation precedence ladder that ADR-0003 fixes; a mistake here either reorders a refusal ADR-0003 settled or breaks a legitimate large clone
- **Depends on**: none (plan 014's `honours` mechanism this plan uses is already landed, commit `f47b42e`)
- **Category**: security
- **Planned at**: commit `a6de808`, 2026-08-16

## Why this matters

`POST /extractions` lets an **unauthenticated** caller provoke real, unbounded server work. `can_create()`, the route's `permission_callback`, runs `validate_payload()` — which does a `realpath()` per selected file path and two `SHOW TABLES` queries — before it calls `Authorizer::authorize()`. WordPress runs the `permission_callback` before the route's own callback, so this ordering is not incidental; it is what lets a 404 for a non-existent resource precede the 403 a missing capability would otherwise return first, and that ordering is ADR-0003's deliberate decision, not a bug. The route registers no `args` schema at all, so nothing bounds how many entries `tables`, `tables_structure_only`, or `files` may carry, and nothing bounds the raw request body's size either. An anonymous caller can therefore submit an arbitrarily large selection or an arbitrarily large body and force the server to spend real CPU and I/O — the `realpath()` loop scales with the file count — before it is ever told it lacks a capability, or even asked for one.

This plan narrows that gap, without touching the ordering ADR-0003 settled, by turning **unbounded** pre-authorization work into **large but finite** pre-authorization work — it does not make that work small, and it does not touch how often the same caller may provoke it. It adds two additive caps — a maximum combined element count across the three selection arrays, and a maximum raw body size — both checked ahead of everything else in `validate_payload()`, including the existence check and the capability gate. A request that clears the caps is validated exactly as it is today, in the exact order ADR-0003 fixed; a request that does not is refused before any of that ordering's existing checks, capability gate included, ever runs. The caps are generous multiples of a real production selection (186 tables, 49,116 files — ADR-0014), so no legitimate clone is expected to ever see the new refusal. See "What this does not fix" below for exactly what is left open, and do not let that section's absence from a summary read as this plan closing the finding outright.

## Current state

### The permission callback runs the expensive validation before authorization

`classes/Rest/Extractions_Controller.php:206-217` (`can_create()`), the route's `permission_callback`:

```php
public function can_create( WP_REST_Request $request ): true|WP_Error {

	// Reject a malformed, keyless, or non-existent-resource request before the
	// capability gate ever runs; only then let the Authorizer have its say.
	$payload = $this->validate_payload( $request );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}

	return $this->authorizer->authorize();

}
```

`register_routes()` wires it in at `classes/Rest/Extractions_Controller.php:130-134`:

```php
[
	'methods' => WP_REST_Server::CREATABLE,
	'callback' => $this->create( ... ),
	'permission_callback' => $this->can_create( ... ),
],
```

**There is no `args` key on this route entry at all** — contrast the `GET` entry two lines below it (`:139-146`), which registers an `args` schema for its `state` parameter with a `validate_callback`. WordPress validates a route's `args` schema (`WP_REST_Request::has_valid_params()` / `sanitize_params()`) *before* calling `permission_callback` — that is the mechanism the `state` parameter's own docblock at `:110-112` relies on ("checked by a `validate_callback` … so anything else is refused with `400 rest_invalid_param` before the callback ever runs"). Because the create route has no `args`, none of that runs for it: every byte of the body is parsed and validated manually, entirely inside `can_create()` → `validate_payload()`, which is itself the `permission_callback` — the earliest point WordPress calls into this plugin's own code for this route, after route matching and (for a route with `args`) schema validation, but before capability is ever checked.

**This is also where WordPress resolves the caller's identity for this request**, lazily, the first time `is_user_logged_in()` or `current_user_can()` is called (`determine_current_user`, which is where Application Password authentication itself runs). `Authorizer::authorize()` (`classes/Authorizer.php:76-114`) is the first and only place in the create path that calls either — at `:80` and `:88`. So for a request with **no** credentials at all, nothing before `authorize()` forces authentication either; `validate_payload()`'s work is genuinely pre-authentication, not merely pre-authorization, for the common case of a fully anonymous caller.

### The expensive work itself

`classes/Rest/Extractions_Controller.php:821-911` (`validate_payload()`), in the order it runs today:

1. `:824-827` — `json_decode()` the raw body; not-an-array is a 422.
2. `:832-837` — `string_selection()` normalises `tables`, `tables_structure_only`, `files`; malformed shape or nothing selected is a 422.
3. `:841-843` — a table in both `tables` and `tables_structure_only` is a 422.
4. `:848-851` — a non-boolean `strict` is a 422.
5. `:855-858` — an absent or malformed `public_key` is a 400.
6. `:864-878` — a selection naming a restricted path (ADR-0011) is a 422.
7. `:885-901` — **the expensive step**: `missing_tables( $tables )` and `missing_tables( $structure_only )` (`:985-1011`) each run `SHOW TABLES` (`:1001`) and scan it once per requested name; `classify_files( $files )` (`:1035-1107`) calls `realpath()` on every single requested file path (`:1086`), one filesystem stat per element. A missing table or an out-of-root file is a 404 naming every offender (ADR-0003).

None of steps 1–7 is capped by count or by body size. `string_selection()` (`:924-941`) checks only that every element is a non-empty string — it accepts an array of any length. `missing_tables()` returns early only when its input array is empty (`:988-990`); it has no upper bound. `classify_files()` returns early only when `$files === []` (`:1038-1044`); otherwise it loops the full array, `realpath()` included, with no upper bound.

### The measured basis for a cap

`docs/adr/0014-the-persisted-record-is-split-so-a-save-is-bounded.md:9` records a real production run: "A real extraction of `safeteam.se` — **186 tables and 49,116 files**, 2.23 GB". `README.md`'s own worked example of a poll response uses the same order of magnitude: `"files_total": 49228` (`README.md:90`, in the "Telling a slow job from a stuck one" section). This is the one real number this codebase has for selection size, and any cap must clear it by a comfortable margin.

A synthetic payload built to this plan's own specification — 186 table names and 49,116 file paths averaging 92 characters (deliberately longer than the samples in the real run, to bias the estimate upward), JSON-encoded exactly as `POST /extractions` expects — measured **4,687,437 bytes (4.47 MiB)** for 49,302 combined elements. Reproduced with:

```
php -r '
$tables = [];
for ($i = 0; $i < 186; $i++) { $tables[] = "wp_" . str_pad((string)$i, 2, "0", STR_PAD_LEFT) . "_some_moderately_long_table_name_example"; }
$files = [];
$samples = [
    "wp-content/uploads/sites/3/2020/05/some-fairly-long-descriptive-filename-example-1234567.jpg",
    "wp-content/uploads/sites/3/2019/11/another-photo-of-something-quite-nice-and-descriptive.png",
    "wp-content/plugins/some-third-party-plugin-slug/assets/js/vendor/some-bundled-script.min.js",
    "wp-content/themes/some-custom-theme-slug/template-parts/content/single-post-template-block.php",
];
for ($i = 0; $i < 49116; $i++) { $files[] = $samples[$i % count($samples)]; }
$body = json_encode(["tables" => $tables, "tables_structure_only" => [], "files" => $files, "public_key" => base64_encode(random_bytes(32)), "strict" => false], JSON_UNESCAPED_SLASHES);
echo strlen($body) . " bytes, " . (count($tables) + count($files)) . " elements\n";
'
```

That gives the two constants below their derivation: **500,000 elements (≈10.1× the measured 49,302) and 50 MiB / 52,428,800 bytes (≈11.7× the measured 4.47 MiB)**.

### The Config seam this plan reintroduces to `Extractions_Controller`

`classes/Rest/Extractions_Controller.php:86-89` currently says, and will become false:

```php
	 * The Config seam is deliberately absent: the concurrency ceiling was the only
	 * knob this endpoint read, and it now belongs to {@see Job_Store::has_free_slot()}
	 * beside the count it bounds, so the controller no longer configures anything.
```

The pattern to copy for a new Config-backed cap is `classes/Rest/Files_Controller.php:48` (`private const int DEFAULT_PAGE_SIZE = 1000;`) and `:112-113`:

```php
$configured = $this->config->get( 'files_page_size', self::DEFAULT_PAGE_SIZE );
$page_size = max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_PAGE_SIZE );
```

`Files_Controller`'s constructor (`:58-61`) takes `Authorizer $authorizer` then `Config $config`, in that order — the pattern `Environment_Controller.php:184-187` also follows. `Extractions_Controller`'s current constructor (`:96-100`) is:

```php
public function __construct(
	private readonly Authorizer $authorizer,
	private readonly Job_Store $store,
	private readonly Dispatcher $dispatcher,
) {}
```

`classes/Plugin.php:114,124` shows `$config` already constructed and already threaded into two other controllers on the same lines:

```php
$config = new Config();
...
$extractions_controller = new Extractions_Controller( $authorizer, $job_store, $dispatcher );
```

### `honours` — the seam for announcing this additively

`classes/Rest/Status_Controller.php:112` — `public const int API_VERSION = 7;` — and `:136-143`:

```php
private const array HONOURED_BEHAVIOURS = [
	'attempts',
	'chunks_done',
	'disclosure',
	'skipped_files',
	'state',
	'strict',
];
```

**Deciding whether the numeric limits themselves are caller-visible, not just their existence.** `honours` is presence-only by design (`docs/adr/0017-...md`: "Absence is the only signal. There is no boolean map"), so `selection_limits` in the list can only ever tell a client that some cap exists, never what it is. That is not where a client needs the number anyway — it needs the number at the moment it is refused, so it can split its selection and retry, not in advance of ever sending a request. This plan therefore reports the limit on the refusal itself: both new `WP_Error`s carry a `limit` member in their `data` array (Steps 2 and 3), alongside the caller's own size (`bytes` / `count`) so a client can compute exactly how far over it was in one response, without a second call. `selection_limits` in `honours` stays purely a presence flag — "a client should check for this name before assuming an arbitrarily large selection will be accepted" — and the actual numbers travel through the error, not through `GET /status`.

### What must NOT change: ADR-0003's ordering, and ADR-0012's uniform refusal

`docs/adr/0003-generic-manifest-and-table-list-no-server-side-categorisation.md`, Consequences: "A resource name that doesn't already exist (an unknown table, a path outside the root) is rejected before any capability check runs, and the 404 names every offender." `plans/README.md:127` records this as settled and explicitly carves out this plan's subject as the unresolved half: "no `args` schema, no element cap, no body-size cap … That half is real and was not selected for planning." **This plan implements exactly that half and nothing else.** The two new caps in this plan run *ahead of* every check ADR-0003 orders, not interleaved with them and not instead of them — a request that clears both caps is validated in precisely the sequence ADR-0003 fixed, unchanged.

`docs/adr/0012-identity-dependent-responses-are-never-cacheable.md` is the ADR governing this plugin's uniform-refusal discipline; its Consequences note that `kntnt_extractor_forbidden` survives "only where it names an entirely different refusal — a missing per-job tick secret and a job belonging to another user — both of which stay deliberately uniform so that neither endpoint becomes an existence oracle." That discipline applies to `resolve_owned_job()` and the id-addressed routes (`:770-787`), which this plan does not touch at all. The two caps this plan adds are content-shape checks — element count and byte count — that carry no information about whether any specific table or file exists; they refuse identically regardless of what the caller selected, so they introduce no new way to distinguish "exists" from "you may not" for any resource.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`. English throughout; a `//` comment above each paragraph stating its *purpose*; WordPress surface style (tabs, `snake_case`, spaces inside parentheses); complete PHPDoc. This file is one of the most heavily documented in the repo — match its density, including the class-level docblock's precedence description at `:61-77`.

From `CONTEXT.md`, the vocabulary already established that this plan's glossary entry should sit beside: **Restricted path** (`CONTEXT.md:23-25`) is the existing example of a request-shape rejection named and defined in the glossary under "Extraction".

## What this does not fix

Read this before writing the ADR in Step 7 and carry its substance into it — the decision record must not imply this plan closes the finding, because it does not.

**The caps turn unbounded pre-authorization work into large-but-finite pre-authorization work. They do not make that work small.** At the default `max_selection_elements` of 500,000, a single unauthenticated, uncredentialled request can still put up to 500,000 entries in `files` and force `classify_files()` (`classes/Rest/Extractions_Controller.php:1035-1107`) to run a `realpath()` on every single one of them — line `:1086` — before `Authorizer::authorize()` is ever called. That is the exact operation the finding this plan addresses names, merely capped rather than removed. `docs/adr/0014-the-persisted-record-is-split-so-a-save-is-bounded.md` measured filesystem calls on this project's own investigated hosts as ranging from cheap on a fast local disk to "a few milliseconds each" on a networked or overlay filesystem. At a few milliseconds per call, 500,000 `realpath()` calls is on the order of minutes of single-request filesystem work, not seconds — real, and now finite, but not small. (A selection weighted toward `tables`/`tables_structure_only` instead is cheaper per element — two fixed `SHOW TABLES` queries plus an in-memory scan against the site's real table count, ordinarily a few hundred at most — but the combined 500,000-element cap permits that weighting too, and a linear scan of up to 500,000 requested names against even a few hundred real ones is itself hundreds of millions of string comparisons.)

**Repetition is untouched by this plan, deliberately.** Nothing here stops the same caller from sending that same near-cap request again immediately, or many callers from sending it concurrently. The cap bounds the cost of *one* request; it says nothing about the rate requests arrive at. Defending against a stream of near-cap requests is a rate-limiting or WAF concern — a web-server or hosting-layer control this plugin has no seam for today and does not gain one from this plan. Naming this explicitly, the way "Scope" already names the `Content-Length`-read boundary this plugin cannot reach, is the point of this section: a reader who only sees the cap and not this paragraph will believe the pre-auth cost problem is solved, and it is only bounded per request.

**Why the cap sits at 500,000 rather than lower.** A real production selection measured 186 tables and 49,116 files, 2.23 GB (`docs/adr/0014-...md:9`) — a legitimate clone this plugin exists to serve, not a hypothetical one. A cap set materially below this plan's ~10× margin over that measurement risks refusing that real request outright, and a plugin that reliably declines the large, legitimate job it exists to do is a worse failure for this project's actual users than a bounded, finite amount of pre-authorization work is for its threat model. The number in this plan is a stated trade, not an oversight: it accepts a request that can still cost minutes of filesystem time from a caller who never proves a credential, in exchange for not breaking the one real workload this codebase has evidence for. A future maintainer who wants the cap lower should re-derive the margin against a larger measured production selection first, not guess at a smaller number and assume it is safe.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0 |
| Static analysis | `composer phpstan` | exit 0 |
| Integration suite | `composer test:integration` | exit 0, `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

All four were run against the tree this plan is planned at (`a6de808`) and pass cleanly before this plan's changes.

## Scope

**In scope**:

- `classes/Rest/Extractions_Controller.php` — the constructor, `validate_payload()`, the class-level and method-level docblocks
- `classes/Rest/Status_Controller.php` — `HONOURED_BEHAVIOURS`
- `classes/Plugin.php` — the `new Extractions_Controller( … )` call
- `tests/Integration/extractions-test.php` — new assertions
- `docs/adr/` — one new ADR
- `CONTEXT.md`, `README.md`, `CHANGELOG.md`

**Out of scope** (do NOT touch):

- **The 404-before-403 ordering itself.** ADR-0003. Do not reorder any existing check in `validate_payload()`; only prepend the two new caps ahead of all of them.
- **`resolve_owned_job()`, the id-addressed routes, and ADR-0012's uniform non-owner refusal.** This plan's caps are content-shape checks unrelated to any specific job's existence or ownership.
- **A REST `args` schema for the create route.** The codebase's own convention for this route is manual validation inside `validate_payload()` for precise, ordered error codes; introducing WordPress's own `args`/`rest_validate_value_from_schema()` machinery for `tables`/`tables_structure_only`/`files` would risk silently changing the status code or error code for shapes the existing test suite already pins (e.g. a non-array `tables` is `422 kntnt_extractor_malformed_body` today — `tests/Integration/extractions-test.php:145` — and must still be, not WordPress core's generic `400 rest_invalid_param`). Add the caps as manual checks instead, exactly like every other check in this method.
- **`Restricted_Path`, `missing_tables()`, `classify_files()` internals.** Their logic is unchanged; they simply never run on an oversized selection now.
- **The concurrency ceiling, `max_active_jobs`, or `has_free_slot()`.** A separate, already-considered-and-rejected finding (`plans/README.md`'s "Findings surfaced but not selected for planning").
- **`API_VERSION`.** See "Why this does not move `API_VERSION`" below. Do not bump the integer.
- **A raw `Content-Length`-based rejection before the body is read into memory.** WordPress core reads the full request body into `WP_REST_Request` before any plugin code — including this route's `permission_callback` — runs at all; nothing in this plugin's code executes early enough to refuse a request before that read happens. The body-size cap in this plan bounds the CPU/memory cost of *decoding and processing* an already-read body, not the read itself. That earlier boundary is a web-server (`client_max_body_size`) / `php.ini` (`memory_limit`, `post_max_size`) concern outside this plugin's reach, and is not this plan's job.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Suggested commits, one per step so a bisect can separate them:
  `Reintroduce the Config seam to Extractions_Controller for two new caps`
  `Reject an oversized request body before decoding it`
  `Reject an oversized selection before any existence check runs`
  `Announce selection_limits in the honours list`
  `Add regression coverage for both caps`
  `Document the caps: ADR, glossary, README, changelog`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Reintroduce Config to `Extractions_Controller`

Add `private readonly Config $config,` to the constructor at `classes/Rest/Extractions_Controller.php:96-100`, positioned second — after `$authorizer`, before `$store` — matching `Files_Controller.php:58-61` and `Environment_Controller.php:184-187`. Add `use Kntnt\Extractor\Config;` to the `use` block (`:11-23`) if not already present (it is not — `Config` is not currently imported in this file).

Add the import, and update `classes/Plugin.php:124` to pass `$config` as the second argument:

```php
$extractions_controller = new Extractions_Controller( $authorizer, $config, $job_store, $dispatcher );
```

Add two new class constants, positioned near the top of `Extractions_Controller` alongside its existing constants (there are none yet at class level besides what you are adding — place them just above the constructor):

```php
/**
 * Combined `tables` + `tables_structure_only` + `files` elements allowed in one
 * selection when the knob does not override it.
 *
 * Measured against a real production selection — 186 tables and 49,116 files
 * (docs/adr/0014) — and set at roughly 10× that combined count so a legitimate
 * clone of a site an order of magnitude larger than the one this was measured
 * against still clears it. Resolved through the Config seam under
 * `max_selection_elements`.
 *
 * @since 0.6.0
 */
private const int DEFAULT_MAX_SELECTION_ELEMENTS = 500_000;

/**
 * Raw request body bytes allowed on `POST /extractions` when the knob does not
 * override it.
 *
 * A synthetic payload shaped like the same production selection — 186 tables,
 * 49,116 files, deliberately long path samples — measured 4,687,437 bytes.
 * This is roughly 11× that. Resolved through the Config seam under
 * `max_body_bytes`.
 *
 * @since 0.6.0
 */
private const int DEFAULT_MAX_BODY_BYTES = 52_428_800;
```

Add two private resolver methods, modelled exactly on `Files_Controller.php:112-113`'s pattern and `Job_Store.php:401-407`'s clamped-to-a-floor style:

```php
/**
 * Resolves the combined selection-element cap through the Config seam, clamped
 * to at least one.
 *
 * @since 0.6.0
 *
 * @return int The maximum combined elements across tables, tables_structure_only,
 *             and files.
 */
private function max_selection_elements(): int {

	$configured = $this->config->get( 'max_selection_elements', self::DEFAULT_MAX_SELECTION_ELEMENTS );

	return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_MAX_SELECTION_ELEMENTS );

}

/**
 * Resolves the request-body byte cap through the Config seam, clamped to at
 * least one.
 *
 * @since 0.6.0
 *
 * @return int The maximum raw request body size in bytes.
 */
private function max_body_bytes(): int {

	$configured = $this->config->get( 'max_body_bytes', self::DEFAULT_MAX_BODY_BYTES );

	return max( 1, is_numeric( $configured ) ? (int) $configured : self::DEFAULT_MAX_BODY_BYTES );

}
```

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0 (confirms `Config` import and constructor wiring are type-correct)
- `composer test:integration` → exit 0 (nothing yet depends on the new methods, so this only proves the wiring did not break instantiation)

### Step 2: Reject an oversized body before decoding it

In `classes/Rest/Extractions_Controller.php:821-828`, insert a check between reading the body and decoding it:

```php
private function validate_payload( WP_REST_Request $request ): array|WP_Error {

	// Reject an oversized body before it is even decoded: an anonymous caller
	// must not be able to spend json_decode() time and memory proportional to a
	// body of unbounded size, ahead of every other check in this method
	// (docs/adr/0020). The limit and the caller's own size are reported in the
	// error data — a client that hits this needs the number to split its
	// request, not merely the fact that a limit exists.
	$body = (string) $request->get_body();
	$body_length = strlen( $body );
	if ( $body_length > $this->max_body_bytes() ) {
		return new WP_Error(
			'kntnt_extractor_payload_too_large',
			__( 'The request body exceeds the maximum accepted size.', 'kntnt-extractor' ),
			[
				'status' => 413,
				'limit' => $this->max_body_bytes(),
				'bytes' => $body_length,
			],
		);
	}

	// Parse the body; anything that is not a JSON object is a malformed body.
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) ) {
		return $this->error( 422, 'kntnt_extractor_malformed_body', __( 'The request body must be a JSON object.', 'kntnt-extractor' ) );
	}
```

**Verify**:
- `composer phpcs`, `composer phpstan` → exit 0
- `composer test:integration` → exit 0. Every existing assertion in `extractions-test.php` sends a body far under 50 MiB, so none should change.

### Step 3: Reject an oversized selection before any existence check runs

Immediately after the existing "nothing selected" check (`classes/Rest/Extractions_Controller.php:832-837`), before the overlap check at `:841`, insert:

```php
	// Bound the total selection before any element of it reaches a realpath()
	// call or a catalog comparison: an oversized selection is refused before the
	// restricted-path check, the existence check, and the capability gate ever
	// run (docs/adr/0020).
	$total_elements = count( $tables ) + count( $structure_only ) + count( $files );
	if ( $total_elements > $this->max_selection_elements() ) {
		return new WP_Error(
			'kntnt_extractor_selection_too_large',
			__( 'The selection exceeds the maximum number of tables and files accepted in one request.', 'kntnt-extractor' ),
			[
				'status' => 422,
				'limit' => $this->max_selection_elements(),
				'count' => $total_elements,
			],
		);
	}
```

This runs on `$tables`, `$structure_only`, `$files` — the already-shape-validated arrays `string_selection()` produced — so it costs one cheap `count()` call each, strictly before `array_intersect()` (`:841`), the `strict` check (`:848`), the `public_key` check (`:855`), `Restricted_Path::matches()` (`:864`), and `missing_tables()`/`classify_files()` (`:885-901`).

**Verify**:
- `composer phpcs`, `composer phpstan` → exit 0
- `composer test:integration` → exit 0. Every existing assertion sends at most two elements, so none should change.

### Step 4: Update the docblocks this plan makes stale

- `classes/Rest/Extractions_Controller.php:86-89` currently says the Config seam is "deliberately absent". Rewrite it to describe what it now configures — the two caps from Step 1 — instead of deleting the paragraph's shape entirely; keep the "why" style this file uses throughout.
- The class-level docblock (`:61-77`) describes the validation order starting from "a malformed body is a 422". Prepend a sentence naming the two new caps and stating they run ahead of that whole ladder, citing ADR-0020.
- `validate_payload()`'s own docblock (`:790-811`) similarly starts "The checks run in the contract's fixed precedence: a body that is not a JSON object…". Prepend the two new caps to that enumeration, in the order they now run (body size, then element count, then the existing ladder unchanged).

**Verify**: `composer phpcs` → exit 0 (phpcs in this repo's config enforces PHPDoc completeness, so a docblock left inconsistent with its method's `@param`/`@return` would already fail this; a purely prose staleness is not machine-checkable — read the three edited docblocks against the code they describe and confirm they still match).

### Step 5: Announce `selection_limits` in `honours`

Add `'selection_limits',` to `classes/Rest/Status_Controller.php:136-143`'s `HONOURED_BEHAVIOURS`, keeping the array's existing alphabetical order (it sorts `s` after `skipped_files` and before `state`, so it lands between them). Extend the constant's own docblock (`:114-135`) with one sentence: the name a caller checks for before assuming a large selection or a large body will be rejected with the new 422/413 codes rather than accepted or timing out.

Do not touch `API_VERSION` — see "Why this does not move `API_VERSION`" below.

**Verify**: `grep -n "'selection_limits'," classes/Rest/Status_Controller.php` → one match, between `skipped_files` and `state`.

### Step 6: Add the regression coverage

Extend `tests/Integration/extractions-test.php` rather than creating a new file. Add the new assertions inside the existing "AC2/AC3: the validation ladder, verified from an UNAUTHORIZED caller" block (after `:153`, `wp_set_current_user( 0 )` is already in force there), following the `add_filter( 'kntnt_extractor_config_max_active_jobs', $force_max ); … remove_filter( … )` pattern already used later in the same file (`:210-214`) — do not build a genuine 500,000-element or 50 MiB fixture; override the knobs down to a small number for the test instead:

```php
// --- new: the two selection-shape caps, verified ahead of the capability gate ---

// Override both caps down to a size the test can build cheaply, then restore
// them immediately after — this file's process is shared with every test file
// that runs after it (tests/Integration/bootstrap.php requires them all into
// one process, alphabetically), so a filter left in place would leak.
$force_elements = static fn(): int => 3;
add_filter( 'kntnt_extractor_config_max_selection_elements', $force_elements );
$oversized_selection = $post_extractions( [ 'tables' => [ 'a', 'b' ], 'files' => [ 'c', 'd' ], 'public_key' => $valid_key ] );
kntnt_extractor_assert( $oversized_selection->get_status() === 422, 'A selection over the element cap is rejected 422 before the capability check' );
kntnt_extractor_assert( ( $oversized_selection->get_data()['code'] ?? null ) === 'kntnt_extractor_selection_too_large', 'The element-cap refusal names its own cause' );
kntnt_extractor_assert( ( $oversized_selection->get_data()['data']['limit'] ?? null ) === 3 && ( $oversized_selection->get_data()['data']['count'] ?? null ) === 4, 'The element-cap refusal reports the limit and the caller\'s own count, so a client can split its selection' );
kntnt_extractor_assert( $post_extractions( [ 'tables' => [ $wpdb->options ], 'public_key' => $valid_key ] )->get_status() === 404, 'A selection within the lowered cap still reaches the existence check' );
remove_filter( 'kntnt_extractor_config_max_selection_elements', $force_elements );

$force_bytes = static fn(): int => 200;
add_filter( 'kntnt_extractor_config_max_body_bytes', $force_bytes );
$oversized_body = $post_extractions( [ 'tables' => [ $wpdb->options ], 'files' => [ 'wp-load.php', 'wp-load.php', 'wp-load.php', 'wp-load.php', 'wp-load.php' ], 'public_key' => $valid_key ] );
kntnt_extractor_assert( $oversized_body->get_status() === 413, 'A body over the byte cap is rejected 413 before it is decoded, before the capability check' );
kntnt_extractor_assert( ( $oversized_body->get_data()['code'] ?? null ) === 'kntnt_extractor_payload_too_large', 'The body-cap refusal names its own cause' );
kntnt_extractor_assert( ( $oversized_body->get_data()['data']['limit'] ?? null ) === 200, 'The body-cap refusal reports the limit so a client can shrink its request' );
remove_filter( 'kntnt_extractor_config_max_body_bytes', $force_bytes );
```

Adjust the literal element counts and the repeated path in the byte-cap fixture as needed once you can see actual encoded byte lengths — the intent is: one request whose JSON body is provably under 200 bytes is impossible to construct with a valid `public_key` (base64 of 32 bytes is 44 characters alone), so pick a cap value and a fixture that are both small and provably on the correct side of each other; do not guess, measure the fixture's `wp_json_encode()` length in the test itself if it makes the assertion more robust.

**Verify**:
- `composer test:integration` → exit 0, both new assertions passing
- Demonstrate the RED step for each: temporarily comment out the Step 2 check, confirm the byte-cap assertion reports `not ok`; restore it; temporarily comment out the Step 3 check, confirm the element-cap assertion reports `not ok`; restore it. **Record both runs in your report.**

### Step 7: ADR, glossary, README, changelog

- **ADR**: `docs/adr/0020-...md`, next-numbered, existing naming convention, prose in the house voice. It must cover: that the finding is unauthenticated pre-authorization work, not the 404-before-403 ordering (cite ADR-0003 and `plans/README.md`'s rejected-findings note); that the fix is two additive caps positioned ahead of the entire existing ladder rather than a reordering of it; the measured basis for both constants (ADR-0014's 186 tables / 49,116 files, and this plan's own synthetic-payload measurement); why manual checks were chosen over a REST `args` schema (existing pinned status/error codes for in-cap malformed shapes); and why this does not move `API_VERSION` (next bullet, copy its reasoning in). **It must also carry forward the "What this does not fix" section above, not just the fix itself**: state plainly, in the ADR's own words, that the caps bound one request's cost to a large-but-finite amount rather than eliminating pre-authorization work, give the same rough worst-case figure (order of 500,000 `realpath()` calls, minutes rather than seconds on a slow filesystem per ADR-0014's own measurement), name rate limiting/a WAF as the un-implemented mitigation for repetition, and state the margin the cap was chosen against. A decision record that reads as though this closes the finding is wrong even if every other word in it is correct.
- **`CONTEXT.md`**: one new glossary entry under "Extraction", beside **Restricted path** (`:23-25`) in shape and length — name it **Selection limits**, define the two caps, state they run ahead of the existence check and the capability gate, and give the "Avoid" line.
- **`README.md`**: a short new subsection near "Large tables and files" (`:67`), listing the two new `KNTNT_EXTRACTOR_MAX_SELECTION_ELEMENTS` / `KNTNT_EXTRACTOR_MAX_BODY_BYTES` constants (and their matching `kntnt_extractor_config_*` filters) in the same style as the existing chunk-size knobs list there.
- **`CHANGELOG.md`**: an entry under `### Fixed` (the unbounded pre-authorization work) and one under `### Added` (the two configurable caps and the `selection_limits` honours entry). State plainly that `api_version` does not move.

**Verify**: `composer gate` → exit 0; `git status --short` → only in-scope files.

## Test plan

- **File**: `tests/Integration/extractions-test.php` (extend, do not create a new file).
- **Cases**: an oversized selection is `422 kntnt_extractor_selection_too_large` ahead of the capability gate; a within-cap selection still reaches the existence check unchanged; an oversized body is `413 kntnt_extractor_payload_too_large` ahead of the capability gate; both refusals carry their own error code, not a generic one; both refusals report the limit they were checked against, not just the fact of the refusal.
- **Pattern to follow**: the existing `add_filter( 'kntnt_extractor_config_max_active_jobs', … )` / `remove_filter( … )` pairing at `:210-214` of the same file, and the anonymous-caller validation-ladder block at `:133-153`.
- **Verification**: `composer test:integration` → all pass, plus the two demonstrated RED runs from Step 6.

## Done criteria

- [ ] `strlen( $request->get_body() )` is checked, and refused with `413 kntnt_extractor_payload_too_large`, before `json_decode()` runs — verify by reading `classes/Rest/Extractions_Controller.php:821-830`
- [ ] The combined element count of `tables` + `tables_structure_only` + `files` is checked, and refused with `422 kntnt_extractor_selection_too_large`, before `array_intersect()`, `Restricted_Path::matches()`, `missing_tables()`, or `classify_files()` run
- [ ] Both caps are resolved through the `Config` seam with a documented, measured default (`DEFAULT_MAX_SELECTION_ELEMENTS = 500_000`, `DEFAULT_MAX_BODY_BYTES = 52_428_800`)
- [ ] Both new `WP_Error`s carry `limit` (and the caller's own `count`/`bytes`) in their `data`, so a refused client can act on the number without a second call
- [ ] `docs/adr/0020-...md` states, in its own prose, that the caps bound one request's cost rather than eliminating pre-authorization work, names rate limiting/a WAF as the un-addressed mitigation for repetition, and gives the worst-case figure and the margin the cap was chosen against — read the ADR after writing it and confirm a reader who skips straight to its Consequences would not conclude the finding is fully closed
- [ ] `'selection_limits'` is present in `Status_Controller::HONOURED_BEHAVIOURS` — `grep -n "'selection_limits'," classes/Rest/Status_Controller.php` → one match
- [ ] `grep -n "API_VERSION = 7" classes/Rest/Status_Controller.php` still matches — this plan does not bump it
- [ ] A request that clears both caps is validated in exactly the order ADR-0003 fixed, unchanged — every existing assertion in `tests/Integration/extractions-test.php` still passes verbatim
- [ ] `composer phpcs`, `composer phpstan`, `composer test:integration`, `composer gate` all exit 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report contains both demonstrated RED runs from Step 6
- [ ] `plans/README.md` status row for 015 updated

## STOP conditions

Stop and report back (do not improvise) if:

- Any existing assertion in `tests/Integration/extractions-test.php` changes status code or error code as a result of this plan's changes. That would mean a cap or its placement is disturbing the ADR-0003 ladder rather than merely preceding it.
- You find yourself needing a REST `args` schema on the create route to make either cap work. That was ruled out deliberately in "Scope" because it risks changing the error code/status of an existing, pinned, in-cap malformed-shape case; if the manual-check approach genuinely cannot bound the work, that is a bigger finding than this plan and should be reported, not worked around.
- The synthetic-payload measurement in "Current state" reproduces to a materially different byte count on your run (more than, say, 2×). Recompute the derived constants from your own measurement rather than shipping the numbers in this plan unverified — the rule this project follows is that a constant is measured, not chosen.
- You discover a code path that reaches `missing_tables()` or `classify_files()` without first passing through the element-count check added in Step 3 (for example, a second entry point into `validate_payload()` this plan's recon missed).
- Adding `Config` to `Extractions_Controller`'s constructor breaks a test that instantiates the class directly rather than through `classes/Plugin.php` or the live REST server. (Recon found none — `grep -rn "new Extractions_Controller" tests/` returns nothing — but if one exists, it needs updating in the same commit as Step 1, not skipped.)

## Maintenance notes

- **What this does not fix.** WordPress core reads the entire raw request body into memory before any plugin code runs, including this route's `permission_callback`. The body-size cap in this plan bounds the cost of decoding and processing an already-read body; it cannot prevent the read itself. A caller who wants to exhaust memory purely by sending a body larger than `memory_limit` still can, bounded only by the web server's own `client_max_body_size` (or equivalent) and PHP's `memory_limit`/`post_max_size` — infrastructure concerns this plugin does not control and this plan does not attempt to.
- **The two caps are independent knobs, not a single one**, because they bound different costs: element count bounds the number of `realpath()` calls and catalog comparisons; body size bounds JSON-decode CPU and memory for a body that could in principle carry very few elements with very long individual strings (a small `count()` that still fails the byte check). Do not collapse them into one constant.
- **If a real site's legitimate clone is ever refused by either cap**, the fix is raising the `KNTNT_EXTRACTOR_MAX_SELECTION_ELEMENTS` / `KNTNT_EXTRACTOR_MAX_BODY_BYTES` constant (or filter) on that site, not widening this plan's scope or removing the cap.
- **What a reviewer should scrutinise**: that both new checks in `validate_payload()` genuinely run before every existing check in the method, including the ones ADR-0003 orders; that neither new `WP_Error` accidentally omits `status` from its data array (which would default to a 500); and that the honours-list addition did not touch `API_VERSION`.

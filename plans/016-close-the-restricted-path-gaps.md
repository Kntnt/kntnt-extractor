# Plan 016: Close the restricted-path deny-list's two enforcement gaps — wider editor-dropping coverage, and a check against the resolved target, not just the requested name

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat a6de808..HEAD -- classes/Restricted_Path.php classes/Rest/Extractions_Controller.php classes/Artifact_Builder.php tests/Integration/restricted-path-test.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW — no `api_version` bump, no cross-repo coordination, no
  release-order constraint. The reasoning for that is a whole section below
  because it looks, at first read of ADR-0011, like it should bump. It doesn't.
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `a6de808`, 2026-08-16

## Why this matters

`classes/Restricted_Path.php` is the deny-list that keeps `wp-config.php`,
`.env`, and root-level database dumps and key material off every extraction
(ADR-0011). Plan 008 fixed the same class of bug one layer up — a deny-list of
secret *define names* on `GET /environment` that failed open on every name
nobody anticipated — and its own "Maintenance notes" named two gaps in this
file's deny-list as "real and were left out of this plan's scope":

1. An editor dropping sitting beside `wp-config.php` — a Vim swap file, an
   Emacs auto-save file, a handful of differently-shaped backup names — is
   extractable today, even though ADR-0011's own prose says "editor droppings"
   is exactly the family this deny-list covers, and the ADR's own motivating
   incident was two `wp-config.php.bak-*` files carrying the full secret
   family in clear text.
2. The deny-list is evaluated against the caller's own, unresolved path
   string. A symlink whose name matches nothing on the list but whose target
   is `wp-config.php` is never checked against what it actually resolves to —
   at selection time or at packaging time.

Both gaps are enforcement holes in an *existing, narrow, already-justified*
deny-list, not evidence that deny-listing itself is the wrong model here —
see "Allow-list or hardened deny-list?" below for why this plan does not
mirror plan 008's allow-list shape.

## Current state

### The deny-list itself

`classes/Restricted_Path.php:33-143` (full file; read it before starting —
it is 142 lines). The three pattern families and their scope:

```php
// :42-47
private const array WP_CONFIG_PATTERNS = [
    '/^wp-config\.php$/i',
    '/^wp-config\.php\..+$/i',
    '/^wp-config\.php~$/i',
    '/^wp-config-(?!sample\.php$).+\.php$/i',
];
```

```php
// :54-57
private const array ENV_PATTERNS = [
    '/^\.env$/i',
    '/^\.env\..+$/i',
];
```

```php
// :65-72
private const array ROOT_ONLY_PATTERNS = [
    '/\.sql$/i',
    '/\.sql\.gz$/i',
    '/\.sql\.zip$/i',
    '/\.pem$/i',
    '/\.key$/i',
    '/^id_rsa/i',
];
```

`is_restricted( string $path ): bool` (`:96-120`) normalises Windows
separators, matches `WP_CONFIG_PATTERNS` and `ENV_PATTERNS` against the
**basename** anywhere in the tree, and matches `ROOT_ONLY_PATTERNS` against the
basename only when `is_root_level()` (`:131-140`) says the path has no
directory component.

### Gap 1, established concretely: which editor-dropping shapes actually pass

Verified by running the four `WP_CONFIG_PATTERNS` regexes and the
`ROOT_ONLY_PATTERNS` `id_rsa` regex directly against candidate basenames
(`php -r`, not assumed):

| Basename | Real-world source | Caught today? |
|---|---|---|
| `.wp-config.php.swp` | Vim's default swap file (hidden, dot-prefixed) | **No** — pattern 2 requires the basename to *start* with `wp-config.php.`; a leading `.` makes it not start there |
| `.wp-config.php.swo`, `.swn`, … | Vim's swap file on a second/third collision | **No**, same reason |
| `#wp-config.php#` | Emacs auto-save file | **No** — starts with `#`, not `wp-config` |
| `.#wp-config.php` | Emacs lock file | **No** — starts with `.#`, not `wp-config` |
| `wp-config.bak.php` | A manual backup with the extension reordered | **No** — pattern 4 requires a hyphen after `wp-config`, this has a dot |
| `wp-config.old` | A manual backup, no `.php` suffix at all | **No** — matches no pattern |
| `wp-config.php.orig`, `.rej`, `.bak`, `.save` | Patch/merge tooling, manual backups | **Yes** — caught by pattern 2 (`wp-config.php.*`) |
| `wp-config.php~` | Emacs/GNU single-file backup | **Yes** — pattern 3, unchanged |
| `wp-config-old.php` | Hyphenated variant | **Yes** — pattern 4, unchanged |
| `id_ed25519`, `id_ecdsa`, `id_ecdsa-sk`, `id_ed25519-sk`, `id_dsa` | OpenSSH's other default key basenames | **No** — `ROOT_ONLY_PATTERNS` only names `id_rsa` |

This matches, and sharpens, the finding already recorded in
`plans/README.md:140`: `.wp-config.php.swp`, `#wp-config.php#`,
`wp-config.bak.php` and `wp-config.old` all pass `Restricted_Path.php:42-47`
today, and "the root-only key-material patterns also predate
`id_ed25519`/`id_ecdsa`".

### Gap 2, established concretely: what the unresolved-path check admits, and where it is and is not applied

**The check runs exactly once, at `Extractions_Controller.php:864`, against
the caller's own path string, before the path is ever resolved:**

```php
// classes/Rest/Extractions_Controller.php:860-878
// Restricted-path rejection runs before the existence check: a selection
// naming a credential-bearing file (ADR-0011) is refused outright, naming
// every offending path, and its mere existence is never disclosed by
// letting it fall through to the existence check's outcome.
$restricted = Restricted_Path::matches( $files );
if ( $restricted !== [] ) {
    return new WP_Error(
        'kntnt_extractor_restricted_path',
        /* ... 422, 'paths' => $restricted ... */
    );
}
```

`$files` here is the raw `files` array from the request body — exactly what
the caller typed. `Restricted_Path::is_restricted()` (`:96-120`) never calls
`realpath()` or touches the filesystem; it is a pure string match on the
name the caller chose.

The very next thing that happens is resolution, at
`classify_files()` (`:886`, method at `:1035-1094`):

```php
// classes/Rest/Extractions_Controller.php:1083-1094
$resolved = realpath( $root . '/' . $file );
if ( $resolved === false ) {
    $vanished[] = $file;
    continue;
}
$resolved = wp_normalize_path( $resolved );
if ( $resolved !== $root && ! str_starts_with( $resolved, $root . '/' ) ) {
    $out_of_bounds[] = $file;
    continue;
}

$kept[] = $file;
```

`realpath()` resolves symlinks by definition (PHP manual: "all symbolic links
… are resolved"). `classify_files()` uses that resolution **only** to decide
in-root/out-of-root/vanished. It never calls `Restricted_Path::is_restricted()`
again on `$resolved`. So: a caller who names a file `notes.txt`, where
`notes.txt` is a symlink pointing at `wp-config.php`, sends a string
(`notes.txt`) that `Restricted_Path::matches()` correctly does not flag, and
`classify_files()` resolves it, finds it inside the root, and keeps it. **The
selection is accepted.** No 422 is returned, and a job is created.

Packaging reads the file at `Artifact_Builder::read_part()` (`:302-361`),
which calls `resolve_in_root()` (`:409-424`):

```php
// classes/Artifact_Builder.php:409-424
private function resolve_in_root( string $file ): string {
    $root = realpath( ABSPATH );
    $root = $root === false ? false : wp_normalize_path( $root );
    $abs = $root === false || str_contains( $file, "\0" ) ? false : realpath( $root . '/' . $file );
    $abs = $abs === false ? false : wp_normalize_path( $abs );
    if ( $root === false || $abs === false || ! ( $abs === $root || str_starts_with( $abs, $root . '/' ) ) ) {
        throw new RuntimeException( 'A requested file resolves outside the installation root.' );
    }
    return $abs;
}
```

Same shape: resolves again (its own docblock at `:392-401` calls this
"defence in depth against a record altered in between" — but only for the
root-boundary check), and again never calls `Restricted_Path::is_restricted()`
on the result. `fopen( $abs, 'rb' )` then reads `wp-config.php`'s actual
bytes, and they are sealed into the container under the segment name
`notes.txt`.

**So: the deny-list is applied exactly once in the whole request lifecycle —
against the unresolved caller string, at `POST /extractions` validation time
— and is never re-applied against the resolved identity, neither at the point
that resolution already happens (`classify_files()`) nor at the point the
bytes are actually read (`resolve_in_root()`).** `GET /files`
(`classes/Manifest.php`) is unaffected by design (ADR-0011) and stays that
way in this plan — worth noting only because its own walk
(`Manifest.php:156,165`) already treats symlinked directories and symlinked
files differently: `is_dir( $abs ) && ! is_link( $abs )` never descends into
a symlinked directory, but `is_file( $abs )` (`:165`) is true for a symlinked
*file* and lists it like any other entry — consistent with the manifest
being deliberately unfiltered, but confirming a file-symlink is something
this codebase already has to reason about elsewhere.

### Where `strict`/`skipped_files` do and do not apply

`skipped_files` (`Extractions_Controller.php:888`) only ever holds *vanished*
files — gone between the manifest walk and the `POST`, and only when the
caller opted into `strict: false`. A restricted path, raw or resolved, is
never a candidate for that list: ADR-0011 explicitly rejected silently
dropping a restricted path at packaging time ("the caller's manifest
accounting would desynchronise… a misconfigured client would receive an
artifact silently missing files instead of learning its selection is wrong",
`docs/adr/0011:9`) in favour of a hard, named 422 at creation. This plan keeps
that invariant for both gaps: a resolved-restricted path is a hard 422 at
creation (same code, same shape as today), and if a record is somehow altered
between creation and the tick that packages it, the packaging-time re-check
is a hard, opaque failure (`RuntimeException`, never re-driven, per
ADR-0015) — never a silent drop, never folded into `skipped_files`.

## Allow-list or hardened deny-list?

Plan 008 replaced a deny-list with an allow-list because `GET /environment`'s
define values are, in principle, unbounded — a site can define *any*
third-party secret in `wp-config.php`, so no deny-list of secret *shapes*
can ever be complete, and the endpoint's whole job is disclosing values, so
failing closed by default was the only safe posture.

The file layer is a genuinely different problem. `POST /extractions` exists
to serve **arbitrary caller-selected files from an arbitrary WordPress
installation** — uploads, theme and plugin source, whatever a site owner put
in `wp-content`. There is no bounded set of "safe to extract" paths to
allow-list; almost every legitimate extraction target is exactly as
unpredictable, in principle, as the credential-bearing ones. An allow-list
here would have to be either so broad it allow-lists everything (defeating
the point) or so narrow it silently drops files a caller actually needs —
which is precisely the false-positive cost this plan must not create (see
next section).

What *is* bounded, and already deny-listed correctly, is the small,
structurally-identifiable family of credential-bearing shapes ADR-0011
targets: one specific config file and its recognisable siblings, one
dotfile-and-siblings convention, and file *extensions* that are database
dumps or key material. Both gaps this plan closes are enforcement failures
inside that already-narrow, already-justified deny-list — a pattern family
that doesn't yet recognise a few more shapes of the same family it already
targets, and a check that runs against the wrong representation of the same
path. Neither gap is evidence the deny-list itself is the wrong shape.
**Conclusion: hardened deny-list, not an allow-list — the file layer is
genuinely different from the define-disclosure case, and reaching for
symmetry with plan 008 here would either do nothing (allow-list everything)
or break real extractions (allow-list too little).**

## The false-positive cost, and how an operator learns about a refusal

Widening a deny-list risks catching a legitimately-needed file. Two things
bound that cost here. First, every new pattern in this plan is scoped to a
name that is *already, deliberately* structured to look like the file it
backs up or the SSH key type it is — `wp-config.bak.php`, `.wp-config.php.swp`,
`id_ed25519` — verified above against negative probes
(`wp-config-docs.md`, `identity.txt`, `id_number.csv`) that stay clear.
Second, and more importantly: **a restricted-path refusal is never silent.**
`POST /extractions` answers a matching selection with `422
kntnt_extractor_restricted_path`, naming every offending path, before the job
is even created (`Extractions_Controller.php:864-878`) — the caller learns
immediately and by name, not by a file quietly missing from the artifact.
This plan's resolved-path check keeps that same shape: a resolved-restricted
file joins the same 422, by the caller's own requested name (never the
resolved target — no server topology is disclosed beyond what the caller
already submitted). The one new failure mode — a record altered between
create-time and a later tick — is a hard, named build failure
(`RuntimeException`, reported through a normal `failed` poll with a reason),
never an artifact silently missing the file.

## Does this move `API_VERSION`?

**No.** Read this section fully before touching `Status_Controller.php` —
`docs/adr/0011`'s own Consequences section looks, at first glance, like it
disagrees.

`docs/adr/0011:17-18`:

> The rejection is caller-visible behaviour, so shipping it increments the
> API version (see [0005](./0005-github-releases-self-hosted-update-checker.md)).
> Widening or narrowing the pattern family later is likewise a caller-visible
> contract change, not a tweak.

Read those two bullets precisely before amending them — do not paraphrase
them as stronger than they are. The **first** bullet is the one that actually
asserts a bump, and its sole cited authority is 0005 ("see [0005]"). The
**second** bullet does not repeat "increments the API version" on its own
authority; it says a later widening or narrowing "is *likewise* a
caller-visible contract change, not a tweak" — chained to the first bullet's
bump claim by "likewise", not re-asserted independently. That chain is
exactly what 0017 cuts. `docs/adr/0017` (`:5`) explicitly narrows the rule the
first bullet cited and says so on the record: *"This amends 0005, whose own
words are broader than what now governs… `API_VERSION` no longer means 'any
caller-visible change, however small'; it means the artifact's shape."*
`docs/adr/0018` then adds one more, narrower ground on top: the version also
moves when a change "would make an already-shipped client's own existing
behaviour unsafe against the new server, in a way that client cannot detect
and did not opt into" (`docs/adr/0018:27`), and that ADR's own precedent —
its section heading reads "This amends [0017]: a named exception, not a
silent contradiction" — is the discipline this plan follows for 0011: **the
new ADR this plan writes must say, explicitly and by name, that 0011's second
bullet chained "likewise" to an authority (0005's broad rule) that 0017 has
since narrowed, and that the narrower authority no longer reaches this plan's
changes.** Do not silently ignore what 0011 says, and do not overstate it
either — amend the specific chain, in writing, the way 0018 amended 0017.

Applying both of 0017's and 0018's tests to this plan's two changes:

- **Not an artifact-shape change.** Nothing here touches the container's
  framing, segments per resource, the sealed index, or reassembly order
  (0017's ground). The response shape of the `422
  kntnt_extractor_restricted_path` error is unchanged — same code, same
  `data.paths` array — only which selections trigger it widens.
- **Not an "already-shipped client's existing logic becomes unsafe,
  undetectably" change** (0018's ground). That ground exists because the
  `disclosure` field problem was a *silent* one: an old client acted on a
  `null` value without any signal it should stop and ask first. A tightened
  restricted-path check is the opposite shape: a selection that used to
  succeed now fails **loudly**, with the same error code and shape a client
  must already be able to handle for the existing deny-list. There is no new
  field to misinterpret and no silent data corruption — the caller is told,
  by name, exactly what was refused and why, before any job is created.

Neither ground applies. This is analogous to widening `ROOT_ONLY_PATTERNS` or
`ENV_PATTERNS` membership, not to the `disclosure` discriminator. `honours`
(`Status_Controller::HONOURED_BEHAVIOURS`) is also not the right home: it
exists for a capability an old client can *opt into* or safely keep ignoring
(`strict`, `state=all`); this is not a capability, it is the same
always-on, unconditional enforcement ADR-0011 already established, now
applied more completely. `api_version` stays `7`; `HONOURED_BEHAVIOURS` is
untouched.

One more thing the new ADR must get right: 0011's second bullet's own words —
"a caller-visible contract change, not a tweak" — stay **true** even though
its bump does not follow. Widening the pattern family is real, documented,
caller-visible behaviour, which is exactly why this plan still requires a
`README.md` update and a `CHANGELOG.md` entry (Step 7) rather than treating
it as cosmetic. What the amendment removes is only the *bump*, by cutting the
authority the "likewise" leaned on — not the bullet's characterisation of the
change as substantive.

## The client's mirror is stale: a coordinated clone can start failing hard at submission

Two things are true here at once, and neither cancels the other: **the
server-side check is sufficient on its own for the security invariant** (no
client's correctness is required for a secret to stay on the server — that is
the whole point of enforcing this at the boundary rather than trusting every
caller to filter correctly), **and this plan creates a real, foreseeable
operational break** for the one production consumer of this API if its own
copy of the pattern family is not updated in step. Read both halves; do not
take the first as license to ignore the second.

**Where the client's mirror lives**, verified directly against
`~/Projects/kntnt-wp-skills`, not assumed:

- `scripts/build_exclusions.py:61-102` — the pattern tuples themselves:
  `_CONFIGURATION_FILE` (`:61`), `_CONFIGURATION_FILE_VARIANTS` (`:73-78`,
  currently `wp-config.php.*`, `wp-config.php~`, `.wp-config.php.sw?`,
  `wp-config-*.php`), `_ENV_FILES` (`:83-86`), `_ROOT_SQL_DUMPS` (`:90-94`),
  `_ROOT_KEY_MATERIAL` (`:98-102`, currently `*.pem`, `*.key`, `id_rsa*`).
- `scripts/filter_manifest.py:74,124-148` — its own `is_excluded()`, which
  consumes that pattern family to **pre-filter the client's own selection**
  before it ever calls `POST /extractions`. Its docblock (`:132-133`) states
  it "Mirrors `scripts/baseline_diff.py`'s `is_excluded` exactly".
- `scripts/baseline_diff.py:196,248-272` — the second, independently
  maintained copy of the same matching logic, consuming the same pattern
  family from `build_exclusions.py`.
- Confirmed independently (not merely re-cited from an earlier pass): `grep
  -rn "kntnt_extractor_restricted_path" ~/Projects/kntnt-wp-skills` — outside
  `plans/` — returns **nothing**. The client has no handling at all of this
  error code anywhere in its runtime scripts.

The client's mirror is already slightly *ahead* of this plugin's current
server-side list in one place — `_CONFIGURATION_FILE_VARIANTS` already
includes `.wp-config.php.sw?` — and behind on the rest: it has nothing for
`#wp-config.php#`, `.#wp-config.php`, `wp-config.bak.php`, `wp-config.old`, or
the non-`id_rsa` SSH key types this plan adds.

**What breaks concretely, in order, if the server widens and the client does
not:** a real site carries one of the newly-restricted shapes (say,
`wp-config.old` left over from a manual edit, or an `id_ed25519` key at the
install root) → the client's pre-filter, built from its own stale copy of the
pattern family, does not recognise it and lets it through → the client
includes that path in its `files` selection on `POST /extractions` → the
server, running this plan's widened deny-list, answers the **whole request**
with `422 kntnt_extractor_restricted_path` (this endpoint's existing,
unchanged behaviour: one restricted path anywhere in the selection rejects
the entire create, no job at all — `Extractions_Controller.php:864-878`) →
the client has never seen this error code and has no code path for it, so
the failure is not a clean, actionable message but an unhandled error at the
point that was supposed to start the transfer. **A clone that worked
yesterday fails today, at submission, on an error nobody on the client side
wrote a message for.**

**The remedy is a release-sequencing decision for the operator, not something
this plan's executor can settle here.** Either the client's copy of the
pattern family is updated to match in the same coordinated release this
plugin's own `plans/README.md` "Cross-repo release order" section already
tracks for other changes, or the operator accepts and communicates that a
clone may fail hard on a site carrying one of these newly-restricted shapes
until it is. Widening the client's mirror to match, and giving it actual
handling of `kntnt_extractor_restricted_path` (so an unanticipated restricted
path fails one file gracefully rather than the whole create), is real,
concrete follow-up work for `~/Projects/kntnt-wp-skills` — named here so a
release manager sees it before shipping, not discovered by an operator's
failed clone.

**This still does not move `API_VERSION`, and the two questions must not be
confused.** The refusal is loud — a named, existing error code, not a new one
— and that is precisely why no bump is warranted (see "Does this move
`API_VERSION`?" above): the security invariant never depended on the client
recognising anything. What breaks here is *convenience and availability* for
one specific, currently-unhandled client, not the guarantee itself. Read
"the client must be updated in step" as an operational and release-sequencing
fact, never as evidence the invariant depends on the client — it does not,
which is the entire point ADR-0011 and this plan both make: the server is the
last party that can guarantee the site's secrets stay on the site.

## Symlinks on the WordPress Playground filesystem

**Symlinks cannot be created on Playground's virtual filesystem at all.**
This is already stated in this repository's own testing strategy:

`docs/testing-strategy.md:74`:

> Symlink handling: Playground's virtual filesystem does not support symlinks
> at all.

Confirmed rather than merely trusted: every other fixture file this suite
creates (e.g. `restricted-path-test.php:144-146`, a `.sql` file written
directly with `file_put_contents()`) is an ordinary file, and no test file
anywhere in `tests/Integration/*.php` calls `symlink()` — `grep -rn
"symlink("  tests/Integration/*.php` returns nothing, consistent with the
harness never having been able to do it. **Consequence for this plan: the
one assertion that actually discriminates "checked against the resolved
target" from "checked against the caller's string" — a symlink whose own
name is innocuous — cannot run on Playground and must not be written there.**
It belongs in `tests/Integration/DDEV/`, which runs against a real
filesystem and already has `run.sh` wiring that copies and executes every
`*-test.php` in that directory. `composer test:integration:mysql` (the DDEV
harness) is **not** part of `composer gate`
(`composer.json`'s `"gate"` array is `["@phpcs", "@phpstan",
"@test:integration", "@test:build"]` — `@test:integration:mysql` is absent,
and `docs/testing-strategy.md:60` states it explicitly: "deliberately not
part of `composer gate`"). This plan's gate-verified done criteria therefore
cannot include the symlink assertion; it is verified separately, once, and
recorded in the executor's report (see Step 5 and the Test plan).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0, no errors |
| Static analysis | `composer phpstan` | exit 0, no errors |
| Fast integration suite (Playground) | `composer test:integration` | exit 0, prints `Integration suite: PASS` |
| Full gate (no MySQL) | `composer gate` | exit 0 |
| MySQL/real-filesystem suite (DDEV, **not** in the gate) | `composer test:integration:mysql` | exit 0, prints `DDEV integration check: PASS`; requires Docker and DDEV |

## Scope

**In scope**:

- `classes/Restricted_Path.php` — widen `WP_CONFIG_PATTERNS` and
  `ROOT_ONLY_PATTERNS`
- `classes/Rest/Extractions_Controller.php` — `classify_files()` and
  `validate_payload()` only
- `classes/Artifact_Builder.php` — `resolve_in_root()` only
- `tests/Integration/restricted-path-test.php`
- `tests/Integration/DDEV/restricted-path-symlink-test.php` (create)
- `docs/adr/` — a new ADR (see Step 6)
- `docs/testing-strategy.md` — the `restricted-path-test.php` bullet, and one
  sentence naming the new DDEV file
- `README.md` — the "Restricted paths" section
- `CONTEXT.md` — the **Restricted path** glossary entry
- `CHANGELOG.md` — one entry under `[Unreleased]` → `### Fixed`

**Out of scope** (do NOT touch):

- `classes/Manifest.php` and `classes/Rest/Files_Controller.php` — `GET
  /files` stays unfiltered and unannotated by design (ADR-0011); this plan
  does not touch it.
- `classes/Rest/Status_Controller.php` — no `API_VERSION` change, no
  `HONOURED_BEHAVIOURS` addition. See "Does this move `API_VERSION`?" above.
- Refusing symlinked files in general (a blanket `is_link()` ban). That would
  be a materially broader, riskier change than this plan's scope — it could
  break a legitimate site whose `wp-content` uses symlinks for other reasons
  (e.g. a Composer-managed plugin) — and it is not what closes the actual
  gap: the gap is that the deny-list isn't checked against the resolved
  identity, not that symlinks exist at all. Report it as a candidate finding
  if you believe it is still warranted; do not implement it here.
- `docs/define-disclosure.md` or any new protocol document. Unlike plan 008,
  nothing here is new wire protocol; `README.md`'s existing "Restricted
  paths" section is already the normative publication point.
- The stale `Extractions_Controller::first_out_of_root_file` reference inside
  `Artifact_Builder.php:399`'s docblock (that method does not exist under
  that name — likely a rename left behind). You are editing this docblock
  anyway; leave that particular staleness alone and mention it in your report
  rather than fixing it, since it is unrelated to this plan's two findings
  and touching it risks masking a real drift signal for whoever investigates
  it properly.
- `~/Projects/kntnt-wp-skills`. This plan changes only the plugin.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message: an imperative sentence, no prefix. Suggested:
  `Check the restricted-path deny-list against a file's resolved identity, and widen it to cover more editor droppings`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Widen the deny-list's pattern families

In `classes/Restricted_Path.php`:

1. Add four members to `WP_CONFIG_PATTERNS` (`:42-47`), each documented in the
   surrounding docblock with what real-world tool produces that shape:

   ```php
   '/^\.wp-config\.php(\..+)?$/i',   // Vim swap files: .wp-config.php.swp, .swo, .swn, ...
   '/^#wp-config\.php#$/i',          // Emacs auto-save file
   '/^\.#wp-config\.php$/i',         // Emacs lock file
   '/^wp-config\.(?:bak|old|orig|save)(?:\.php)?$/i', // reordered backup names
   ```

2. Replace the `id_rsa` member of `ROOT_ONLY_PATTERNS` (`:65-72`) with:

   ```php
   '/^id_(?:rsa|dsa|ecdsa(?:-sk)?|ed25519(?:-sk)?)/i', // OpenSSH's default key basenames
   ```

   Keep the same prefix-match style the existing `id_rsa` pattern used (no
   end anchor), so `id_ed25519.pub` still matches through the prefix.

3. Update `@since` on any new consequence to `0.6.0` (the plugin's current
   unreleased version — confirm with `grep -n 'Version:' kntnt-extractor.php`
   and `head -10 CHANGELOG.md`, matching the convention already used by
   other unreleased additions, e.g. `grep -n '@since 0.6.0' classes/Dispatcher.php`).

**Verify**:
- `composer phpcs` → exit 0
- `php -r '$c=["/^\.wp-config\.php(\..+)?$/i","/^#wp-config\.php#$/i","/^\.#wp-config\.php$/i","/^wp-config\.(?:bak|old|orig|save)(?:\.php)?$/i"]; foreach([".wp-config.php.swp","#wp-config.php#",".#wp-config.php","wp-config.bak.php","wp-config.old"] as $x){$hit=false;foreach($c as $p)if(preg_match($p,$x)){$hit=true;break;}echo $x." ".($hit?"CAUGHT":"MISS")."\n";}'`
  → all five print `CAUGHT`

### Step 2: Check `classify_files()`'s resolved identity against the deny-list

In `classes/Rest/Extractions_Controller.php`:

1. Change `classify_files()`'s return type to add a fourth bucket,
   `restricted`, in both the docblock (`:1033`) and all three returned array
   literals: the two early-returns (`:1039-1043` for the empty-selection
   case, `:1052-1056` for the unresolvable-root case), each getting
   `'restricted' => []`, and the final return (`:1101-1105`), getting
   `'restricted' => $restricted`.

2. Initialise `$restricted = [];` alongside `$kept`, `$vanished`, and
   `$out_of_bounds` (`:1060-1062`).

3. Inside the per-file loop, immediately after the existing in-root
   confirmation (the block ending `$out_of_bounds[] = $file; continue;` at
   `:1093-1094`) and before the `$kept[] = $file;` line (`:1097`), insert:

   ```php
   // A path may resolve inside the root under an innocuous name while its
   // real target — through a symlink or other indirection — is one of the
   // credential-bearing patterns (ADR-0011/ADR-00XX). Re-apply the deny-list
   // against the resolved, root-relative identity, not only the caller's own
   // spelling, so a restricted file cannot be smuggled out under a different
   // name.
   $resolved_relative = ltrim( substr( $resolved, strlen( $root ) ), '/' );
   if ( Restricted_Path::is_restricted( $resolved_relative ) ) {
       $restricted[] = $file;
       continue;
   }

   $kept[] = $file;
   ```

   (Replace `ADR-00XX` with the real number once Step 6 assigns it.)

4. In `validate_payload()`, immediately after `$classified =
   $this->classify_files( $files );` (`:886`) and before `$missing_files =
   …` (`:887`), insert a second restricted-path rejection reusing the exact
   same error shape as the existing one at `:864-878`, naming
   `$classified['restricted']`:

   ```php
   // Re-apply the restricted-path rejection against every kept file's
   // resolved identity: the caller-string check above cannot see through a
   // symlink or other indirection whose target is denied. Same code, same
   // shape, still ahead of the existence check.
   if ( $classified['restricted'] !== [] ) {
       return new WP_Error(
           'kntnt_extractor_restricted_path',
           sprintf(
               /* translators: %s: a comma-separated list of the restricted file paths the caller selected. */
               __( 'The selection includes restricted path(s) that cannot be extracted: %s', 'kntnt-extractor' ),
               implode( ', ', $classified['restricted'] ),
           ),
           [
               'status' => 422,
               'paths' => $classified['restricted'],
           ],
       );
   }
   ```

   Do **not** merge this into the raw-string check at `:864-878` or change
   that block at all — it stays exactly as it is, so its existing test
   coverage (`restricted-path-test.php:97-114`) is unaffected. This is a
   second, sequential check, not a rewritten first one.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -c "resolved_relative" classes/Rest/Extractions_Controller.php` → at least 1

### Step 3: Re-check the resolved identity at packaging time, as defence in depth

In `classes/Artifact_Builder.php`, inside `resolve_in_root()` (`:409-424`),
after the existing boundary check throws or passes, and before `return
$abs;`, insert:

```php
// Re-check the resolved identity against the restricted-path deny-list, as
// defence in depth against a record whose file was replaced by a symlink to
// a credential-bearing path between create-time validation and this tick.
$relative = ltrim( substr( $abs, strlen( $root ) ), '/' );
if ( Restricted_Path::is_restricted( $relative ) ) {
    throw new RuntimeException( 'A requested file resolves to a restricted path.' );
}
```

`Restricted_Path` is already in the `Kntnt\Extractor` namespace — same as
`Artifact_Builder` (`:11`) — so no `use` import is needed.

This throw follows the exact same failure shape as the sibling
out-of-root throw two lines above: an opaque failure the job fails on and
never retries (ADR-0015), reported through a normal `failed` poll.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0
- `grep -c "restricted" classes/Artifact_Builder.php` → at least 1

### Step 4: Extend the Playground test suite

In `tests/Integration/restricted-path-test.php`:

1. Add positive assertions for every new shape from Step 1 (model on the
   existing style at `:29-54`, one `kntnt_extractor_assert()` per case):
   `.wp-config.php.swp`, `.wp-config.php.swo`, `#wp-config.php#`,
   `.#wp-config.php`, `wp-config.bak.php`, `wp-config.old`, a nested case
   (`backups/.wp-config.php.swp`), `id_ed25519`, `id_ed25519.pub`,
   `id_ecdsa`, and a root-only negative (`wp-content/id_ed25519` is **not**
   restricted, matching the existing nested-`.sql` negative pattern at
   `:53`).
2. Add negative controls proving the widened patterns don't over-match:
   `wp-config-docs.md`, `identity.txt` (a file merely starting with `id`).
3. Add a regression assertion through the real `POST /extractions` path
   proving `classify_files()`'s new `restricted` bucket does not misfire on
   an ordinary, non-restricted file: create a real fixture file at a nested
   path (model on the `.sql` fixture technique at `:139-146`, e.g.
   `wp-content/kntnt-extractor-test-<random>.txt`), select it, and assert the
   request reaches the same point it would have before this plan (the
   authorization gate, same as the existing `wp-config-sample.php` assertion
   at `:120-121`) rather than a spurious 422. Clean the fixture up
   (`@unlink`) the same way the existing `.sql` fixture is cleaned at `:166`.
4. **Do not** attempt to test the resolved-path check's actual discriminating
   case (a symlink) here — Playground cannot create one (see the dedicated
   section above). That assertion belongs only in Step 5.

**Verify**: `composer test:integration` → exit 0, `Integration suite: PASS`,
and the new assertion count is visible in the TAP output (`grep -c '^ok\|^not ok'`
on the captured output should have grown by the number of assertions you
added).

### Step 5: Add the DDEV symlink test — the only place this can actually be proven

Create `tests/Integration/DDEV/restricted-path-symlink-test.php`, modelled on
`tests/Integration/DDEV/tables-size-test.php`'s structure (its own TAP
helper, `wp eval-file` entry point, `global $wpdb` only if needed). It must:

1. Resolve the real installation root (`realpath( ABSPATH )`), and create a
   symlink at an innocuously-named path directly in that root — a name that
   matches **none** of the deny-list patterns — pointing at the real
   `wp-config.php`. Use `symlink()`; assert it returned `true` (this is also
   the assertion that would fail loudly if a future DDEV/PHP environment
   ever lost `symlink()` support, rather than silently skipping the real
   check).
2. Authenticate as the administrator (same pattern as
   `tables-size-test.php:42-43`).
3. Dispatch `POST /extractions` selecting the symlink's name (not
   `wp-config.php`), with a valid `public_key`, and assert:
   - the response is `422`
   - `code` is `kntnt_extractor_restricted_path`
   - `data.paths` is exactly `[ <the symlink's name> ]` — proving the error
     names what the caller submitted, not the resolved target
4. Remove the symlink (`@unlink`) so the throwaway DDEV project leaves
   nothing extra behind before its own teardown.
5. Exit non-zero on any failed assertion, matching every other file in this
   directory.

Before implementing Steps 2–3, run this new test file against the
**unmodified** code (temporarily `git stash` your Step 1–3 changes, or write
this test first) to confirm it demonstrates the RED state: the symlinked
selection succeeds (`201`, a job is created) rather than being refused. This
is the concrete proof the gap existed. Record that run's output. Then
restore your changes and re-run to confirm GREEN (`422`).

**Verify**:
- RED run (before Steps 2–3, or with them stashed): `composer
  test:integration:mysql` → the new test prints `not ok` for the 422
  assertion (the selection wrongly succeeded)
- GREEN run (with Steps 2–3 applied): `composer test:integration:mysql` →
  exit 0, `DDEV integration check: PASS`, and the new test's every line
  prints `ok`
- **Record both runs' relevant output in your report.** Requires Docker and
  DDEV; if neither is available in this environment, say so explicitly as a
  STOP condition rather than skipping the demonstration.

### Step 6: Write the ADR

Create the next-numbered file in `docs/adr/` — `0020-` prefixed (confirm with
`ls docs/adr/ | sort | tail -1`, which shows `0019-…` at the time this plan
was written). Read `docs/adr/0011-*.md` first for voice — it is this
decision's direct parent — and `docs/adr/0018-*.md` for how to amend a
predecessor ADR explicitly rather than silently.

It must cover:

- Both gaps, each with the concrete evidence gathered above (the pattern
  table, the resolved-vs-unresolved trace through `classify_files()` and
  `resolve_in_root()`).
- Why deny-list-plus-hardening is correct here and an allow-list is not — the
  file layer serves arbitrary caller-selected content, unlike the bounded
  `wp-config.php` define-value case plan 008 fixed with an allow-list.
- **An explicit, precisely-targeted amendment of `docs/adr/0011`'s two
  Consequences bullets.** Quote both bullets verbatim (do not paraphrase them
  as a single, stronger claim): the first asserts the bump, citing 0005 as its
  sole authority; the second says a later widening or narrowing "is likewise
  a caller-visible contract change, not a tweak", chained to the first by
  "likewise" rather than re-asserting the bump on its own authority. State on
  the record that `docs/adr/0017` narrowed the authority the first bullet
  cited, so the chain the second bullet leans on no longer reaches a bump —
  and that neither 0017's nor 0018's own grounds independently apply to this
  plan's changes either. Preserve what the second bullet gets right: the
  change is real and caller-visible, not a tweak, which is why it still gets
  a `README.md` and `CHANGELOG.md` update. Add a short "Amended by"
  cross-reference note to `docs/adr/0011` itself (append a line; do not
  rewrite its existing prose), the same way `docs/adr/0018`
  cross-references `docs/adr/0017`.
- Why the packaging-time re-check exists alongside the validation-time one
  (defence in depth against a record altered in between — the exact
  reasoning `resolve_in_root()`'s own docblock already uses for the
  root-boundary check).
- That `api_version` stays `7` and why (both grounds tested and found not to
  apply).
- That symlinks cannot be created on WordPress Playground and the
  discriminating test therefore lives in `tests/Integration/DDEV/` — record
  this so a future reader does not try to move it to Playground and discover
  the same thing the hard way.
- Under Consequences: the widened pattern list is now caller-visible
  documented behaviour (`README.md`), so widening or narrowing it again later
  is still worth a `CHANGELOG.md` entry even though it does not move
  `api_version`; and the note to `kntnt-wp-skills` that its own mirror is
  now behind this plugin's list in several shapes (left to that repository).

**Verify**: `ls docs/adr/` → the new file is present, numbered one above the
current highest.

### Step 7: README, CONTEXT.md, testing-strategy.md, and CHANGELOG

- `README.md`, "Restricted paths" section (`:51-57`): rewrite the bullet list
  to name every new pattern shape (model the prose on the existing bullets;
  do not just paste raw regexes) and add one sentence stating the check also
  applies to what a selected path resolves to, not only the name it was
  requested under, checked again at packaging time. Keep each paragraph on
  one physical line (this project's Markdown convention).
- `CONTEXT.md`, the **Restricted path** entry (`:23-25`): add the same
  resolved-identity clause, and mention the editor-dropping siblings
  explicitly rather than only "variants". Keep the `_Avoid_` line unchanged.
- `docs/testing-strategy.md:42`: extend the `restricted-path-test.php` bullet
  to mention the widened pattern coverage, and add one sentence to the DDEV
  section (`:50-70`) naming the new `restricted-path-symlink-test.php` file
  and what it proves that Playground cannot.
- `CHANGELOG.md`: one entry under `[Unreleased]` → `### Fixed`, matching the
  surrounding entries' voice and length (see the existing entries for
  register). State both gaps, name ADR-0020 (or whatever number Step 6
  assigned), and state plainly that `api_version` stays `7` and why in one
  clause, matching the "No REST change." / "`api_version` stays `7`." pattern
  already used throughout the file.

**Verify**: `git diff --stat` → only files from "In scope" above.

### Step 8: Full gate, plus the DDEV run already captured in Step 5

**Verify**:
- `composer gate` → exit 0
- The Step 5 GREEN run's output is already captured in your report; do not
  re-run it here unless you changed anything after Step 5.

## Test plan

- **File**: `tests/Integration/restricted-path-test.php` (extend).
  New cases: every new pattern shape from Step 1, both positive and negative
  controls, plus the regression assertion proving `classify_files()`'s new
  bucket doesn't misfire on an ordinary kept file.
- **File**: `tests/Integration/DDEV/restricted-path-symlink-test.php`
  (create). The one assertion that actually discriminates "checked against
  resolved identity" from "checked against the caller's string" — necessarily
  here, not on Playground (see the dedicated section above).
- **Pattern to follow**: `restricted-path-test.php`'s existing style for the
  Playground additions; `tables-size-test.php`'s structure for the DDEV file.
- **Verification**: `composer test:integration` → all pass, including the
  Playground additions. `composer test:integration:mysql` → all pass,
  including the RED-then-GREEN pair from Step 5, recorded in your report.

## Done criteria

Machine-checkable. ALL must hold:

- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] `composer test:integration:mysql` exits 0 and prints `DDEV integration
      check: PASS`, with `restricted-path-symlink-test.php`'s every assertion
      `ok` — run once and its output recorded in your report, since it is
      not part of `composer gate`
- [ ] `composer gate` exits 0
- [ ] `grep -c 'wp-config.bak.php' tests/Integration/restricted-path-test.php` returns at least 1 (checks the test's literal
      assertion string, not the implementation's exact regex syntax — more robust against a differently-phrased but
      equivalent pattern)
- [ ] `grep -c 'id_ed25519' tests/Integration/restricted-path-test.php` returns at least 1
- [ ] `grep -c 'resolved_relative' classes/Rest/Extractions_Controller.php` returns at least 1
- [ ] `grep -c 'restricted' classes/Artifact_Builder.php` returns at least 1
- [ ] `grep -n 'API_VERSION = 7' classes/Rest/Status_Controller.php` returns a match (unchanged from before this plan)
- [ ] `git diff classes/Rest/Status_Controller.php` is empty (no version or `HONOURED_BEHAVIOURS` change)
- [ ] A new ADR file exists in `docs/adr/`, numbered one above the highest
      that existed at `a6de808`, and it contains an explicit amendment
      statement referencing `docs/adr/0011`
- [ ] `grep -c 'ed25519\|swp\|wp-config.bak' README.md` returns at least 1
- [ ] `git status --short` lists only files from the in-scope list (the
      pre-existing untracked `plans/` directory is expected and not a
      violation)
- [ ] Your report contains the RED and GREEN output from Step 5
- [ ] `plans/README.md` status row for 016 updated

## STOP conditions

Stop and report back (do not improvise) if:

- The code at `classes/Restricted_Path.php:42-72`,
  `classes/Rest/Extractions_Controller.php:860-878` or `:1035-1094`, or
  `classes/Artifact_Builder.php:409-424` does not match the excerpts above.
- Docker/DDEV is not available to run `composer test:integration:mysql`.
  Report this rather than skipping Step 5 or writing the symlink assertion
  somewhere it cannot actually run — an unreachable assertion in this repo
  has already had to be documented as such once; do not make it twice.
- Widening the pattern family in Step 1 turns out to catch a name you judge
  is not actually a credential-bearing shape (a real false positive found
  while testing, not merely a probe). Report which name and why; do not
  narrow the family silently to make a test pass.
- You find another place in the request lifecycle — beyond
  `classify_files()` and `resolve_in_root()` — that reads a selected file's
  bytes or resolves its path without checking the deny-list. Report it; that
  is a real finding this plan's scope did not anticipate.
- You conclude the version bump reasoning in "Does this move `API_VERSION`?"
  is wrong for a reason not already addressed there. Report your reasoning
  rather than silently bumping or silently not bumping.

## Maintenance notes

- **`kntnt-wp-skills`'s own mirror is now behind this plugin's list** — see
  "The client's mirror is stale: a coordinated clone can start failing hard
  at submission" above for the exact files, the concrete failure sequence,
  and why this is a release-sequencing decision rather than a blocker on
  this plan. Worth that repository's own follow-up plan, not this one.
- **The blanket "refuse every symlinked file" option was deliberately not
  taken** (see Out of scope). If a future finding shows the narrower,
  pattern-matched resolved check in this plan is insufficient — for instance,
  a site that needs to refuse *all* indirection regardless of target — that
  is a new decision, not an extension of this one, because it trades away
  legitimate symlink use this plan was careful not to touch.
- **What a reviewer should scrutinise**: that the new patterns in Step 1
  don't over-match (re-run the negative-control probes from "The
  false-positive cost" above); that the resolved-path check in
  `classify_files()` only fires inside the `kept` branch (a `vanished` or
  `out_of_bounds` file must never also appear in `restricted` — check the
  `continue` placement); that the packaging-time check in `resolve_in_root()`
  reuses the exact same `Restricted_Path::is_restricted()` call, not a
  reimplementation; and that the DDEV symlink test's `data.paths` assertion
  names the caller's string, not the resolved target.
- **The stale `Extractions_Controller::first_out_of_root_file` reference**
  inside `Artifact_Builder.php:399`'s docblock (a method that does not exist
  under that name) was left alone deliberately — see Out of scope. Whoever
  picks that up next should grep for the real current method name
  (`classify_files`) and fix the cross-reference, but should do so as its
  own small, separate change so the fix is traceable to the actual rename
  that caused the drift, not bundled into an unrelated security plan.
- **What future changes will interact with this**: any future file-selection
  code path added to this plugin (a hypothetical bulk-copy or preview
  endpoint) must call `Restricted_Path::is_restricted()` against the
  *resolved* identity, not just accept a caller string at face value — that
  is the exact mistake this plan fixes, and it is easy to reintroduce by
  writing a new endpoint that copies `classify_files()`'s boundary check
  without also copying its restricted-path re-check.

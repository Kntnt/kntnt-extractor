# Plan 014: Let a caller discover what this build honours, instead of inferring it from a version number

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: `git diff --stat 8a35b2b..HEAD -- classes/Rest/Status_Controller.php classes/Rest/Extractions_Controller.php docs/adr/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW — additive; the existing anonymous handshake is unchanged
- **Depends on**: none. **Blocks**: plan 013, and `kntnt-wp-skills` plan 003 (settle this before that is executed, or its report gets written twice)
- **Category**: direction
- **Planned at**: commit `8a35b2b`, 2026-08-16

## Why this matters

This plugin has one integer, `api_version`, and it is being asked to answer two
different questions at once. It cannot.

The first question is **"may I proceed at all with this artifact contract?"**
That is what the consuming client's verified ceiling is for, and it is a real
safety mechanism with a real incident behind it: version 5 changed a table from
one segment in the sealed index to one-or-more, and a client that had not been
updated kept only the last slice and lost data silently. That question is about
the container's shape, and an integer answers it well.

The second question is **"what does this build actually do?"** — and the integer
cannot answer it, provably. The `strict: false` submission mode is implemented
at `classes/Rest/Extractions_Controller.php:772` while `API_VERSION` stayed at
6. So **no version number will ever distinguish an Extractor that honours
`strict` from one that ignores it.** Not now and not retroactively. A caller
that wants to use it has no way to find out whether it will work, and a caller
that assumes it works gets a hard failure on the first vanished file instead of
the reported skip it asked for.

That is not an isolated slip. It is the same blind spot that leaves nothing
detecting a server *older* than the client: the versioning rule as written
reasons about old-client/new-server ("additive, and the default is current
behaviour, so an old client is unchanged") and never about new-client/old-server
— which is the direction that has actually bitten, because production runs a
release two versions behind.

The fix is to stop overloading the integer. Keep it for the artifact contract
and the ceiling. Add a named capability list for behaviour. Two questions, two
mechanisms, no overlap.

## Current state

### The endpoint, and the anonymous handshake that must not change

`classes/Rest/Status_Controller.php:128-145`:

```php
	public function get_status(): WP_REST_Response {

		// The version handshake is the entire contract for an anonymous caller.
		$status = [ 'api_version' => self::API_VERSION ];

		// Answer the identity question outright for an authenticated caller.
		if ( is_user_logged_in() ) {
			$status['authenticated_as'] = wp_get_current_user()->user_login;
			$status['capabilities'] = [
				Authorizer::OPERATE_CAPABILITY => current_user_can( Authorizer::OPERATE_CAPABILITY ),
				Authorizer::MANAGE_CAPABILITY => current_user_can( Authorizer::MANAGE_CAPABILITY ),
			];
		}

		return new WP_REST_Response( $status );

	}
```

**Note the name collision you must avoid.** `capabilities` is already taken, and
it means *the caller's WordPress capabilities*, not the build's features. Do not
reuse or overload it. Pick a distinct name — `honours` reads well and cannot be
confused with the existing member; `features` is the obvious alternative. Choose
one, and say which in your report.

### The constraint that decides the design

**The integer MUST stay anonymous.** The consuming client reaches `GET /status`
in its health check *before* it resolves any credential, precisely so a
wrong-version server is refused on the cheapest possible call. If the integer
moved behind an authentication gate, a site with a broken Application Password
would report a credential problem when the real answer is "your Extractor is too
new".

**The capability list goes behind the gate.** The consuming client confirmed
every behaviour-gating use it has is post-authentication — `strict` on
`POST /extractions`, `chunks_done` in the poll loop, the `state` parameter plan
013 adds. It never needs to know what a build honours before it has credentials.
And the list is a build fingerprint, so disclosing it anonymously is a small but
free-to-avoid cost.

So: **integer anonymous, capability list authenticated.** That split is the
whole design and both halves are load-bearing.

### The version constant and its own history

`classes/Rest/Status_Controller.php:87` and the docblock above it, which is
where the project states its own rule:

```
	 * Raised to 6 for the poll contract's fifth progress counter, `chunks_done`: […]
	 * It is additive — every existing field keeps its meaning — but the poll
	 * response is caller-visible, so it ships under a bump like any other
	 * change to it.
```

```php
	public const int API_VERSION = 6;
```

Read that against `strict`, which is additive, caller-visible, and did not bump.
The rule and the practice disagree, and this plan is where that gets settled.

### The behaviours a caller currently has to infer

Establish the full list from the code and the CHANGELOG rather than trusting
this one, but it should come out close to:

- `strict` — `POST /extractions` accepts `strict: false` and reports skipped
  files (`classes/Rest/Extractions_Controller.php:772`). **Unversioned.**
- `chunks_done` — the poll's fifth progress counter (version 6).
- `attempts` — the bounded attempt log on the job poll (ADR-0016, unreleased).
- `skipped_files` — named on the create and poll responses (unreleased).
- `disclosure` — the per-record define discriminator, if plan 008 has landed.
- `state` filter on `GET /extractions`, if plan 013 has landed.

### Conventions to match

Read `agents.d/coding-standard/general.md` and `agents.d/coding-standard/php.md`.
Load-bearing: English throughout; a `//` comment above each paragraph stating its
*purpose*; WordPress surface style (tabs, `snake_case`, spaces inside
parentheses); typed constants (`private const array`, `public const int`) as this
file already uses. Complete PHPDoc with `@since`.

Markdown in `docs/`: keep each paragraph on a single physical line.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Coding standard | `composer phpcs` | exit 0 |
| Static analysis | `composer phpstan` | exit 0 |
| Integration suite | `composer test:integration` | exit 0, `Integration suite: PASS` |
| Everything | `composer gate` | exit 0 |

## Scope

**In scope**:

- `classes/Rest/Status_Controller.php`
- `tests/Integration/rest-status-test.php`
- `docs/adr/` — a new ADR settling what the integer means from now on
- `README.md` — a short paragraph on discovery
- `CHANGELOG.md`

**Out of scope** (do NOT touch):

- **The anonymous half of `GET /status`.** `api_version` stays exactly where it
  is, disclosed to an unauthenticated caller. This is a hard constraint, not a
  preference.
- The existing `capabilities` member. It means the caller's WordPress
  capabilities and keeps that meaning. Do not rename it, do not extend it.
- `API_VERSION`'s value. Whether it moves is plan 008's business, not this
  plan's. **Do not bump it here.**
- Any behaviour named in the list. This plan announces what already exists; it
  implements none of it. If a capability you want to name does not exist yet,
  leave it out.
- Retrofitting the list into the ceiling logic. The ceiling stays an integer
  comparison on the client side; that is the point of the split.

## Git workflow

- Trunk-based: commit straight to `main`, no branch, no pull request.
- Commit message, imperative, no prefix. Suggested:
  `Announce what this build honours, so a caller stops inferring behaviour from a version`
- Do NOT push unless the operator instructed it.

## Steps

### Step 1: Establish the real list

Walk `CHANGELOG.md`, `classes/Rest/`, and `docs/adr/` and write down every
caller-visible behaviour a client might reasonably want to gate on, with the
`file:line` that implements it. Then remove any that a caller cannot actually
act on — a name nobody branches on is noise, and this list has to be maintained.

**Verify**: every name on your list has an implementing `file:line`, and you can
state in one sentence what a caller would do differently if it were absent.

### Step 2: Add the member, behind the authentication gate

In `classes/Rest/Status_Controller.php`, add the list inside the existing
`is_user_logged_in()` branch. Back it with a `private const array` of the names,
so the set is stated once. Emit it as a **list of strings**, sorted, not a map of
booleans — absence is the signal, and a map invites the same "member absent
means old server" ambiguity plan 008 had to remove.

Add a paragraph comment stating the split explicitly: the integer answers "may I
proceed with this artifact contract" and stays anonymous; this list answers
"what does this build do" and requires credentials. A future reader who does not
know why the two are on different sides of the gate will eventually move one.

**Verify**:
- `composer phpcs` → exit 0
- `composer phpstan` → exit 0

### Step 3: Pin both halves of the split in the test

Extend `tests/Integration/rest-status-test.php`. It already pins the anonymous
handshake as "exactly `{ api_version }` and nothing more" and the authenticated
identity report, so both halves have an existing home.

Assert:

1. **The anonymous response still carries `api_version` and does not carry the
   new list.** The existing "nothing more" assertion may already cover this —
   check, and if it does, add a description naming the new member explicitly so
   a future reader sees it was considered.
2. **An authenticated caller receives the list**, it is a list of strings, and it
   contains at least one known name.
3. **The list is sorted and free of duplicates.**
4. **The existing `capabilities` member is unchanged** and still reports the
   caller's own two WordPress capabilities. This is the collision guard.
5. **`strict` appears in the list** — the behaviour that motivated the whole
   plan, and the one no integer can express.

**Verify**:
- `composer test:integration` → exit 0, `Integration suite: PASS`
- Demonstrate the RED step: temporarily move the list outside the
  `is_user_logged_in()` branch, re-run, and confirm assertion 1 reports
  `not ok`. Restore and re-run to green. **Record both runs in your report.**

### Step 4: Write the ADR that settles what the integer means

This is the substance of the plan; the code is four lines. Create the
next-numbered file in `docs/adr/`, following the existing naming convention.
Read two or three existing ADRs first for voice — they argue a decision in prose.

It must settle, and be explicit that it is amending earlier guidance:

- **What `api_version` means from now on**: the artifact contract and the
  ceiling — the container's layout, its framing, segments per resource, the
  sealed index, and reassembly order. Cite the version-5 incident as the reason
  the ceiling exists.
- **What it no longer means**: a general "any caller-visible change" counter.
  Note that `CONTEXT.md`'s current definition and the `API_VERSION` docblock's
  own account of the version-6 bump both read more broadly, and say which
  reading now governs.
- **Why an integer cannot do the second job**: `strict` shipped without a bump,
  so no integer can distinguish it retroactively. This is the decisive argument
  and it should be stated as such.
- **The split**: integer anonymous because the refusal must work before
  credentials resolve; list authenticated because it is a build fingerprint and
  no consumer needs it earlier.
- **The blind spot this addresses**: the scheme reasoned only about
  old-client/new-server. Name the direction that actually bit.
- **Consequences**: what a client should do with an unknown name (ignore it);
  that absence is the only signal and therefore the list must always be
  complete; and that adding a capability is additive and does not move the
  integer.

Then update `CONTEXT.md`'s **API version** entry so the glossary matches. The
project's rule is that `CONTEXT.md`'s terms are authoritative and used in code,
docs and dialogue, so leaving it saying something the ADR has just narrowed
would be the worst of both.

**Verify**: `ls docs/adr/` → the new file is present and numbered above the
current highest; `grep -c 'api_version' CONTEXT.md` → the entry has been edited.

### Step 5: README and changelog

- `README.md`: a short paragraph in the Usage area saying a caller can ask
  `GET /status` what the build honours, that the version integer is about the
  artifact contract, and that the list requires authentication.
- `CHANGELOG.md`: one entry under `### Added`. Say plainly that this does not
  move `api_version`.

**Verify**:
- `composer gate` → exit 0
- `git status --short` → only the files from "In scope"

## Test plan

- **File**: `tests/Integration/rest-status-test.php` (extend).
- **Cases**: the five assertions in step 3.
- **Pattern to follow**: the existing anonymous-handshake and identity-report
  assertions in the same file.
- **Verification**: `composer test:integration` → all pass, plus the
  demonstrated failing run from step 3.

## Done criteria

- [ ] The new member appears only inside the `is_user_logged_in()` branch — verify by reading
- [ ] `api_version` is still returned to an anonymous caller
- [ ] The existing `capabilities` member is untouched
- [ ] `grep -n 'API_VERSION = 6' classes/Rest/Status_Controller.php` still matches (this plan does not bump)
- [ ] A new ADR exists in `docs/adr/` and states what `api_version` does and does not mean
- [ ] `CONTEXT.md`'s **API version** entry has been updated to match the ADR
- [ ] `composer phpcs` exits 0
- [ ] `composer phpstan` exits 0
- [ ] `composer test:integration` exits 0 and prints `Integration suite: PASS`
- [ ] `composer gate` exits 0
- [ ] `git status --short` lists only files from the In-scope list
- [ ] Your report contains the output of the deliberately-failing run from step 3
- [ ] Your report names which member name you chose and why
- [ ] `plans/README.md` status row for 014 updated

## STOP conditions

Stop and report back (do not improvise) if:

- You find yourself putting the list, or any part of it, outside the
  `is_user_logged_in()` branch. The fingerprinting concern is small but the
  split is a negotiated contract with the consuming repository.
- You find yourself moving `api_version` behind the gate. This would break the
  consuming client's health check, which reads it before resolving a credential
  by design.
- You find yourself bumping `API_VERSION`. Not this plan's business.
- The ADR you are writing contradicts an existing ADR in a way you cannot state
  cleanly as an amendment. Report rather than quietly narrowing a decision.
- A behaviour you want to name turns out not to be implemented. Leave it out and
  say so.

## Maintenance notes

- **The rule to keep**: a new capability is additive and does not move the
  integer. The integer moves only when the container's shape moves. If you ever
  find yourself wanting to bump the integer for a REST-only change, that is the
  signal the ADR from step 4 needs revisiting — not a signal to bump.
- **The list must stay complete.** Absence is the only signal a caller has, so a
  behaviour that ships without being named is exactly the `strict` failure
  again, one layer up. Consider a checklist line in the release procedure.
- **Interaction with the consuming repository**: their plan 003 carries
  `api_version` into their discovery document and reports what it degrades. With
  this list, that report becomes mechanical — name what is absent — instead of
  hand-maintained "below version N implies feature F" prose across two skill
  files. **Settle this plan before their 003 is executed**, or that report gets
  written twice.
- **What a reviewer should scrutinise**: that the anonymous response really is
  unchanged, and that the new member's name cannot be confused with the existing
  `capabilities`.

# Define disclosure protocol

Normative specification of what a `GET /environment` `defines` record means and how a reader MUST interpret it. See [ADR-0018](adr/0018-a-defines-value-discloses-only-from-an-allow-list-with-a-per-record-discriminator.md) for why this exists and [ADR-0017](adr/0017-api-version-bounds-the-artifact-contract-honours-reports-what-a-build-does.md) for how `api_version` and `honours` relate to it.

This document specifies the *protocol* — what a value or its absence means, and what a reader MUST do about it. It deliberately does not specify the *policy* — which define names are currently allow-listed. Policy is not part of this contract; see "Policy is not part of this contract" below.

## The record shape

Every entry in the `defines` array on `GET /environment` has exactly this shape:

```json
{ "name": "DB_PASSWORD", "value": null, "disclosure": "secret" }
```

- `name` — the define's name, exactly as found in the site's `wp-config.php`. A name is reported for every `define()` call the server locates, unconditionally. Names are never withheld; only values are.
- `value` — the define's resolved live value (`string | int | float | bool | null`), or `null`.
- `disclosure` — a string naming why `value` is what it is. See below.

## What `null` means

Before this protocol existed, `value: null` was ambiguous: it could mean "the server withheld this by policy" or "this define's real value is the PHP value `null`" (a legitimate `define('X', null)`), and nothing on the wire distinguished the two. `disclosure` exists to remove that ambiguity. A reader MUST NOT infer a define's disclosure state from `value` alone; `disclosure` is the only reliable signal.

## The `disclosure` enum

`disclosure` MUST be one of exactly three values:

| Value | Meaning |
|---|---|
| `included` | The value is disclosed and, when present, is the define's real, live value. `value` may still be `null` here — a legitimately-null define, or one the allow-list permits but that is not currently defined on this install — and that is a fact about the define, not a withholding. |
| `secret` | The value is withheld because the name matched the server's heuristic redaction pattern (a name shaped like a credential — containing `KEY`, `SECRET`, `TOKEN`, `PASS`, `SALT`, `NONCE`, `AUTH`, `CREDENTIAL`, `PRIVATE`, `LICEN`, or `API`, case-insensitively). `value` is always `null`. |
| `not_allow_listed` | The value is withheld because the name is not on the server's disclosure allow-list (and did not match the heuristic pattern either). `value` is always `null`. |

The set is closed. A reader MUST treat any `disclosure` value it does not recognise — a future fourth value this document has not yet been updated to describe — as withheld, exactly as it would treat `secret` or `not_allow_listed`: do not read `value`, do not port it, do not treat its absence as evidence the define does not exist server-side.

## The present-on-every-record rule (MUST)

**`disclosure` MUST be present on every record, including one whose value is disclosed (`included`).** This is the load-bearing rule of the whole protocol. If the member were present only on a withheld record, then its *absence* would become a fourth, unstated state meaning "this server predates the protocol" — and a reader would be back to inferring a define's disclosure state from context, which is the exact failure this protocol exists to remove. A reader that finds a `defines` record without a `disclosure` member MUST treat that record as talking to a server that does not implement this protocol at all, and MUST NOT assume anything about `value` on that record beyond what it could already assume before this protocol existed.

## Names are always disclosed

A name is reported for every `define()` the server locates in `wp-config.php`, regardless of `disclosure`. Only values are gated. A reader that wants a value the server withheld knows the name exists and can ask the site operator to opt it in (see "The escape hatch" below); a protocol that hid names as well would prevent even that.

## Policy is not part of this contract

Which define names are currently allow-listed — and therefore capable of reporting `included` — is server-side policy, not part of this protocol, and MAY change between releases without a version bump. A reader MUST NOT hard-code the current allow-list's membership, assert it against the server's, or treat a name moving from `not_allow_listed` to `included` (or back) between two `api_version`-equal responses as a protocol violation. The same holds for the heuristic that produces `secret`: the substrings listed in the enum table above (`KEY`, `SECRET`, `TOKEN`, `PASS`, `SALT`, `NONCE`, `AUTH`, `CREDENTIAL`, `PRIVATE`, `LICEN`, `API`) are illustration of the current policy, not a contractual set — they too MAY change between releases without a version bump, and a reader MUST NOT hard-code them, assert them against the server's, or treat a name moving between `secret` and `not_allow_listed` as a protocol violation. What MUST NOT change without a version bump is the *protocol* above: the meaning of `null`, the three-value `disclosure` enum and its meanings, and the present-on-every-record rule.

## The escape hatch

A site operator MAY disclose a specific, currently-unlisted define's value by naming it explicitly through the server's own configuration (the `disclosable_defines` knob). This is a per-site, explicit, operator-initiated decision — never a caller-supplied request parameter, and never a way to disclose an unbounded set of names at once. A reader MUST NOT assume an escape-hatched name stays disclosed on a different site, a different release, or after the operator reverses the decision; a name reporting `included` today is a fact about this response, not a promise about the next one.

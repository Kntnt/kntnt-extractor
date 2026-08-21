# The byte-compatibility claim is checked against a golden container, read by a reader that never ships

[0014](./0014-the-persisted-record-is-split-so-a-save-is-bounded.md)'s Consequences assert that "an artifact produced by this release is byte-compatible with one produced by 0.5.1 and `kntnt-wp-skills` needs no change". `docs/container-format.md` §10 repeated the claim and recorded that nothing here could check it. Until now the two ends of a binary cross-repository contract were held together by two people's reading of two diffs.

They are now held together by `tests/Integration/golden-container-test.php`, which reads a container the **0.5.1** writer produced — committed as `tests/Fixtures/container-0.5.1.b64` — with a reader that exists only under `tests/`, and compares its framing against a container the current writer produces from the same recipe. `composer gate` now fails if this release would frame an artifact differently from the release the claim names.

Three decisions carry it, and each was open when the work was filed.

## 1. The fixture is cut from `v0.5.1`, and there is one fixture per `FORMAT_VERSION`

`v0.5.1` is the release the claim names, so a fixture cut from it makes the sentence checkable exactly as written. It is also the newest tag whose writer is **not** the writer under test: `git diff v0.6.0..HEAD -- classes/Crypto/Sealed_Writer.php` is empty, so a fixture cut from 0.6.0 would have been produced by the code it is meant to check — a tautology on the day it landed. The 0.5.1 writer is a genuinely different implementation: six commits between the tags moved 304 insertions and 109 deletions through that file, taking the index from an in-memory list to a sidecar (`f5a1e0a`, the commit [0014](./0014-the-persisted-record-is-split-so-a-save-is-bounded.md) records) and `add_segment()` from a stream parameter to a string (`a2d7c01`). That the two still frame identically is the entire content of the claim.

It also stands in for everything before it. `git diff v0.1.0 v0.5.1 -- classes/Crypto/Sealed_Writer.php` is one line, inside a docblock, so the writer's code is byte-identical from 0.1.0 through 0.5.1 and there is nothing further back to gain.

The general rule, for whoever faces the question next: **one fixture per `FORMAT_VERSION`, cut from a release that shipped that version, never from the working tree.** Version 1's fixture is the 0.5.1 one, and it is now permanent.

## 2. The reader is test-only, and the build test is what enforces that

`tests/Support/Sealed_Reader.php` is written from `docs/container-format.md` §4 alone and never ships.

**The server cannot use a reader, in any release, ever.** [0009](./0009-per-segment-encrypted-artifact-sealed-to-caller-key.md)'s construction is one-directional: every segment key and the sealed index are sealed to the caller's X25519 public key and the private half never reaches this site. A `Sealed_Reader` under `classes/` would be code no production path could call, shipped to the site, sitting in the `Crypto` namespace, and read by every reviewer who wonders what opens artifacts on the server. The answer has to stay "nothing does", and it has to be visible that it stays that way.

**A second shipped implementation is the cost the specification exists to avoid.** `docs/container-format.md` names one reference implementation here (the writer) and one production reader elsewhere (`kntnt-wp-skills`). A reader shipped from the repository that also owns the format document would look normative, and every format change would then need three things kept in step instead of two. A test-only reader carries no such claim: it is written *from* the document, the document governs on any disagreement, and its only job is to hold the writer to what the document says. That is also why it may not name the writer's class or import its constants — a reader that took its expectations from the code it checks could not detect that code changing them, and `grep -c 'Sealed_Writer' tests/Support/Sealed_Reader.php` returning 0 is the cheap mechanical form of that rule.

**It costs nothing to enforce.** `tests/Build/build-release-zip-test.sh` already asserts that no path under `kntnt-extractor/tests/` reaches the distributable, and `composer test:build` is the gate's fourth step.

The rejected answer was right about one thing, and it is a real cost rather than a rhetorical one: `phpcs` and `phpstan` read only `classes/` and the three root files, deliberately, so nothing under `tests/` is statically analysed. The reader is not. That is paid for by keeping it small, giving it one caller, and exercising it with a dozen assertions on every gate run. If it grows a second responsibility, revisit — but revisit by shrinking it, not by shipping it.

## 3. The fixture's bytes are never regenerated

This is the decision with the trap in it. A golden file that is re-cut whenever it fails proves nothing, because re-cutting it is exactly the action a broken writer needs in order to become invisible. Three legs answer it, and the first is the one that does the work.

**Leg 1 — the expectations are derived from the specification, not recorded from the fixture.** The test computes `sk_length` (32 + 48), every `ct_length` (plaintext + 16), `index_length` (Σ(8 + name) + 48) and `total` (9 + Σ(8 + sk + 24 + 8 + ct) + index + 8) from the recipe by the document's own arithmetic. **No digest of the fixture is recorded anywhere.** The arithmetic never consults the file, so a re-cut blob cannot turn a red assertion green — it can only move which assertion is red. Defeating the test requires editing the arithmetic, which is editing the format's rules in a reviewable diff.

**Leg 2 — nothing here can rewrite the fixture, and no script that would is committed.** The suite never writes to `tests/Fixtures/`. The procedure for re-deriving the container is recorded in prose in `tests/Fixtures/README.md`, so provenance can be audited without "re-cut the golden file" being a one-command reflex when a test goes red. This is the one place the design trades convenience for friction deliberately.

**Leg 3 — provenance is pinned to git, which supports an audit.** The tag, the commit and the two blob hashes are recorded and are checkable with `git rev-parse` against history no regeneration touches. What that cannot do is prevent a substitution: a regeneration can simply leave the README alone, and the fixture's random keys and nonces mean its bytes cannot be re-derived and compared. The hashes attest to what is *said* to have produced the bytes. Leg 1 is what makes producing different bytes pointless.

## Consequences

- **A red golden test has exactly two causes, and neither is "the fixture is stale":** the current code no longer honours the format, or the format was moved deliberately and the second fixture that change owes has not been added yet. Regenerating is not on the list. `tests/Fixtures/README.md` says so where whoever meets the red test will be standing.
- **A format change adds a fixture rather than replacing one.** Moving `FORMAT_VERSION` moves `api_version` (§8) and is a coordinated release of both repositories. That change adds `container-<release>.b64` for the release that first shipped the new version, with its own recipe and its own spec-derived arithmetic.
- **What the reader does with the old fixture is decided by that change**, and there are two admissible outcomes, both assertions rather than deletions: either the reader still opens version-1 containers and the old fixture keeps its assertions, or it refuses them and those assertions become an asserted refusal naming the version. `docs/container-format.md` §8 requires a reader to refuse a *higher* version than it implements and says nothing about a lower one, which is why this belongs to that change and is not settled here.
- **`API_VERSION` does not move, and `HONOURED_BEHAVIOURS` gains nothing.** No production byte changed — not a line, not a comment — and no caller can observe any of this. The plugin gains no capability: the reader is test-only, the server still holds no private key, and [0009](./0009-per-segment-encrypted-artifact-sealed-to-caller-key.md) is untouched.
- **What is checked is the framing, not the content.** The sealed keys, nonces and ciphertexts are random and are the one thing two releases must *not* agree about, so the comparison is over the version byte, the total length, the index length and the ordered length pairs — every byte of a container that is not one of those three spans. A writer that changed what it puts *inside* a segment passes this test; that is `tests/Integration/table-chunking-test.php`'s job.
- **This does not prove `kntnt-wp-skills` can read the container.** It proves the container matches the document that client is written against. The client's own conformance is that repository's to test, and a golden artifact is exactly as reusable there.
- **Plan 009's open review question is now answered by the gate rather than by opinion.** That plan asked whether someone could implement a working reader from `docs/container-format.md` alone, without reading `classes/`. One has been, and it is required to stay that way.

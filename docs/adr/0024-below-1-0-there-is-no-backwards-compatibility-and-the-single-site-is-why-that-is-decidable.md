# Below 1.0 there is no backwards compatibility, and the single site is why that is decidable

Until this plugin reaches 1.0 it carries **no backwards compatibility with its own earlier releases**. It ships no migration, no tolerance branch for a record an older release wrote, no cleanup routine for what one left behind, and no deprecation cycle. A release understands the shapes it writes, and no others.

The fact that makes this a decision rather than a wish: the plugin is installed on **exactly one site**, operated by the author, and has been distributed to nobody else. There is no second installation whose upgrade path has to be reasoned about, because there is no second installation.

## Why this had to be written down rather than assumed

[0015](./0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md) built an entire back-compatibility cluster — a resume path for a stall stranded by a pre-0.6.0 release, a carve-out exempting that record from the TTL sweep, a reseeded sealed index, and a family of tolerant deserialisation branches — and then stated that its retirement condition was uncheckable in general, "because it ships to sites the author does not operate."

That premise was simply false, and nobody had said so. The cluster was therefore on its way to becoming permanent by default, which is the exact failure mode that same ADR names in its own last bullet: an unimplemented recommendation sitting in a Consequences list is the shape that quietly becomes permanent. The condition it could not check is settled here by decision instead, and #52 is the retirement it unblocks.

The general point is worth more than the instance. An architectural decision that reasons about "sites the author does not operate" is reasoning about a population that does not exist, and any cost it justifies is paid for nothing.

## What this is not

**It is not licence to break the sealed container's byte format or the REST contract casually.** `API_VERSION` and `SCHEMA_VERSION` keep the lifecycles [0017](./0017-api-version-bounds-the-artifact-contract-honours-reports-what-a-build-does.md) and [0018](./0018-a-defines-value-discloses-only-from-an-allow-list-with-a-per-record-discriminator.md) give them, and the coordinated-release requirement with `kntnt-wp-skills` is untouched. That requirement rests on a different fact: the client is a **separate program with its own release cadence**, and an installed copy of it can be older than the server it is pointed at. Compatibility with a concurrently-running client is a real constraint. Compatibility with a record this plugin itself wrote three releases ago is not.

**It is not a licence to lose data carelessly.** Removing a tolerance branch must leave the plugin able to *ignore* what it no longer understands: a record it cannot parse is skipped and the records around it still enumerate. A removal that lets one stale file break the listing or the sweep for live jobs is a defect, not an application of this decision.

**It is not permanent.** Reaching 1.0 re-opens it deliberately. The point of naming 1.0 is that the reversal happens on a decision rather than by drift.

## What follows concretely

A deserialiser understands the current schema and no earlier one. No migration is written, ever — what an unsupported release left on disk is either reclaimed by the ordinary sweep windows or left where it lies, and neither is the plugin's concern.

Version history in the source falls under the same reasoning. `@since` exists so a future reader can tell which release a symbol appeared in, which is bookkeeping for exactly the compatibility question this decision defers; it is therefore not required on new symbols below 1.0 (#45). The requirement to document *why* and *what the contract is* on every symbol is untouched and is not what this relaxes.

## Consequences

- [0015](./0015-a-stall-shrinks-the-chunk-and-a-failed-stall-can-be-re-driven.md)'s back-compatibility cluster retires as one piece (#52), and the "a failed stall can be re-driven" half of that decision's title stops describing the code. Its own text stands with a dated addendum rather than being rewritten, per the convention [0013](./0013-tables-are-dumped-in-keyset-paginated-slices-across-ticks.md) established.
- The TTL sweep carries no exemption of any kind. `failed` becomes terminal for every purpose, in both directions: it frees the concurrency slot and nothing re-enters it into `running`. A failed record is reclaimed on the same two windows as any unfinished job, which also disposes of whatever an older release left behind, without a migration and without anything to remember to run.
- A site that skipped releases and still holds an in-progress build from an older one loses that work on upgrade. There is one site, and its operator took this decision knowing that.
- New symbols below 1.0 carry no `@since`. Existing stamps are correct for the releases they shipped in and are left alone.
- **If this plugin is ever installed anywhere the author does not operate, this decision must be revisited before that happens, not after.** Its whole ground is a population of one, and distribution is what removes the ground. The GitHub release the bundled update checker reads ([0005](./0005-github-releases-self-hosted-update-checker.md)) is a distribution channel that is already open, so this is a condition to watch rather than one that cannot arise.

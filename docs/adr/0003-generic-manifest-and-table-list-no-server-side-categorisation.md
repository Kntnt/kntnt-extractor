# A generic manifest and table list, with no server-side categorisation or query language

The plugin exposes exactly three read/select primitives: a table list, a recursive file manifest (path, size, mtime) from the WordPress installation root downward, and a single extraction endpoint that takes an explicit, already-resolved list of table names and/or file paths. There is no "give me everything except X" query language, and no concept of a generated thumbnail, a blob, or any other classification of what a file is *for* — the plugin reports what exists and extracts exactly what it is told to, nothing more expressive.

Building that classification logic into the plugin was considered and rejected: it is domain knowledge specific to one consumer (`kntnt-wp-skills` already computes exactly this distinction client-side, from attachment metadata it reads via the table list), and baking it into a plugin meant to be a standalone product regardless of that consumer would be a layering violation. It also isn't needed to avoid excessive round trips: the same read-manifest-then-request-exactly-this-list shape already used by `kntnt-wp-skills`'s existing production channel is one manifest call, one table-list call, and one batched extraction call — not a round trip per file.

The manifest is delivered complete, but not necessarily in one HTTP response: on a large installation it is paged through an opaque, ordering-based cursor that the caller loops over to reassemble the whole listing. This keeps that intent — no round trip per file — while bounding each response's memory and time, and it is never a semantic filter: the reassembled manifest is still every file from the installation root downward.

Table names are validated against the database's actual table list before use; no fragment of SQL is ever accepted from a caller. File paths are `realpath`-normalised and rejected outright — not merely sanitised — if they resolve outside the WordPress installation root.

## Consequences

- Any client wanting a filtered or categorised selection (e.g. "only original images, not regenerated thumbnails") must compute that filter itself from the manifest and table list, then submit the resolved list.
- A resource name that doesn't already exist (an unknown table, a path outside the root) is rejected before any capability check runs.

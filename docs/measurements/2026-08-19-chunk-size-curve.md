# Chunk size against packaging cost, 2026-08-19

A controlled experiment on the production host, run after `2026-08-18-production-run.md` showed that files needing more than one part cost 44 % of a six-hour extraction. It answers one question: **how much does `chunk_size` matter, and where does it stop mattering?**

`DEFAULT_CHUNK_SIZE` has never been measured. `CHANGELOG.md`'s 0.6.0 entry says so outright — "of 28,021 files packaged successfully the largest was 3.87 MB, so no part anywhere near 8 MiB had ever actually been produced on that host." The default was a chosen constant that the first file large enough to exercise it then killed a run on.

## Method

The same file every time: `wp-content/uploads/2020/04/lifvs.jpg`, 36,248,482 bytes. Each run is a fresh single-file extraction — its own job, its own empty container, its own ephemeral key — submitted through `POST /extractions` and sampled by an external observer polling `GET /extractions/{id}` every 15–20 s. The size was varied by the `kntnt_extractor_config_chunk_size` filter on production between runs, and every run's part count was checked against the size to confirm the filter was actually in force. Each job was consumed or cancelled afterwards; the raw series are the `2026-08-19-chunk-*.csv` files beside this one.

Holding the file, the host, the build and the method fixed leaves `chunk_size` as the only variable. That matters, and it is what an earlier reading of the production run got wrong — see "The confound this was designed to remove".

## Result

| `chunk_size` | Parts | Wall clock | Per part | Relative to 256 KB | Re-begun offsets |
|---|---|---|---|---|---|
| 256 KB | 139 | 85 s | 0.61 s | 1× | 0 |
| 256 KB (repeat) | 139 | 82 s | 0.59 s | 1× | 0 |
| 1 MiB | 35 | 108 s | 3.09 s | 5.0× | 0 |
| 2 MiB | 18 | 184 s | 10.22 s | 16.7× | 0 |
| 4 MiB | ≥3 | **abandoned at 12 min** | ~270 s | ~440× | 0 observed |

Two things in that table, and they are different shapes of finding.

**Up to 2 MiB it is a curve, not a threshold.** Per-part cost grows roughly twice as fast as the part: 4× the size costs 5× the time, 8× costs 17×. Nothing retries; every part completes on its first attempt. Smaller is simply better, monotonically, with no cliff to keep clear of.

**Between 2 MiB and 4 MiB something breaks.** Doubling the part from 2 MiB to 4 MiB multiplies the per-part cost by about 26. At 4 MiB the run managed two parts in twelve minutes and was abandoned; extrapolated, the 36 MB file would have taken about forty minutes, against 85 seconds at 256 KB. **What that threshold is has not been identified.** `php://temp`, whose in-memory spill threshold is 2 MB and which would have been an elegant fit, was removed from `classes/` by plan 006 and is structurally absent — it is not the cause. No further hypothesis has been tested, and guessing one here would be the mistake this document exists to correct.

## The confound this was designed to remove

The 4 MiB figure in `2026-08-18-production-run.md` was taken mid-clone, when the container was already gigabytes long and tens of thousands of segments deep, while every other figure came from a fresh container. Two things varied at once — the part size and the container's state — so that comparison could not attribute the cost to either. `Sealed_Writer::suspend()`/`resume()` do an `ftruncate` and an `fflush` on the container per chunk, and on a multi-gigabyte file that is a materially different operation than on an empty one, which made the container's state a live alternative explanation rather than a pedantic caveat.

The 4 MiB run above is the control that separates them: **fresh container, one file, ~270 s per part** — indistinguishable from the ~333 s per part measured deep inside the six-hour run. The container's state is not the driver. The part size is.

## What follows

- **256 KB is the measured choice for this host**, on four controlled runs: fastest, monotonically better than every larger value, two mutually consistent repeats, and no retries anywhere. It is measured rather than chosen, which is what rule R4 asks for.
- **8 MiB is not merely suboptimal, it is dangerous.** It sits far past a threshold that turns a slow run into an impossible one, and it was, at the time of this experiment, the shipped default. **Since acted on**: [ADR-0023](../adr/0023-the-file-part-default-is-the-one-size-measured-to-complete-a-clone.md) made `DEFAULT_CHUNK_SIZE` 256 KB on the strength of these figures.
- **The right value is host-specific and this experiment cannot generalise.** One host, one filesystem, one process manager. What generalises is the method — vary the size against one file and read the part count — and it took under ten minutes per point.
- **The size could not, at the time of this experiment, be set per run.** `POST /extractions` accepted no `chunk_size`; the only lever was a site constant or filter, so the one knob that decides whether a multi-hour extraction survives required editing code on production. Making it an optional member of the create payload would be additive — an old client that omits it is unaffected — so it belongs in `HONOURED_BEHAVIOURS` rather than in an `api_version` bump, and it is what would let a client probe for the value the way this experiment did by hand. **Since acted on**: the member exists as of issue #28 and [ADR-0021](../adr/0021-the-create-payload-carries-the-file-part-budget-in-the-range-the-config-seam-already-permits.md), on that reasoning; the shipped default is unchanged and stays a separate decision.

## What this does not establish

- **Where the threshold between 2 and 4 MiB is, or what it is.** Bisecting it was not attempted.
- **That 256 KB is optimal.** Nothing below 256 KB was tested; the curve's shape suggests smaller may be faster still, at some point traded against per-chunk overhead that this host's numbers do not resolve.
- **Anything about small files.** Every file under one part is unaffected by this setting, and the ~200 ms per small file measured on 2026-08-18 is untouched by any value here. On a selection of tens of thousands of small files, that cost still dominates the run.
- **Anything about other hosts.** Each figure is `safeteam.se` on LiteSpeed, PHP 8.4.21.

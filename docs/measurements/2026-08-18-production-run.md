# Production run, 2026-08-18: what a chunk actually costs

The measurement `ADR-0014` said could not be taken from a laptop, taken on the host that produced the original figures. `2026-08-18-production-run.csv` beside this file is the raw sample series it is derived from; every number here is computed from that file and nothing else.

## The run

| | |
|---|---|
| Build | 0.6.0, the first release carrying the record split |
| Host | LiteSpeed, PHP 8.4.21, `max_execution_time` 300 |
| Selection | 186 tables, 48,559 files |
| Outcome | **failed at 47,504 files (97.8 %)** after 6 h 05 min |
| Sampling | `GET /extractions/{id}` every 30 s, 720 samples, median interval 30 s, no gaps, no cached responses |

The sampler was an external observer, independent of the client's own poll loop. It began about ten minutes into the run, so the table phase and the first minutes of the file phase are outside the series; everything below is scoped to what was actually observed.

## The three findings

### 1. Three files consumed 44 % of the wall clock

The bounded `attempts` log names the chunk each tick began. Filtering the series for a file that ever appears at a non-zero offset — that is, a file large enough to need more than one part — gives three files in the observed window:

| Largest observed offset | Parts seen | File |
|---|---|---|
| 32 MiB | 7 | `wp-content/uploads/2020/04/lifvs.jpg` |
| 32 MiB | 8 | `wp-content/uploads/2026/05/Vad-hander-nar-jag-fyller-i-formularet.mp4` |
| 8 MiB | 2 | `wp-content/uploads/2023/06/safeteam_the_movie_202100901.m4v` |

Summing the sample intervals during which one of those three was the current chunk: **2.06 of 4.70 observed hours, 44 %.** Those three files are **0.006 %** of the selection's file count. The client-side session reported four such files across the whole run, accounting for about 2.5 h of the 6 h — consistent with this, with one file packaged before sampling began.

Per byte the disparity is the sharpest number the run produced:

| | Throughput |
|---|---|
| Ordinary files (~44 KB) | ~220 KB/s |
| A multi-part file | ~12.7 KB/s |

**A large file packages roughly seventeen times slower per byte than a small one.** This is not per-file overhead. `realpath()`, the `stat` calls and the open-read-close are paid once per file, so they cannot explain a cost that grows with the part count; whatever dominates here scales with the part, not the file.

### 2. The record split delivered nothing measurable on this host

`ADR-0014` measured the split at a factor of 13.8 on a local fixture where `save()` was 96 % of per-chunk time, and predicted "roughly a factor of two there — about 3.2 hours of file phase down to about 1.5" on the production host, where `save()` was estimated at 45–56 % of a chunk.

Observed, with the pauses of §1 and §3 removed so the comparison is like for like:

| | |
|---|---|
| Moving time, file phase | 3.39 h |
| Files packaged | 47,449 |
| Moving rate | 3.89 files/s, **257 ms/chunk** |
| Best sustained 10-minute window | 5.60 files/s, 179 ms/chunk |
| Prior run, same host | 4.75 files/s, ~210 ms/chunk |

**The predicted factor of two is not there.** The best window this build achieved is about 15 % better than the prior build's average; the moving average is worse. The estimate that `save()` was half of a production chunk was wrong: it was a much smaller share, and the unattributed remainder dominates more than anyone assumed. The ~115 ms `ADR-0014` could not attribute is therefore not a residue around a fixed cost — it is very nearly the whole cost.

This does not retract the split. Per-chunk cost no longer varies with selection size, which was its own claim and is not what this measurement tests. What is retracted is the prediction of what it would buy on a slow host.

### 3. The failure that ends production runs records nothing about itself

The run failed having packaged 97.8 % of the selection. **What it died on is now identified, and it is not what this document first said.** An earlier revision read the failure as the large-file wall, because the last sample before it showed a multi-part `.mp4` at byte 0; that was wrong. The `attempts` list in the failed sample shows that file completed, then eight small `.webp` files begun within two seconds at full speed, and the last chunk begun before the job died was `wp-content/uploads/2026/08/kntnt-extractor.zip` — at 02:34:55, nine seconds before the failure.

That file was present in the `GET /files` walk at 20:22 and returns `404` today while its neighbours in the same directory return `200` (verified independently). The most likely explanation is WordPress's own upgrade cleanup removing the release archive that installed the very Extractor build this run depended on, hours into the run. `strict: false` covers only files already gone when the job is created, so a file deleted *during* packaging fails the whole job.

Be exact about the epistemic status: **the plugin said only `The extraction failed.`** The attribution above is a strongly supported inference from four converging facts — the file was the last chunk begun, in the same second range as the death; it 404s now; its neighbours do not; and its removal has an obvious cause. The server confirmed none of it, which is the subject of this finding rather than an aside. The poll reported:

```json
{"state": "failed", "error": {"message": "The extraction failed."}}
```

`Extractions_Controller::error_of()` reads `$job->error ?? __( 'The extraction failed.' )`. The fallback was used, so **`$job->error` was null** — an unexpected throw, not the stall rule reaching its floor. Three consequences follow, and together they are the most actionable result of the run:

- **No stall reason was written**, so the two-pair limit reading — the host's own configured limits against the ones in force after the plugin asked for more — does not exist for this failure. That pair is the cheap signal `ADR-0015` built to discriminate a PHP ceiling from a kill above PHP, and the failure mode that actually ends runs on this host bypasses it entirely.
- **The resume path never re-drives an opaque failure** (`ADR-0015`), correctly, since it would retry a permanent error forever.
- **The container and its sidecar are discarded at fail time**, so six hours of packaging were destroyed by a failure that recorded one sentence about itself.

The adaptation family fired and worked, which is worth stating plainly because it is easy to read this run as its failure. Part sizes in the `attempts` series are 4 MiB — exactly half the 8 MiB default — so a stall was detected and `chunk_size` was halved. The three large files were survived rather than fatal, which is the behaviour the prior release lacked when it died three times at byte 0 of the first full-size part. What killed this run was something else, and nothing recorded what.

## Two secondary observations

**Pauses are locked to the host's execution limit.** Every pause of 60 s or more clusters at 303–304 s, against a host `max_execution_time` of 300. Each part of a large file consumes an entire execution window and commits exactly one chunk. Pauses account for **2.49 of the 5.87 observed file-phase hours, 42 %**.

**`GET /environment` does not carry the execution limits.** Diagnosing this from outside required reading them from a stall reason, which an opaque failure never writes. The queue item that would add them to `/environment` before a run — so a caller can know what a host grants without failing first — is unimplemented, and this run is the argument for it.

## What this does not establish

- **One run, one host.** Every number here is `safeteam.se` on LiteSpeed. Nothing generalises to a host with a different filesystem or process manager.
- **The cause of the large-file cost is not identified**, only bounded. The measurement says it scales with the part rather than the file, and rules out per-file path resolution and stat calls. It does not distinguish reading, sealing, the container's suspend/resume on a file already gigabytes long, or memory pressure. Attributing it needs timing inside a tick, which this build does not have.
- **The cause of the opaque failure is unknown**, and by construction unknowable from this run — that is the finding, not a gap in the analysis.
- **The file selection differed** from the prior run's (48,559 against 49,228), so the two runs are comparable in rate but not identical in content.

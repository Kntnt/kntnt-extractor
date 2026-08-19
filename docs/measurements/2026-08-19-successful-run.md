# The run that completed, 2026-08-19

The same site, the same build, the same selection as `2026-08-18-production-run.md` — which died at 97.8 % after six hours. This one finished. The only deliberate difference is `chunk_size`, lowered from the shipped 8 MiB default to 256 KB on the strength of `2026-08-19-chunk-size-curve.md`.

It is included here because a controlled experiment on one file predicts, and a completed production run confirms. The prediction was ~3.5 h against the previous 6; the run took **3.56 h**.

## Result

| | 2026-08-18 | 2026-08-19 |
|---|---|---|
| Outcome | failed at 47,504 / 48,559 (97.8 %) | **complete: 48,578 / 48,578, 186/186 tables** |
| Wall clock | 6.08 h | **3.56 h** |
| Pauses ≥ 60 s | 42 % of the file phase | **2.5 %** |
| Mean rate | 2.24 files/s | **4.00 files/s** |
| Moving rate | 3.89 files/s, 257 ms/chunk | 4.10 files/s, 229 ms/chunk |
| `chunk_size` | 8 MiB default, adapted to 4 MiB | 256 KB |

Sampling was identical to the previous run: an external observer polling `GET /extractions/{id}` every 30 s, 424 samples, no gaps and no cached responses. `2026-08-19-successful-run.csv` beside this file is the series.

The client reported `skipped_files: []`, `poll_transport_failures: 0`, `poll_wall_seconds: 12512.4`. The import matched discovery on every count — 108 posts, 118 pages, 940 attachments, 4 users — and the smoke test passed 48 of 48.

## What it establishes

**The pauses were the chunk size, and nothing else.** They fell from 42 % of the file phase to 2.5 %. The 303-second cycles that dominated the previous run — each one an execution window spent on a single part — are effectively gone. No code changed between the two runs; one site constant did.

**The moving rate barely moved: 257 → 229 ms per chunk.** That is the cost of an ordinary small file, and it is untouched by this setting because a 44 KB file is smaller than any part size. It remains unattributed. On a selection of tens of thousands of small files it is still what the run costs, and halving *it* would be worth more than everything measured here — 48,578 files at ~230 ms is about three hours, which is essentially the whole run.

**A run of this length is survivable but the margin is thin.** The previous attempt died to a file deleted mid-packaging; `plans/018` covers that and is not yet implemented. Three and a half hours on a live client site is still three and a half hours of exposure to the same class of failure.

## What it does not establish

- **That 256 KB is optimal.** It is measured against 1, 2 and 4 MiB on one file, and nothing below it was tried.
- **Anything about other hosts.** One site, LiteSpeed, PHP 8.4.21.
- **That the run is now reliable.** One success after one failure is not a rate. The failure mode that ended the previous attempt is unchanged in the code.

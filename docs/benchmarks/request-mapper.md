# Request mapper benchmark

The runner measures the complete warmed middleware path. Each mapped scenario
calls `TreeMapper::map()` as well as resolving the handler target, mapping plan,
and selected HTTP input. It is not a resolver-only benchmark.

The large-payload scenarios exercise the request-scoped input snapshot:

| Scenario | Input |
| --- | --- |
| Large flat body, one operation | 10,001 fields / strings, about 252 KiB JSON |
| Large flat body, three operations | same body mapped three times |
| Large nested body, one operation | 20,201 fields, 20,001 strings, about 496 KiB JSON |
| Large nested body, three operations | same body mapped three times |
| Large body plus combined source | flat body plus one route and query value |
| Input source context, body only / combined source | flat body, validation only — no mapping |

Every raw result contains the precise field count, string count, approximate
JSON payload size, per-sample CPU times with min/median/max, and peak memory.

## Reproduction and evidence

The comparison runs the same harness bytes against clean worktrees of both
revisions in an interleaved A-B-B-A order, so environment drift between runs is
measured by control scenarios instead of being attributed to the change.

- Orchestrator: [`benchmarks/compare.php`](https://github.com/sirix777/mezzio-valinor-request-mapper/blob/main/benchmarks/compare.php)
- Baseline commit: `048b55856c732500d1c0a22da60651488859f42c`
- Optimized commit: `6d1419fb599ba4a44a9e5ef59c63e6d069de6f59`
- Evidence: [`raw/compare-048b558-6d1419f-20260912-154933.json`](https://github.com/sirix777/mezzio-valinor-request-mapper/blob/main/docs/benchmarks/raw/compare-048b558-6d1419f-20260912-154933.json)

The orchestrator creates detached worktrees, copies one harness file and one
`composer.lock` into both (so both revisions measure identical bytes and
dependencies), verifies HEAD, harness, and lock hashes in every worktree, and
runs eight batches: baseline, optimized ×2, baseline, baseline, optimized ×2,
baseline. Each batch runs all scenarios in a separate process with 500 measured
iterations, 100 warmup iterations, and 7 samples per scenario. Provenance
(revision, harness SHA-256, orchestrator SHA-256, lock SHA-256, locked package
versions for both `packages` and `packages-dev`) is computed by the harness in
every scenario worker, verified by the orchestrator against each worktree, and
stored once per batch plus once at the document level. `--batches` means
batches per revision: two times its value process batches are executed. The
document is written atomically with its full provenance only after a
successful run; progress goes to STDERR.

Earlier raw files published before this procedure existed were removed: their
provenance was not computed by a runner, and their baseline multi-operation
values could not be reproduced from a clean checkout of the baseline revision.
They are superseded by the interleaved evidence above.

```sh
php benchmarks/compare.php \
  --baseline=048b55856c732500d1c0a22da60651488859f42c \
  --optimized=6d1419fb599ba4a44a9e5ef59c63e6d069de6f59 \
  --batches=4 --iterations=500 --warmup=100 --samples=7 \
  --output=docs/benchmarks/raw
```

## Observed effect (eight batches, standard median of batch medians)

The table is generated from the raw file by `benchmarks/aggregate.php`, which
uses the standard median: for an even batch count the average of the two
central batch medians, not an upper median.

| Scenario | Baseline µs/op | Optimized µs/op | Delta |
| --- | ---: | ---: | ---: |
| No RouteResult passthrough (control) | 0.473 | 0.479 | +1.3% |
| Reflection via direct handler object (control) | 8.004 | 7.861 | -1.8% |
| Reflection via lazy FQCN handler (control) | 7.749 | 8.094 | +4.5% |
| Route options single DTO (request-path check) | 7.914 | 7.820 | -1.2% |
| Three operations with different outputs (request-path check) | 23.297 | 24.504 | +5.2% |
| Repeated calls reusing middleware and builder (request-path check) | 7.965 | 7.905 | -0.8% |
| New middleware/resolvers/builder per iteration with file cache (request-path check) | 67.525 | 67.320 | -0.3% |
| Large flat body, one operation | 1,357.148 | 1,326.757 | -2.2% |
| Large flat body, three operations | 3,952.075 | 1,619.668 | -59.0% |
| Large nested body, one operation | 2,335.066 | 2,367.763 | +1.4% |
| Large nested body, three operations | 7,328.929 | 2,350.745 | -67.9% |
| Large body plus combined source | 2,643.554 | 1,413.221 | -46.5% |
| Input source context, body only | 1,211.162 | 1,121.791 | -7.4% |
| Input source context, combined source | 1,177.979 | 1,154.491 | -2.0% |

Attribution is limited to what the controls support:

- Pure no-mapping controls (no RouteResult passthrough, direct handler,
  lazy handler) — scenarios the input-validation change cannot affect —
  moved between -1.8% and +4.5%. Differences of this size in this table are
  environment drift, not an effect of the change.
- Request-path checks (route options, three-operations metadata, repeated
  calls, per-iteration rebuilds) share some request-level work with the
  validation change, so they are not used as drift evidence; their movement
  (-1.2% to +5.2%) is reported without causal attribution.
- Multi-operation scenarios improved far beyond the no-mapping drift band
  (-46.5% to -67.9%). At the baseline revision every mapped operation re-runs
  the UTF-8 validation traversal of the body; the optimized revision
  validates once per request. The arithmetic matches: baseline flat
  three-operation cost is consistent with one mapping plus two additional
  full body traversals.
- Single-operation scenarios are within the drift band (-2.2% flat,
  +1.4% nested): the data does not demonstrate a CPU improvement for them.
  The narrow validator-only scenario moved by -7.4% for body-only and -2.0%
  for the combined source.
- Peak memory is identical between revisions in every scenario (4 MiB for
  small-payload scenarios, 8 MiB for the file-cache and large-payload
  scenarios), so the change introduced no memory regression.

These figures describe observed medians from the recorded interleaved run.
They are not a performance threshold, and the JSON file is the source of truth
for the individual samples behind every number above.

The runner creates a separate process for every scenario, so its peak-memory
value is scenario-local. Scenario 7 accepts `--cache-dir` as an existing
writable parent; it creates and removes only a randomly named child directory.
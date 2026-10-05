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
JSON payload size, per-sample elapsed times with min/median/max, and peak allocated PHP memory.
`hrtime()` measures elapsed time, not CPU time. PHP used heap, allocated heap,
and process RSS are different metrics; resource scenarios report them separately.

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
| Reflection via direct handler object (request-path check) | 8.003 | 7.861 | -1.8% |
| Reflection via lazy FQCN handler (request-path check) | 7.749 | 8.093 | +4.5% |
| Route options single DTO (request-path check) | 7.914 | 7.820 | -1.2% |
| Three operations with different outputs (request-path check) | 23.297 | 24.504 | +5.2% |
| Repeated calls reusing middleware and builder (request-path check) | 7.965 | 7.905 | -0.8% |
| New middleware/resolvers/builder per iteration with file cache (request-path check) | 67.525 | 67.320 | -0.3% |
| Large flat body, one operation | 1,357.148 | 1,326.757 | -2.2% |
| Large flat body, three operations | 3,952.074 | 1,619.668 | -59.0% |
| Large nested body, one operation | 2,335.065 | 2,367.763 | +1.4% |
| Large nested body, three operations | 7,328.929 | 2,350.745 | -67.9% |
| Large body plus combined source | 2,643.554 | 1,413.221 | -46.5% |
| Input source context, body only | 1,211.162 | 1,121.791 | -7.4% |
| Input source context, combined source | 1,177.979 | 1,154.490 | -2.0% |

Attribution is limited to what the controls support:

- The archived no-RouteResult control moved +1.3%. Direct/lazy annotated
  handlers execute mapping and cannot serve as independent noise controls.
  The corrected harness adds a matched route without mappings as scenario15;
  that control is absent from archived evidence. Small movements in the
  archived mapped paths do not establish an independent drift band.
- Request-path checks (route options, three-operations metadata, repeated
  calls, per-iteration rebuilds) share some request-level work with the
  validation change, so they are not used as drift evidence; their movement
  (-1.2% to +5.2%) is reported without causal attribution.
- Multi-operation scenarios showed decreases of -46.5% to -67.9%.
  These are archived observed changes, rather than a
  new stable-controls acceptance result. At the baseline revision every mapped operation re-runs
  the UTF-8 validation traversal of the body; the optimized revision
  validates once per request. The arithmetic matches: baseline flat
  three-operation cost is consistent with one mapping plus two additional
  full body traversals.
- Single-operation scenarios moved -2.2% flat and +1.4% nested: the data
  does not establish a causal improvement for them.
  The narrow validator-only scenario moved by -7.4% for body-only and -2.0%
  for the combined source.
- Peak memory is identical between revisions in every scenario (4 MiB for
  small-payload scenarios, 8 MiB for the file-cache and large-payload
  scenarios). Equal allocated peaks do not establish absence of used-heap
  regression, retained-object growth, or RSS regression.

These figures describe observed medians from the recorded interleaved run.
They are not a performance threshold, and the JSON file is the source of truth
for the individual samples behind every number above.

The runner creates a separate process for every scenario, so its peak-memory
value is scenario-local. Scenario 7 accepts `--cache-dir` as an existing
writable parent; it creates and removes only a randomly named child directory.

## Hardening comparison (2026-10-05 reboot recovery)

Plan04 source/tests/evidence had been uncommitted in a temporary worktree that
was lost after reboot. The corrected implementation is reconstructed in a
persistent worktree. Previous +5.470%/+5.360% matched-control results survive
only as historical plan notes; their raw files were lost and are not evidence
for this run. The original archived schema/1 JSON above remains unchanged.

Schema/1 retains its historical upper sample median; schema/2 uses the standard
median, including averaging the two central samples for even counts. Both
schemas validate min/median/max against raw samples with tolerance 0.001 µs
plus floating-point epsilon, before printing any table. A failed HTTP or DTO
correctness probe aborts the benchmark before measurement.

Schema/2 always requires complete document, batch and per-scenario manifests;
removing all count/ID markers cannot downgrade its validation. Only archived
schema/1 uses legacy compatibility semantics. The initial experimental schema/2
reports ending `085349` and `090730` remain unchanged but are incomplete and
rejected by the finalized aggregator; their values below are historical run
observations, not accepted evidence. The complete long-controls report ending
`094627` remains structurally valid, although its performance gate failed.

Comparison environment fields come from verified worker provenance, not from
the orchestrating PHP process; runtime facts must match across workers and
revisions. Parent-only `php -d` flags are not inherited by child PHP processes.

Baseline `e581e210953faaaae5c21627b651727a505c4df8`, candidate production source
`f7dd99fe539b73327ad867c299d95e27939f7820`. Corrected harness changes remain
uncommitted until review and message approval; recorded SHA256 identifies the
measured bytes independently from these production commits. Both use the same
recorded dependency lock, optional limits disabled, interleaved A-B-B-A batches.

The controls gate requires both scenario 1 (no route) and scenario 15 (matched
route without mappings) to move within 5%. Small mapped regressions above 10%
require profiling when those controls are stable. Evidence and gate evaluation
are recorded below. Worker-soak and application concurrency gates belong to
plans 05/06; no production RPS is inferred here.

The measured environment is Intel Core Ultra 5 135U, 14 logical CPUs, WSL2 Linux
6.18.40.1-microsoft-standard-WSL2, PHP 8.5.10, OPCache CLI off, JIT disabled,
PCOV 1.0.12 enabled with its default worktree/src instrumentation. All measurement
workers inherit CPU 6 affinity. Pinning avoids CPU migration; it does not
establish isolation from Windows host scheduling or thermal/power variation.
Dependency versions and hashes are in each comparison manifest; extensions
and heap units are in the resource report.

The first full retry after reboot retained the prescribed 500 iterations,
100 warmup / 7 samples, 4 batches per revision and 15 scenarios. Its raw report is
[compare-e581e21-f7dd99f-20261005-090730.json](raw/compare-e581e21-f7dd99f-20261005-090730.json).
No-route control moved +6.975184% and matched-route control +0.425015%, so the
unchanged 5% gate FAILED. Its mapped slowdowns above 10% cannot be attributed
to source changes while the independent controls fail.

[Exact diagnostic driver](raw/diagnose-pcov-20261005.php) and
[raw diagnostic report](raw/pcov-diagnostic-20261005.json) retain 8 ABBA batches
per runtime mode, controls 100,000 iterations and mapped scenarios 2–6
10,000 iterations, 1,000 warmup / 7 samples. Direct child invocation passes
`-d pcov.enabled=1` or 0 explicitly; each mode records the observed runtime
values and source/harness/lock provenance. The diagnostic does not run all 15
scenarios and cannot close the full comparison gate.

| PCOV | No-route control | Matched-route control | Small mapped 2–6 range |
| --- | ---: | ---: | ---: |
| enabled | +1.802108% | +0.559380% | -1.99% to +2.81% |
| disabled | -3.649068% | +5.577889% | -1.77% to +8.64% |

Disabling coverage substantially reduces absolute elapsed time but does not
stabilize every control. PCOV overhead is observed; PCOV is not demonstrated
as the cause of the failed controls. Initial mapped increases above 10% were
not reproduced with longer windows.

The justified full retry uses optional `--control-iterations=100000` while
mapped/wide scenarios remain 500 iterations. Default CLI behavior keeps all
scenario counts equal to `--iterations`; document, batch and individual
worker manifests record and validate actual effective counts. Both revisions
use the same count for each scenario. All 15 scenarios and the 5% gate remain.
The different scenario durations can respond differently to drift; exact
counts make that limitation reviewable.

The full long-controls report is retained unchanged as
[compare-e581e21-f7dd99f-20261005-094627.json](raw/compare-e581e21-f7dd99f-20261005-094627.json).
No-route control moved -3.483724% (PASS), but matched-route control moved
-39.458525% (FAIL). Increasing the window did not establish stable controls.
Within one baseline worker its seven matched-route samples were
`[12.005, 7.531, 7.765, 5.409, 4.219, 3.3, 3.024]` µs/op, demonstrating drift
during execution of unchanged source. The runtime/environment cause remains
unknown; source effects and host noise are not established by this report.

After both independent reviewers accepted corrected code, the
[same-source A-A driver](raw/diagnose-aa-20261005.php) measured two distinct,
equal-length detached checkout paths, both at candidate `f7dd99f`, with identical
final harness `38beea5c` and lock `ab4eb981`. Its
[raw report](raw/aa-diagnostic-20261005.json) verifies matching worker provenance
and runtime facts. It uses CPU 6, PCOV enabled, controls 1/15 only, 100,000
iterations / 1,000 warmup / 7 samples, four batches per path in eight ABBA batches.

| Same-source control | Left median µs/op | Right median µs/op | Difference | 5% gate |
| --- | ---: | ---: | ---: | --- |
| No route | 1.7175 | 1.8595 | +8.267831% | FAIL |
| Matched route without mappings | 2.6350 | 2.7910 | +5.920304% | FAIL |

These standard medians aggregate four per-worker medians for each checkout.
The A-A diagnostic shows this measurement setup cannot establish the unchanged
5% control gate even with identical production source. It does not identify
Windows scheduling, power/thermal variation or any other specific cause, and
it cannot replace an all-15-scenario comparison. No additional blind full retry
was run. Final resource evidence is now generated with stable bytes, as described
in [resource scenarios](resource-mapper.md). At the end of measurement/review,
plan acceptance was still OPEN because PERF-05 had not passed. That historical
status is superseded by the explicit user decision below, not by new measurements.

## Conditional acceptance (2026-10-05)

The user conditionally accepted plan04 to avoid blocking further development.
PERF-05 is `DEFERRED_VM`, **not PASS**. The unchanged 5% independent-control gate
and profiling requirement for small mapped regressions above 10% still apply.
The [complete isolated replay](perf05-isolated-run.md) is mandatory when a separate
measurement VM becomes available; keep and independently review its full raw report.
Existing failed reports remain unchanged. Conditional acceptance is not evidence
of production RPS/p99 or capacity. Commit-message approval remains pending; no
commit has been made, and plan05 must not begin before the approved plan04 commit.

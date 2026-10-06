[Full protocol](application-load-protocol.md) · [Artifact index](raw/application-load-pilot/artifact-index.md)

# Mezzio Docker pilot — invalid partial run

2026-10-06: **0 valid measured cells, 1 invalid attempted cell, 80 skipped**.
All six HTTP contract/readiness cells passed, but the first measured window
stopped on three invalid sampler rows. There is no valid baseline/candidate
comparison, runtime comparison, capacity result, SLO, significance or production
p99 claim. PERF-04 remains incomplete; **PERF-05 DEFERRED_VM / NOT PASS** and
the separate VM follow-up remain unchanged.

## Frozen scope and provenance

The owner-approved pilot, not the full protocol matrix, used one PHP worker,
8 preallocated/max k6 VUs, rates 10/20/40 RPS, one repetition, 10s warmup + 30s
measurement, 2s request timeout and 2s final drain. Runtime order was FPM →
RoadRunner → Swoole. W1/W2/W3/mixed were paired; mixed was deterministic
40%W1/40%W2/20%W3. W4 was candidate-only because baseline lacks input limits.
No unbounded list10000 or additional application request was permitted.

Mezzio skeleton upstream `a3668bb107170e26d3346994cafcef1622507a28`, common
lock/dependencies and normal non-optimized autoload were frozen. Library source
revisions were baseline `e581e210953faaaae5c21627b651727a505c4df8` and candidate
`6793346724d0db91e4b481af131a0714dc821ddf`. Composer's `dev-pilot` path reference
is a metadata hash, not either historical revision; production requirements
match, with the candidate-only development dependency excluded. The selected
library source root was verified in each serving runtime, not assumed from CLI
environment overrides. Paired payloads and response contracts were unchanged;
W4 reused W1 bytes, and mapper-call/DTO freshness checks covered alternating
rejections and successes. Baseline/candidate error shapes were validated separately.

Actual serving platform: PHP8.3.35, Mezzio3.28.1, Valinor2.6.0,
RoadRunner2025.1.15, Swoole6.1.0, nginx1.28.0, native k6 2.3.0, linux/amd64.
OPCache was enabled with timestamps disabled, JIT disabled, with a fresh worker
and equal warmup/cache policy per window. App limits4CPU/2GiB, gateway1CPU/256MiB,
generator2CPU/1GiB. The pinned local Unix daemon reported14 CPUs and
27,316,473,856 bytes; this current observation supersedes the historical16GiB
value retained in dependency-resolution metadata. This was a shared host, not
an isolated capacity environment. No host ports/network, privileged container,
Docker-socket mount, credentials, redirects or k6 usage reporting were allowed.

The immutable [executed manifest](raw/application-load-pilot/manifest.json)
binds exact versions, image/config IDs, locks, source/config/payload hashes,
machine/daemon identity, caps, contracts, schedule and232 pre-execution proof
hashes. Manifest SHA256:
`6acbb0509a31a1e1e0a3bdc808f5449b62ad792379c7b57f36c9dbfc40fc6a9f`.
[Executed app replay](raw/application-load-pilot/app-replay.tar):3496 regular
files/21,268,480bytes, SHA256
`21bd7204cbcf7da98fe530b4407c073b786d03cda123de69d7c90c95e94ff232`.
Clean reconstruction verified every path/hash before HTTP. Pre-execution native
proof was one zero-network/zero-HTTP Trend/API/owned-PID interrupt/salvage run,
with34 actual metric points and byte-identical retained gzip. It is labeled
pre-execution evidence, not application load or measurement readiness.

## Execution and complete cell disposition

Exactly one `--execute` launch, session28208, began its durable93-request
reservation/clock at08:37:35Z (monotonic13358.06667116). The4500s hard deadline
was monotonic17858.06667116. All transitions, gaps, stop/salvage and cleanup
belonged to that same clock. No other benchmark/QA ran concurrently, no
readiness or measured cell was retried, and no budget was reset.

| Runtime | Baseline readiness | Candidate readiness | Measured schedule |
| --- | --- | --- | --- |
| FPM | exit0,15 native requests | exit0,16 native requests | W1/10/baseline invalid; other26 skipped |
| RoadRunner | exit0,15 native requests | exit0,16 native requests | All27 skipped |
| Swoole | exit0,15 native requests | exit0,16 native requests | All27 skipped |

Every readiness cell retained its exact success marker,0 health requests,
native request count, before/after container and worker/runtime/revision/source
proof. They total93 application HTTP requests. Swoole's actual worker PID1 is
accepted only with its exact worker command and matching container/start identity.
[Readiness results and independent recalculation](raw/application-load-pilot/independent-accounting.json)
retain all six projects/timestamps/counts. [Schedule](raw/application-load-pilot/schedule.json)
and [all81 cell dispositions](raw/application-load-pilot/cell-status.csv) specify
every runtime/revision/profile/rate; all nine candidate-only W4 cells were skipped,
not measured. No baseline W4 result exists.

Build357.271023/3600s, active=null, with no Task5 pull/build/install. Actual and
durably reserved HTTP493=93 readiness+400 first-window arrivals, below80000 total
and300 preflight ceilings. Planned but unexecuted arrivals are not counted as
sent requests. The [original execution result](raw/application-load-pilot/execution.json)
records partial status, driver exit1 and122.621999446s elapsed to driver return.
Final residual cleanup finished08:52:41Z; total905.521650579s from the original
reservation, including intervening gaps, remained below4500. The original22s
cleanup reserve/66s prepared fit were not enlarged.

## Stop reason and quarantined diagnostics

The first cell, FPM/W1/10RPS/baseline, retained native k6 exit0,400 contiguous
invocation IDs,400 results and400 native requests; dropped0, unfinished0,
unavailable transport timing0, unexpected outcomes0. All 400 results were successful
W1 responses with 25-byte bodies; there was no observed timeout or expected 422
population in this cell. A request timeout would remain in its outcome/cohort,
and “unfinished” means only a missing result, not unavailable latency.

The [sampler summary](raw/application-load-pilot/runs/fpm-w1-10-baseline/samples.jsonl.summary.json)
contains44/44 rows, but indices33/34/36 exceeded the unchanged1s collection
overhead gate:1.011535868/1.129272483/1.043694053s. Their lateness was
0.000184731/0.011886966/0.022508645s. Worker identity remained PID7/start1343060,
one worker, no observed restart/OOM. Sampler stderr was empty; its invalid-row
exit1 caused the conservative whole-study stop. This is an instrumentation
validity failure, not evidence of an application defect or server ceiling.
After-container/worker endpoint proofs are absent because the driver stopped
before collecting them. The cell cannot be promoted to valid despite complete
request/result populations.

These numbers are audit diagnostics from INVALID evidence, not performance results:

| Diagnostic population | Recomputed value |
| --- | --- |
| Native sending starts in [origin+10s,origin+40s) | 300; starts/30s=10RPS, not completed throughput |
| Success actual-send→complete latency, n=300 | p50=7.684959ms; p95=12.378663ms; p99=17.918135ms |
| Strict-positive invocation lag subset | 87 positive /300 full signed values; p95=9ms; max=29ms |
| Observed request-worker RSS peak | 22,704KiB, not PHP heap or summed-process RSS |
| Observed app container memory peak | 30,670,848bytes, separate from worker RSS |
| Generator observed CPU peak | 0.105831319 of2CPU; no three-valid-measured-interval≥0.90 breach |
| Completed results within wall-time [origin+10s,origin+40s) | 300/30s=10RPS; a separate diagnostic completion rate |

All 400 native HTTP statuses, outcomes and body sizes were independently
aggregated, not inferred from the first response. [Diagnostic aggregates](raw/application-load-pilot/audit/diagnostic-aggregates.json)
also retain the separate completion-wall population and resource totals below.
These resource values cover all 44 retained rows, including invalid rows and
warmup/drain; they are not valid measured-window aggregates.

| Container | Observed CPU peak / cap | Memory peak, bytes | CPU usage delta, µs | Network RX / TX delta, bytes |
| --- | --- | --- | --- | --- |
| App | 0.097710455 /4CPU | 30,670,848 | 8,493,525 | 300,136 /173,428 |
| Generator | 0.105831319 /2CPU | 28,815,360 | 5,172,963 | 138,123 /135,516 |
| Gateway | 0.207039759 /1CPU | 6,455,296 | 3,550,915 | 309,162 /438,130 |

Collector cost was 0.140486s sampler CPU, 17.780735s Docker-CLI CPU and
30.205838854s collection wall time over 44.000370438s elapsed. The generator
CPU/lag diagnostics did not trip their own gates, but this cannot establish
headroom or validity after the sampler and after-endpoint gates failed.

Percentiles use nearest rank and milliseconds, classified by native sending
start, with warmup excluded and late completion retained through drain. No
coordinated-omission correction is applied. Signed invocation lag is
invocation_ms−origin_ms−iteration×1000/rate; p95 uses ONLY lag>0, with positive
and full counts retained (empty positive subset would be0/n0). Frozen pilot
p95 limits100/50/25ms and max250/200/100ms use strict greater-than. CPU is
delta usage_usec/(actual per-source uptime delta×1e6×CPU cap), not nominal
one-second sampling. Full raw signed lags, source CPU deltas, sample misses,
outcomes and native cohort membership remain in the independent accounting.
Collector CPU/wall overhead is reported and included, never subtracted.

## Cleanup boundary and post-data correction

Native stop succeeded and bounded tmpfs streaming preserved the first-window
[raw gzip](raw/application-load-pilot/runs/fpm-w1-10-baseline/k6-raw.json.gz)
(74,795bytes, SHA256`bb374944236f15b8a5d18336f7d70ba024f3b439f02599d9469272f81ba96d33`)
and [summary](raw/application-load-pilot/runs/fpm-w1-10-baseline/k6-summary.json)
(3433bytes, SHA256`e862d0870e4cfa267aed410e4088806579390cbd28fc068064c8ae3e825c7799`).
Raw64MiB/summary1MiB/probe16KiB caps,4s per stream, separate stderr and atomic
canonical promotion are instrumentation invalidity guards, not resource increases.

A separate driver flaw was then observed: Compose `down` returned0 while leaving
each `run -d ... sleep600` generator one-off and busy private network. All seven
original cleanup logs are preserved. After read-only full-ID/label/member proof,
root authorized only those seven exact generator containers and seven verified
empty networks for manual removal. Final inventories for all seven projects
were empty; [cleanup proof](raw/application-load-pilot/audit/task-5-owned-cleanup.json)
records exact49 commands, ownership, removals and original-clock elapsed time.

The minimal cleanup correction is explicitly POST-DATA. It discovers an unknown
generator only by exact current-project/service/oneoff labels, verifies its full
ID/image/labels, and removes it after stop/salvage before own down. Missing inspect
or disappearing-generator failures retain explicit evidence while still attempting
own down if time remains; foreign identities are rejected before mutation.
Any network-only residue requires exact full ID, project/network labels and zero
members before targeted removal. Final container/network inventories must be empty.

All commands and TERM/KILL/reaping share the original 5s cleanup phase inside
the 22s reserve. The corrected helper reserves 100ms teardown/capture allowance
inside that deadline; it does not increase the budget. The first follow-up
failed scoped deadline/disappearance coverage review and is retained separately.
[Revision2 RED/GREEN and tests](raw/application-load-pilot/audit/task-5-cleanup-v2-delta-report.md)
cover actual inert TERM-ignoring children, near expiry, disappearing generators,
exact empty-network cleanup, foreign/busy/mismatched refusal and abort ordering.
The one post-package serial suite passed all 13 checks without traffic.
[Corrected follow-up manifest](raw/application-load-pilot/followup/cleanup-v2-preparation/manifest.json)
and [replay](raw/application-load-pilot/followup/cleanup-v2-preparation/app-replay.tar)
are UNEXECUTED preparation only, never substituted for original evidence.
The [scoped rereviews](raw/application-load-pilot/audit/task-5-cleanup-v2-review.md)
accepted only this three-file delta: SpecCompliant / TaskQualityApproved and
SecurityApproved, no findings. Native cleanup with the revised source is
**NOT PROVED**: no daemon proof or HTTP/load rerun was authorized. Offline clean
replay verified all 3496 files, while preparation/native/replay-execution flags
remain conservative. These verdicts do not accept the study or its performance.

The preserved [checker result](raw/application-load-pilot/checker-result.json)
was generated before the post-data source correction and exits1:0/81 valid,
partial budget/reservations, first-cell missing after-inventory and80 absent
unstarted cells. Independent recalculation likewise ran before that correction,
with clean original source hashes. Current app source intentionally contains the
separately frozen after-data cleanup fix; no revised source is claimed to have
produced the original measurements. The inherited no-dev skeleton PHPUnit
executable was absent; no install was performed. Final independent review and
security acceptance are pending; no commit/push/merge/release was performed.

## See Also

- [Full application-load protocol](application-load-protocol.md)
- [All retained artifacts and checksums](raw/application-load-pilot/artifact-index.md)
- [Frozen executed provenance](raw/application-load-pilot/manifest.json)

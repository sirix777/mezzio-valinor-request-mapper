[Back to README](../../README.md) · [Worker soak](worker-soak.md) · [Isolated PERF-05 replay](perf05-isolated-run.md)

# Application mapper load protocol

Status (2026-10-05): **protocol preparation only; owner inputs missing; PERF-04 NOT RUN**.
There is no selected application, runtime, URL, load ceiling, generator or
application service-level objective (SLO). No application requests or measurements
have run, and no application manifest or measurement report has been generated.
This document defines how to bind and execute plan06 once those inputs exist.

Independent protocol and security reviews accepted this preparation document
without findings; the user approved its documentation commit. This acceptance
does not authorize application requests or close plan06/PERF-04.

Plans01–05 are committed through `ef126583dfc576c71e2b95ffba206796d64d4d4e`.
Plan04 is conditionally accepted: **PERF-05 DEFERRED_VM / NOT PASS**. Its separate
VM replay remains mandatory when available, with the unchanged 5% independent
control gate and profiling requirement for small mapped regressions above 10%.
Protocol review cannot close either performance gate.

## Approved bounded Docker pilot exception

2026-10-06: the separately approved Mezzio Docker pilot was attempted once;
[its report](application-load-pilot-report.md) records **0 valid measured cells,
1 invalid attempted cell and 80 skipped**, plus six passed readiness cells.
This does not bind or complete the full owner-input table/matrix below, close
PERF-04, or change PERF-05 DEFERRED_VM / NOT PASS and its required VM follow-up.

Only the pilot used FPM → RoadRunner → Swoole sequentially, 1 PHP worker, 8 in-flight
requests, 10/20/40RPS, 10s warmup + 30s measured and 1 repetition. Mixed was 40%W1 /
40%W2 /20%W3; W4 was candidate-only. App 4CPU/2GiB, gateway 1CPU/256MiB and
generator 2CPU/1GiB were enforced. Total execution 4500s, build 3600s, 80000 scheduled
requests and 300 preflight requests were hard ceilings, with no retry/reset.
Actual 493 HTTP = 93 readiness + 400 first-window requests; the sampler validity gate
stopped all escalation. The [frozen pilot manifest](raw/application-load-pilot/manifest.json)
and immutable evidence, not the unbound full-protocol defaults, define this scope.
No SLO, significance, sustainable-RPS or production-p99 claim follows.

## Owner inputs and execution gate

The application owner must fill every required field below before preflight.
Blank cells mean missing information, not defaults or permission to infer values.
Select an existing HTTP generator capable of the required scheduling, response
validation and metrics; record its actual capabilities before using it.

| Required owner input | Owner value (unbound) |
| --- | --- |
| Existing test application checkout, revision and fixture/config paths | |
| Explicitly permitted test target/base URL and allowed endpoint paths | |
| Runtime/version, process model and supported worker counts | |
| Approved starting offered rate, maximum rate and maximum in-flight requests | |
| Per-run and total study duration/request budgets, including preflight/warmup | |
| Server CPU, per-worker/total memory and other resource ceilings; stop operator | |
| Existing generator/version, machine, connection settings and timeouts | |
| Generator headroom criteria: CPU, queue delay, drops and network thresholds | |
| Four concrete workload contracts below, payload files and validation method | |
| Explicit within-class workload weights for the mixed profile | |
| Baseline/candidate revisions, dependency lock and input/error limit settings | |
| SLO: required offered load, latency percentile limits and error budget | |

An absent SLO permits only observations after the remaining gates are satisfied;
it prevents capacity or “withstands this load” claims. Missing target, limits,
contracts or instrumentation prevents execution entirely. Owner approval must
cover the total planned matrix, not just one 120-second window. Bind fixtures in
the selected application's checkout; this library protocol creates no app,
deployment, server or generator framework. Deployment/configuration changes
require their own authorized workflow.

Verify the permitted target and disable redirects to unapproved destinations.
Use synthetic, non-sensitive payloads and an owner-approved test identity. Keep
credentials in the application's existing secret mechanism; omit credentials,
tokens, auth/cookie headers and sensitive bodies from retained artifacts.

## Four workload contracts

These are the four required workloads, not invented endpoints. The owner must
fill a binding record for **each** row before sending any request.

| ID | Required workload | Required observable result |
| --- | --- | --- |
| W1 | Successful small DTO | Successful application status and exact intended small DTO/server result |
| W2 | Successful nested list100 | Successful hydration of 100 nested list items, including checked nested values |
| W3 | Malformed list100 | HTTP 422 for 100 malformed list items; mapping-error contract, not a transport failure |
| W4 | Input-limit rejection before mapper | HTTP 422 with the expected input-limit reason/source; evidence that the current operation never invokes the mapper |

Each binding record requires:

| Contract field | Required value |
| --- | --- |
| Target | Approved endpoint path, HTTP method, media type and safe header names |
| Input identity | Synthetic payload file/path, exact byte length, SHA-256 of bytes sent, list count, DTO types and selected input source |
| Input setup | Route/query values and relevant parser limits; payload must deliberately pass or exceed the named mapper input limit |
| Server result | Expected status, DTO values or error reason/source; no unintended persistence or other side effects |
| Response validation | Content type, schema, checked values, JSON type checks and configured response body/message limits |
| Rejection proof (W4) | Existing test-app instrumentation or equivalent evidence of zero mapper calls for the rejected operation; HTTP 422 alone is insufficient |
| Revision support | The same endpoint, bytes, expectations and limit semantics validated on both baseline and candidate |

Record compatible configuration and expected contracts for each revision. A
historical baseline may lack `InputLimits`/`ErrorResponseOptions` and serialize
numeric error paths differently; check those differences before binding it.

For the default error responder validate `application/json`, the `Mapping failed`
error value and a JSON **object** for `messages`, with arrays of strings as values.
Validate any configured message cap (including its omission marker) and body byte
cap against the encoded JSON body before compression; the body must remain valid
UTF-8 JSON. A custom responder needs its own explicit owner-supplied schema.
W4 may fail after earlier mapping operations have completed; its proof concerns
the rejected operation, not all preceding operations in the request.

If the baseline lacks input-limit support or another required contract, stop the
paired comparison for that workload. Do not silently change payloads, options,
expectations or revisions to manufacture equivalence. Record the incompatibility
and obtain an explicit comparison design; a candidate-only observation cannot
be labeled a baseline/candidate regression result.
An owner-declared equivalent application mechanism requires recorded configuration
and proof of comparable semantics; this protocol supplies no compatibility shim.

An optional **unbounded list10000** workload is outside the required four-workload
matrix. It requires an explicit additional request/resource budget, disabled
limits recorded as such and a separate resource-capped test deployment. A response
cap does not limit Valinor's mapping/error-tree cost, and parsed-body/input limits
do not bound body-parser work or arbitrary constructors. Do not run this optional
workload on an assumed-safe shared target.

## Preflight

Before any load, verify the app revision, library revisions, lock/dependency
versions, runtime configuration and worker count against the completed manifest.
Confirm server and generator instrumentation works, clocks/timestamps can be
correlated, resource ceilings are enforced and an operator can stop the run.
Review the plan01–05 evidence and record plan04's deferred gate explicitly; do
not assert that all prior quantitative gates passed.

Use an owner-approved small request count/rate, included in the total budget, to
validate W1–W4 on each revision. Check every status/schema/result, body cap and W4
mapper-call proof. Disable unplanned retries and record timeout settings. An
incorrect endpoint, payload/hash, DTO result, error shape or unexpected error
halts measurements until corrected and preflight repeated. Freeze the validated
contracts and manifest before the measured phase.

## Matrix and offered-rate sweep

| Dimension | Required schedule, subject to the owner ceiling |
| --- | --- |
| Workers | 1, 4, 8 |
| Concurrency | Maximum in-flight requests 1, 8, 32 |
| Profiles | Four homogeneous profiles W1/W2/W3/W4, plus one mixed profile |
| Mixed profile | 80% successful (W1/W2), 20% expected422 (W3/W4); owner records fixed within-class weights and seed/schedule |
| Per run | 30 seconds warmup, then 120 seconds measured |
| Repetitions | 3 per revision for every permitted workers/concurrency/profile/rate combination |
| Offered rates | Start at the small owner-approved rate; double (×2) to saturation or the approved safe ceiling |

Exclude any worker/concurrency/rate combination exceeding the approved ceiling;
record why it was not run. Record the approved final ceiling explicitly if it
falls between doubling steps. Budget the resulting run count and time, including
both revisions, warmups, repetitions and safe recovery intervals, before starting.
An incomplete or ceiling-restricted matrix limits the report's scope; do not
report excluded combinations as measured.

Use a controlled offered-rate scheduler, not only a maximum-throughput closed
loop, to compare tails. Document how this generator combines offered rate with
the in-flight cap, including queues, late starts and dropped scheduled requests.
Keep intended request arrival times; a scheduler that slows down under load must
expose the missed demand. Offered RPS means scheduled demand; achieved RPS means
completed requests over the measured window. Record started/completed/unfinished
counts, cutoff/drain policy and treatment of timeouts so achieved RPS is auditable.

Use equal offered rates, worker counts, concurrency caps, payload bytes, workload
schedule/weights, timeouts, connection/TLS settings and measurement windows for
baseline and candidate. Interleave paired runs: repetition1 baseline→candidate,
repetition2 candidate→baseline, repetition3 baseline→candidate; save actual order
and timestamps. Use the same dependency lock and app revision; differences must
be confined to the package change and explicitly necessary recorded options.
Equivalent effective input-limit semantics are required for a paired W4 comparison;
record any necessary per-revision configuration differences and their proof.

Apply the same restart/cache policy to both revisions. Use release-specific
metadata caches and warm them with the same builder/configuration as the measured
app; record OPCache/JIT, process age and cache warmup state. Exclude the 30-second
warmup from measurement. Define the owner's recovery/idle condition between runs
so resource carryover does not favor either revision. Compare only overlapping
valid rate points; stop escalating both sides if one reaches a safety ceiling.
Saturation is an observed inability to deliver scheduled demand or a predeclared
SLO/resource threshold, not authorization to exceed the safe ceiling.

## Metrics and stop rules

Retain raw generator output for each run, plus server CPU and **per-worker RSS
sampled every second** through warmup and measurement. Identify each worker by
PID and start time, retaining restarts as separate identities. Record sampling
method, CPU normalization, timestamps, missed samples and units. RSS is resident
process memory; it is not PHP used/allocated heap, and summing RSS can double-count
shared pages. Record server/container total-memory metrics separately where used
for a ceiling.

For each measured run record offered/started/achieved RPS, request and response
sizes, counts per workload, p50/p95/p99, expected422, unexpected HTTP errors,
timeouts/network failures and unfinished requests. Keep successful and expected422
latency distributions separately, plus the mixed distribution with its actual
composition. State histogram precision, sample count, latency unit, timeout
censoring and which events enter each percentile. Validate responses during the
run without retaining every body; capture safe failure diagnostics. Include the
validator's overhead in generator headroom evaluation.

Record the latency source and interval: intended arrival→complete, actual
send→complete, or another documented generator definition. State whether the
generator corrects **coordinated omission** (missing the latency of requests it
could not issue during stalls), its method and raw/corrected histogram provenance.
Without correction or preserved intended-arrival latency, explicitly restrict
tail-latency conclusions to issued requests and do not claim an SLO for all
offered demand. Do not hide timeouts by dropping them from the reported population.

Expected422 responses meeting W3/W4 contracts count as completed expected traffic,
not transport failures and not unexpected errors. Define unexpected-error
percentage as unexpected outcomes divided by started requests, with each request
counted once. Report dropped/not-started scheduled requests separately against
offered demand; an error budget must not conceal them.

Measure generator CPU, scheduling queues/delay, dropped iterations, memory and
network utilization independently of target CPU. Require the owner's predefined
headroom criteria at every accepted rate. If the generator is saturated or samples
are missing, classify the affected result as inconclusive; do not call it a server
ceiling. Preserve evidence and repeat only within the authorized budget after
resolving the bottleneck.

Stop the affected run and rate escalation on unexpected OOM, worker/runtime restart,
any approved CPU/memory/request/duration ceiling breach, an invalid response
contract or off-target request. Preserve partial generator output and metric
samples with failure reason, timestamp, revision and run ID as **failed evidence**.
Unexpected errors halt further escalation pending diagnosis. Keep failed and
inconclusive runs in the report; replacing them does not erase them.

## Required manifest and eventual report

After binding owner inputs and before execution, write the actual manifest to
`docs/benchmarks/raw/application-load/manifest.json`. This is a required-field
description, **not a filled manifest**; no file is generated during preparation.

The actual manifest must contain study/run identifiers and timestamps; app
revision/fixture/config paths; full library baseline/candidate SHAs; app dependency
lock hash and PHP, Mezzio/runtime/router/Valinor versions; CPU model/count, OS/kernel,
memory limits and machine placement; worker counts/process settings; OPCache/JIT,
extensions and cache/warmup policy; effective input/error/body-parser limits;
payload paths, hashes and byte sizes with frozen workload/validation contracts;
generator name/version/configuration hash/machine and latency/coordinated-omission
method; sanitized base URL/approved paths; authorized duration/rate/concurrency
and resource ceilings; offered sweep/matrix/weights/order; metrics sources;
owner SLO or explicit absence; prior deferred gates; exclusions and deviations.
Store credentials externally and omit secrets from command lines/raw exports as
well as the manifest. Review artifacts for sensitive data before retaining them.

After measurements, write `docs/benchmarks/application-load-report.md` with
relative links to the exact manifests, raw generator output and worker metric
samples. Report each repetition, spread and excluded/failed/inconclusive runs;
do not average percentiles as if they were one combined percentile. A capacity
claim needs the owner-defined SLO met at equal offered load, correct responses,
complete scoped evidence and demonstrated generator headroom. Without an SLO,
report measured observations only. Results apply only to the recorded
DTOs/payloads/runtime/limits/machines; never derive production RPS from reciprocal
CLI microseconds or generalize to another application.

Independent protocol review covers preparation only. Plan06/PERF-04 remain open
until the scoped matrix, correctness, headroom, raw evidence and report have been
executed and reviewed. No unit/soak success or protocol commit substitutes for
these measurements; PERF-05's isolated VM follow-up remains a separate obligation.

## See Also

- [Worker soak](worker-soak.md) — process-lifetime evidence and its limits.
- [Isolated PERF-05 replay](perf05-isolated-run.md) — deferred quantitative gate.
- [Request mapper benchmark](request-mapper.md) — CLI measurements, not application capacity.

# Request mapper hardening verification

Recorded 2026-10-05 in the isolated `harden/request-mapper` worktree. Execution
BASE/source revision is `d0f76505da9d5daf5d92c24c036ba95f0e2590be` (approved
plan04). Plan05 adds verification tooling, development-only Nyholm contracts and
documentation; it does not change production source or supported dependency
ranges. Production `src/` is unchanged from the `f7dd99f` candidate used by
plan04 (`git diff --exit-code f7dd99f HEAD -- src` passed).

Status: implementation, local compatibility/QA and PERF03 evidence accepted.
Fresh independent code/docs and security reviews both returned ACCEPT without
findings; the parent accepted plan05. User approval of the proposed single
whole-plan commit is pending. PERF05 is `DEFERRED_VM`, **not PASS**. The user accepted that
development disposition on 2026-10-05, with a mandatory full retest on a separate
VM when available and unchanged 5% controls / 10% small-mapped thresholds.
PERF04 concurrent application measurement is **NOT RUN**. No release, deploy,
production throughput/p99 claim or release number is authorized by this report.

## Worker gate (PERF03)

The following commands ran sequentially in separate PHP processes with no
concurrent QA or source changes, both exit 0:

```bash
php benchmarks/worker-soak.php --mode=static --warmup=10000 --requests=100000 --interval=10000 --output=docs/benchmarks/raw/worker-static.json
php benchmarks/worker-soak.php --mode=ephemeral --warmup=10000 --requests=100000 --interval=10000 --output=docs/benchmarks/raw/worker-ephemeral.json
```

Both reports qualify for the full gate: 10,000 warmup and 100,000 measured
requests, ten checkpoints at 10,000 intervals, 110,000 exact changing-ID checks
including warmup, and zero correctness failures. The final five checkpoint
positions are 60,000 through 100,000 measured requests. Gate threshold is
`max(1 MiB, 5% of the first of those five used-memory values)`.

| Evidence | Static | Ephemeral |
|---|---:|---:|
| Raw report | [worker-static.json](raw/worker-static.json) | [worker-ephemeral.json](raw/worker-ephemeral.json) |
| Used bytes after GC, every last-five checkpoint | 3,712,360 | 3,710,816 |
| Last-five range / allowed range, bytes | 0 / 1,048,576 | 0 / 1,048,576 |
| Four strictly positive differences (sustained growth) | false | false |
| Allocated PHP bytes after GC, every last-five checkpoint | 4,194,304 | 4,194,304 |
| Process peak used / allocated PHP bytes | 3,770,984 / 4,194,304 | 3,770,344 / 4,194,304 |
| Final RSS bytes (separate OS metric) | 46,047,232 | 45,723,648 |
| Sampled weak references checked / still alive | 40 / 0 | 60 / 0 |
| Sampled temporary routes / wrappers checked | 0 / 0 | 10 / 10 |
| Full memory gate / correctness-release result | PASS / PASS | PASS / PASS |

Runtime: PHP8.5.10 CLI on Linux WSL2
`6.18.40.1-microsoft-standard-WSL2`, x86_64. OPCache CLI disabled, JIT disabled,
PCOV enabled, `memory_limit=-1`. Actual extensions and locked versions are in
each raw report. This is the measured benchmark environment, not an application
runtime recommendation. RSS is read from `/proc/self/status`; it is not used by
the PHP used-memory gate. Used heap, allocator reservation and process peaks
are reported separately. Weak references are sparse release probes, not an
exhaustive object census. See [runner semantics](worker-soak.md).

Both reports and post-run files have identical provenance:

| Artifact | SHA256 |
|---|---|
| Worker runner | `fed85252a2213ed05d135fae77919a6f345f383e57a1a218f8967c4d14f6409d` |
| Shared request harness | `38beea5c377083bc8126859bf87aa366194eba146ea7a1ecf16a84362e6433e8` |
| Execution dependency lock | `6f02c4fda5839bf3cc581c0c1c92a5018d0519ce5d189acdbf7235ac3afd7cc0` |
| Static raw report | `410c0a7d9cd4490594e3346389ba8ec1f74db0c942861c96ec339b4cb4651842` |
| Ephemeral raw report | `72791edbafef92ef0c78c72ce7061d464a3fe6a8243b9fd1e1760db903b2bad9` |

The source revision is BASE, before the new runner is committed. Its content is
identified by the worker SHA256 rather than falsely attributed to that commit.
The ignored library lock was resolved coherently; Nyholm1.8.2 was the only
execution-lock addition. The new dev lock differs from plan04 performance locks.

## Contract and compatibility checks

`php vendor/bin/phpunit test/Benchmark/WorkerSoakTest.php --do-not-cache-result`
passed 15 tests / 144 assertions after the initial ten missing-runner failures
(RED). Tests cover gate boundaries, sustained growth, the last-five window,
short/partial runs, changing IDs, static/ephemeral release, import safety,
invalid and duplicate options, output writing and failure.

`php vendor/bin/phpunit test/Integration/AlternatePsrImplementationTest.php --do-not-cache-result`
passed 4 tests / 31 assertions. These characterize successful immutable
Nyholm requests, preserved streams/headers, numeric error messages decoded as
JSON objects, standard input-limit responses and byte-cap fallback, using the
Nyholm PSR17 request/response/stream factory. Initially 3/4 passed; the long-value
bytecap fixture did not exceed the cap because Valinor abbreviates the error
value. Twenty invalid list elements corrected the fixture. No production RED
or new PSR behavior is claimed.

Normal/lowest CI jobs already run the full suite, including alternate contracts
and existing wrapper tests. The no-intl CI subset was expanded to include
InputLimits and AlternatePsr. CI YAML coverage is configuration; remote CI was
not executed in this session. The following equivalent matrix was run locally
in isolated temporary checkouts, with Composer resolving on actual PHP8.2 and
without ignored platform requirements:

| Actual PHP | Router | Valinor | PSR container / message | Result |
|---|---|---|---|---|
| 8.2.33, 8.3.33, 8.4.25, 8.5.10 (four cells) | 3.20.0 (`^3.15`) | 2.6.0 | 2.0.2 / 2.0 | Each PASS456 tests / 1636 assertions |
| 8.2.33, 8.3.33, 8.4.25, 8.5.10 (four cells) | 4.2.0 (`^4.1`) | 2.6.0 | 2.0.2 / 2.0 | Each PASS456 tests / 1636 assertions |
| 8.2.33, lowest | 3.15.0 | 2.4.0 | 1.0.0 / 1.1 | PASS456 / 1636 |
| 8.2.33, lowest | 4.1.0 | 2.4.0 | 1.1.2 / 1.1 | PASS456 / 1636 |
| 8.2.33, intl absent (asserted at runtime), filtered suite | 4.2.0 | 2.6.0 | 2.0.2 / 2.0 | PASS124 / 272 |

Nyholm1.8.2 and PHPUnit11.5.56 were installed in all cells. Lowest means the
existing CI's pinned Valinor/router with `--prefer-lowest`, not that every dev
package was downgraded. Container doubles retain untyped `$id` for 1.x.
PHP8.4 matrix used `XDEBUG_MODE=off`; extensions otherwise came from local PHP
installations. Matrix commands after each resolution:

```bash
XDEBUG_MODE=off php8.2 vendor/bin/phpunit --do-not-cache-result --colors=never
# Normal cells repeat with php8.3, php8.4 and php8.5.
PHP_INI_SCAN_DIR=/tmp/plan05-matrix-LIvDUz/php82-no-intl php8.2 vendor/bin/phpunit --do-not-cache-result --colors=never --filter 'Encoding|Responder|Utf8|InvalidUtf8|DefaultMappingError|InputLimits|AlternatePsr'
```

Resolutions used isolated `COMPOSER_HOME=/tmp/plan05-composer-GKqtNw`:
`php8.2 /usr/local/bin/composer update 'mezzio/mezzio-router:^3.15'`
or `'^4.1'`, with `--with-all-dependencies --no-interaction --prefer-dist --no-plugins --no-scripts`.
Lowest resolutions pinned `'cuyz/valinor:2.4.0'` and router `3.15.0` or `4.1.0`,
adding `--prefer-lowest --prefer-stable`. Initial sandbox DNS failed; official
repository resolution succeeded with approved network access. No private
global repository or platform bypass was used in successful resolutions.

The first matrix source copies omitted Git metadata and three existing
benchmark provenance tests failed (`revisionOfDirectory` returned null).
Providing real isolated Git metadata for the existing BASE commit fixed the
environment; all ten full cells reran and passed. This is recorded as a failed
fixture attempt, not omitted as a false compatibility success.

## Whole-change QA and requirement coverage

Fresh read-only parent QA on the execution checkout:

| Command | Result |
|---|---|
| `php vendor/bin/phpunit --do-not-cache-result --colors=never` | PASS456 tests / 1636 assertions |
| `composer cs-check -- --sequential` | PASS0 changes / 105 scanned files |
| `composer rector -- --debug` | PASS, no proposed changes |
| `composer phpstan -- --debug` | PASS, no errors |
| `composer analyse-deps` | PASS105 files, no issues |
| `composer validate --strict` / `composer check-platform-reqs` | PASS / PASS |
| `git diff --check` | PASS |

Serial/debug variants avoid the already reproduced sandbox localhost-TCP
limitation; no suppression was added. Worktree tool vendors reuse installed
MAIN tools; unrelated tool manifests were preserved. Initial dependency analysis
failed two unknown worker functions. Its new configuration imports the existing
import-safe benchmark entrypoints and scans benchmarks as development code, so
Reflection can identify declarations and dependencies. A scan-only attempt
still failed unknown declarations; import plus scan passed, independently
confirmed by the parent. It does not change product autoloading.

QA tools were run on PHP8.5 using the existing shared tool vendors. A scoped
style attempt on PHP8.2 failed while parsing that vendor's Symfony Console
syntax; this is a tool-installation limitation, not product compatibility
evidence. The new QA configuration passed PHP8.2 syntax checking and explicit
PHP8.5 style/Rector checks. Product suites ran on all stated PHP8.2–8.5 cells;
tool-platform requirements were not bypassed.

| Requirements | Implemented behavior / evidence |
|---|---|
| ERR01–03 | `DefaultMappingErrorResponder` / `ErrorResponseOptions`; `DefaultMappingErrorResponderTest`, factory options validation, alternate Nyholm error contracts: JSON object paths, message cap/omission, byte fallback, no discarded formatting |
| IN01–04 | `InputLimits`, `InputEncodingValidator`, request-scoped source factory/context; limits/unit/memory/integration tests cover exact budgets, early stop/no mapper call, source isolation/reuse, source identity, cycles/shared references and disabled defaults |
| IN05 | Request-scoped shallow source snapshot, no shared request state; source factory/context/cache tests plus changing-ID/release soak; README documents mutation/constructor limits |
| DISC01–02 | `HandlerTargetResolver`, mapping resolvers; inherited instance/static equivalent callables, parent method/child class attributes, anonymous closure isolation, known wrappers/negative cache and route invalidation tests, run in normal and lowest matrix |
| CFG01–02 | Builder factory tests for every additive false flag, configurator order/date formats, strict diagnostics and legacy skips/propagation, container construction and manual defaults; README/config example agree |
| PERF01–02 | Corrected harness/control classification, standard median, aggregation fail-closed validation and resource probes; existing plan04 tests pass in all cells; [resource-candidate.json](raw/resource-candidate.json) records all nine separate workers |
| PERF03 | Both qualifying full worker modes pass, linked above |
| PERF05 | Historical full/A-A evidence retained; [comparison account](request-mapper.md) and [isolated VM replay](perf05-isolated-run.md); `DEFERRED_VM`, not PASS |
| PERF04 | NOT RUN: application/runtime and concurrency workload still need selection |

PERF01/02 resource evidence was accepted in plan04 with its own matching worker
source/harness/lock hashes; it is not presented as freshly remeasured under the
new dev lock. Historical PERF05 manifests refer to `e581e21` versus `f7dd99f`
and identical locks per comparison, and preserve failed 5% controls, including
the same-source A-A diagnostic. They cannot establish performance acceptance for
this final dev lock or justify changing the thresholds.

## Candidate release notes and remaining gates

`CHANGELOG.md` explicitly adopts Semantic Versioning. Conservatively classify
this combined candidate as a **major** release: clients may depend on numeric
error paths being arrays and existing inherited-callable mapping selection.
Those observable corrections must be called out with migration guidance.
Additive budgets and strict diagnostics remain disabled by default and could
ship separately. No version number is chosen.

Candidate notes: numeric and empty `messages` become JSON objects; inherited
first-class and array method callables agree on the called child class; optional
per-source input budgets and default-responder message/body caps; optional strict
configurator diagnostics; documented additive flags; alternate PSR and worker
verification. Existing body parsing, custom constructors/responders, full
Valinor error-tree cost and shallow references remain application concerns.
Loaded attribute changes require worker restart; cache directories should be
release-specific and warmed with the same configured builder as runtime.

Independent review accepted this plan05 scope without findings: the reviewer
also reran 19 targeted tests / 175 assertions and inspected all ten matrix logs,
the no-intl log and raw hashes; security found no material issues. Previously
accepted plans01–04 remain their existing accepted scope, including plan04's
conditional PERF05 disposition. These reviews do not reclassify that deferred
measurement as passed. Temporary vendor symlinks and unrelated MAIN tool edits
are outside the plan05 commit scope.

Final readiness is fail-closed: user approval of the single whole-plan commit
is pending; PERF05 remains a mandatory deferred VM obligation. PERF04 cannot
be marked passed by microbenchmarks or soak and is required before any concurrent
production capacity claim. Plan06 must wait for a selected application/runtime.

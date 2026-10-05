# PERF-05 replay in another environment

On 2026-10-05 the user conditionally accepted plan04 so development can continue.
PERF-05 is `DEFERRED_VM`, **not PASS**: the complete replay remains mandatory
when a separate measurement VM becomes available. After the reboot, two full
comparisons failed the unchanged 5% independent-controls gate. An eight-batch A-A diagnostic using
identical production revision, harness and lock also failed: no-route
+8.267831%, matched route +5.920304%. This demonstrates insufficient
repeatability in that run, without identifying a particular host/runtime cause.
The code and security reviews accepted the implementation; the final resource
report passed correctness and provenance checks. Conditional acceptance does
not change the thresholds or turn the failed measurements into a quantitative pass.

The local replay kit is generated in the persistent worktree at
`.superpowers/sdd/2026-10-02-04-benchmark-integrity/transfer/`:

- `request-mapper-hardening.bundle`: committed production revision and history.
- `benchmark-overlay.tar.gz`: the four final benchmark scripts, three benchmark
  test files, exact dependency lock and two unchanged experimental raw reports
  required by rejection regression tests, including uncommitted plan 04 changes.
- `SHA256SUMS`: verifies the overlay files after extraction.

Transfer these three files to an owner-approved Linux measurement environment.
The kit is local only; no commit, push, deployment or remote connection was made.
From the directory containing the kit, use a new checkout:

```sh
git clone --branch harden/request-mapper --single-branch request-mapper-hardening.bundle replay
cd replay
tar -xzf ../benchmark-overlay.tar.gz
sha256sum -c ../SHA256SUMS
composer install --no-interaction --no-progress --no-plugins --no-scripts
php vendor/bin/phpunit --do-not-cache-result --colors=never
```

The source revision must be `f7dd99fe539b73327ad867c299d95e27939f7820`;
the baseline is `e581e210953faaaae5c21627b651727a505c4df8`. Both are in the
bundle. Preserve the supplied lock and scripts. Record CPU/OS, `php -v`,
`php --ini`, `php -m`, OPCache/JIT/PCOV settings and other concurrent workloads.
PHP options supplied only to the orchestration process with `-d` are not
inherited by its children; configure workers through their shared INI setup
or inherited `PHP_INI_SCAN_DIR`. Reports record actual worker settings.

After QA finishes, run the complete comparison without concurrent QA or agents:

```sh
php benchmarks/compare.php \
  --baseline=e581e210953faaaae5c21627b651727a505c4df8 \
  --optimized=f7dd99fe539b73327ad867c299d95e27939f7820 \
  --batches=4 --iterations=500 --control-iterations=100000 \
  --warmup=100 --samples=7 --workdir=/tmp \
  --output=docs/benchmarks/raw
```

Use an absolute `--workdir`. Keep every generated report, including failed runs.
Aggregate the generated JSON with `php benchmarks/aggregate.php <raw-json>`.
Both independent controls (IDs 1 and 15) must be within 5% in absolute movement.
When they are stable, any small mapped regression above 10% requires profiling
and explanation. A diagnostic or partial run cannot replace the full gate.
Do not infer production RPS/p99 from these CLI measurements.

Closing the deferred quantitative gate requires checking the new report's
revision, harness/orchestrator and lock hashes, effective counts and worker environment, then independent
review. VM replay is no longer a prerequisite for conditionally accepting plan04
or continuing development, but must be performed when that VM is available.
Commit-message approval is still pending; no commit has been made, and plan05
must not begin before the approved whole-plan04 commit. Conditional acceptance
does not establish production capacity or change the 5% / 10% thresholds.

# Task5 — retained Swoole PID1 checker delta

Root authorized this checker-only correction after all six actual zero-HTTP
runtime/revision endpoint proofs passed. No new native probe/runtime sequence,
app request, image/build/install, or execution has occurred for this delta.

## Reproduction and exact scope

The actual retained Swoole workers are PID1, command `pilot-swoole-worker-0`:
baseline start1189666/RSS38060KiB; candidate start1190329/RSS38052KiB before,
38048KiB after. The original checker rejected every PID<=1. The preserved
six-cell native proof is `task5-platform/results.json`; the earlier failed
source-inventory attempt remains unchanged in
`task5-platform-before-source-inventory-correction`.

Only A/pilot/check.php, pilot/tests/checker.php, and pilot/freeze.php changed.
The checker now requires a strict positive integer PID, and allows PID1 only
for runtime=swoole and command=pilot-swoole-worker-0. Existing exact app
container ID/state/image/labels/caps, runtime/platform/revision/source,
before/after start ticks, and sample identity checks are unchanged. The native
k6 owned-process stop/salvage PID>1 guard is unchanged. The manifest records
this worker identity policy; serving app/runtime/library/config/dependencies,
resource caps, budgets, and generator instrumentation are unchanged.

## Meaningful RED → GREEN

Both commands used cwd A and exactly:
`php -d zend.assertions=1 -d assert.exception=1 pilot/tests/checker.php`.

RED (session13500) exited255 before the production change:
`RuntimeException: Retained one-worker identity missing` at check.php:38,
called from checker.php:106. It reached actual saved Swoole PID1 after checking
retained FPM/RoadRunner endpoint proofs.

GREEN (session20239) exited0 after the narrow predicate change:
`PASS all6 ACTUAL saved endpoint identities (synthetic container wrapper); Swoole PID1 mutations rejected`
and `PASS independent checker: forged completion cannot replace raw/sampler/readiness/hash/archive evidence; zero HTTP`.

The fixture uses each actual saved before/after endpoint proof unmodified,
alongside explicitly labeled synthetic generator/container wrappers: it is a
checker test, never measurement readiness or a fresh native proof. Each actual
Swoole revision rejects PID0, PID-1, string PID1, mismatched start ticks,
container ID, revision, serving role, and worker command. Portable clean-replay
fixtures additionally accept the exact Swoole PID1 contract, reject a wrong
PID1 command, and reject FPM PID1 even with the Swoole command.

## Frozen delta and pending verification

Package/freeze was created before the isolated serial suite. No source edits
after that package. `task-5-pid1-review-package.md` is diffed ONLY against the
clean accepted stream archive reconstruction. `task-5-pid1-hashes.json` records
the exact hashes and source_issues=[]. Preparation3496files/21268480bytes,
archiveSHA21bd7204cbcf7da98fe530b4407c073b786d03cda123de69d7c90c95e94ff232,
manifestSHA3593650fa0795d8a24909ec55e45242eefba04158f9c22bed94280eb4e65a838,
packageSHAfa6485904c00ac907620c626cd343a005fafeb0e5745ac71b607e977791a5347.

The retained native helper/probe/platform-helper hashes are unchanged. The
native combined API/Trend/abort/salvage proof remains passed and is not rerun;
all six actual runtime/revision proofs remain passed and are not rerun.
Current preparation flags remain true/false/false pending final freeze and
clean reconstruction of this changed archive. HTTP0, no budget-execution.json,
build357.271023/3600s active=null, no execution clock started.

All13 isolated serial regressions exited0, after package/freeze, with unchanged
timing limits. The checker output again validates all six saved endpoints and
negative matrix. Exact commands/exits/stdout/stderr/timings are retained in
task-5-pid1-checks.json. Three changed-PHP syntax checks and both worktree
`git diff --check` commands exited0. The source hash set remained unchanged
after the suite. Scoped review, final manifest/go-no-go and root confirmation
are required before --execute. No HTTP or measured-cell
retry is allowed. PERF-04 incomplete; PERF-05 DEFERRED_VM / NOT PASS.

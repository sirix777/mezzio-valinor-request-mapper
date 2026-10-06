# Task5 — post-data owned cleanup correction

This is a POST-EXECUTION source correction and an unexecuted follow-up replay,
not a replacement for the original study. No HTTP, native probe, runtime
sequence, readiness or measured-cell retry occurred after the single execution.

## Verified failure and data preservation

The original execution used manifest
6acbb0509a31a1e1e0a3bdc808f5449b62ad792379c7b57f36c9dbfc40fc6a9f and
archive21bd7204cbcf7da98fe530b4407c073b786d03cda123de69d7c90c95e94ff232.
They, all raw/summaries/samples, and all seven original cleanup logs are immutable.
All six HTTP readiness cells passed exact15/16 counts and worker/runtime/source
validation. The first window stopped on sampler exit1:44 rows, invalid indices
33/34/36, collection overhead1.011535868/1.129272483/1.043694053s above the
unchanged1s guard. Sampler stderr is empty. This is an instrumentation validity
failure, not evidence of an application/library defect.

Separately, the original runner started generator Compose one-offs with
`run -d ... sleep600`, then `down --timeout2` returned0 while leaving those
one-offs and busy networks. All seven original cleanup logs preserve this exact
message. Read-only ownership proofs established seven exact own generator IDs
and their seven private networks with matching Compose labels and sole members.
Root authorized ONLY those fourteen exact resources for manual cleanup.
task-5-owned-cleanup.json records49 exact commands, all ownership checks,
fourteen removals and empty final inventories. Cleanup finished08:52:41Z,
905.521650579s from the original execution reservation, within4500; no reset.

## Exact source ownership and bounded fix

Only A/pilot/run.php, pilot/tests/driver.php, pilot/tests/fake-docker.php changed.
`pilotOwnCleanup` validates its exact Compose project. If inventory assignment
has not supplied the generator, it queries ONLY that current project, service
generator, oneoff=True; ambiguity/malformed IDs are rejected. Whether discovered
or supplied, the full ID, exact project/service/oneoff labels and pinned image
must match a single inspect result before `rm -f` that exact ID. This occurs
AFTER native owned-PID stop and independent artifact salvage, BEFORE own down.
Final project-filtered container AND network inventories must be empty.

Discovery, inspect, rm, down and final empty queries share ONE existing5s cleanup
phase. Each subcommand is bounded by its remaining deadline; exhaustion fails
and retains cleanup-failure evidence. No added allowance: existing daemon
check5s + native salvage12s + cleanup5s stays within22s reserve. Prepared66s,
4500s global clock, request budgets/caps, native PID/start/abort guards and all
measurement validity gates are unchanged. No broad down, orphan sweep, wildcard,
prune or foreign/reused resource deletion is added.

## Meaningful RED → GREEN

Command cwd A:
`php -d zend.assertions=1 -d assert.exception=1 pilot/tests/driver.php`.
RED exited255 at driver.php:94 before production edit:
`Observed cleanup regression: own down returned0 but left sleep600 generator and busy network`.
The inert CLI modeled the actually observed down0/retained one-off/network.
GREEN exited0 after the minimal helper, with exact output:
`PASS owned cleanup: observed one-off/network regression, abort salvage→exact rm→down, pre-inventory discovery, foreign/reused/ambiguous identity refusal, empty checks and cumulative deadline; zero HTTP`.
Existing inert-driver/stream/budget/signal tests also passed.

The independent inert native process proves stop→byte-identical salvage→exact
generator removal→own down ordering and no live native work. A production-driver
test fails after run-d but before inventory assigns the generator, then proves
strict label discovery, removal and empty network without launching k6. Direct
helper cases cover success, discovery, foreign project, reused role, mismatched
ID, ambiguous discovery, final leftovers and expired deadline. Refused identities
and deadline perform no rm/down, and an unrelated sentinel remains untouched.
These are explicitly synthetic zero-HTTP tests, not native proof or performance.

## Separate follow-up artifacts and verification

task5-cleanup-followup-dryrun is labeled POST-DATA / preparation only and retains
false replay/native-execution flags. The original executed source remains in
its immutable archive and clean reconstruction; current A source now intentionally
differs ONLY by this after-data cleanup correction. The checker result and
independent accounting were captured BEFORE this correction while original
source hash checks were clean. Their invalid0/81 result is preserved unchanged,
not recast as passing or silently regenerated against the follow-up source.

task-5-cleanup-review-package.md diffs only those three files against the clean
original executed replay. task-5-cleanup-hashes.json records follow-up archive,
manifest/package hashes and source_issues=[]. The package/freeze precedes the
isolated13 serial checks; no source edits afterward. Full scoped code/security
rereviews are coordinator-owned. No new execution authority is requested.

After package/freeze all13 serial checks exited0; three changed-PHP syntax
checks and both worktree diff checks exited0. Offline clean reconstruction
matched every one of3496 regular files, with no symlinks or inventory extras.
No source edits followed the package. Unexecuted follow-up archive3496files /
21278720bytes SHA5a15ddff36e144c2f6e9266d5d6e4bfd967dcdaa86714a5c7afda1c5f9895b85,
manifestSHA6642f22ecebbb9573c17c1b25ef5d984f49221476a4d024461e4215dc4f81438,
packageSHA4056557f5d0dc173ad7a0dcce578bca957c8ead8f3d2de0f1fd7097b8a9740f3.

The raw study remains INVALID / partial:493 actual HTTP=93 readiness+400 first
window,400 contiguous invocations/results/native requests,0 drop/unfinished/
unavailable timing,300 native measured starts. Diagnostics stay quarantined
because samples and after-container/worker endpoint proof are incomplete.
No baseline/candidate comparison, sustainable RPS, SLO, significance or production
p99 claim. PERF-04 incomplete; PERF-05 DEFERRED_VM / NOT PASS; VM follow-up required.

# Task5 — post-data cleanup boundary revision2

Scope ONLY A/pilot/run.php, tests/driver.php, tests/fake-docker.php, after the
single original HTTP execution. No native/API/runtime proof or HTTP/load retry.
Original executed manifest6acbb050…6a9f/archive21bd7204…ff232/raw/7cleanup logs
and first unexecuted follow-up5a15ddff…95b85/package4056557f…740f3 are preserved.

The first cleanup follow-up received NeedsRevision: generic metadata reads used
timeout's extra1s KILL grace, child teardown added50ms beyond the phase, and
already-disappeared generator/network-only boundaries were not exercised.
Root authorized only these combined cleanup fixes, preserving5s phase/22s
reserve/66s prepared fit/all native guards, budgets and measurement gates.

## Meaningful combined RED and GREEN

Both used cwd A and exactly
`php -d zend.assertions=1 -d assert.exception=1 pilot/tests/driver.php`.
RED session35936 exited1 before production revision2:

- running TERM-ignoring inspect:1.305320312s against0.3s;
- running TERM-ignoring down:5.060049095s against5s;
- generator already gone before supplied-ID inspect:down0;
- generator disappears between inspect/rm:down0;
- network-only residue:down1/network_rm0, failed to clean;
- foreign/busy/ID-mismatched network cases already refused unsafe rm.

GREEN session34667 exited0 with the same actual running inert processes:

- near-expiry capture0.259064953s against0.3s;
- TERM-ignoring down4.959758358s against5s;
- gone-before-inspect0.287741395s/down1;
- gone-before-rm0.579758766s/down1;
- network-only0.895407703s/down1/exact network_rm1;
- foreign/busy/ID-mismatched networks refused without network removal.

All original stream/inert native abort/owned cleanup/early-inventory/identity
refusal checks also passed. New test output records explicit fixture paths;
their phase JSON and canonical/partial captures preserve each status/hash,
failed inspect/rm, own down attempt and final-empty/failure evidence. Running
timeout children are verified gone/Z, with no later write. No test sends HTTP.

## Minimal production correction

Cleanup ONLY reuses the existing bounded binary stream transport for command
metadata/stdout (64KiB cap), with separate stderr and canonical/partial status.
It subtracts100ms for existing50ms termination plus polling/reaping/durable
capture BEFORE launching each command, INSIDE its one absolute5s deadline.
No generic runtime/docker.php timeout or global process/native-PID guard changes.
All discovery/inspect/rm/down/empty-network/final verification commands consume
the same phase; timeout or insufficient teardown allowance fails conservatively.

Nonzero supplied-generator inspect or rm retains explicit failure evidence and
still attempts ONLY its own Compose down while time remains. The helper does
not remove unverified IDs and does not retry commands. Successful inspection
with foreign project/role/oneoff/image/full-ID mismatch rejects before mutation.
Disappearance is never silently promoted to a successful cleanup proof.

After own down, container inventory must be empty. A network-only residual is
removable ONLY as one full64-character ID, with a single matching inspect result,
exact current-project and network=pilot labels and explicit zero members.
Foreign/ambiguous/mismatched/nonempty networks are not removed. A subsequent
same-project network inventory must be empty. No registry, orphan sweep, wildcard,
prune, cross-project cleanup or new resource/deadline allowance is introduced.

## Follow-up evidence classification

The second separate replay is POST-DATA/unexecuted/preparation-only, with false
native/replay execution flags. It is not substituted for the original study.
The preserved original checker/accounting were generated before source fixes,
source hashes then clean, and remain INVALID0/81: three overhead-invalid sampler
rows and absent after-window endpoint proof;80 cells unstarted/skipped.
Actual493 HTTP=93 readiness+400 first window; all manual seven-generator and
seven-empty-network removal completed905.521650579s from the original clock.
No SLO/comparison/significance/capacity/production-p99 result; PERF-04 incomplete,
PERF-05 DEFERRED_VM / NOT PASS, VM follow-up mandatory.

Package/freeze precedes the one isolated13 serial suite. No source edits after
package. Exact follow-up hashes/check commands/outputs/timings and offline clean
replay are in task-5-cleanup-v2-{hashes,checks,replay}.json and
task-5-cleanup-v2-review-package.md. Fresh scoped code AND security rereview
remain coordinator-owned before acceptance. No new execution is authorized.

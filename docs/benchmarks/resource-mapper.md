# Mapper resource scenarios

The candidate-only runner imports the corrected compatibility harness without
executing its CLI. Each scenario, including each error cardinality, uses a
separate PHP worker. It hydrates 100 nested DTO records; generates 10, 100,
1,000 and 10,000 invalid integer errors; rejects 10,000 input elements at a
1,000-node budget; and caps a 10,000-error response at 100 messages / 8,192 bytes.

```sh
taskset -c 6 php -d memory_limit=512M benchmarks/resource-mapper.php \
  --scenario=all --iterations=100 --warmup=10 --samples=5 \
  --output=docs/benchmarks/raw/resource-candidate.json
php vendor/bin/phpunit test/Benchmark/ResourceMapperTest.php --do-not-cache-result
```

`--scenario` also accepts `nested`, `errors`, `input-limit`, `response-limit`,
and `cold-warm`. Explicit integer CLI arguments are validated; successful
stdout is JSON unless `--output` writes a complete document atomically.

Each worker records its own runtime environment and revision/runner/harness/lock
hashes. The parent verifies identical hashes and runtime facts across workers,
then derives the report environment from those measured workers. Only the
parent memory limit is explicitly forwarded: other parent-only `php -d` flags
do not configure children. Children use their inherited environment and PHP
INI configuration, so disabling PCOV only in the parent does not disable worker
instrumentation. Resource heap peaks include the worker metadata capture.

Measurements are elapsed microseconds per operation, not CPU time. Setup used
and allocated PHP heap are captured after bootstrap and before scenario
payload/services construction; scenario peaks include setup, correctness
probe, warmup and measurement. Allocated heap is allocator capacity; used heap
is live PHP memory. RSS is null because it is not measured. JSON body bytes
are recorded separately. Heap measurements do not establish object retention
or process RSS stability.

The cold lifecycle measures one first map on a new builder and middleware,
excluding construction/setup. It emits one elapsed sample regardless of warm
sample count. The separate warm worker probes its first map, warms the same
builder/middleware, then times reuse. HTTP status and nested DTO values are
verified outside timing. A counting TreeMapper decorator records mapper calls
for early-rejection verification; its tiny overhead is included in resource
timings, so these numbers are not compatibility comparison data.

Oversized input is checked to invoke no mapper during the correctness probe
or measured operations. Response errors are JSON-decoded and checked against
both caps; retained rows include any omission marker. Earlier unit tests cover
early traversal stop and skipping formatting for discarded messages. The
responder limits serialized details, while Valinor still constructs its full
error tree. Input budgets apply after PSR-7 body parsing.

Final generated evidence: [resource-candidate.json](raw/resource-candidate.json).
The serial CPU 6 run completed with 100 iterations / 10 warmup / 5 samples,
512 MiB memory limit, PHP 8.5.10, PCOV enabled, OPcache CLI off and JIT disabled.
Every measured child recorded identical actual environment and provenance;
post-run runner/harness/lock hashes match the final source bytes. Source revision
is `f7dd99f`; the uncommitted resource runner is identified by SHA256 `d9476243`
and the common harness by `38beea5c` (full hashes are in the report).

| Scenario | Median µs/op | Peak used bytes | Peak allocated bytes | Body bytes |
| --- | ---: | ---: | ---: | ---: |
| Nested 100 records | 1334.127 | 3946352 | 4194304 | 0 |
| Errors 10 | 140.830 | 4337888 | 6291456 | 669 |
| Errors 100 | 1123.935 | 5608424 | 6291456 | 6429 |
| Errors 1000 | 15851.845 | 18585608 | 20971520 | 64929 |
| Errors 10000 | 608879.106 | 149095168 | 153092096 | 658929 |
| Input node limit | 437.778 | 3596048 | 4194304 | 84 |
| Response limits | 854366.588 | 143453560 | 144703488 | 6476 |
| Cold first map (one sample) | 16104.367 | 3946352 | 4194304 | 0 |
| Warm reuse | 2396.245 | 3946352 | 4194304 | 0 |

Both successful hydration workers returned HTTP 204; all invalid-input workers
returned 422. Unlimited error cardinalities match their inputs exactly. The
input-limited worker made zero mapper calls, including measured operations.
The response-limited body has 101 retained rows including the omission marker
and 6476 bytes, below the 8192-byte budget. Its roughly 143 MB used-heap peak
still includes the full Valinor error tree; response caps are not mapping-memory
caps. The capped worker was observed slower than the unlimited worker in this
run. Separate workers and the unstable control environment do not establish a
causal time improvement or regression from response caps. These resource values
do not close the compatibility control gate or prove long-worker retention.

The initial [reconstruction report](raw/resource-reconstruction-20261005.json)
is retained unchanged for the execution record. Reconstruction changed source
while that run was executing, so its reported hashes cannot attest the exact
loaded source bytes; it is excluded from acceptance evidence. The final report
is regenerated only after source bytes are stable.
These scenarios run only on the candidate and do not close the long-worker
or concurrent-application acceptance gates in plans 05/06.

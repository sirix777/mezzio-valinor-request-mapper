# Persistent mapper worker memory

`benchmarks/worker-soak.php` checks a shared mapper/middleware across changing
request instances and scalar IDs. Static mode alternates two fixed routes;
ephemeral mode creates and releases a route and `RequestHandlerMiddleware`
wrapper for each request, using the same loaded handler class. Each mapped ID,
HTTP 204 response and absence of a DTO attribute on the original request are
checked during every iteration. The runner discards requests, DTOs and responses.

Run each mode in its own process, with no simultaneous QA or source changes:

```bash
php benchmarks/worker-soak.php --mode=static --warmup=10000 --requests=100000 --interval=10000 --output=docs/benchmarks/raw/worker-static.json
php benchmarks/worker-soak.php --mode=ephemeral --warmup=10000 --requests=100000 --interval=10000 --output=docs/benchmarks/raw/worker-ephemeral.json
```

These counts are the defaults. Smaller positive counts support smoke checks;
`qualifies_for_full_gate` requires at least 10,000 warmup requests, 100,000
measured requests, five checkpoints, and an interval of exactly 10,000. A short
successful smoke check does not establish the full memory gate. Numeric CLI
counts are capped at 1,000,000; unknown, duplicate or invalid options fail.

At each checkpoint the runner checks a bounded group of WeakReferences to the
original and processed requests, DTO and response; ephemeral mode also checks
the temporary route and wrapper. It runs GC, checks that the sampled objects
are gone, and clears the WeakReferences before memory snapshots. Report storage
is preallocated before warmup so retained scalar checkpoint rows do not imitate
request retention. No historical payloads are retained for correctness checks.

The gate examines used PHP memory **after GC** at the last five checkpoints.
Their range must be at most `max(1 MiB, 5% of the first of those five values)`.
Four strictly positive consecutive differences fail as sustained growth, even
within that range. Fewer than five checkpoints cannot pass the calculator.
Correctness, object release or a qualifying memory-gate failure produces a
nonzero exit status. JSON is emitted for completed runs, including failed gates;
invalid configuration or an exception reports an error on stderr and fails.

Schema `sirix-mezzio-valinor-worker/1` records before/after-GC used and allocated
PHP memory, separate process peaks, RSS where `/proc/self/status` is readable,
counts, bounded scalar correctness samples, release counts and gate decisions.
`provenance` records the source revision, harness/worker hashes, dependency-lock
hash and locked versions; runtime fields describe the actual process. A source
revision before an uncommitted harness addition is accompanied by its hash, not
presented as a commit containing that addition.

Used PHP heap, allocator reservation/peaks and RSS are different measurements.
The gate does not require strong reflection/class metadata to disappear: those
caches are expected to live for the worker lifetime. Request-scoped source
snapshots are shallow; custom services and DTO constructors must not retain
requests or mutate nested references between mappings. Applications still
choose restart policy, release-specific cache directories and warmup using the
same registered builder as runtime mapping.

See the [hardening verification report](hardening-verification.md) for measured
results and limitations. These runs establish only the stated benchmark-process
memory checks. They do not establish production throughput, p99, concurrent
capacity or an arbitrary application's memory footprint. PERF05 is separately
`DEFERRED_VM`, not PASS; PERF04 remains not run.

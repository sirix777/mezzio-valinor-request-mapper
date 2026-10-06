# Developer benchmarks

Run these commands from a repository checkout with a supported PHP CLI and
Composer development dependencies installed (`composer install`). The scripts
use the development PSR-7/17 and Mezzio implementations; a production package
distribution excludes the benchmark scripts and this guide.

## Request mapping

List scenario IDs or run the request-path harness with explicit counts:

```sh
php benchmarks/request-mapper.php --list-scenarios
php benchmarks/request-mapper.php --iterations=500 --warmup=100 --samples=7
```

Use `--scenario=<id>` to select one scenario. The harness checks mapped values
and responses before timing, and runs scenarios in separate PHP processes.

To compare two full Git revisions with the same harness and dependency lock:

```sh
php benchmarks/compare.php --baseline=<full-sha> --optimized=<full-sha> --batches=4 --iterations=500 --control-iterations=500 --warmup=100 --samples=7 --workdir=/tmp --output=/tmp/mapper-comparison
php benchmarks/aggregate.php /tmp/mapper-comparison/<generated-file>.json
```

Replace the SHA and generated-file placeholders. The comparison requires Git,
a local `composer.lock` and dependency access. It creates temporary detached
worktrees and installs the locked dependencies in them. Batches alternate
between revisions; `--batches` is the count per revision. Control counts default
to `--iterations`; an override applies only to the control scenarios. The
aggregator validates recorded samples and counts before emitting a table.

## Resource use and persistent workers

```sh
php benchmarks/resource-mapper.php --scenario=all --iterations=100 --warmup=10 --samples=5
php benchmarks/worker-soak.php --mode=static --warmup=10000 --requests=100000 --interval=10000
php benchmarks/worker-soak.php --mode=ephemeral --warmup=10000 --requests=100000 --interval=10000
```

Resource scenarios cover nested DTOs, error cardinality, input budgets, response
caps, and cold/warm mapping. Worker modes reuse the mapper while changing
request data; ephemeral mode also creates and releases routes. Worker output
records object-release checks and used PHP memory after garbage collection,
separately from allocator peaks and RSS where available. Smaller counts are
useful for smoke checks but do not qualify for the full worker memory gate.
Resource and worker scripts accept `--output=<file>` to retain JSON.

## Measurement limits

Use a quiet, isolated machine, fixed PHP settings and locked dependencies for
comparisons. CLI OPcache/JIT, extensions, CPU scheduling, warmup, cache state
and worker lifecycle affect results; record them and repeat measurements.
Used PHP memory, allocator reservation and RSS measure different things.

These developer checks do not establish production
throughput, tail latency, concurrency capacity or an application's memory
footprint; measure those in the intended application and runtime.

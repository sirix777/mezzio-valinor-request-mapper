# Request mapper benchmark

This benchmark compares the middleware/resolver path before and after the
middleware refactor and metadata caching introduced in plan 10.

It does **not** benchmark Valinor mapping itself; it measures the per-request
overhead of resolving the handler target and building the mapping plan.

## Reproduction and provenance

The optimized result was produced by the current runner with:

```sh
php benchmarks/request-mapper.php --iterations=10000 --warmup=1000 --samples=5 \
  > docs/benchmarks/raw/optimized-2026-09-09.json
```

The exact JSON produced by that command is stored in
[`raw/optimized-2026-09-09.json`](raw/optimized-2026-09-09.json).

The baseline values below are **historical**, from the pre-plan-10 revision
`8c96f448a57a2b8b01ba51835356a40a9b58d9ad`. That revision predates this runner,
so its figures must not be presented as reproducible by the current runner. New
comparisons should run the same runner and parameters on both checked-out
revisions and retain both raw JSON files.

For scenario 7, `--cache-dir` is an existing writable **parent** directory;
the runner creates and removes only its own random child directory. It never
removes the supplied directory or its existing contents.

## Environment

| Component        | Version |
|------------------|---------|
| PHP              | 8.2.33  |
| cuyz/valinor     | 2.6.0   |
| mezzio/mezzio-router | 4.2.0 |
| OPcache (CLI)    | disabled |

## Parameters

- Iterations: `10000`
- Warmup: `1000`
- Samples: `5`
- Reported value: median microseconds per operation

## Results

| Scenario                                          | Lifecycle                | Historical baseline (us/op) | Optimized (us/op) | Change  |
|---------------------------------------------------|--------------------------|-----------------:|------------------:|--------:|
| No RouteResult passthrough                        | reuse                    | 0.516            | 0.502             | -2.7%   |
| Reflection via direct handler object              | reuse                    | 9.649            | 7.169             | -25.7%  |
| Reflection via lazy FQCN handler                  | reuse                    | 9.474            | 7.152             | -24.5%  |
| Route options single DTO                          | reuse                    | 8.649            | 7.836             | -9.4%   |
| Three operations with different outputs           | reuse                    | 29.734           | 22.890            | -23.0%  |
| Repeated calls reusing middleware and builder     | reuse                    | 9.493            | 7.174             | -24.4%  |
| New middleware/resolvers/builder per iteration with file cache | new-each-iteration | 69.391 | 65.528 | -5.6%   |

Peak memory did not change between runs (4 MiB for reuse scenarios, 32 MiB for
new-each-iteration).

## Observations

- The largest wins come from caching `HandlerTarget` and `MapRequest` metadata
  across requests, which removes repeated reflection for handlers that are reused
  by the application container.
- Scenarios that construct fresh resolvers every iteration benefit less because
  the caches live on the resolver instances and are rebuilt each time.
- The passthrough path is slightly faster because route metadata resolution is
  also skipped when there is no `RouteResult`.

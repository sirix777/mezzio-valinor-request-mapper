# Request mapper benchmark

The runner measures the complete warmed middleware path. Each mapped scenario
calls `TreeMapper::map()` as well as resolving the handler target, mapping plan,
and selected HTTP input. It is not a resolver-only benchmark.

The large-payload scenarios exercise the request-scoped input snapshot:

| Scenario | Input |
| --- | --- |
| Large flat body, one operation | 10,001 fields / strings, about 252 KiB JSON |
| Large flat body, three operations | same body mapped three times |
| Large nested body, one operation | 20,201 fields, 20,001 strings, about 496 KiB JSON |
| Large nested body, three operations | same body mapped three times |
| Large body plus combined source | flat body plus one route and query value |

Every raw result contains the precise field count, string count, approximate
JSON payload size, median CPU time, and peak memory.

## Reproduction

Run the same command from clean worktrees at the two revisions being compared,
then retain both JSON files alongside their commit SHA:

```sh
php benchmarks/request-mapper.php --iterations=20 --warmup=5 --samples=3 \
  > docs/benchmarks/raw/<sha>.json
```

The runner creates a separate process for every scenario, so its peak-memory
value is scenario-local. Scenario 7 accepts `--cache-dir` as an existing
writable parent; it creates and removes only a randomly named child directory.

[`raw/working-tree-2026-09-10.json`](raw/working-tree-2026-09-10.json) is a
non-comparative smoke-run of the current implementation. It deliberately does
not replace a two-revision comparison: commit the change first, rerun the
command at both exact SHAs, and name the resulting files after those SHAs.

<?php

declare(strict_types=1);

/**
 * Alternating two-revision benchmark orchestrator.
 *
 * Creates clean detached worktrees for the baseline and optimized revisions,
 * copies the current harness into both (so both revisions measure the same
 * bytes), installs dependencies from one shared composer.lock, and runs full
 * benchmark batches in an interleaved A-B-B-A order. Every batch result is
 * kept, so CPU drift between batches is distinguishable from a per-revision
 * effect: control scenarios (which the input-validation change cannot affect)
 * and treatment scenarios are compared under the same drift conditions.
 *
 * Usage:
 *   php benchmarks/compare.php \
 *     --baseline=<full-sha> --optimized=<full-sha> \
 *     [--batches=4] [--iterations=500] [--control-iterations=500] [--warmup=100] [--samples=7] \
 *     [--workdir=/tmp] [--output=docs/benchmarks/raw]
 *
 * --batches is the number of full batches per revision (each batch runs all discovered
 * scenarios), so the total process batch count is twice that value. The raw
 * document records it as `batches_per_revision`.
 */
const HARNESS_SOURCE = __DIR__ . '/request-mapper.php';

/**
 * @param array<string, bool|list<mixed>|string> $options
 * @param non-empty-string                       $name
 */
function parsePositiveIntOption(
    array $options,
    string $name,
    int $default,
    int $min,
    int $max
): int {
    $raw = $options[$name] ?? null;

    if (null === $raw) {
        return $default;
    }

    if (! \is_string($raw)) {
        throw new InvalidArgumentException("Option --{$name} must be a non-negative integer");
    }

    if (1 !== \preg_match('/^\d+$/', $raw)) {
        throw new InvalidArgumentException("Option --{$name} must be a non-negative integer");
    }

    $value = (int) $raw;

    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException("Option --{$name} must be between {$min} and {$max}");
    }

    return $value;
}

final class CompareBenchmark
{
    private readonly string $workRoot;

    private readonly int $controlIterations;

    /** @var array{baseline?: string, optimized?: string} */
    private array $worktrees = [];

    /** @var list<int> */
    private array $scenarioIds = [];

    public function __construct(
        private readonly string $baseline,
        private readonly string $optimized,
        private readonly int $batchesPerRevision,
        private readonly int $iterations,
        private readonly int $warmup,
        private readonly int $samples,
        private readonly ?string $outputDir,
        string $workRoot,
        ?int $controlIterations = null,
    ) {
        $this->controlIterations = $controlIterations ?? $this->iterations;

        if ($this->controlIterations < 1 || $this->controlIterations > 1000000) {
            throw new InvalidArgumentException('Option --control-iterations must be between 1 and 1000000');
        }

        $this->workRoot = \rtrim($workRoot, '/\\')
            . '/mezzio-valinor-compare-' . \bin2hex(\random_bytes(6));
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $results       = [];
        $environment   = null;
        $harnessSha256 = \hash_file('sha256', HARNESS_SOURCE);

        if (false === $harnessSha256) {
            throw new RuntimeException('Failed to hash harness file');
        }

        $orchestratorSha256 = \hash_file('sha256', __FILE__);

        if (false === $orchestratorSha256) {
            throw new RuntimeException('Failed to hash orchestrator file');
        }

        $lockSource = $this->resolveLockSource();
        $lockSha256 = \hash_file('sha256', $lockSource);

        if (false === $lockSha256) {
            throw new RuntimeException('Failed to hash composer.lock');
        }

        // Compute and validate the batch order before any side effect
        // (worktrees, composer install), so invalid parameters cannot leave
        // partially prepared environments behind.
        $order = $this->interleavedOrder($this->batchesPerRevision);

        try {
            $this->prepareWorktrees($lockSource, $harnessSha256);

            foreach ($order as $index => $revision) {
                $label = $this->baseline === $revision ? 'baseline' : 'optimized';

                \fwrite(STDERR, \sprintf(
                    "batch %d/%d: %s %s\n",
                    $index + 1,
                    \count($order),
                    $label,
                    $revision,
                ));

                $batchResult       = $this->runHarness($revision, $index + 1);
                $workerEnvironment = $this->measuredEnvironment($batchResult['provenance']['versions']);

                if (null !== $environment && $environment !== $workerEnvironment) {
                    throw new RuntimeException('Measured worker runtime environment differs between batches or revisions');
                }

                $environment = $workerEnvironment;

                $results[] = [
                    'batch'          => $index + 1,
                    'label'          => $label,
                    'revision'       => $revision,
                    'harness_sha256' => $harnessSha256,
                    'lock_sha256'    => $lockSha256,
                    'result'         => $batchResult,
                ];
            }

            $document = [
                'schema'      => 'sirix-mezzio-valinor-compare/2',
                'created_at'  => \gmdate('Y-m-d\TH:i:s\Z'),
                'baseline'    => $this->baseline,
                'optimized'   => $this->optimized,
                'params'      => [
                    'batches_per_revision' => $this->batchesPerRevision,
                    'order'                => $order,
                    'iterations'           => $this->iterations,
                    'control_iterations'   => $this->controlIterations,
                    'warmup'               => $this->warmup,
                    'samples'              => $this->samples,
                    'scenario_ids'         => $this->scenarioIds,
                ],
                'environment' => $environment,
                'provenance'  => [
                    'harness_sha256'      => $harnessSha256,
                    'orchestrator_sha256' => $orchestratorSha256,
                    'lock_sha256'         => $lockSha256,
                    'lock_source'         => $this->describeLockSource($lockSource),
                    'worker'              => $results[0]['result']['provenance'] ?? null,
                ],
                'batches'     => $results,
            ];

            $this->writeOutput($document);

            return $document;
        } finally {
            $this->removeWorktrees();
        }
    }

    private function prepareWorktrees(string $lockSource, string $harnessSha256): void
    {
        if (! \is_dir(\dirname($this->workRoot))) {
            throw new RuntimeException("Work root is not a directory: {$this->workRoot}");
        }

        \mkdir($this->workRoot, 0o700, true);

        foreach ([
            'baseline'  => $this->baseline,
            'optimized' => $this->optimized,
        ] as $label => $revision) {
            $path = "{$this->workRoot}/{$label}";

            $this->git(['worktree', 'add', '--detach', $path, $revision]);

            // Registered before any per-worktree preparation step, so every
            // later failure still triggers best-effort cleanup.
            $this->worktrees[$label] = $path;

            // The harness under test is the current one, identical in both
            // worktrees; production source stays at each revision.
            if (! \copy(HARNESS_SOURCE, $path . '/benchmarks/request-mapper.php')) {
                throw new RuntimeException("Failed to copy harness into worktree: {$path}");
            }

            if (! \copy($lockSource, $path . '/composer.lock')) {
                throw new RuntimeException("Failed to copy composer.lock into worktree: {$path}");
            }

            $this->composerInstall($path);
            $this->verifyProvenance($path, $revision, $lockSource, $harnessSha256);

            $ids = $this->discoverScenarioIds($path);

            if ([] !== $this->scenarioIds && $this->scenarioIds !== $ids) {
                throw new RuntimeException('Baseline and optimized harness scenario IDs differ');
            }

            $this->scenarioIds = $ids;
        }
    }

    /** @return list<int> */
    private function discoverScenarioIds(string $path): array
    {
        $stdout = $this->execute([PHP_BINARY, $path . '/benchmarks/request-mapper.php', '--list-scenarios'], $path);
        $ids    = \json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        if (! \is_array($ids) || ! \array_is_list($ids) || [] === $ids) {
            throw new RuntimeException('Harness returned invalid scenario IDs');
        }

        foreach ($ids as $id) {
            if (! \is_int($id) || $id < 1) {
                throw new RuntimeException('Harness scenario IDs must be positive integers');
            }
        }

        if (\count($ids) !== \count(\array_unique($ids))) {
            throw new RuntimeException('Harness scenario IDs must be unique');
        }

        return $ids;
    }

    private function resolveLockSource(): string
    {
        $lock = __DIR__ . '/../composer.lock';

        if (! \file_exists($lock)) {
            throw new RuntimeException('composer.lock is missing in the source repository');
        }

        return $lock;
    }

    private function describeLockSource(string $lockSource): string
    {
        // The lockfile is an untracked environment artifact, deliberately not
        // part of either revision; the recorded SHA-256 identifies the exact
        // bytes used by both runs.
        $mainRepo = \dirname(__DIR__);

        return \str_starts_with($lockSource, $mainRepo)
            ? 'source repository composer.lock (untracked, recorded by SHA-256)'
            : $lockSource;
    }

    /**
     * A-B-B-A interleaving: every adjacent batch pair shares conditions, so
     * linear drift cannot masquerade as a revision effect. One A-B-B-A cycle
     * gives exactly two batches per revision, so only even counts are valid.
     *
     * @return list<string>
     */
    private function interleavedOrder(int $batchesPerRevision): array
    {
        if ($batchesPerRevision < 2 || $batchesPerRevision > 64) {
            throw new InvalidArgumentException('Batches per revision must be between 2 and 64');
        }

        if (0 !== $batchesPerRevision % 2) {
            throw new InvalidArgumentException(
                'Batches per revision must be even so batches can be interleaved as symmetric A-B-B-A groups',
            );
        }

        $order = [];

        for ($pair = 0; $pair < \intdiv($batchesPerRevision, 2); ++$pair) {
            $order[] = $this->baseline;
            $order[] = $this->optimized;
            $order[] = $this->optimized;
            $order[] = $this->baseline;
        }

        return $order;
    }

    /**
     * @return array{batch: int, params: array<string, int>, provenance: array<mixed>, scenarios: list<array<string, mixed>>}
     */
    private function runHarness(string $revision, int $batch): array
    {
        $label = $this->baseline === $revision ? 'baseline' : 'optimized';
        $path  = $this->worktrees[$label]
            ?? throw new RuntimeException("Worktree for {$label} is not prepared");
        $worktreeRevision = \trim($this->git(['rev-parse', 'HEAD'], $path));
        $expectedHarness  = \hash_file('sha256', HARNESS_SOURCE);
        $expectedLock     = \hash_file('sha256', $path . '/composer.lock');

        if (false === $expectedHarness || false === $expectedLock) {
            throw new RuntimeException('Failed to hash worktree harness/lock');
        }

        $results    = [];
        $provenance = null;

        foreach ($this->scenarioIds as $scenario) {
            $iterations         = \in_array($scenario, [1, 15], true) ? $this->controlIterations : $this->iterations;
            $expectedParameters = [
                'iterations' => $iterations,
                'warmup'     => $this->warmup,
                'samples'    => $this->samples,
            ];
            $stdout = $this->execute([
                PHP_BINARY,
                $path . '/benchmarks/request-mapper.php',
                '--scenario=' . $scenario,
                '--iterations=' . $iterations,
                '--warmup=' . $this->warmup,
                '--samples=' . $this->samples,
            ], $path);

            $decoded = \json_decode($stdout, true);

            if (! \is_array($decoded)) {
                throw new RuntimeException("Scenario {$scenario} returned invalid JSON: {$stdout}");
            }

            if (($decoded['scenario_id'] ?? null) !== $scenario || ($decoded['params'] ?? null) !== $expectedParameters) {
                throw new RuntimeException("Scenario {$scenario} worker parameters do not match the requested operation counts");
            }

            // Every scenario worker reports its own provenance: verify it
            // against the expected worktree state, then strip it from the
            // metrics so one identical manifest is stored once per batch.
            $scenarioProvenance = $decoded['provenance'] ?? null;
            unset($decoded['provenance']);

            $this->verifyWorkerProvenance(
                $scenarioProvenance,
                $worktreeRevision,
                $expectedHarness,
                $expectedLock,
            );

            if (null === $provenance) {
                $provenance = $scenarioProvenance;
            } elseif ($provenance !== $scenarioProvenance) {
                throw new RuntimeException("Scenario {$scenario} provenance differs from previous scenarios");
            }

            $results[] = $decoded;
        }

        return [
            'batch'      => $batch,
            'params'     => [
                'iterations'         => $this->iterations,
                'control_iterations' => $this->controlIterations,
                'warmup'             => $this->warmup,
                'samples'            => $this->samples,
            ],
            'provenance' => $provenance,
            'scenarios'  => $results,
        ];
    }

    private function verifyWorkerProvenance(
        mixed $provenance,
        string $expectedRevision,
        string $expectedHarness,
        string $expectedLock,
    ): void {
        if (! \is_array($provenance)) {
            throw new RuntimeException('Harness scenario output does not contain provenance');
        }

        if (($provenance['revision'] ?? null) !== $expectedRevision) {
            throw new RuntimeException(
                \sprintf(
                    'Harness provenance revision %s does not match worktree HEAD %s',
                    \var_export($provenance['revision'] ?? null, true),
                    $expectedRevision,
                ),
            );
        }

        foreach ([
            'harness_sha256' => $expectedHarness,
            'lock_sha256'    => $expectedLock,
        ] as $key => $expected) {
            $actual = $provenance[$key] ?? null;

            if (! \is_string($actual) || ! \hash_equals($expected, $actual)) {
                throw new RuntimeException("Harness provenance {$key} does not match the measured worktree");
            }
        }

        $versions = $provenance['versions'] ?? null;

        if (
            ! \is_array($versions)
            || ! \is_array($versions['locked_packages'] ?? null)
            || ! \is_array($versions['locked_packages_dev'] ?? null)
            || [] === $versions['locked_packages']
            || [] === $versions['locked_packages_dev']
        ) {
            throw new RuntimeException('Harness provenance does not contain locked package manifests');
        }
    }

    /**
     * @param array<string, mixed> $versions
     *
     * @return array<string, mixed>
     */
    private function measuredEnvironment(array $versions): array
    {
        if (
            ! \is_string($versions['php'] ?? null)
            || ! \is_bool($versions['opcache_cli'] ?? null)
            || ! \is_bool($versions['pcov_enabled'] ?? null)
            || (! \is_string($versions['jit'] ?? null) && false !== ($versions['jit'] ?? null))
        ) {
            throw new RuntimeException('Measured worker provenance does not contain valid runtime facts');
        }

        return [
            'php'          => $versions['php'],
            'opcache_cli'  => $versions['opcache_cli'],
            'pcov_enabled' => $versions['pcov_enabled'],
            'jit'          => $versions['jit'],
        ];
    }

    private function verifyProvenance(string $path, string $revision, string $lockSource, string $harnessSha256): void
    {
        $actualRevision = \trim($this->git(['rev-parse', 'HEAD'], $path));

        if (! \hash_equals($revision, $actualRevision)) {
            throw new RuntimeException("Worktree HEAD {$actualRevision} does not match requested {$revision}");
        }

        $worktreeHarness = \hash_file('sha256', $path . '/benchmarks/request-mapper.php');
        $sourceHarness   = \hash_file('sha256', HARNESS_SOURCE);

        if (
            false === $worktreeHarness
            || false === $sourceHarness
            || ! \hash_equals($harnessSha256, $worktreeHarness)
            || ! \hash_equals($sourceHarness, $worktreeHarness)
        ) {
            throw new RuntimeException('Copied harness does not match the source harness');
        }

        $actualLock = \hash_file('sha256', $path . '/composer.lock');
        $sourceLock = \hash_file('sha256', $lockSource);

        if (false === $actualLock || false === $sourceLock || ! \hash_equals($sourceLock, $actualLock)) {
            throw new RuntimeException('Copied composer.lock does not match the source lockfile');
        }
    }

    private function composerInstall(string $path): void
    {
        $this->execute([
            'composer',
            'install',
            '--no-interaction',
            '--no-progress',
            '--no-scripts',
            '--ignore-platform-reqs',
            '--quiet',
        ], $path);
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments, ?string $workingDirectory = null): string
    {
        return $this->execute(['git', ...$arguments], $workingDirectory ?? \dirname(__DIR__));
    }

    /**
     * @param list<string> $command
     */
    private function execute(array $command, string $workingDirectory): string
    {
        $process = \proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
        );

        if (! \is_resource($process)) {
            throw new RuntimeException('Failed to start: ' . \implode(' ', $command));
        }

        \fclose($pipes[0]);

        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $exitCode = \proc_close($process);

        if (0 !== $exitCode) {
            throw new RuntimeException(
                \sprintf("Command failed (%d): %s\n%s", $exitCode, \implode(' ', $command), $stderr),
            );
        }

        return (string) $stdout;
    }

    private function removeWorktrees(): void
    {
        foreach ($this->worktrees as $path) {
            try {
                $this->execute(['git', 'worktree', 'remove', '--force', $path], \dirname(__DIR__));
            } catch (RuntimeException) {
                // Best-effort cleanup; a later failure here must not mask the
                // original exception.
            }

            if (\is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            }
        }

        $this->worktrees = [];

        // When "git worktree remove" failed, the manual directory removal
        // leaves the administrative worktree entry behind; prune clears it so
        // repeated runs never accumulate stale registrations.
        try {
            $this->execute(['git', 'worktree', 'prune', '--expire=now'], \dirname(__DIR__));
        } catch (RuntimeException) {
            // Best-effort as well: pruning must not mask the original error.
        }

        @\rmdir($this->workRoot);
    }

    private function removeDirectoryRecursive(string $directory): void
    {
        // Never follow symlinks: getRealPath() resolves links, so a symlink
        // inside the worktree could lead to unlink()/rmdir() on an external
        // target. getPathname() stays inside the worktree; links themselves
        // are unlinked, not traversed.
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        ) as $item) {
            $path = $item->getPathname();

            if ($item->isLink() || ! $item->isDir()) {
                @\unlink($path);

                continue;
            }

            $this->removeDirectoryRecursive($path);
        }

        @\rmdir($directory);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function writeOutput(array $document): void
    {
        if (null === $this->outputDir) {
            return;
        }

        if (! \is_dir($this->outputDir) && ! \mkdir($this->outputDir, 0o755, true) && ! \is_dir($this->outputDir)) {
            throw new RuntimeException("Failed to create output directory: {$this->outputDir}");
        }

        $target = $this->outputDir
            . '/compare-'
            . \substr($this->baseline, 0, 7)
            . '-'
            . \substr($this->optimized, 0, 7)
            . '-'
            . \gmdate('Ymd-His')
            . '.json';

        $json = \json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        // Atomic write: evidence only appears under its final name when the
        // complete document is on disk.
        $temporary = $target . '.tmp';

        if (false === \file_put_contents($temporary, $json) || ! \rename($temporary, $target)) {
            @\unlink($temporary);

            throw new RuntimeException("Failed to write output document: {$target}");
        }

        \fwrite(STDERR, "wrote {$target}\n");
    }
}

function compareMain(): void
{
    $options = \getopt('', [
        'baseline:',
        'optimized:',
        'batches:',
        'iterations:',
        'control-iterations:',
        'warmup:',
        'samples:',
        'workdir:',
        'output:',
    ]);

    $baselineSha  = \is_string($options['baseline'] ?? null) ? $options['baseline'] : null;
    $optimizedSha = \is_string($options['optimized'] ?? null) ? $options['optimized'] : null;

    if (
        null === $baselineSha
        || null === $optimizedSha
        || 1 !== \preg_match('/^[0-9a-f]{40}$/', $baselineSha)
        || 1 !== \preg_match('/^[0-9a-f]{40}$/', $optimizedSha)
    ) {
        \fwrite(STDERR, "Usage: php benchmarks/compare.php --baseline=<full-sha> --optimized=<full-sha> [options]\n");

        exit(1);
    }

    $batchesPerRevision = \parsePositiveIntOption($options, 'batches', 4, 2, 64);
    $iterations         = \parsePositiveIntOption($options, 'iterations', 500, 1, 1000000);
    $controlIterations  = \parsePositiveIntOption($options, 'control-iterations', $iterations, 1, 1000000);
    $warmup             = \parsePositiveIntOption($options, 'warmup', 100, 0, 100000);
    $samples            = \parsePositiveIntOption($options, 'samples', 7, 1, 1000);
    $workdir            = \is_string($options['workdir'] ?? null) ? $options['workdir'] : \sys_get_temp_dir();
    $output             = isset($options['output']) && \is_string($options['output']) && '' !== $options['output']
        ? $options['output']
        : null;

    $comparison = new CompareBenchmark(
        $baselineSha,
        $optimizedSha,
        $batchesPerRevision,
        $iterations,
        $warmup,
        $samples,
        $output,
        $workdir,
        $controlIterations,
    );

    echo \json_encode(
        $comparison->run(),
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . "\n";
}

if (__FILE__ === \realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    \compareMain();
}

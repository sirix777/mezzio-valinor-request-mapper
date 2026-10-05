<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function bin2hex;
use function dirname;
use function fclose;
use function file_put_contents;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function random_bytes;
use function range;
use function rmdir;
use function str_repeat;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;
use function var_export;

final class BenchmarkRunnerTest extends TestCase
{
    #[Test]
    public function parentCliOverridesDoNotDescribeWorkerEnvironment(): void
    {
        $batch = $this->comparisonBatch(parentOpcache: true);
        self::assertTrue($batch['parent_opcache_cli']);
        self::assertFalse($batch['environment']['opcache_cli']);
        self::assertSame($batch['provenance']['versions']['php'], $batch['environment']['php']);
        self::assertSame($batch['provenance']['versions']['pcov_enabled'], $batch['environment']['pcov_enabled']);
        self::assertSame($batch['provenance']['versions']['jit'], $batch['environment']['jit']);
    }

    #[Test]
    public function legacyContainerInterfaceSupportsCompatibilityScenarios(): void
    {
        $path = var_export($this->path(), true);

        foreach ([1, 2, 3, 15] as $scenario) {
            $script = <<<'PHP'
                namespace Psr\Container {
                    interface ContainerInterface {
                        public function get($id);
                        public function has($id);
                    }
                }
                namespace {
                PHP;
            $script .= 'require ' . $path . '; createServices(); echo json_encode(runScenario(' . $scenario . ', 1, 0, 1)); }';
            [$status, $stdout, $stderr] = $this->process([PHP_BINARY, '-r', $script]);
            self::assertSame(0, $status, 'scenario ' . $scenario . ': ' . $stderr);
            $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(204, $result['correctness']['status']);
            self::assertCount(1, $result['samples_us_per_op']);
        }
    }

    #[Test]
    public function singleScenarioReportsActualWorkerParameters(): void
    {
        [$status, $stdout, $stderr] = $this->process([
            PHP_BINARY, '-d', 'pcov.enabled=0', '-d', 'opcache.jit=0', $this->path(),
            '--scenario=15', '--iterations=3', '--warmup=2', '--samples=1',
        ]);
        self::assertSame(0, $status, $stderr);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(15, $result['scenario_id']);
        self::assertSame([
            'iterations' => 3,
            'warmup'     => 2,
            'samples'    => 1,
        ], $result['params']);
        self::assertFalse($result['provenance']['versions']['pcov_enabled']);
        self::assertSame('0', $result['provenance']['versions']['jit']);
    }

    #[Test]
    public function comparisonDefaultsControlsToMappedIterationCount(): void
    {
        $batch = $this->comparisonBatch();
        self::assertSame(1, $batch['params']['control_iterations']);
        self::assertSame([1, 2, 15], array_column($batch['scenarios'], 'scenario_id'));
        self::assertSame([
            [
                'iterations' => 1,
                'warmup'     => 0,
                'samples'    => 1,
            ],
            [
                'iterations' => 1,
                'warmup'     => 0,
                'samples'    => 1,
            ],
            [
                'iterations' => 1,
                'warmup'     => 0,
                'samples'    => 1,
            ],
        ], array_column($batch['scenarios'], 'params'));
    }

    #[Test]
    public function comparisonOverrideOnlyChangesActualControlWorkers(): void
    {
        $batch = $this->comparisonBatch(3);
        self::assertSame(3, $batch['params']['control_iterations']);
        self::assertSame([1, 2, 15], array_column($batch['scenarios'], 'scenario_id'));
        self::assertSame([
            [
                'iterations' => 3,
                'warmup'     => 0,
                'samples'    => 1,
            ],
            [
                'iterations' => 1,
                'warmup'     => 0,
                'samples'    => 1,
            ],
            [
                'iterations' => 3,
                'warmup'     => 0,
                'samples'    => 1,
            ],
        ], array_column($batch['scenarios'], 'params'));
    }

    #[Test]
    public function invalidControlIterationsFailBeforePreparingWorktrees(): void
    {
        foreach (['0', '-1', '1000001', 'nope'] as $value) {
            [$status, $stdout, $stderr] = $this->process([
                PHP_BINARY, dirname(__DIR__, 2) . '/benchmarks/compare.php',
                '--baseline=' . str_repeat('a', 40), '--optimized=' . str_repeat('b', 40),
                // Odd batches safely stop the old runner before any worktree side effects in RED.
                '--batches=3', '--control-iterations=' . $value,
            ]);
            self::assertNotSame(0, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString('--control-iterations', $stderr);
        }
    }

    #[Test]
    public function evenSamplesUseStandardMedian(): void
    {
        self::assertSame('2.5', $this->harness('echo benchmarkMedian([1.0, 2.0, 3.0, 4.0]);'));
    }

    #[Test]
    public function oddSamplesUseStandardMedian(): void
    {
        self::assertSame('3', $this->harness('echo benchmarkMedian([9.0, 1.0, 3.0]);'));
    }

    #[Test]
    public function emptySamplesAreRejected(): void
    {
        self::assertSame('rejected', $this->harness('try { benchmarkMedian([]); } catch (\InvalidArgumentException) { echo "rejected"; }'));
    }

    #[Test]
    public function requiringHarnessDoesNotExecuteMain(): void
    {
        self::assertSame('', $this->harness(''));
    }

    #[Test]
    public function matchedRouteWithoutMappingsIsRealControl(): void
    {
        $result = json_decode($this->harness('echo json_encode(runScenario(15, 1, 0, 4));'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Matched route without mappings', $result['name']);
        self::assertSame('control', $result['classification']);
        self::assertCount(4, $result['samples_us_per_op']);
    }

    #[Test]
    public function wrongStatusFailsCorrectnessProbe(): void
    {
        $code = <<<'PHP'
            try {
                assertScenarioResult(new \Laminas\Diactoros\Response\EmptyResponse(422), 204);
            } catch (\RuntimeException) {
                echo "rejected";
            }
            PHP;
        self::assertSame('rejected', $this->harness($code));
    }

    #[Test]
    public function discoversAllCompatibilityScenarios(): void
    {
        [$status, $stdout, $stderr] = $this->process([PHP_BINARY, $this->path(), '--list-scenarios']);
        self::assertSame(0, $status, $stderr);
        self::assertSame(range(1, 15), json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function mappedScenariosVerifyDtoValues(): void
    {
        foreach (range(2, 12) as $id) {
            $result = json_decode($this->harness('echo json_encode(runScenario(' . $id . ', 1, 0, 1));'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('mapping', $result['classification'], 'scenario ' . $id);
            self::assertTrue($result['correctness']['dto_values_verified'], 'scenario ' . $id);
            self::assertSame(204, $result['correctness']['status']);
        }
    }

    #[Test]
    public function incorrectDtoFailsBeforeTiming(): void
    {
        $code = <<<'PHP'
            [$middleware] = createServices();
            $handler = new BenchmarkDirectHandler();
            $route = new \Mezzio\Router\Route(
                "/example", new \Laminas\Stratigility\Middleware\RequestHandlerMiddleware($handler), ["POST"]
            );
            $request = makeRequest("POST", ["name" => "Grace"])->withAttribute(
                \Mezzio\Router\RouteResult::class, \Mezzio\Router\RouteResult::fromRoute($route, [])
            );
            try {
                measureMappedScenario(
                    $middleware, $request, $handler, [BenchmarkBodyRequest::class => new BenchmarkBodyRequest("Ada")], 1, 0, 1
                );
            } catch (\RuntimeException) {
                echo "rejected";
            }
            PHP;
        self::assertSame('rejected', $this->harness($code));
    }

    #[Test]
    public function comparisonDiscoversHarnessScenarios(): void
    {
        $compare = var_export(dirname(__DIR__, 2) . '/benchmarks/compare.php', true);
        $root    = var_export(dirname(__DIR__, 2), true);
        $output  = $this->harness('require ' . $compare . ';'
            . '$runner = new CompareBenchmark(str_repeat("a", 40), str_repeat("b", 40), 2, 1, 0, 1, null, sys_get_temp_dir());'
            . '$method = new \ReflectionMethod($runner, "discoverScenarioIds");'
            . 'echo json_encode($method->invoke($runner, ' . $root . '));');
        self::assertSame(range(1, 15), json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function sharedPackedRefIdentifiesWorktreeRevision(): void
    {
        $directory = sys_get_temp_dir() . '/benchmark-ref-' . bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory . '/admin');
        file_put_contents($directory . '/.git', "gitdir: admin\n");
        file_put_contents($directory . '/admin/HEAD', "ref: refs/heads/benchmark\n");
        file_put_contents($directory . '/admin/commondir', "..\n");
        file_put_contents($directory . '/packed-refs', str_repeat('a', 40) . " refs/heads/benchmark\n");

        try {
            self::assertSame(str_repeat('a', 40), $this->harness('echo revisionOfDirectory(' . var_export($directory, true) . ');'));
        } finally {
            unlink($directory . '/admin/HEAD');
            unlink($directory . '/admin/commondir');
            unlink($directory . '/.git');
            unlink($directory . '/packed-refs');
            rmdir($directory . '/admin');
            rmdir($directory);
        }
    }

    private function path(): string
    {
        return dirname(__DIR__, 2) . '/benchmarks/request-mapper.php';
    }

    /** @return array<string, mixed> */
    private function comparisonBatch(?int $controlIterations = null, bool $parentOpcache = false): array
    {
        $root  = var_export(dirname(__DIR__, 2), true);
        $extra = null === $controlIterations ? '' : ', ' . $controlIterations;
        $code  = 'require ' . $root . ' . "/benchmarks/compare.php";'
            . '$revision = revisionOfDirectory(' . $root . ');'
            . '$runner = new CompareBenchmark($revision, str_repeat("b", 40), 2, 1, 0, 1, null, sys_get_temp_dir()' . $extra . ');'
            . '(new \ReflectionProperty($runner, "worktrees"))->setValue($runner, ["baseline" => ' . $root . ']);'
            . '(new \ReflectionProperty($runner, "scenarioIds"))->setValue($runner, [1, 2, 15]);'
            . '$batch = (new \ReflectionMethod($runner, "runHarness"))->invoke($runner, $revision, 1);';

        if ($parentOpcache) {
            $code .= '$batch["environment"] = (new \ReflectionMethod($runner, "measuredEnvironment"))'
                . '->invoke($runner, $batch["provenance"]["versions"]);'
                . '$batch["parent_opcache_cli"] = (bool) ini_get("opcache.enable_cli");';
        }

        $code .= 'echo json_encode($batch);';

        return json_decode($this->harness($code, $parentOpcache ? ['-d', 'opcache.enable_cli=1'] : []), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $arguments */
    private function harness(string $code, array $arguments = []): string
    {
        // Fail safely before require while restoring the import guard: never run expensive defaults in PHPUnit.
        $path   = var_export($this->path(), true);
        $script = 'if (!str_contains(file_get_contents(' . $path . '), "function benchmarkMain")) {'
            . 'fwrite(STDERR, "Import-safe entry point missing"); exit(91); }'
            . 'require ' . $path . '; ' . $code;
        [$status, $stdout, $stderr] = $this->process([PHP_BINARY, ...$arguments, '-r', $script]);
        self::assertSame(0, $status, $stderr);

        return $stdout;
    }

    /** @param list<string> $command
     * @return array{int, string, string}
     */
    private function process(array $command): array
    {
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }
}

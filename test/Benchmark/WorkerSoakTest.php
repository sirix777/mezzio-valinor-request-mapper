<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function dirname;
use function evaluateWorkerMemoryGate;
use function fclose;
use function file_get_contents;
use function implode;
use function json_decode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;
use function workerConfiguration;

final class WorkerSoakTest extends TestCase
{
    #[Test]
    public function flatMemoryPasses(): void
    {
        $gate = $this->gate([4000000, 4000000, 4000000, 4000000, 4000000]);
        self::assertTrue($gate['passed']);
        self::assertSame(0, $gate['range_bytes']);
        self::assertSame(1048576, $gate['allowed_range_bytes']);
        self::assertFalse($gate['sustained_growth']);
    }

    #[Test]
    public function rangeAboveThresholdFails(): void
    {
        $gate = $this->gate([4000000, 6000000, 4000000, 4000000, 4000000]);
        self::assertFalse($gate['passed']);
        self::assertSame(2000000, $gate['range_bytes']);
        self::assertFalse($gate['sustained_growth']);
    }

    #[Test]
    public function sustainedGrowthFails(): void
    {
        $gate = $this->gate([4000000, 4001000, 4002000, 4003000, 4004000]);
        self::assertFalse($gate['passed']);
        self::assertTrue($gate['sustained_growth']);
        self::assertSame(4000, $gate['range_bytes']);
    }

    #[Test]
    public function onlyLastFiveCheckpointsDetermineGate(): void
    {
        $gate = $this->gate([90000000, 40000000, 40500000, 40500000, 40500000, 41000000]);
        self::assertTrue($gate['passed']);
        self::assertSame(1000000, $gate['range_bytes']);
        self::assertSame(2000000, $gate['allowed_range_bytes']);
    }

    #[Test]
    public function insufficientCheckpointsCannotQualify(): void
    {
        self::assertFalse($this->gate([4000000, 4000000, 4000000, 4000000])['passed']);
    }

    #[Test]
    public function gateAcceptsExactAllowedRange(): void
    {
        self::assertTrue($this->gate([4000000, 5048576, 4000000, 4000000, 4000000])['passed']);
        self::assertFalse($this->gate([4000000, 5048577, 4000000, 4000000, 4000000])['passed']);
        self::assertTrue($this->gate([40000000, 42000000, 40000000, 40000000, 40000000])['passed']);
        self::assertFalse($this->gate([40000000, 42000001, 40000000, 40000000, 40000000])['passed']);
    }

    #[Test]
    public function defaultsSupportFullQualification(): void
    {
        self::assertFileExists($this->path());

        require_once $this->path();
        $config = workerConfiguration([]);
        self::assertSame('static', $config['mode']);
        self::assertSame(10000, $config['warmup']);
        self::assertSame(100000, $config['requests']);
        self::assertSame(10000, $config['interval']);
    }

    #[Test]
    public function requiringRunnerDoesNotExecuteMain(): void
    {
        self::assertFileExists($this->path());
        [$status, $stdout, $stderr] = $this->process([PHP_BINARY, '-r', 'require ' . var_export($this->path(), true) . ';']);
        self::assertSame(0, $status, $stderr);
        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
    }

    #[Test]
    public function staticRunReleasesRequests(): void
    {
        $result = $this->smoke('static');
        self::assertSame(0, $result['release']['alive_weak_references']);
        self::assertGreaterThan(0, $result['release']['checked_weak_references']);
        self::assertSame(0, $result['release']['checked_ephemeral_routes']);
    }

    #[Test]
    public function ephemeralRunReleasesRoutes(): void
    {
        $result = $this->smoke('ephemeral');
        self::assertSame(0, $result['release']['alive_weak_references']);
        self::assertGreaterThan(0, $result['release']['checked_ephemeral_routes']);
        self::assertSame($result['release']['checked_ephemeral_routes'], $result['release']['checked_ephemeral_wrappers']);
    }

    #[Test]
    public function eachRequestMapsItsOwnId(): void
    {
        foreach (['static', 'ephemeral'] as $mode) {
            $result = $this->smoke($mode);
            self::assertSame(110, $result['correctness']['verified_requests']);
            self::assertSame(0, $result['correctness']['failures']);
            self::assertSame([11, 60, 110], array_column($result['correctness']['sample_ids'], 'expected'));
            self::assertSame([11, 60, 110], array_column($result['correctness']['sample_ids'], 'mapped'));
        }
    }

    #[Test]
    public function invalidConfigurationFails(): void
    {
        foreach ([
            ['--mode=unknown', '--warmup=10', '--requests=100', '--interval=10'],
            ['--warmup=0', '--requests=100', '--interval=10'],
            ['--warmup=10', '--requests=-1', '--interval=10'],
            ['--warmup=10', '--requests=100', '--interval=nope'],
            ['--warmup=10', '--requests=999999999999999999999999', '--interval=10'],
            ['--warmup=10', '--requests=100', '--interval=10', '--unknown=1'],
            ['--warmup=10', '--requests=100', '--interval=10', '--mode=static', '--mode=ephemeral'],
        ] as $arguments) {
            self::assertFileExists($this->path());
            [$status, $stdout, $stderr] = $this->process([PHP_BINARY, $this->path(), ...$arguments]);
            self::assertNotSame(0, $status, implode(' ', $arguments));
            self::assertSame('', $stdout);
            self::assertNotSame('', $stderr);
        }
    }

    #[Test]
    public function partialCheckpointSmokeRemainsNonQualifying(): void
    {
        [$status, $stdout, $stderr] = $this->process([
            PHP_BINARY, $this->path(), '--warmup=10', '--requests=100', '--interval=30',
        ]);
        self::assertSame(0, $status, $stderr);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([30, 60, 90, 100], array_column($result['checkpoints'], 'request_number'));
        self::assertFalse($result['qualifies_for_full_gate']);
        self::assertFalse($result['memory_gate']['passed']);
        self::assertTrue($result['passed']);
    }

    #[Test]
    public function outputFileContainsTheReportedEvidence(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'worker-soak-');
        self::assertIsString($output);

        try {
            [$status, $stdout, $stderr] = $this->process([
                PHP_BINARY, $this->path(), '--warmup=10', '--requests=100', '--interval=10', '--output=' . $output,
            ]);
            self::assertSame(0, $status, $stderr);
            self::assertSame($stdout, file_get_contents($output));
        } finally {
            unlink($output);
        }
    }

    #[Test]
    public function unwritableOutputFails(): void
    {
        [$status, $stdout, $stderr] = $this->process([
            PHP_BINARY, $this->path(), '--warmup=10', '--requests=100', '--interval=10', '--output=' . __DIR__,
        ]);
        self::assertNotSame(0, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Failed to write worker output', $stderr);
    }

    /** @param list<int> $usedBytes
     * @return array{passed: bool, range_bytes: int, allowed_range_bytes: int, sustained_growth: bool}
     */
    private function gate(array $usedBytes): array
    {
        self::assertFileExists($this->path());

        require_once $this->path();

        return evaluateWorkerMemoryGate(array_map(static fn (int $used): array => [
            'request_number'           => 10000,
            'used_bytes_after_gc'      => $used,
            'allocated_bytes_after_gc' => 8388608,
        ], $usedBytes));
    }

    /** @return array<string, mixed> */
    private function smoke(string $mode): array
    {
        self::assertFileExists($this->path());
        [$status, $stdout, $stderr] = $this->process([
            PHP_BINARY, $this->path(), '--mode=' . $mode, '--warmup=10', '--requests=100', '--interval=10',
        ]);
        self::assertSame(0, $status, $stderr);
        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('sirix-mezzio-valinor-worker/1', $result['schema']);
        self::assertSame($mode, $result['params']['mode']);
        self::assertFalse($result['qualifies_for_full_gate']);
        self::assertCount(10, $result['checkpoints']);
        self::assertSame(100, $result['checkpoints'][9]['request_number']);
        self::assertGreaterThan(0, $result['checkpoints'][9]['used_bytes_after_gc']);
        self::assertGreaterThan(0, $result['checkpoints'][9]['allocated_bytes_after_gc']);
        self::assertSame(64, strlen($result['provenance']['worker_sha256']));

        return $result;
    }

    private function path(): string
    {
        return dirname(__DIR__, 2) . '/benchmarks/worker-soak.php';
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

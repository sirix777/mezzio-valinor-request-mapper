<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function extension_loaded;
use function fclose;
use function json_decode;
use function proc_close;
use function proc_open;
use function stream_get_contents;

final class ResourceMapperTest extends TestCase
{
    #[Test]
    public function nestedRecordsAreHydrated(): void
    {
        [$code, $stdout, $stderr] = $this->runResource('nested');
        self::assertSame(0, $code, $stderr);
        $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(100, $document['scenarios'][0]['correctness']['hydrated_records']);
        self::assertSame(204, $document['scenarios'][0]['status']);
        self::assertCount(2, $document['scenarios'][0]['samples_us_per_op']);
    }

    #[Test]
    public function errorsHaveTheRequestedCardinality(): void
    {
        [$code, $stdout, $stderr] = $this->runResource('errors');
        self::assertSame(0, $code, $stderr);
        $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(4, $document['scenarios']);
        foreach ($document['scenarios'] as $index => $result) {
            self::assertSame([10, 100, 1000, 10000][$index], $result['correctness']['retained_messages']);
            self::assertSame(422, $result['status']);
            self::assertGreaterThan(0, $result['peak_used_bytes']);
            self::assertGreaterThanOrEqual($result['setup_allocated_bytes'], $result['peak_allocated_bytes']);
            self::assertNull($result['rss_bytes']);
        }
    }

    #[Test]
    public function oversizedInputNeverCallsMapper(): void
    {
        [$code, $stdout, $stderr] = $this->runResource('input-limit');
        self::assertSame(0, $code, $stderr);
        $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $document['scenarios'][0]['correctness']['mapper_calls']);
        self::assertSame('input_node_limit_exceeded', $document['scenarios'][0]['correctness']['reason']);
    }

    #[Test]
    public function responseFitsBothCaps(): void
    {
        [$code, $stdout, $stderr] = $this->runResource('response-limit');
        self::assertSame(0, $code, $stderr);
        $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertLessThanOrEqual(101, $document['scenarios'][0]['correctness']['retained_messages']);
        self::assertLessThanOrEqual(8192, $document['scenarios'][0]['response_bytes']);
    }

    #[Test]
    public function coldWarmHaveSeparateLifecycle(): void
    {
        [$code, $stdout, $stderr] = $this->runResource('cold-warm');
        self::assertSame(0, $code, $stderr);
        $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['cold-first-map', 'warm-reuse'], array_column($document['scenarios'], 'lifecycle'));
    }

    #[Test]
    public function malformedCliFailsWithoutJson(): void
    {
        [$code, $stdout, $stderr] = $this->runResource('unknown');
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Usage:', $stderr);
    }

    #[Test]
    public function measuredEnvironmentComesFromWorkerRatherThanParentFlags(): void
    {
        if (! extension_loaded('Zend OPcache')) {
            self::markTestSkipped('OPcache is needed to exercise differing parent and child configuration');
        }
        [$code, $stdout, $stderr] = $this->runResource('nested');
        self::assertSame(0, $code, $stderr);
        $expected                 = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['environment'];
        $opcache                  = (bool) $expected['opcache_cli'] ? '0' : '1';
        [$code, $stdout, $stderr] = $this->runResource('nested', ['-d', 'opcache.enable_cli=' . $opcache, '-d', 'pcov.enabled=0']);
        self::assertSame(0, $code, $stderr);
        $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $document['environment']);
    }

    #[Test]
    public function eachWorkerRecordsMatchingActualEnvironmentAndProvenance(): void
    {
        [$code, $stdout, $stderr] = $this->runResource('cold-warm');
        self::assertSame(0, $code, $stderr);
        $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        foreach ($document['scenarios'] as $result) {
            self::assertSame($document['environment'], $result['environment'] ?? null);
            self::assertSame($document['provenance'], $result['provenance'] ?? null);
        }
    }

    /**
     * @param list<string> $phpArguments
     *
     * @return array{int, string, string}
     */
    private function runResource(string $scenario, array $phpArguments = []): array
    {
        $command = [PHP_BINARY, ...$phpArguments, __DIR__ . '/../../benchmarks/resource-mapper.php', '--scenario=' . $scenario,
            '--iterations=1', '--warmup=0', '--samples=2'];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}

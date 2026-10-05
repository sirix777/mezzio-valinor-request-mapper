<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Benchmark;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function fclose;
use function file_put_contents;
use function json_encode;
use function proc_close;
use function proc_open;
use function str_repeat;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

final class BenchmarkAggregateTest extends TestCase
{
    #[Test]
    public function rejectsTamperedSummaryBeforeStdout(): void
    {
        foreach (['min_us_per_op', 'median_us_per_op', 'max_us_per_op'] as $metric) {
            $document = $this->document();
            $document['batches'][3]['result']['scenarios'][0][$metric] += 0.002;
            [$status, $stdout, $stderr] = $this->aggregate($document);

            self::assertSame(1, $status, $metric);
            self::assertSame('', $stdout, $metric);
            self::assertStringContainsString($metric, $stderr);
        }
    }

    #[Test]
    public function allowsRoundingTolerance(): void
    {
        $document = $this->document();

        foreach ($document['batches'] as &$batch) {
            foreach (['min_us_per_op', 'median_us_per_op', 'max_us_per_op'] as $metric) {
                $batch['result']['scenarios'][0][$metric] += 0.001;
            }
        }

        [$status, $stdout, $stderr] = $this->aggregate($document);

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('| Fixture | 3.001 | 3.001 | +0.0% |', $stdout);
        self::assertSame('', $stderr);
    }

    #[Test]
    public function readsArchivedSchemaOne(): void
    {
        [$status, $stdout, $stderr] = $this->runProcess([
            PHP_BINARY,
            $this->script(),
            dirname(__DIR__, 2) . '/docs/benchmarks/raw/compare-048b558-6d1419f-20260912-154933.json',
        ]);

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('| Scenario | Baseline', $stdout);
        self::assertStringContainsString('Reflection via direct handler object (request-path check)', $stdout);
        self::assertStringContainsString('Reflection via lazy FQCN handler (request-path check)', $stdout);
    }

    #[Test]
    public function schemaOneUsesHistoricalUpperEvenMedian(): void
    {
        [$status, $stdout, $stderr] = $this->aggregate($this->document());

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('| Fixture | 3.000 | 3.000 | +0.0% |', $stdout);

        $document                                                             = $this->document();
        $document['batches'][0]['result']['scenarios'][0]['median_us_per_op'] = 2.5;
        [$status, $stdout]                                                    = $this->aggregate($document);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
    }

    #[Test]
    public function schemaTwoUsesStandardEvenMedian(): void
    {
        $document                   = $this->controlOverrideDocument();
        [$status, $stdout, $stderr] = $this->aggregate($document);

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('| Scenario 1 | 2.500 | 2.500 | +0.0% |', $stdout);

        $document['batches'][0]['result']['scenarios'][0]['median_us_per_op'] = 3;
        [$status, $stdout]                                                    = $this->aggregate($document);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
    }

    #[Test]
    public function revisionSummaryUsesStandardMedianOfBatchMedians(): void
    {
        $document = $this->document();

        foreach ($document['batches'] as $index => &$batch) {
            $value                                                = [1, 2, 4, 3][$index];
            $batch['result']['scenarios'][0]['samples_us_per_op'] = [$value, $value, $value, $value];

            foreach (['min_us_per_op', 'median_us_per_op', 'max_us_per_op'] as $metric) {
                $batch['result']['scenarios'][0][$metric] = $value;
            }
        }

        [$status, $stdout, $stderr] = $this->aggregate($document);

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('| Fixture | 2.000 | 3.000 | +50.0% |', $stdout);
    }

    #[Test]
    public function missingCliArgumentsPrintUsage(): void
    {
        [$status, $stdout, $stderr] = $this->runProcess([PHP_BINARY, $this->script()]);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Usage:', $stderr);
        self::assertStringNotContainsString('Warning', $stderr);
    }

    #[Test]
    public function missingServerArgvPrintsUsageWithoutWarnings(): void
    {
        [$status, $stdout, $stderr] = $this->runProcess([
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            '-r',
            'unset($_SERVER["argv"]); require ' . var_export($this->script(), true) . '; exit(aggregateMain($_SERVER["argv"] ?? []));',
        ]);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Usage:', $stderr);
        self::assertStringNotContainsString('Warning', $stderr);
    }

    #[Test]
    public function requiringAggregatorDoesNotExecuteMain(): void
    {
        [$status, $stdout, $stderr] = $this->runProcess([
            PHP_BINARY,
            '-r',
            'require ' . var_export($this->script(), true) . ';',
        ]);

        self::assertSame(0, $status, $stderr);
        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
    }

    #[Test]
    public function unknownSchemaFailsBeforeStdout(): void
    {
        $document                   = $this->document();
        $document['schema']         = 'sirix-mezzio-valinor-compare/999';
        [$status, $stdout, $stderr] = $this->aggregate($document);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unknown schema', $stderr);
    }

    #[Test]
    public function malformedSamplesFailBeforeStdout(): void
    {
        foreach ([[], [1, 2, 3, 0], [1, 2, 3, '4'], [1, 2, 3, null]] as $samples) {
            $document                                                              = $this->document();
            $document['batches'][3]['result']['scenarios'][0]['samples_us_per_op'] = $samples;
            [$status, $stdout, $stderr]                                            = $this->aggregate($document);

            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString('Invalid raw document:', $stderr);
        }
    }

    #[Test]
    public function matchedRouteWithoutMappingsIsClassifiedAsControl(): void
    {
        $document = $this->document();

        foreach ($document['batches'] as &$batch) {
            $batch['result']['scenarios'][0]['name'] = 'Matched route without mappings';
        }

        [$status, $stdout, $stderr] = $this->aggregate($document);

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('Matched route without mappings (control)', $stdout);
    }

    #[Test]
    public function acceptsRecordedControlIterationOverride(): void
    {
        [$status, $stdout, $stderr] = $this->aggregate($this->controlOverrideDocument());

        self::assertSame(0, $status, $stderr);
        self::assertStringContainsString('| Scenario 1 | 2.500 | 2.500 | +0.0% |', $stdout);
        self::assertStringContainsString('| Scenario 2 | 2.500 | 2.500 | +0.0% |', $stdout);
        self::assertStringContainsString('| Scenario 15 | 2.500 | 2.500 | +0.0% |', $stdout);
        self::assertSame('', $stderr);
    }

    #[Test]
    public function rejectsTamperedWorkerCountsBeforeStdout(): void
    {
        foreach ([0, 1, 2] as $scenarioIndex) {
            foreach (['iterations', 'warmup', 'samples'] as $parameter) {
                $document = $this->controlOverrideDocument();
                ++$document['batches'][3]['result']['scenarios'][$scenarioIndex]['params'][$parameter];
                [$status, $stdout, $stderr] = $this->aggregate($document);

                self::assertSame(1, $status, "Scenario {$scenarioIndex} {$parameter}");
                self::assertSame('', $stdout);
                self::assertStringContainsString($parameter, $stderr);
            }
        }
    }

    #[Test]
    public function rejectsInvalidControlIterationsBeforeStdout(): void
    {
        foreach ([null, 0, -1, 1000001, '1000', 1000.5] as $iterations) {
            $document                                 = $this->controlOverrideDocument();
            $document['params']['control_iterations'] = $iterations;
            [$status, $stdout, $stderr]               = $this->aggregate($document);

            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString('control_iterations', $stderr);
        }
    }

    #[Test]
    public function rejectsOmittedControlConfigurationBeforeStdout(): void
    {
        foreach (['batch', 'scenario_id', 'params'] as $manifestSource) {
            $document = $this->controlOverrideDocument();
            unset($document['params']['control_iterations']);

            foreach ($document['batches'] as &$batch) {
                if ('batch' !== $manifestSource) {
                    unset($batch['result']['params']['control_iterations']);
                }

                foreach ($batch['result']['scenarios'] as &$scenario) {
                    if ('scenario_id' !== $manifestSource) {
                        unset($scenario['scenario_id']);
                    }

                    if ('params' !== $manifestSource) {
                        unset($scenario['params']);
                    }
                }
            }

            [$status, $stdout, $stderr] = $this->aggregate($document);

            self::assertSame(1, $status, $manifestSource);
            self::assertSame('', $stdout);
            self::assertStringContainsString('control_iterations', $stderr);
        }
    }

    #[Test]
    public function rejectsSchemaTwoWithAllCountMarkersStrippedBeforeStdout(): void
    {
        $document = $this->controlOverrideDocument();
        unset($document['params']['control_iterations']);

        foreach ($document['batches'] as &$batch) {
            unset($batch['result']['params']['control_iterations']);

            foreach ($batch['result']['scenarios'] as &$scenario) {
                unset($scenario['scenario_id'], $scenario['params']);
            }
        }

        $document['batches'][3]['result']['params']['iterations'] = 1;
        [$status, $stdout, $stderr]                               = $this->aggregate($document);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertStringContainsString('control_iterations', $stderr);
    }

    #[Test]
    public function rejectsIncompleteExperimentalSchemaTwoBeforeStdout(): void
    {
        foreach (['085349', '090730'] as $time) {
            [$status, $stdout, $stderr] = $this->runProcess([
                PHP_BINARY,
                $this->script(),
                dirname(__DIR__, 2) . "/docs/benchmarks/raw/compare-e581e21-f7dd99f-20261005-{$time}.json",
            ]);

            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString('control_iterations', $stderr);
        }
    }

    #[Test]
    public function rejectsUnequalBatchParametersBeforeStdout(): void
    {
        foreach (['iterations', 'control_iterations', 'warmup', 'samples'] as $parameter) {
            $document = $this->controlOverrideDocument();
            ++$document['batches'][3]['result']['params'][$parameter];
            [$status, $stdout, $stderr] = $this->aggregate($document);

            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString($parameter, $stderr);
        }

        $document = $this->controlOverrideDocument();
        unset($document['batches'][3]['result']['params']);
        [$status, $stdout] = $this->aggregate($document);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
    }

    #[Test]
    public function rejectsInvalidOrInconsistentScenarioIdsBeforeStdout(): void
    {
        foreach ([null, 0, 16, '2', 1, 3] as $id) {
            $document                                                        = $this->controlOverrideDocument();
            $document['batches'][3]['result']['scenarios'][1]['scenario_id'] = $id;
            [$status, $stdout, $stderr]                                      = $this->aggregate($document);

            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString('scenario', $stderr);
        }

        $document                           = $this->controlOverrideDocument();
        $document['params']['scenario_ids'] = [1, 15, 2];
        [$status, $stdout]                  = $this->aggregate($document);

        self::assertSame(1, $status);
        self::assertSame('', $stdout);
    }

    #[Test]
    public function rejectsEffectiveParameterBoundsBeforeStdout(): void
    {
        foreach ([
            'iterations' => 1000001,
            'warmup'     => 100001,
            'samples'    => 1001,
        ] as $parameter => $value) {
            $document                       = $this->controlOverrideDocument();
            $document['params'][$parameter] = $value;
            [$status, $stdout, $stderr]     = $this->aggregate($document);

            self::assertSame(1, $status);
            self::assertSame('', $stdout);
            self::assertStringContainsString($parameter, $stderr);
        }
    }

    private function script(): string
    {
        return dirname(__DIR__, 2) . '/benchmarks/aggregate.php';
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $baseline  = str_repeat('a', 40);
        $optimized = str_repeat('b', 40);
        $labels    = ['baseline', 'optimized', 'optimized', 'baseline'];
        $batches   = [];

        foreach ($labels as $index => $label) {
            $batches[] = [
                'batch'    => $index + 1,
                'label'    => $label,
                'revision' => 'baseline' === $label ? $baseline : $optimized,
                'result'   => [
                    'scenarios' => [[
                        'name'              => 'Fixture',
                        'median_us_per_op'  => 3.0,
                        'min_us_per_op'     => 1.0,
                        'max_us_per_op'     => 4.0,
                        'peak_memory_bytes' => 1024,
                        'samples_us_per_op' => [1, 2, 3, 4],
                    ]],
                ],
            ];
        }

        return [
            'schema'    => 'sirix-mezzio-valinor-compare/1',
            'baseline'  => $baseline,
            'optimized' => $optimized,
            'params'    => [
                'batches_per_revision' => 2,
                'iterations'           => 1,
                'warmup'               => 0,
                'samples'              => 4,
                'order'                => [$baseline, $optimized, $optimized, $baseline],
            ],
            'batches'   => $batches,
        ];
    }

    /** @return array<string, mixed> */
    private function controlOverrideDocument(): array
    {
        $document                                 = $this->document();
        $document['schema']                       = 'sirix-mezzio-valinor-compare/2';
        $document['params']['iterations']         = 100;
        $document['params']['control_iterations'] = 1000;
        $document['params']['warmup']             = 10;
        $document['params']['scenario_ids']       = [1, 2, 15];

        foreach ($document['batches'] as &$batch) {
            $scenario                  = $batch['result']['scenarios'][0];
            $batch['result']['params'] = [
                'iterations'         => 100,
                'control_iterations' => 1000,
                'warmup'             => 10,
                'samples'            => 4,
            ];
            $batch['result']['scenarios'] = [];

            foreach ([1, 2, 15] as $id) {
                $batch['result']['scenarios'][] = [
                    ...$scenario,
                    'name'             => "Scenario {$id}",
                    'median_us_per_op' => 2.5,
                    'scenario_id'      => $id,
                    'params'           => [
                        'iterations' => 2 === $id ? 100 : 1000,
                        'warmup'     => 10,
                        'samples'    => 4,
                    ],
                ];
            }
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array{int, string, string}
     */
    private function aggregate(array $document): array
    {
        $path = tempnam(sys_get_temp_dir(), 'benchmark-aggregate-');
        self::assertNotFalse($path);

        try {
            file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));

            return $this->runProcess([PHP_BINARY, $this->script(), $path]);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function runProcess(array $command): array
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
        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return [proc_close($process), $stdout, $stderr];
    }
}

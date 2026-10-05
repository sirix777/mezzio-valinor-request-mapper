<?php

declare(strict_types=1);

/**
 * Aggregates a compare.php raw evidence document into the documentation table.
 *
 * Reads the raw JSON produced by benchmarks/compare.php, validates its full
 * schema fail-closed, and prints the observed-effect table using the standard
 * median (average of the two central values for an even sample count) of
 * batch medians per revision. Nothing is written to STDOUT before validation
 * has fully succeeded, so no partial table can be produced from malformed
 * input.
 *
 * Usage: php benchmarks/aggregate.php <raw-json>
 */
/**
 * Standard median: for an even number of values the average of the two
 * central values, for an odd count the central value.
 *
 * @param list<float> $values
 */
function median(array $values): float
{
    \sort($values);
    $count  = \count($values);
    $middle = \intdiv($count, 2);

    if (0 !== $count % 2) {
        return (float) $values[$middle];
    }

    return ($values[$middle - 1] + $values[$middle]) / 2;
}

/**
 * Validates the raw document fail-closed before any output is produced.
 *
 * @param array<mixed> $document
 */
function validateDocument(array $document): void
{
    foreach (['schema', 'baseline', 'optimized', 'params', 'batches'] as $key) {
        if (! \array_key_exists($key, $document)) {
            \fail("Document is missing {$key}");
        }
    }

    if (! \is_string($document['schema'])) {
        \fail('Document schema is not a string');
    }

    if (! \in_array($document['schema'], ['sirix-mezzio-valinor-compare/1', 'sirix-mezzio-valinor-compare/2'], true)) {
        \fail("Unknown schema: {$document['schema']}");
    }

    foreach (['baseline', 'optimized'] as $key) {
        if (! \is_string($document[$key]) || 1 !== \preg_match('/^[0-9a-f]{40}$/', $document[$key])) {
            \fail("Document has an invalid {$key} revision");
        }
    }

    if (! \is_array($document['params'])) {
        \fail('Document params is not an object');
    }

    foreach (['batches_per_revision', 'iterations', 'samples'] as $key) {
        if (! \is_int($document['params'][$key] ?? null) || $document['params'][$key] < 1) {
            \fail("Document params is missing positive integer {$key}");
        }
    }

    if (! \is_int($document['params']['warmup'] ?? null) || $document['params']['warmup'] < 0) {
        \fail('Document params is missing non-negative integer warmup');
    }

    if (! \is_array($document['batches']) || ! \array_is_list($document['batches'])) {
        \fail('Document batches is not a list');
    }

    $schemaTwo = 'sirix-mezzio-valinor-compare/2' === $document['schema'];

    if ($schemaTwo) {
        foreach ([
            'iterations'         => [1, 1000000],
            'control_iterations' => [1, 1000000],
            'warmup'             => [0, 100000],
            'samples'            => [1, 1000],
        ] as $key => [$minimum, $maximum]) {
            $value = $document['params'][$key] ?? null;

            if (! \is_int($value) || $value < $minimum || $value > $maximum) {
                \fail("Document params has an invalid {$key}");
            }
        }

        $scenarioIds = $document['params']['scenario_ids'] ?? null;

        if (! \is_array($scenarioIds) || ! \array_is_list($scenarioIds) || [] === $scenarioIds) {
            \fail('Document params has no scenario_ids list');
        }

        foreach ($scenarioIds as $id) {
            if (! \is_int($id) || $id < 1 || $id > 15) {
                \fail('Document params has an invalid scenario_id');
            }
        }

        if (\count(\array_unique($scenarioIds)) !== \count($scenarioIds)) {
            \fail('Document params has duplicate scenario_ids');
        }
    }

    if (! \is_array($document['params']['order'] ?? null)
        || ! \array_is_list($document['params']['order'])
        || \count($document['params']['order']) !== $document['params']['batches_per_revision'] * 2) {
        \fail('Document params is missing an order list matching the batch count');
    }

    foreach ($document['params']['order'] as $revision) {
        if (! \is_string($revision) || ($revision !== $document['baseline'] && $revision !== $document['optimized'])) {
            \fail('Document params order contains an unknown revision');
        }
    }

    $expectedRevisionBalance = $document['params']['batches_per_revision'] * 2;
    $labels                  = [];
    $referenceScenarioNames  = null;

    if (\count($document['batches']) !== $expectedRevisionBalance) {
        \fail(\sprintf('Document has %d batches, expected %d (batches_per_revision × 2)', \count($document['batches']), $expectedRevisionBalance));
    }

    $labelsByIndex = [];

    foreach ($document['batches'] as $batchIndex => $batch) {
        if (! \is_array($batch)) {
            \fail("Batch {$batchIndex} is not an object");
        }

        foreach (['batch', 'label', 'revision', 'result'] as $key) {
            if (! \array_key_exists($key, $batch)) {
                \fail("Batch {$batchIndex} is missing {$key}");
            }
        }

        if ($batch['batch'] !== $batchIndex + 1) {
            \fail("Batch {$batchIndex} has an out-of-order batch number");
        }

        if (! \is_string($batch['label']) || ! \in_array($batch['label'], ['baseline', 'optimized'], true)) {
            \fail("Batch {$batchIndex} has an invalid label");
        }

        $expectedRevision = 'baseline' === $batch['label'] ? $document['baseline'] : $document['optimized'];

        if ($batch['revision'] !== $expectedRevision) {
            \fail("Batch {$batchIndex} revision does not match its label");
        }

        $labelsByIndex[]         = $batch['label'];
        $labels[$batch['label']] = ($labels[$batch['label']] ?? 0) + 1;

        if (! \is_array($batch['result']) || ! \is_array($batch['result']['scenarios'] ?? null)
            || ! \array_is_list($batch['result']['scenarios'])) {
            \fail("Batch {$batchIndex} has no scenario list");
        }

        $scenarios = $batch['result']['scenarios'];

        if ($schemaTwo) {
            $batchParams = $batch['result']['params'] ?? null;

            if (! \is_array($batchParams)) {
                \fail("Batch {$batchIndex} has no effective params");
            }

            foreach (['iterations', 'control_iterations', 'warmup', 'samples'] as $key) {
                if (($batchParams[$key] ?? null) !== $document['params'][$key]) {
                    \fail("Batch {$batchIndex} has inconsistent {$key}");
                }
            }

            if (\count($scenarios) !== \count($document['params']['scenario_ids'])) {
                \fail("Batch {$batchIndex} scenario_ids differ from document params");
            }
        }

        if (null === $referenceScenarioNames) {
            $referenceScenarioNames = \array_column($scenarios, 'name');

            if ([] === $referenceScenarioNames) {
                \fail("Batch {$batchIndex} has no scenarios");
            }
        } else {
            $names = \array_column($scenarios, 'name');

            if (\count($names) !== \count($referenceScenarioNames)
                || $names !== $referenceScenarioNames) {
                \fail("Batch {$batchIndex} scenarios differ in order or names from the first batch");
            }
        }

        foreach ($scenarios as $scenarioIndex => $scenario) {
            if (! \is_array($scenario)) {
                \fail("Batch {$batchIndex} scenario {$scenarioIndex} is not an object");
            }

            if (! \is_string($scenario['name'] ?? null)) {
                \fail("Batch {$batchIndex} scenario {$scenarioIndex} is missing a name");
            }

            if ($schemaTwo) {
                $id = $scenario['scenario_id'] ?? null;

                if ($id !== $document['params']['scenario_ids'][$scenarioIndex]) {
                    \fail("Batch {$batchIndex} scenario {$scenarioIndex} has inconsistent scenario_id");
                }

                $scenarioParams = $scenario['params'] ?? null;

                if (! \is_array($scenarioParams)) {
                    \fail("Batch {$batchIndex} scenario {$scenarioIndex} has no worker params");
                }

                $expectedParams = [
                    'iterations' => \in_array($id, [1, 15], true)
                        ? $document['params']['control_iterations']
                        : $document['params']['iterations'],
                    'warmup'     => $document['params']['warmup'],
                    'samples'    => $document['params']['samples'],
                ];

                foreach ($expectedParams as $key => $value) {
                    if (($scenarioParams[$key] ?? null) !== $value) {
                        \fail("Batch {$batchIndex} scenario {$scenarioIndex} has inconsistent worker {$key}");
                    }
                }
            }

            \validateScenarioMetrics(
                $batchIndex,
                $scenarioIndex,
                $scenario,
                $document['params']['samples'],
                'sirix-mezzio-valinor-compare/1' === $document['schema'],
            );
        }
    }

    // The orchestrator only emits symmetric A-B-B-A groups: batch i mirrors
    // batch n-1-i, and every A-B-B-A group contains two batches per revision.
    $count = \count($labelsByIndex);

    for ($i = 0; $i < $count; $i += 4) {
        $group = \array_slice($labelsByIndex, $i, 4);

        if (4 !== \count($group) || 'baseline' !== $group[0] || $group !== ['baseline', 'optimized', 'optimized', 'baseline']) {
            \fail('Batch order is not A-B-B-A interleaving');
        }
    }

    // The params.order list must agree with the actual batch order.
    foreach ($document['params']['order'] as $orderIndex => $revision) {
        $expectedLabel = $revision === $document['baseline'] ? 'baseline' : 'optimized';

        if ($expectedLabel !== $labelsByIndex[$orderIndex]) {
            \fail('Document params order does not match the actual batch labels');
        }
    }
}

/**
 * @param array<mixed> $scenario
 */
function validateScenarioMetrics(
    int $batchIndex,
    int $scenarioIndex,
    array $scenario,
    int $expectedSamples,
    bool $legacyMedian
): void {
    foreach (['median_us_per_op', 'min_us_per_op', 'max_us_per_op'] as $key) {
        $value = $scenario[$key] ?? null;

        if (! \is_int($value) && ! \is_float($value)
            || ! \is_finite((float) $value)
            || (float) $value <= 0.0) {
            \fail("Batch {$batchIndex} scenario {$scenarioIndex} has an invalid {$key}");
        }
    }

    if (! \is_int($scenario['peak_memory_bytes'] ?? null) || $scenario['peak_memory_bytes'] <= 0) {
        \fail("Batch {$batchIndex} scenario {$scenarioIndex} has an invalid peak_memory_bytes");
    }

    if (! \is_array($scenario['samples_us_per_op'] ?? null) || ! \array_is_list($scenario['samples_us_per_op'])) {
        \fail("Batch {$batchIndex} scenario {$scenarioIndex} has no sample list");
    }

    $samples = $scenario['samples_us_per_op'];

    if (\count($samples) !== $expectedSamples) {
        \fail(\sprintf(
            'Batch %d scenario %d has %d samples, expected %d',
            $batchIndex,
            $scenarioIndex,
            \count($samples),
            $expectedSamples,
        ));
    }

    foreach ($samples as $sample) {
        if (! \is_int($sample) && ! \is_float($sample) || ! \is_finite((float) $sample) || (float) $sample <= 0.0) {
            \fail("Batch {$batchIndex} scenario {$scenarioIndex} has an invalid sample value");
        }
    }

    \sort($samples);
    $derived = [
        'min_us_per_op'    => $samples[0],
        'median_us_per_op' => $legacyMedian ? $samples[\intdiv(\count($samples), 2)] : \median($samples),
        'max_us_per_op'    => $samples[\count($samples) - 1],
    ];

    foreach ($derived as $key => $expected) {
        // Published samples and summaries each round to three decimals.
        $tolerance = 0.001 + PHP_FLOAT_EPSILON * \max(\abs((float) $scenario[$key]), \abs((float) $expected)) * 4;

        if (! \is_finite((float) $expected) || \abs($scenario[$key] - $expected) > $tolerance) {
            \fail("Batch {$batchIndex} scenario {$scenarioIndex} has inconsistent {$key}");
        }
    }
}

/**
 * @param non-empty-string $message
 */
function fail(string $message): void
{
    \fwrite(STDERR, "Invalid raw document: {$message}\n");

    exit(1);
}

/** @param list<string> $arguments */
function aggregateMain(array $arguments): int
{
    if (! \is_string($arguments[1] ?? null) || '' === $arguments[1]) {
        \fwrite(STDERR, "Usage: php benchmarks/aggregate.php <raw-json>\n");

        return 1;
    }

    $path = $arguments[1];

    if (! \is_file($path) || ! \is_readable($path)) {
        \fwrite(STDERR, "Raw file not found or unreadable: {$path}\n");

        return 1;
    }

    $document = \json_decode((string) \file_get_contents($path), true);

    if (! \is_array($document)) {
        \fwrite(STDERR, "Raw file does not contain a JSON object: {$path}\n");

        return 1;
    }

    \validateDocument($document);

    $medByLabel = [];

    foreach ($document['batches'] as $batch) {
        foreach ($batch['result']['scenarios'] as $index => $scenario) {
            $medByLabel[$index][$batch['label']][] = $scenario['median_us_per_op'];
            $medByLabel[$index]['name']            = $scenario['name'];
        }
    }

    $scenarioKinds = [
        'No RouteResult passthrough'                                     => 'control',
        'Matched route without mappings'                                 => 'control',
        'Reflection via direct handler object'                           => 'request-path check',
        'Reflection via lazy FQCN handler'                               => 'request-path check',
        'Route options single DTO'                                       => 'request-path check',
        'Three operations with different outputs'                        => 'request-path check',
        'Repeated calls reusing middleware and builder'                  => 'request-path check',
        'New middleware/resolvers/builder per iteration with file cache' => 'request-path check',
    ];

    $lines = [];

    foreach ($medByLabel as $values) {
        $name        = $values['name'];
        $annotation  = $scenarioKinds[$name] ?? null;
        $displayName = null === $annotation ? $name : "{$name} ({$annotation})";

        $b = \median($values['baseline']);

        if ($b <= 0.0 || ! \is_finite($b)) {
            \fail("Scenario {$name} has a non-positive baseline median");
        }

        $o     = \median($values['optimized']);
        $delta = ($o - $b) / $b * 100;

        $lines[] = \sprintf(
            '| %s | %s | %s | %+.1f%% |',
            $displayName,
            \number_format($b, 3, '.', ','),
            \number_format($o, 3, '.', ','),
            $delta,
        );
    }

    // Only now that every row has been computed safely the table goes to STDOUT.
    echo "| Scenario | Baseline µs/op | Optimized µs/op | Delta |\n";
    echo "| --- | ---: | ---: | ---: |\n";

    foreach ($lines as $line) {
        echo $line, "\n";
    }

    return 0;
}

if (__FILE__ === \realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(\aggregateMain($_SERVER['argv'] ?? []));
}

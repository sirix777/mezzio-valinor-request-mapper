<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function implode;
use function sprintf;

/**
 * Regression: budget checks must not copy the whole branch up front.
 *
 * The validator previously wrapped each branch in a fresh ArrayIterator,
 * which eagerly copied the entire array before the first max_nodes check.
 * A wide root array then exhausted memory before any limit could reject it.
 */
final class InputLimitsMemoryTest extends TestCase
{
    #[Test]
    public function wideRootArrayIsRejectedWithoutExhaustingMemory(): void
    {
        $output = $this->runProbe(elements: 1_000_000, maxNodes: 1, memoryLimit: '28M');

        self::assertSame('REJECTED:input_node_limit_exceeded', $output);
    }

    #[Test]
    public function nestedWideArrayIsRejectedWithoutExhaustingMemory(): void
    {
        $output = $this->runProbe(elements: 1_000_000, maxNodes: 5, memoryLimit: '28M', shape: 'nested');

        self::assertSame('REJECTED:input_node_limit_exceeded', $output);
    }

    private function runProbe(int $elements, int $maxNodes, string $memoryLimit, string $shape = 'root'): string
    {
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            '-d',
            escapeshellarg("memory_limit={$memoryLimit}"),
            escapeshellarg(__DIR__ . '/Fixture/wide-input-probe.php'),
            escapeshellarg((string) $elements),
            escapeshellarg((string) $maxNodes),
            escapeshellarg($shape),
        ]);

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, sprintf('Probe failed (exit %d): %s', $exitCode, implode("\n", $output)));

        return implode("\n", $output);
    }
}

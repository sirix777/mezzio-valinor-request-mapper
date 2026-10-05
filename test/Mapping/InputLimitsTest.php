<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Mapping\InputLimits;

final class InputLimitsTest extends TestCase
{
    #[Test]
    public function defaultLimitsAreDisabled(): void
    {
        $limits = new InputLimits();

        self::assertFalse($limits->isEnabled());
        self::assertNull($limits->maxNodes);
        self::assertNull($limits->maxDepth);
        self::assertNull($limits->maxTotalStringBytes);
    }

    /** @param array<string, int> $config */
    #[Test]
    #[DataProvider('enablingLimitConfigs')]
    public function oneLimitEnablesBudget(array $config): void
    {
        self::assertTrue(InputLimits::fromArray($config)->isEnabled());
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function enablingLimitConfigs(): iterable
    {
        yield 'nodes'        => [[
            'max_nodes' => 1,
        ]];

        yield 'depth'        => [[
            'max_depth' => 1,
        ]];

        yield 'string bytes' => [[
            'max_total_string_bytes' => 1,
        ]];
    }

    #[Test]
    public function fromArrayReadsValues(): void
    {
        $limits = InputLimits::fromArray([
            'max_nodes'              => 3,
            'max_depth'              => 4,
            'max_total_string_bytes' => 5,
        ]);

        self::assertSame(3, $limits->maxNodes);
        self::assertSame(4, $limits->maxDepth);
        self::assertSame(5, $limits->maxTotalStringBytes);
    }

    #[Test]
    public function explicitNullKeepsLimitDisabled(): void
    {
        $limits = InputLimits::fromArray([
            'max_nodes'              => null,
            'max_depth'              => null,
            'max_total_string_bytes' => null,
        ]);

        self::assertFalse($limits->isEnabled());
    }

    #[Test]
    #[DataProvider('invalidLimitValues')]
    public function rejectsInvalidLimitValues(mixed $value): void
    {
        foreach (['max_nodes', 'max_depth', 'max_total_string_bytes'] as $key) {
            try {
                InputLimits::fromArray([
                    $key => $value,
                ]);
                self::fail("Expected InvalidMapRequestConfiguration for {$key}.");
            } catch (InvalidMapRequestConfiguration $exception) {
                self::assertStringContainsString(
                    "sirix_mezzio_valinor.input_limits.{$key}",
                    $exception->getMessage(),
                );
            }
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidLimitValues(): iterable
    {
        yield 'zero'          => [0];

        yield 'negative'      => [-1];

        yield 'bool'          => [true];

        yield 'numeric string' => ['10'];

        yield 'float'         => [1.5];
    }

    #[Test]
    public function rejectsUnknownKeys(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('sirix_mezzio_valinor.input_limits.unknown');

        InputLimits::fromArray([
            'unknown' => 1,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Error;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Valinor\Error\ErrorResponseOptions;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;

final class ErrorResponseOptionsTest extends TestCase
{
    #[Test]
    public function defaultsAreUnlimited(): void
    {
        $options = new ErrorResponseOptions();

        self::assertNull($options->maxMessages);
        self::assertNull($options->maxResponseBytes);
    }

    #[Test]
    public function fromArrayReadsPositiveValues(): void
    {
        $options = ErrorResponseOptions::fromArray([
            'max_messages'       => 2,
            'max_response_bytes' => 256,
        ]);

        self::assertSame(2, $options->maxMessages);
        self::assertSame(256, $options->maxResponseBytes);
    }

    #[Test]
    public function fromEmptyArrayKeepsDefaults(): void
    {
        $options = ErrorResponseOptions::fromArray([]);

        self::assertNull($options->maxMessages);
        self::assertNull($options->maxResponseBytes);
    }

    #[Test]
    #[DataProvider('invalidMaxMessagesProvider')]
    public function rejectsInvalidMaxMessages(mixed $value, string $path): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage($path);

        ErrorResponseOptions::fromArray([
            'max_messages' => $value,
        ]);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function invalidMaxMessagesProvider(): iterable
    {
        yield 'zero'        => [0, 'sirix_mezzio_valinor.error_response.max_messages'];

        yield 'negative'    => [-1, 'sirix_mezzio_valinor.error_response.max_messages'];

        yield 'bool'        => [true, 'sirix_mezzio_valinor.error_response.max_messages'];

        yield 'numeric int' => ['2', 'sirix_mezzio_valinor.error_response.max_messages'];

        yield 'float'       => [2.5, 'sirix_mezzio_valinor.error_response.max_messages'];
    }

    #[Test]
    #[DataProvider('invalidMaxResponseBytesProvider')]
    public function rejectsInvalidMaxResponseBytes(mixed $value): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('sirix_mezzio_valinor.error_response.max_response_bytes');

        ErrorResponseOptions::fromArray([
            'max_response_bytes' => $value,
        ]);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidMaxResponseBytesProvider(): iterable
    {
        yield 'zero'     => [0];

        yield 'negative' => [-1];

        yield 'below min' => [255];

        yield 'bool'     => [true];

        yield 'numeric string' => ['256'];
    }

    #[Test]
    public function acceptsMinimumByteCap(): void
    {
        self::assertSame(256, ErrorResponseOptions::fromArray([
            'max_response_bytes' => 256,
        ])->maxResponseBytes);
    }

    #[Test]
    public function rejectsUnknownKeys(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('sirix_mezzio_valinor.error_response.unknown');

        ErrorResponseOptions::fromArray([
            'unknown' => 1,
        ]);
    }
}

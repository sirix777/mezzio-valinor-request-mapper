<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;

use function preg_match;

final class InputEncodingValidatorTest extends TestCase
{
    private InputEncodingValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InputEncodingValidator();
    }

    /** @param 'body'|'query'|'route' $inputSource */
    #[Test]
    #[DataProvider('validUtf8Values')]
    public function acceptsValidUtf8StringsAndScalars(mixed $value, string $inputSource): void
    {
        self::expectNotToPerformAssertions();

        $this->validator->assertValid([
            'key' => $value,
        ], $inputSource);
    }

    /**
     * @return iterable<string, array{mixed, 'body'|'query'|'route'}>
     */
    public static function validUtf8Values(): iterable
    {
        yield 'empty string'   => ['', 'body'];

        yield 'ascii string'   => ['hello', 'query'];

        yield 'cyrillic'       => ['привет', 'route'];

        yield 'emoji'          => ['👋🌍', 'body'];

        yield 'combining char' => ['é', 'query'];

        yield 'null'           => [null, 'body'];

        yield 'boolean'        => [true, 'query'];

        yield 'integer'        => [42, 'route'];

        yield 'float'          => [3.14, 'body'];
    }

    #[Test]
    public function rejectsInvalidUtf8StringValue(): void
    {
        $this->expectException(RequestInputError::class);
        $this->expectExceptionMessage('Request input contains invalid UTF-8.');

        $this->validator->assertValid([
            'page' => "\xB1\x31",
        ], 'query');
    }

    #[Test]
    public function rejectsInvalidUtf8StringKey(): void
    {
        try {
            $this->validator->assertValid([
                "\xB1\x31" => 'value',
            ], 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('invalid_utf8', $error->reason);
            self::assertSame('body', $error->inputSource);
        }
    }

    #[Test]
    public function errorContainsReasonAndSourceWithoutOriginalBytes(): void
    {
        try {
            $this->validator->assertValid([
                'page' => "\xB1\x31",
            ], 'route');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('invalid_utf8', $error->reason);
            self::assertSame('route', $error->inputSource);
            self::assertSame(0, preg_match('/\xB1/', $error->getMessage()));
            self::assertSame(0, preg_match('/page/', $error->getMessage()));
        }
    }

    #[Test]
    public function rejectsInvalidUtf8InDeeplyNestedValue(): void
    {
        $this->expectException(RequestInputError::class);

        $this->validator->assertValid([
            'level1' => [
                'level2' => [
                    'level3' => "\xB1\x31",
                ],
            ],
        ], 'body');
    }

    #[Test]
    public function rejectsInvalidUtf8InDeeplyNestedKey(): void
    {
        $this->expectException(RequestInputError::class);

        $this->validator->assertValid([
            'level1' => [
                "\xB1\x31" => 'value',
            ],
        ], 'query');
    }

    #[Test]
    public function ignoresObjectValuesWithoutCallingToString(): void
    {
        $object = new class {
            public function __toString(): string
            {
                throw new RuntimeException('__toString must not be called.');
            }
        };

        self::expectNotToPerformAssertions();

        $this->validator->assertValid([
            'object' => $object,
        ], 'body');
    }

    #[Test]
    public function handlesRepeatedArrayReferenceWithoutInfiniteLoop(): void
    {
        $shared = [
            'name' => 'Ada',
        ];
        $values = [
            'first'  => &$shared,
            'second' => &$shared,
        ];

        self::expectNotToPerformAssertions();

        $this->validator->assertValid($values, 'body');
    }

    #[Test]
    public function handlesCircularArrayReferenceWithoutInfiniteLoop(): void
    {
        $circular = [
            'value' => 'ok',
        ];
        $circular['self'] = &$circular;

        self::expectNotToPerformAssertions();

        $this->validator->assertValid($circular, 'body');
    }

    #[Test]
    public function detectsInvalidUtf8InsideSharedReferenceOnlyOnce(): void
    {
        $shared = [
            'name' => "\xB1\x31",
        ];
        $values = [
            'first'  => &$shared,
            'second' => &$shared,
        ];

        $callCount = 0;

        try {
            $this->validator->assertValid($values, 'query');
        } catch (RequestInputError) {
            ++$callCount;
        }

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function doesNotModifyThePassedArrayOrItsReferences(): void
    {
        $original = [
            'list' => ['a', 'b'],
        ];

        $this->validator->assertValid($original, 'body');

        self::assertSame([
            'list' => ['a', 'b'],
        ], $original);
    }
}

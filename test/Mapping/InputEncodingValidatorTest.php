<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\InputLimits;

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

    /**
     * @param array<mixed>           $values
     * @param 'body'|'query'|'route' $inputSource
     */
    #[Test]
    #[DataProvider('nodeBoundaryProvider')]
    public function nodeBoundaryIsInclusive(array $values, bool $accepted, string $inputSource): void
    {
        $validator = new InputEncodingValidator(new InputLimits(maxNodes: 2));

        if ($accepted) {
            self::expectNotToPerformAssertions();
            $validator->assertValid($values, $inputSource);

            return;
        }

        try {
            $validator->assertValid($values, $inputSource);
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('input_node_limit_exceeded', $error->reason);
            self::assertSame($inputSource, $error->inputSource);
            self::assertSame('Request input exceeds the node limit.', $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, bool, 'body'|'query'|'route'}>
     */
    public static function nodeBoundaryProvider(): iterable
    {
        foreach (['body', 'query', 'route'] as $source) {
            yield "at limit {$source}" => [[1, 2], true, $source];

            yield "over limit {$source}" => [[1, 2, 3], false, $source];
        }
    }

    /** @param array<mixed> $values */
    #[Test]
    #[DataProvider('depthBoundaryProvider')]
    public function emptyArrayCountsDepth(array $values, bool $accepted): void
    {
        $validator = new InputEncodingValidator(new InputLimits(maxDepth: 1));

        if ($accepted) {
            self::expectNotToPerformAssertions();
            $validator->assertValid($values, 'body');

            return;
        }

        try {
            $validator->assertValid($values, 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('input_depth_limit_exceeded', $error->reason);
            self::assertSame('body', $error->inputSource);
            self::assertSame('Request input exceeds the nesting depth limit.', $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, bool}>
     */
    public static function depthBoundaryProvider(): iterable
    {
        yield 'empty root at depth 1'  => [[], true];

        yield 'nested array at depth 2' => [[
            'x' => [],
        ], false];
    }

    #[Test]
    public function stringBytesIncludeKeys(): void
    {
        $accepted = new InputEncodingValidator(new InputLimits(maxTotalStringBytes: 5));
        $accepted->assertValid([
            'x' => 'éé',
        ], 'body');

        $rejected = new InputEncodingValidator(new InputLimits(maxTotalStringBytes: 5));

        try {
            $rejected->assertValid([
                'x' => 'ééa',
            ], 'query');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('input_string_bytes_limit_exceeded', $error->reason);
            self::assertSame('query', $error->inputSource);
            self::assertSame('Request input exceeds the string byte limit.', $error->getMessage());
        }
    }

    /**
     * @param array<mixed>           $values
     * @param 'body'|'query'|'route' $inputSource
     */
    #[Test]
    #[DataProvider('invalidUtf8OverBudgetProvider')]
    public function budgetPrecedesInvalidUtf8(array $values, string $inputSource): void
    {
        $validator = new InputEncodingValidator(new InputLimits(maxTotalStringBytes: 1));

        try {
            $validator->assertValid($values, $inputSource);
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('input_string_bytes_limit_exceeded', $error->reason);
            self::assertSame($inputSource, $error->inputSource);
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, 'body'|'query'|'route'}>
     */
    public static function invalidUtf8OverBudgetProvider(): iterable
    {
        // String key: the key itself already consumes the one-byte budget, so
        // the rejection happens before the invalid value is inspected.
        yield 'string key' => [[
            'name' => "\xB1\x31\xB1",
        ], 'route'];

        // Integer key: keys of other types do not consume string bytes, so the
        // invalid value is what exceeds the budget before UTF-8 validation.
        yield 'integer key' => [[
            0 => "\xB1\x31\xB1",
        ], 'body'];
    }

    #[Test]
    public function singleLimitDoesNotEnableOthers(): void
    {
        self::expectNotToPerformAssertions();

        $validator = new InputEncodingValidator(new InputLimits(maxNodes: 10));

        $validator->assertValid([
            'deep' => [
                'nested' => [
                    'array' => [
                        'with' => 'a very long string value that is not measured',
                    ],
                ],
            ],
        ], 'body');
    }

    #[Test]
    public function earlyNodeFailureDoesNotScanTheTail(): void
    {
        $validator = new InputEncodingValidator(new InputLimits(maxNodes: 1));

        try {
            $validator->assertValid(['ok', "\xB1\x31"], 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('input_node_limit_exceeded', $error->reason);
        }
    }

    #[Test]
    public function sharedSiblingReferencesCountEachOccurrence(): void
    {
        $shared = [
            'v' => 1,
        ];
        $values = [
            'a' => &$shared,
            'b' => &$shared,
        ];

        $accepted = new InputEncodingValidator(new InputLimits(maxNodes: 4));
        $accepted->assertValid($values, 'body');

        try {
            (new InputEncodingValidator(new InputLimits(maxNodes: 3)))->assertValid($values, 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('input_node_limit_exceeded', $error->reason);
        }
    }

    #[Test]
    public function sharedReferenceAtDeeperPathHonorsDepth(): void
    {
        $shared = [
            'v' => 1,
        ];
        $values = [
            'a' => &$shared,
        ];

        $accepted = new InputEncodingValidator(new InputLimits(maxDepth: 2));
        $accepted->assertValid($values, 'body');

        try {
            (new InputEncodingValidator(new InputLimits(maxDepth: 1)))->assertValid($values, 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('input_depth_limit_exceeded', $error->reason);
        }
    }

    #[Test]
    public function enabledLimitsRejectSelfCycles(): void
    {
        $circular = [
            'value' => 'ok',
        ];
        $circular['self'] = &$circular;

        try {
            (new InputEncodingValidator(new InputLimits(maxNodes: 100)))->assertValid($circular, 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('cyclic_input', $error->reason);
            self::assertSame('body', $error->inputSource);
            self::assertSame('Request input contains a circular array reference.', $error->getMessage());
        }
    }

    #[Test]
    public function enabledLimitsRejectMutualCycles(): void
    {
        $a      = [];
        $b      = [];
        $a['b'] = &$b;
        $b['a'] = &$a;

        try {
            (new InputEncodingValidator(new InputLimits(maxNodes: 100)))->assertValid($a, 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('cyclic_input', $error->reason);
        }
    }

    #[Test]
    public function disabledLimitsKeepCycleCompatibility(): void
    {
        $self = [
            'value' => 'ok',
        ];
        $self['self'] = &$self;

        $a      = [];
        $b      = [];
        $a['b'] = &$b;
        $b['a'] = &$a;

        self::expectNotToPerformAssertions();

        $validator = new InputEncodingValidator();
        $validator->assertValid($self, 'body');
        $validator->assertValid($a, 'body');
    }

    #[Test]
    public function validationDoesNotMutateReferences(): void
    {
        $shared = [
            'v' => 1,
        ];
        $values = [
            'a' => &$shared,
            'b' => &$shared,
        ];

        (new InputEncodingValidator(new InputLimits(maxNodes: 4)))->assertValid($values, 'body');

        $values['a']['v'] = 2;
        self::assertSame(2, $shared['v']);
        self::assertSame([
            'v' => 2,
        ], $values['b']);
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

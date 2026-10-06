<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;

final class MapRequestOptionsParserTest extends TestCase
{
    private MapRequestOptionsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MapRequestOptionsParser();
    }

    #[Test]
    public function emptyListMeansNoMappings(): void
    {
        self::assertSame([], $this->parser->parse([]));
    }

    #[Test]
    public function parsesMinimalMapping(): void
    {
        $result = $this->parser->parse([
            [
                'body' => self::class,
            ],
        ]);

        self::assertCount(1, $result);
        self::assertInstanceOf(MapRequest::class, $result[0]);
        self::assertSame(self::class, $result[0]->body);
        self::assertSame([], $result[0]->methods);
    }

    #[Test]
    public function parsesFullMapping(): void
    {
        $result = $this->parser->parse([
            [
                'body'           => self::class,
                'query'          => TestCase::class,
                'route'          => MapRequest::class,
                'output'         => null,
                'errorResponder' => DefaultMappingErrorResponderForParserTest::class,
                'methods'        => ['post', 'PUT'],
            ],
        ]);

        $mapping = $result[0];

        self::assertSame(self::class, $mapping->body);
        self::assertSame(TestCase::class, $mapping->query);
        self::assertSame(MapRequest::class, $mapping->route);
        self::assertNull($mapping->source);
        self::assertNull($mapping->output);
        self::assertSame(DefaultMappingErrorResponderForParserTest::class, $mapping->errorResponder);
        self::assertSame(['POST', 'PUT'], $mapping->methods);
    }

    #[Test]
    public function explicitNullForStringFieldsIsAllowed(): void
    {
        $result = $this->parser->parse([
            [
                'body'           => self::class,
                'query'          => null,
                'route'          => null,
                'source'         => null,
                'output'         => null,
                'errorResponder' => null,
            ],
        ]);

        self::assertCount(1, $result);
        self::assertSame(self::class, $result[0]->body);
    }

    #[Test]
    public function parsesSingleSourceWithExplicitOutput(): void
    {
        $result = $this->parser->parse([[
            'query'  => 'array{page: int}',
            'output' => 'payload',
        ]]);

        self::assertSame('array{page: int}', $result[0]->query);
        self::assertSame('payload', $result[0]->output);
    }

    /** @param array<string, string> $mapping */
    #[Test]
    #[DataProvider('intrinsicCollisionProvider')]
    public function intrinsicCollisionInRouteOptionsIsRejected(array $mapping, string $effectiveOutput): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage($effectiveOutput);

        $this->parser->parse([$mapping]);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function intrinsicCollisionProvider(): iterable
    {
        yield 'shared explicit output' => [[
            'body'   => self::class,
            'query'  => TestCase::class,
            'output' => 'payload',
        ], 'payload'];

        yield 'same default target' => [[
            'body'  => self::class,
            'query' => self::class,
        ], self::class];
    }

    #[Test]
    public function unknownKeyIsReported(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage("valinor_mappings[0]: unknown key 'extra'.");

        $this->parser->parse([
            [
                'body'  => self::class,
                'extra' => 'value',
            ],
        ]);
    }

    #[Test]
    public function invalidTopLevelTypeIsReported(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('valinor_mappings: expected list<map<string, mixed>>.');

        $this->parser->parse([
            'body' => self::class,
        ]);
    }

    #[Test]
    public function invalidItemTypeIsReported(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('valinor_mappings[1]: expected map<string, mixed>.');

        $this->parser->parse([
            [
                'body' => self::class,
            ],
            'not-a-map',
        ]);
    }

    #[Test]
    public function invalidStringFieldTypeIsReported(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('valinor_mappings[0].body: expected string|null.');

        $this->parser->parse([
            [
                'body' => 123,
            ],
        ]);
    }

    #[Test]
    public function invalidMethodsTypeIsReported(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('valinor_mappings[2].methods: expected list<string>.');

        $this->parser->parse([
            [
                'body' => self::class,
            ],
            [
                'body' => self::class,
            ],
            [
                'body'    => self::class,
                'methods' => 'POST',
            ],
        ]);
    }

    #[Test]
    public function invalidMethodsElementTypeIsReported(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('valinor_mappings[1].methods: expected list<string>.');

        $this->parser->parse([
            [
                'body' => self::class,
            ],
            [
                'body'    => self::class,
                'methods' => [123],
            ],
        ]);
    }

    #[Test]
    public function semanticValidationIsDelegatedToMapRequest(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);

        $this->parser->parse([
            [
                'body' => '  ',
            ],
        ]);
    }

    /**
     * @param list<string> $input
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('validMethodsProvider')]
    public function normalizesMethods(array $input, array $expected): void
    {
        $result = $this->parser->parse([
            [
                'body'    => self::class,
                'methods' => $input,
            ],
        ]);

        self::assertSame($expected, $result[0]->methods);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: list<string>}>
     */
    public static function validMethodsProvider(): iterable
    {
        yield 'duplicates removed preserving order' => [[' post ', 'POST', 'put'], ['POST', 'PUT']];

        yield 'non-standard token' => [['PROPFIND'], ['PROPFIND']];

        yield 'empty list' => [[], []];
    }
}

final readonly class DefaultMappingErrorResponderForParserTest {}

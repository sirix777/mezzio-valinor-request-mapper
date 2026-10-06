<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Attribute;

use Fig\Http\Message\RequestMethodInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Routing\Contracts\AggregatingRouteAttributeModifierInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

final class MapRequestTest extends TestCase
{
    #[Test]
    public function implementsAggregatingRouteAttributeModifierInterface(): void
    {
        $attr = new MapRequest(body: self::class);

        self::assertInstanceOf(AggregatingRouteAttributeModifierInterface::class, $attr);
    }

    #[Test]
    public function getMiddlewareReturnsNoLegacyMiddleware(): void
    {
        $attr = new MapRequest(body: self::class);

        self::assertSame([], $attr->getMiddleware());
    }

    #[Test]
    public function getUniqueMiddlewareReturnsMapperMiddleware(): void
    {
        $attr = new MapRequest(body: self::class);

        self::assertSame([
            'sirix.mezzio.valinor.request-mapper' => ValinorRequestMapperMiddleware::class,
        ], $attr->getUniqueMiddleware());
    }

    #[Test]
    public function mergeDefaultsContainsAllFields(): void
    {
        $attr = new MapRequest(
            body: self::class,
            query: TestCase::class,
            route: MapRequest::class,
            methods: [RequestMethodInterface::METHOD_POST, RequestMethodInterface::METHOD_PUT],
            errorResponder: DefaultMappingErrorResponder::class,
        );

        $defaults = $attr->mergeDefaults([]);

        self::assertArrayHasKey('valinor_mappings', $defaults);
        $mapping = $defaults['valinor_mappings'][0];

        self::assertSame(self::class, $mapping['body']);
        self::assertSame(TestCase::class, $mapping['query']);
        self::assertSame(MapRequest::class, $mapping['route']);
        self::assertNull($mapping['source']);
        self::assertNull($mapping['output']);
        self::assertSame(DefaultMappingErrorResponder::class, $mapping['errorResponder']);
        self::assertSame([RequestMethodInterface::METHOD_POST, RequestMethodInterface::METHOD_PUT], $mapping['methods']);
    }

    #[Test]
    public function methodsDefaultsToEmptyArray(): void
    {
        $attr = new MapRequest(body: self::class);

        self::assertSame([], $attr->methods);
    }

    #[Test]
    public function preservesMethodsAsTheSixthPositionalArgument(): void
    {
        $attr = new MapRequest(self::class, null, null, null, null, ['post']);

        self::assertSame([RequestMethodInterface::METHOD_POST], $attr->methods);
        self::assertNull($attr->errorResponder);
    }

    #[Test]
    public function allFieldsNullThrowsConfigurationError(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('at least one of $body, $query, $route or $source');

        new MapRequest();
    }

    #[Test]
    public function sourceAndBodyAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('$source is mutually exclusive');

        new MapRequest(body: self::class, source: TestCase::class);
    }

    #[Test]
    public function sourceAndQueryAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);

        new MapRequest(query: self::class, source: TestCase::class);
    }

    #[Test]
    public function sourceAndRouteAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);

        new MapRequest(route: self::class, source: TestCase::class);
    }

    #[Test]
    public function bodyQueryRouteTogetherIsAllowed(): void
    {
        $attr = new MapRequest(body: self::class, query: TestCase::class, route: MapRequest::class);

        self::assertSame(self::class, $attr->body);
        self::assertSame(TestCase::class, $attr->query);
        self::assertSame(MapRequest::class, $attr->route);
    }

    /** @param array<string, string> $args */
    #[Test]
    #[DataProvider('intrinsicCollisionProvider')]
    public function intrinsicOutputCollisionsAreRejected(array $args, string $effectiveOutput): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage($effectiveOutput);

        new MapRequest(...$args);
    }

    /** @param array<string, string> $args */
    #[Test]
    #[DataProvider('intrinsicCollisionProvider')]
    public function intrinsicCollisionMessageNamesFirstConflictingSources(
        array $args,
        string $effectiveOutput,
        string $firstSource,
        string $secondSource,
    ): void {
        try {
            new MapRequest(...$args);
        } catch (InvalidMapRequestConfiguration $caught) {
            self::assertStringContainsString($effectiveOutput, $caught->getMessage());
            self::assertStringContainsString($firstSource, $caught->getMessage());
            self::assertStringContainsString($secondSource, $caught->getMessage());

            return;
        }

        self::fail('Expected intrinsic output collision to be rejected by the constructor.');
    }

    /** @param array<string, string> $args */
    #[Test]
    #[DataProvider('intrinsicCollisionProvider')]
    public function intrinsicCollisionIsRejectedRegardlessOfMethodFilter(array $args, string $effectiveOutput): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage($effectiveOutput);

        new MapRequest(...$args, methods: ['PATCH']);
    }

    /** @return iterable<string, array{array<string, string>, string, string, string}> */
    public static function intrinsicCollisionProvider(): iterable
    {
        yield 'body query explicit output' => [[
            'body'   => self::class,
            'query'  => TestCase::class,
            'output' => 'payload',
        ], 'payload', 'body', 'query'];

        yield 'body route explicit output' => [[
            'body'   => self::class,
            'route'  => TestCase::class,
            'output' => 'payload',
        ], 'payload', 'body', 'route'];

        yield 'query route explicit output' => [[
            'query'  => self::class,
            'route'  => TestCase::class,
            'output' => 'payload',
        ], 'payload', 'query', 'route'];

        yield 'all sources explicit output' => [[
            'body'   => self::class,
            'query'  => TestCase::class,
            'route'  => MapRequest::class,
            'output' => 'payload',
        ], 'payload', 'body', 'query'];

        yield 'body query same target' => [[
            'body'  => self::class,
            'query' => self::class,
        ], self::class, 'body', 'query'];

        yield 'body route same target' => [[
            'body'  => self::class,
            'route' => self::class,
        ], self::class, 'body', 'route'];

        yield 'query route same target' => [[
            'query' => self::class,
            'route' => self::class,
        ], self::class, 'query', 'route'];

        yield 'all sources same target' => [[
            'body'  => self::class,
            'query' => self::class,
            'route' => self::class,
        ], self::class, 'body', 'query'];
    }

    /** @param array<string, string> $args */
    #[Test]
    #[DataProvider('zeroCollisionProvider')]
    public function numericZeroOutputCollisionsAreRejected(array $args): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('0');

        new MapRequest(...$args);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function zeroCollisionProvider(): iterable
    {
        yield 'explicit zero output' => [[
            'body'   => self::class,
            'query'  => TestCase::class,
            'output' => '0',
        ]];

        yield 'zero target default output' => [[
            'body'  => '0',
            'query' => '0',
        ]];
    }

    #[Test]
    #[DataProvider('singleSourceProvider')]
    public function singleSourceWithExplicitOutputIsAllowed(string $source): void
    {
        $attr = new MapRequest(...[
            $source  => self::class,
            'output' => 'payload',
        ]);

        self::assertSame('payload', $attr->output);
        self::assertSame('payload', $attr->mergeDefaults([])['valinor_mappings'][0]['output']);
    }

    #[Test]
    #[DataProvider('singleSourceProvider')]
    public function singleSourceRetainsNumericZeroOutput(string $source): void
    {
        $attr = new MapRequest(...[
            $source  => self::class,
            'output' => '0',
        ]);

        self::assertSame('0', $attr->output);
    }

    /** @return iterable<string, array{string}> */
    public static function singleSourceProvider(): iterable
    {
        yield 'body' => ['body'];

        yield 'query' => ['query'];

        yield 'route' => ['route'];

        yield 'source' => ['source'];
    }

    #[Test]
    #[DataProvider('targetSignatureProvider')]
    public function targetSignaturesArePreservedWithoutClassValidation(string $target): void
    {
        self::assertSame($target, (new MapRequest(query: $target))->query);
    }

    /** @return iterable<string, array{string}> */
    public static function targetSignatureProvider(): iterable
    {
        yield 'DTO class' => [self::class];

        yield 'generic DTO' => ['Example\GenericDto<int>'];

        yield 'array shape' => ['array{page: int}'];

        yield 'grammar interpreted later' => ['array{'];
    }

    #[Test]
    public function methodsAreNormalizedAndDuplicatesRemoved(): void
    {
        $attr = new MapRequest(body: self::class, methods: [' post ', RequestMethodInterface::METHOD_POST, 'put']);

        self::assertSame([RequestMethodInterface::METHOD_POST, RequestMethodInterface::METHOD_PUT], $attr->methods);
    }

    #[Test]
    public function acceptsNonStandardHttpMethodTokens(): void
    {
        $attr = new MapRequest(body: self::class, methods: ['PROPFIND', 'CUSTOM']);

        self::assertSame(['PROPFIND', 'CUSTOM'], $attr->methods);
    }

    #[Test]
    public function emptyMethodsMeansAnyHttpMethod(): void
    {
        $attr = new MapRequest(body: self::class);

        self::assertSame([], $attr->methods);
    }

    /**
     * @param array<string, mixed> $args
     */
    #[Test]
    #[DataProvider('surroundingWhitespaceProvider')]
    public function rejectsSurroundingWhitespaceInStringFields(array $args): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('without surrounding whitespace');

        new MapRequest(...$args);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function surroundingWhitespaceProvider(): iterable
    {
        yield 'body with surrounding whitespace' => [[
            'body' => ' ' . self::class . ' ',
        ]];

        yield 'query with surrounding whitespace' => [[
            'query' => ' ' . self::class . ' ',
        ]];

        yield 'route with surrounding whitespace' => [[
            'route' => ' ' . self::class . ' ',
        ]];

        yield 'source with surrounding whitespace' => [[
            'source' => ' ' . self::class . ' ',
        ]];

        yield 'output with surrounding whitespace' => [
            [
                'body'   => self::class,
                'output' => ' form ',
            ],
        ];

        yield 'error responder with surrounding whitespace' => [
            [
                'body'           => self::class,
                'errorResponder' => ' ' . DefaultMappingErrorResponder::class . ' ',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[Test]
    #[DataProvider('invalidConfigurationProvider')]
    public function rejectsInvalidConfiguration(array $args): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);

        new MapRequest(...$args);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'all fields null' => [[]];

        yield 'blank body' => [[
            'body' => '   ',
        ]];

        yield 'blank query' => [[
            'query' => '',
        ]];

        yield 'blank route' => [[
            'route' => "\t",
        ]];

        yield 'blank source' => [[
            'source' => '  ',
        ]];

        yield 'blank output' => [[
            'body'   => self::class,
            'output' => '',
        ]];

        yield 'blank error responder' => [[
            'body'           => self::class,
            'errorResponder' => '   ',
        ]];

        yield 'empty string in methods' => [[
            'body'    => self::class,
            'methods' => [''],
        ]];

        yield 'whitespace string in methods' => [[
            'body'    => self::class,
            'methods' => ['   '],
        ]];

        yield 'non-string in methods' => [[
            'body'    => self::class,
            'methods' => [123],
        ]];

        yield 'internal whitespace in method' => [[
            'body'    => self::class,
            'methods' => ['P OST'],
        ]];

        yield 'keyed methods array' => [[
            'body'    => self::class,
            'methods' => [
                'x' => 'POST',
            ],
        ]];

        yield 'invalid token in method' => [[
            'body'    => self::class,
            'methods' => ['POST('],
        ]];
    }

    #[Test]
    public function multipleDefaultsCanBeMergedIntoSingleValinorMappingsPayload(): void
    {
        $classLevel  = new MapRequest(query: self::class, output: 'class');
        $methodLevel = new MapRequest(body: TestCase::class, output: 'method');

        $defaults = $methodLevel->mergeDefaults($classLevel->mergeDefaults([]));

        self::assertCount(2, $defaults['valinor_mappings']);
        self::assertSame(self::class, $defaults['valinor_mappings'][0]['query']);
        self::assertSame(TestCase::class, $defaults['valinor_mappings'][1]['body']);
        self::assertSame('class', $defaults['valinor_mappings'][0]['output']);
        self::assertSame('method', $defaults['valinor_mappings'][1]['output']);
    }
}

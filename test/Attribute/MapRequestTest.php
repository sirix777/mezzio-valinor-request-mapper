<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Attribute;

use Fig\Http\Message\RequestMethodInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Routing\Contracts\RouteAttributeModifierInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

final class MapRequestTest extends TestCase
{
    #[Test]
    public function implementsRouteAttributeModifierInterface(): void
    {
        $attr = new MapRequest(body: self::class);

        self::assertInstanceOf(RouteAttributeModifierInterface::class, $attr);
    }

    #[Test]
    public function getMiddlewareReturnsMapperMiddleware(): void
    {
        $attr = new MapRequest(body: self::class);

        self::assertSame([ValinorRequestMapperMiddleware::class], $attr->getMiddleware());
    }

    #[Test]
    public function getDefaultsContainsAllFields(): void
    {
        $attr = new MapRequest(
            body: self::class,
            query: TestCase::class,
            route: MapRequest::class,
            output: 'form',
            methods: [RequestMethodInterface::METHOD_POST, RequestMethodInterface::METHOD_PUT],
            errorResponder: DefaultMappingErrorResponder::class,
        );

        $defaults = $attr->getDefaults();

        self::assertArrayHasKey('valinor_mappings', $defaults);
        $mapping = $defaults['valinor_mappings'][0];

        self::assertSame(self::class, $mapping['body']);
        self::assertSame(TestCase::class, $mapping['query']);
        self::assertSame(MapRequest::class, $mapping['route']);
        self::assertNull($mapping['source']);
        self::assertSame('form', $mapping['output']);
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
}

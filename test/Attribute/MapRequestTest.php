<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Attribute;

use Fig\Http\Message\RequestMethodInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Routing\Contracts\RouteAttributeModifierInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
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
    public function allFieldsCanBeNull(): void
    {
        $attr = new MapRequest();

        self::assertNull($attr->body);
        self::assertNull($attr->query);
        self::assertNull($attr->route);
        self::assertNull($attr->source);
        self::assertNull($attr->output);
        self::assertNull($attr->errorResponder);
        self::assertSame([], $attr->methods);
    }

    #[Test]
    public function sourceAndBodyAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MapRequest(body: self::class, source: TestCase::class);
    }

    #[Test]
    public function sourceAndQueryAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MapRequest(query: self::class, source: TestCase::class);
    }

    #[Test]
    public function sourceAndRouteAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);

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
    public function methodsAreNormalizedAndNonStringsAreFilteredOut(): void
    {
        $attr = new MapRequest(body: self::class, methods: ['post', 123, RequestMethodInterface::METHOD_PUT, '']);

        self::assertSame([RequestMethodInterface::METHOD_POST, RequestMethodInterface::METHOD_PUT], $attr->methods);
    }
}

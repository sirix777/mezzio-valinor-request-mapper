<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use Fig\Http\Message\RequestMethodInterface;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Laminas\Stratigility\MiddlewarePipe;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedRequestHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\DualInterfaceHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;

final class MapRequestResolverTest extends TestCase
{
    private MapRequestResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MapRequestResolver(
            new HandlerTargetResolver(),
            new MapRequestOptionsParser(),
        );
    }

    #[Test]
    public function returnsEmptyListWhenRouteNotMatched(): void
    {
        $result = RouteResult::fromRouteFailure([RequestMethodInterface::METHOD_GET]);

        self::assertSame([], $this->resolver->resolve($result));
    }

    #[Test]
    public function resolvesClassLevelAttributeFromRequestHandler(): void
    {
        $route = new Route('/example', new RequestHandlerMiddleware(new AttributedRequestHandler()), [RequestMethodInterface::METHOD_POST]);

        $mapRequests = $this->resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertCount(1, $mapRequests);
        self::assertSame(RequiredRequest::class, $mapRequests[0]->body);
    }

    #[Test]
    public function resolvesClassLevelAttributeFromMiddleware(): void
    {
        $route = new Route('/example', new AttributedMiddleware(), [RequestMethodInterface::METHOD_POST]);

        $mapRequests = $this->resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertCount(1, $mapRequests);
        self::assertSame(RequiredRequest::class, $mapRequests[0]->body);
    }

    #[Test]
    public function resolvesMethodLevelAttributeFromDualInterfaceProcess(): void
    {
        $route = new Route('/example', new DualInterfaceHandler(), [RequestMethodInterface::METHOD_POST]);

        $mapRequests = $this->resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertCount(1, $mapRequests);
        self::assertSame(RequiredRequest::class, $mapRequests[0]->body);
    }

    #[Test]
    public function classLevelAttributesRunBeforeMethodLevel(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'class')]
        class implements MiddlewareInterface {
            #[MapRequest(body: RequiredRequest::class, output: 'method')]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);

        $mapRequests = $this->resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertCount(2, $mapRequests);
        self::assertSame('class', $mapRequests[0]->output);
        self::assertSame('method', $mapRequests[1]->output);
    }

    #[Test]
    public function nonEmptyRouteOptionsReplaceReflection(): void
    {
        $handler = new class implements MiddlewareInterface {
            #[MapRequest(body: RequiredRequest::class)]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'body' => MapRequestResolverTest::class,
                ],
            ],
        ]);

        $mapRequests = $this->resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertCount(1, $mapRequests);
        self::assertSame(MapRequestResolverTest::class, $mapRequests[0]->body);
    }

    #[Test]
    public function emptyRouteOptionsFallsBackToReflection(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [],
        ]);

        $mapRequests = $this->resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertCount(1, $mapRequests);
        self::assertSame(RequiredRequest::class, $mapRequests[0]->body);
    }

    #[Test]
    public function missingRouteOptionsFallsBackToReflection(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);

        $mapRequests = $this->resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertCount(1, $mapRequests);
        self::assertSame(RequiredRequest::class, $mapRequests[0]->body);
    }

    #[Test]
    public function invalidNonEmptyRouteOptionsThrowConfigurationError(): void
    {
        $handler = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'methods' => 'POST',
                ],
            ],
        ]);

        $this->expectException(InvalidMapRequestConfiguration::class);

        $this->resolver->resolve(RouteResult::fromRoute($route, []));
    }

    #[Test]
    public function unsupportedHandlerWithoutMetadataReturnsEmptyList(): void
    {
        $handler = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);

        self::assertSame([], $this->resolver->resolve(RouteResult::fromRoute($route, [])));
    }

    #[Test]
    public function middlewarePipeWithoutMetadataReturnsEmptyList(): void
    {
        $route = new Route('/example', new MiddlewarePipe(), [RequestMethodInterface::METHOD_POST]);

        self::assertSame([], $this->resolver->resolve(RouteResult::fromRoute($route, [])));
    }
}

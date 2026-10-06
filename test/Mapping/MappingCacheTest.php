<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use Fig\Http\Message\RequestMethodInterface;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\MiddlewareContainer;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionProperty;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedRequestHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\DualInterfaceHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\PaginationRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;
use WeakReference;

use function class_alias;
use function gc_collect_cycles;

final class MappingCacheTest extends TestCase
{
    #[Test]
    public function handlerTargetResolverReturnsSameInstanceForSameObject(): void
    {
        $resolver = new HandlerTargetResolver();
        $handler  = new AttributedRequestHandler();

        $first  = $resolver->resolve($handler);
        $second = $resolver->resolve($handler);

        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    #[Test]
    public function handlerTargetResolverDoesNotMixWrappersAroundDifferentHandlers(): void
    {
        $resolver = new HandlerTargetResolver();

        $first  = $resolver->resolve(new RequestHandlerMiddleware(new AttributedRequestHandler()));
        $second = $resolver->resolve(new RequestHandlerMiddleware(new DualInterfaceHandler()));

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
        self::assertSame(AttributedRequestHandler::class, $first->className);
        self::assertSame(DualInterfaceHandler::class, $second->className);
    }

    #[Test]
    public function handlerTargetResolverDoesNotHoldWrapperAfterItIsDestroyed(): void
    {
        $resolver  = new HandlerTargetResolver();
        $wrapper   = new RequestHandlerMiddleware(new AttributedRequestHandler());
        $reference = WeakReference::create($wrapper);

        $resolver->resolve($wrapper);
        unset($wrapper);
        gc_collect_cycles();

        self::assertNull($reference->get());

        $property = new ReflectionProperty($resolver, 'cache');
        self::assertCount(0, $property->getValue($resolver));
    }

    #[Test]
    public function mapRequestResolverReturnsSameMapRequestInstancesForSameRoute(): void
    {
        $resolver = new MapRequestResolver(
            new HandlerTargetResolver(),
            new MapRequestOptionsParser(),
        );
        $route = new Route('/example', new RequestHandlerMiddleware(new AttributedRequestHandler()), [
            RequestMethodInterface::METHOD_POST,
        ]);

        $first  = $resolver->resolve(RouteResult::fromRoute($route, []));
        $second = $resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertSame($first, $second);
    }

    #[Test]
    public function mapRequestResolverUsesOneReflectionEntryForClassAndAlias(): void
    {
        $resolver = new MapRequestResolver(
            new HandlerTargetResolver(),
            new MapRequestOptionsParser(),
        );
        $alias = __NAMESPACE__ . '\AttributedRequestHandlerAlias';
        class_alias(AttributedRequestHandler::class, $alias);

        $directRoute = new Route('/direct', new RequestHandlerMiddleware(new AttributedRequestHandler()), [
            RequestMethodInterface::METHOD_POST,
        ]);
        $aliasRoute = new Route('/alias', new LazyLoadingMiddleware(
            $this->createMock(MiddlewareContainer::class),
            $alias,
        ), [RequestMethodInterface::METHOD_POST]);

        $direct  = $resolver->resolve(RouteResult::fromRoute($directRoute, []));
        $aliased = $resolver->resolve(RouteResult::fromRoute($aliasRoute, []));

        self::assertSame($direct, $aliased);

        $property = new ReflectionProperty($resolver, 'reflectionCache');
        self::assertCount(1, $property->getValue($resolver));
    }

    #[Test]
    public function mapRequestResolverInvalidatesWhenRouteOptionsChange(): void
    {
        $resolver = new MapRequestResolver(
            new HandlerTargetResolver(),
            new MapRequestOptionsParser(),
        );

        $handler = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [[
                'body' => RequiredRequest::class,
            ]],
        ]);

        $first = $resolver->resolve(RouteResult::fromRoute($route, []));

        $route->setOptions([
            'valinor_mappings' => [[
                'body' => PaginationRequest::class,
            ]],
        ]);

        $second = $resolver->resolve(RouteResult::fromRoute($route, []));

        self::assertNotSame($first, $second);
        self::assertSame(RequiredRequest::class, $first[0]->body);
        self::assertSame(PaginationRequest::class, $second[0]->body);
    }

    #[Test]
    public function routeOptionsTransitionsRespectExplicitDisable(): void
    {
        $resolver = new MapRequestResolver(
            new HandlerTargetResolver(),
            new MapRequestOptionsParser(),
        );

        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);

        $reflectionMappings = $resolver->resolve(RouteResult::fromRoute($route, []));
        self::assertCount(1, $reflectionMappings);
        self::assertSame(RequiredRequest::class, $reflectionMappings[0]->body);

        $initial = $resolver->resolve(RouteResult::fromRoute($route, []));
        self::assertSame($reflectionMappings, $initial);

        $route->setOptions([
            'valinor_mappings' => [],
        ]);
        $disabled = $resolver->resolve(RouteResult::fromRoute($route, []));
        self::assertSame([], $disabled);
        self::assertSame([], $resolver->resolve(RouteResult::fromRoute($route, [])));

        $route->setOptions([
            'valinor_mappings' => [[
                'body'   => PaginationRequest::class,
                'output' => 'explicit',
            ]],
        ]);
        $overridden = $resolver->resolve(RouteResult::fromRoute($route, []));
        self::assertNotSame($reflectionMappings, $overridden);
        self::assertSame(PaginationRequest::class, $overridden[0]->body);
        self::assertSame('explicit', $overridden[0]->output);
        self::assertSame($overridden, $resolver->resolve(RouteResult::fromRoute($route, [])));

        $route->setOptions([]);
        $restored = $resolver->resolve(RouteResult::fromRoute($route, []));
        self::assertSame($reflectionMappings, $restored);
    }

    #[Test]
    public function invalidRouteOptionsAreNotCached(): void
    {
        $resolver = new MapRequestResolver(
            new HandlerTargetResolver(),
            new MapRequestOptionsParser(),
        );

        $handler = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [[
                'methods' => 'POST',
            ]],
        ]);

        try {
            $resolver->resolve(RouteResult::fromRoute($route, []));
            self::fail('Expected InvalidMapRequestConfiguration to be thrown.');
        } catch (InvalidMapRequestConfiguration) {
        }

        $route->setOptions([
            'valinor_mappings' => [[
                'body' => RequiredRequest::class,
            ]],
        ]);

        $result = $resolver->resolve(RouteResult::fromRoute($route, []));
        self::assertSame(RequiredRequest::class, $result[0]->body);
    }

    #[Test]
    public function routeCacheDoesNotHoldRouteAfterItIsDestroyed(): void
    {
        $resolver = new MapRequestResolver(
            new HandlerTargetResolver(),
            new MapRequestOptionsParser(),
        );

        $route = new Route('/example', new RequestHandlerMiddleware(new AttributedRequestHandler()), [
            RequestMethodInterface::METHOD_POST,
        ]);
        $reference = WeakReference::create($route);

        $resolver->resolve(RouteResult::fromRoute($route, []));
        unset($route);
        gc_collect_cycles();

        self::assertNull($reference->get());

        $property = new ReflectionProperty($resolver, 'routeCache');
        self::assertCount(0, $property->getValue($resolver));
    }
}

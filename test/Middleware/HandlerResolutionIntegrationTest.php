<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Fig\Http\Message\RequestMethodInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactory;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedClosureFactory;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CallableMethodHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\DualInterfaceHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\InheritedCallableChild;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\LazyLoadingMiddlewareHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\LazyLoadingRequestHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequestMapperMiddlewareBuilder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;

use function array_key_exists;
use function json_decode;

final class HandlerResolutionIntegrationTest extends TestCase
{
    #[Test]
    public function inheritedCallablesMapChildBeforeInheritedMethod(): void
    {
        $child          = new InheritedCallableChild();
        $factory        = new MiddlewareFactory(new MiddlewareContainer($this->createContainer([])));
        $instanceMethod = 'work';
        $staticMethod   = 'staticWork';

        foreach ([
            [$child, $instanceMethod],
            $child->work(...),
            [InheritedCallableChild::class, $staticMethod],
            InheritedCallableChild::staticWork(...),
        ] as $callable) {
            $routeMiddleware = $factory->callable($callable);
            $route           = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);
            $request         = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_POST)
                ->withParsedBody([
                    'name' => 'inherited',
                ])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            $mapCount = 0;
            $calls    = [];
            $response = $this->middleware($this->spyMapper($mapCount, $calls))->process(
                $request,
                $this->dispatchingNextHandler($routeMiddleware),
            );

            self::assertSame([
                'class'  => 'inherited',
                'method' => 'inherited',
            ], json_decode((string) $response->getBody(), true));
            self::assertSame(2, $mapCount);
        }
    }

    #[Test]
    public function lazyLoadingRequestHandlerResolvesHandleAttribute(): void
    {
        $container = $this->createContainer([
            LazyLoadingRequestHandler::class => new LazyLoadingRequestHandler(),
        ]);
        $factory         = new MiddlewareFactory(new MiddlewareContainer($container));
        $routeMiddleware = $factory->prepare(LazyLoadingRequestHandler::class);

        $route = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];
        $response = $this->middleware($this->spyMapper($mapCount, $calls))->process(
            $request,
            $this->dispatchingNextHandler($routeMiddleware),
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
        self::assertSame(1, $mapCount);
        self::assertSame(RequiredRequest::class, $calls[0][0]);
    }

    #[Test]
    public function lazyLoadingMiddlewareResolvesProcessAttribute(): void
    {
        $container = $this->createContainer([
            LazyLoadingMiddlewareHandler::class => new LazyLoadingMiddlewareHandler(),
        ]);
        $factory         = new MiddlewareFactory(new MiddlewareContainer($container));
        $routeMiddleware = $factory->prepare(LazyLoadingMiddlewareHandler::class);

        $route = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];
        $response = $this->middleware($this->spyMapper($mapCount, $calls))->process(
            $request,
            $this->dispatchingNextHandler($routeMiddleware),
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
        self::assertSame(1, $mapCount);
        self::assertSame(RequiredRequest::class, $calls[0][0]);
    }

    #[Test]
    public function lazyLoadingDoesNotResolveServiceBeforeDispatch(): void
    {
        $container = $this->createContainer([
            LazyLoadingRequestHandler::class => new LazyLoadingRequestHandler(),
        ]);
        $factory         = new MiddlewareFactory(new MiddlewareContainer($container));
        $routeMiddleware = $factory->prepare(LazyLoadingRequestHandler::class);

        $route = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $called = false;
        $next   = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $this->middleware($this->createMock(TreeMapper::class))->process($request, $next);

        self::assertTrue($next->called);
        self::assertFalse($container->wasCalled(LazyLoadingRequestHandler::class), 'Service should not be resolved during metadata discovery');
    }

    #[Test]
    public function lazyLoadingAliasWithoutMetadataIsNoOp(): void
    {
        $container = $this->createContainer([
            'order.handler.alias' => new LazyLoadingRequestHandler(),
        ]);
        $routeMiddleware = new LazyLoadingMiddleware(
            new MiddlewareContainer($container),
            'order.handler.alias',
        );

        $route = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];

        $called = false;
        $next   = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $this->middleware($this->spyMapper($mapCount, $calls))->process($request, $next);

        self::assertTrue($next->called);
        self::assertSame(0, $mapCount);
        self::assertFalse($container->wasCalled('order.handler.alias'));
    }

    #[Test]
    public function lazyLoadingAliasWithExplicitRouteOptionsMapsDto(): void
    {
        $container = $this->createContainer([
            'order.handler.alias' => new LazyLoadingRequestHandler(),
        ]);
        $routeMiddleware = new LazyLoadingMiddleware(
            new MiddlewareContainer($container),
            'order.handler.alias',
        );

        $route = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'body' => RequiredRequest::class,
                ],
            ],
        ]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];
        $response = $this->middleware($this->spyMapper($mapCount, $calls))->process(
            $request,
            $this->dispatchingNextHandler($routeMiddleware),
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
        self::assertSame(1, $mapCount);
    }

    #[Test]
    public function dualInterfaceHandlerUsesProcessAndIgnoresHandleAttribute(): void
    {
        $routeMiddleware = new DualInterfaceHandler();
        $route           = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];
        $response = $this->middleware($this->spyMapper($mapCount, $calls))->process(
            $request,
            $this->dispatchingNextHandler($routeMiddleware),
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('foo', $body['name']);
        self::assertSame(1, $mapCount);
        self::assertSame(RequiredRequest::class, $calls[0][0]);
    }

    #[Test]
    public function callableMiddlewareDecoratorWithSpecificMethodDoesNotReadOtherMethods(): void
    {
        $handler         = new CallableMethodHandler();
        $factory         = new MiddlewareFactory(new MiddlewareContainer($this->createContainer([])));
        $routeMiddleware = $factory->callable($handler->create(...));

        $route = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];
        $response = $this->middleware($this->spyMapper($mapCount, $calls))->process(
            $request,
            $this->dispatchingNextHandler($routeMiddleware),
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('foo', $body['name']);
        self::assertSame(1, $mapCount);
        self::assertSame(RequiredRequest::class, $calls[0][0]);
    }

    #[Test]
    public function closureInsideAttributedClassDoesNotMapRequest(): void
    {
        $factory         = new MiddlewareFactory(new MiddlewareContainer($this->createContainer([])));
        $routeMiddleware = $factory->callable((new AttributedClosureFactory())->create());
        $route           = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];
        $response = $this->middleware($this->spyMapper($mapCount, $calls))->process(
            $request,
            $this->dispatchingNextHandler($routeMiddleware),
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($body['hasDto']);
        self::assertSame(0, $mapCount);
    }

    #[Test]
    public function pipelineWithoutMetadataIsNoOp(): void
    {
        $factory         = new MiddlewareFactory(new MiddlewareContainer($this->createContainer([])));
        $routeMiddleware = $factory->pipeline([
            static fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => new JsonResponse(
                $request->getAttribute(RequiredRequest::class),
            ),
        ]);

        $route = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];

        $called = false;
        $next   = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $this->middleware($this->spyMapper($mapCount, $calls))->process($request, $next);

        self::assertTrue($next->called);
        self::assertSame(0, $mapCount);
    }

    #[Test]
    public function pipelineWithExplicitRouteOptionsMapsDto(): void
    {
        $factory         = new MiddlewareFactory(new MiddlewareContainer($this->createContainer([])));
        $routeMiddleware = $factory->pipeline([
            static fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => new JsonResponse(
                $request->getAttribute(RequiredRequest::class),
            ),
        ]);

        $route = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'body' => RequiredRequest::class,
                ],
            ],
        ]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];
        $response = $this->middleware($this->spyMapper($mapCount, $calls))->process(
            $request,
            $this->dispatchingNextHandler($routeMiddleware),
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
        self::assertSame(1, $mapCount);
    }

    #[Test]
    public function nonEmptyRouteOptionsTakePriorityOverReflection(): void
    {
        $routeMiddleware = new AttributedMiddleware();
        $route           = new Route('/example', $routeMiddleware, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'body'    => HandlerResolutionIntegrationTest::class,
                    'methods' => [RequestMethodInterface::METHOD_GET],
                ],
            ],
        ]);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'foo',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $mapCount = 0;
        $calls    = [];
        $this->middleware($this->spyMapper($mapCount, $calls))->process(
            $request,
            $this->dispatchingNextHandler($routeMiddleware),
        );

        self::assertSame(0, $mapCount);
    }

    /**
     * @param list<array{0: string, 1: mixed}> $calls
     */
    private function spyMapper(int &$mapCount, array &$calls): TreeMapper
    {
        $inner = (new MapperBuilder())->mapper();
        $mock  = $this->createMock(TreeMapper::class);
        $mock->method('map')->willReturnCallback(
            static function(string $signature, mixed $source) use ($inner, &$mapCount, &$calls): mixed {
                ++$mapCount;
                $calls[] = [$signature, $source];

                return $inner->map($signature, $source);
            }
        );

        return $mock;
    }

    private function middleware(TreeMapper $mapper): ValinorRequestMapperMiddleware
    {
        return RequestMapperMiddlewareBuilder::build(
            $mapper,
            new DefaultMappingErrorResponder(
                new ResponseFactory(),
                new StreamFactory(),
            ),
            $this->createContainer([]),
            self::class,
        );
    }

    private function dispatchingNextHandler(MiddlewareInterface $routeMiddleware): RequestHandlerInterface
    {
        return new class($routeMiddleware) implements RequestHandlerInterface {
            public function __construct(private readonly MiddlewareInterface $middleware) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new EmptyResponse();
                    }
                });
            }
        };
    }

    /**
     * @param array<string, mixed> $services
     */
    private function createContainer(array $services): TrackingContainer
    {
        return new TrackingContainer($services);
    }
}

final class TrackingContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(private readonly array $services,
        /** @var array<string, true> */
        private array $calls = [],) {}

    public function get($id): mixed
    {
        $this->calls[$id] = true;

        if (array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }

        throw new RuntimeException("Service not found: {$id}");
    }

    public function has($id): bool
    {
        return array_key_exists($id, $this->services);
    }

    public function wasCalled(string $id): bool
    {
        return array_key_exists($id, $this->calls);
    }
}

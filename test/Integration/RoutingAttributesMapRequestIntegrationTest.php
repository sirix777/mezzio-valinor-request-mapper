<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Integration;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use LogicException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Routing\Attributes\Attribute\Post;
use Sirix\Mezzio\Routing\Attributes\AttributeRouteProvider;
use Sirix\Mezzio\Routing\Attributes\Cache\NullRouteRegistrarCache;
use Sirix\Mezzio\Routing\Attributes\Cache\RouteCacheGenerator;
use Sirix\Mezzio\Routing\Attributes\Cache\RouteCacheLoader;
use Sirix\Mezzio\Routing\Attributes\Cache\RouteCacheStorage;
use Sirix\Mezzio\Routing\Attributes\Cache\RouteRegistrarCacheInterface;
use Sirix\Mezzio\Routing\Attributes\CompiledRouteRegistrarCache;
use Sirix\Mezzio\Routing\Attributes\Discovery\NullDiscoveredClassesResolver;
use Sirix\Mezzio\Routing\Attributes\DuplicateRouteResolver;
use Sirix\Mezzio\Routing\Attributes\Extractor\AttributeRouteExtractor;
use Sirix\Mezzio\Routing\Attributes\Extractor\AttributeRouteExtractorInterface;
use Sirix\Mezzio\Routing\Attributes\Extractor\ClassEligibilityValidator;
use Sirix\Mezzio\Routing\Attributes\Extractor\MethodSignatureValidator;
use Sirix\Mezzio\Routing\Attributes\Extractor\RouteAttributeReader;
use Sirix\Mezzio\Routing\Attributes\Extractor\RouteDataNormalizer;
use Sirix\Mezzio\Routing\Attributes\Extractor\RouteDefinitionBuilder;
use Sirix\Mezzio\Routing\Attributes\MiddlewarePipelineFactory;
use Sirix\Mezzio\Routing\Attributes\ServiceMiddlewareResolver;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequestMapperMiddlewareBuilder;

use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class RoutingAttributesMapRequestIntegrationTest extends TestCase
{
    #[Test]
    public function classAndMethodMappingsAccumulateWhileMapperMiddlewareIsUnique(): void
    {
        $routes = $this->extractor()->extract([AggregatingMapRequestHandler::class]);

        self::assertCount(1, $routes);
        self::assertSame([ValinorRequestMapperMiddleware::class], $routes[0]->middlewareServices);
        self::assertSame([
            'valinor_mappings' => [
                $this->mapping(query: QueryDto::class, output: 'query'),
                $this->mapping(body: BodyDto::class, output: 'body'),
                $this->mapping(route: RouteDto::class, output: 'route'),
            ],
        ], $routes[0]->defaults);
    }

    #[Test]
    public function finalEmptyMetadataDisablesAssembledMappings(): void
    {
        [$route, $container, $handler, $responder] = $this->register(
            AggregatingMapRequestHandler::class,
            $this->extractor(),
            new NullRouteRegistrarCache(),
        );
        if (! $handler instanceof AggregatingMapRequestHandler) {
            throw new LogicException('Expected the aggregating route handler.');
        }
        self::assertSame([
            $this->mapping(query: QueryDto::class, output: 'query'),
            $this->mapping(body: BodyDto::class, output: 'body'),
            $this->mapping(route: RouteDto::class, output: 'route'),
        ], $route->getOptions()['valinor_mappings']);

        $mapper = $this->createMock(TreeMapper::class);
        $mapper->expects(self::never())->method('map');
        $container->set(ValinorRequestMapperMiddleware::class, RequestMapperMiddlewareBuilder::build(
            $mapper,
            $responder,
            $container,
            self::class,
        ));

        $options                     = $route->getOptions();
        $options['valinor_mappings'] = [];
        $route->setOptions($options);
        $this->resetDtoCounters();

        $response = $route->process($this->request($route), new UnusedRequestHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, QueryDto::$created);
        self::assertSame(0, BodyDto::$created);
        self::assertSame(0, RouteDto::$created);
        self::assertSame(0, $responder->calls);
        self::assertSame(1, $handler->calls);
        self::assertSame([
            'query' => null,
            'body'  => null,
            'route' => null,
        ], $handler->attributes);
        self::assertSame(1, $container->getCalls[ValinorRequestMapperMiddleware::class] ?? 0);
    }

    #[Test]
    public function coldAndCompiledRegistrationRetainOneMapperPipelineAndAllMappings(): void
    {
        $cacheFile = tempnam(sys_get_temp_dir(), 'valinor-routing-attributes-');
        self::assertIsString($cacheFile);
        unlink($cacheFile);

        try {
            [$coldRoute, $coldContainer, $coldHandler, $coldResponder] = $this->register(
                AggregatingMapRequestHandler::class,
                $this->extractor(),
                $this->compiledCache($cacheFile),
            );
            self::assertFileExists($cacheFile);
            if (! $coldHandler instanceof AggregatingMapRequestHandler) {
                throw new LogicException('Expected the aggregating route handler.');
            }
            $this->assertRoutePipelineMapsEachDtoOnce($coldRoute, $coldContainer, $coldHandler, $coldResponder);

            [$compiledRoute, $compiledContainer, $compiledHandler, $compiledResponder] = $this->register(
                AggregatingMapRequestHandler::class,
                new class implements AttributeRouteExtractorInterface {
                    public function extract(array $classes): array
                    {
                        throw new LogicException('A valid compiled route cache must bypass discovery.');
                    }
                },
                $this->compiledCache($cacheFile),
            );
            self::assertSame($coldRoute->getOptions(), $compiledRoute->getOptions());
            if (! $compiledHandler instanceof AggregatingMapRequestHandler) {
                throw new LogicException('Expected the aggregating route handler.');
            }
            $this->assertRoutePipelineMapsEachDtoOnce($compiledRoute, $compiledContainer, $compiledHandler, $compiledResponder);
        } finally {
            if (is_file($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }

    #[Test]
    public function assembledMappingsRejectOutputCollisionsBeforeMapperIsCalled(): void
    {
        [$route, , $handler] = $this->register(
            CollidingMapRequestHandler::class,
            $this->extractor(),
            new NullRouteRegistrarCache(),
        );
        if (! $handler instanceof CollidingMapRequestHandler) {
            throw new LogicException('Expected the colliding route handler.');
        }

        try {
            $this->resetDtoCounters();
            $route->process($this->request($route), new UnusedRequestHandler());
            self::fail('Expected an output collision to abort the route pipeline.');
        } catch (InvalidMapRequestConfiguration) {
            self::assertSame(0, QueryDto::$created);
            self::assertSame(0, BodyDto::$created);
            self::assertSame(0, $handler->calls);
        }
    }

    #[Test]
    public function inactiveMethodMappingWithTheSameOutputDoesNotConflict(): void
    {
        [$route, , $handler] = $this->register(
            MethodFilteredMapRequestHandler::class,
            $this->extractor(),
            new NullRouteRegistrarCache(),
        );

        if (! $handler instanceof MethodFilteredMapRequestHandler) {
            throw new LogicException('Expected the method-filtered route handler.');
        }
        $this->resetDtoCounters();
        $route->process($this->request($route), new UnusedRequestHandler());

        self::assertSame(1, BodyDto::$created);
        self::assertSame(0, QueryDto::$created);
        self::assertSame(1, $handler->calls);
        self::assertInstanceOf(BodyDto::class, $handler->attributes['shared']);
    }

    private function extractor(): AttributeRouteExtractor
    {
        return new AttributeRouteExtractor(
            new ClassEligibilityValidator(),
            new RouteAttributeReader(),
            new RouteDefinitionBuilder(
                new RouteAttributeReader(),
                new MethodSignatureValidator(),
                new RouteDataNormalizer(),
            ),
        );
    }

    private function compiledCache(string $cacheFile): CompiledRouteRegistrarCache
    {
        return new CompiledRouteRegistrarCache(
            $cacheFile,
            new RouteCacheGenerator(),
            new RouteCacheStorage(),
            new RouteCacheLoader(),
        );
    }

    /**
     * @param class-string<RequestHandlerInterface> $handlerClass
     *
     * @return array{Route, IntegrationContainer, RequestHandlerInterface, CountingResponder}
     */
    private function register(
        string $handlerClass,
        AttributeRouteExtractorInterface $extractor,
        RouteRegistrarCacheInterface $cache,
    ): array {
        $handler   = new $handlerClass();
        $responder = new CountingResponder();
        $container = new IntegrationContainer([
            $handlerClass => $handler,
        ]);
        $container->set(ValinorRequestMapperMiddleware::class, RequestMapperMiddlewareBuilder::build(
            (new MapperBuilder())->allowSuperfluousKeys()->mapper(),
            $responder,
            $container,
            self::class,
        ));

        $collector = new IntegrationRouteCollector();
        (new AttributeRouteProvider(
            $extractor,
            [$handlerClass],
            new DuplicateRouteResolver(),
            new MiddlewarePipelineFactory($container, new ServiceMiddlewareResolver()),
            $cache,
            new NullDiscoveredClassesResolver(),
        ))->registerRoutes($collector);

        self::assertCount(1, $collector->routes);

        return [$collector->routes[0], $container, $handler, $responder];
    }

    private function assertRoutePipelineMapsEachDtoOnce(
        Route $route,
        IntegrationContainer $container,
        AggregatingMapRequestHandler $handler,
        CountingResponder $responder,
    ): void {
        $this->resetDtoCounters();
        $response = $route->process($this->request($route), new UnusedRequestHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, QueryDto::$created);
        self::assertSame(1, BodyDto::$created);
        self::assertSame(1, RouteDto::$created);
        self::assertSame(0, $responder->calls);
        self::assertSame(1, $handler->calls);
        self::assertInstanceOf(QueryDto::class, $handler->attributes['query']);
        self::assertInstanceOf(BodyDto::class, $handler->attributes['body']);
        self::assertInstanceOf(RouteDto::class, $handler->attributes['route']);
        self::assertSame(1, $container->getCalls[ValinorRequestMapperMiddleware::class] ?? 0);
    }

    private function resetDtoCounters(): void
    {
        QueryDto::$created = 0;
        BodyDto::$created  = 0;
        RouteDto::$created = 0;
    }

    private function request(Route $route): ServerRequest
    {
        return (new ServerRequest(
            uri: '/mapped',
            method: 'POST',
            queryParams: [
                'page' => '1',
            ],
            parsedBody: [
                'name' => 'Ada',
            ],
        ))->withAttribute(RouteResult::class, RouteResult::fromRoute($route, [
            'id' => '42',
        ]));
    }

    /** @return array<string, null|list<string>|string> */
    private function mapping(?string $body = null, ?string $query = null, ?string $route = null, ?string $output = null): array
    {
        return [
            'body'           => $body,
            'query'          => $query,
            'route'          => $route,
            'source'         => null,
            'output'         => $output,
            'errorResponder' => null,
            'methods'        => [],
        ];
    }
}

#[MapRequest(query: QueryDto::class, output: 'query')]
final class AggregatingMapRequestHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    /** @var array<string, object> */
    public array $attributes = [];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response();
    }

    #[Post('/mapped', name: 'mapped')]
    #[MapRequest(body: BodyDto::class, output: 'body')]
    #[MapRequest(route: RouteDto::class, output: 'route')]
    public function mapped(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $this->attributes = [
            'query' => $request->getAttribute('query'),
            'body'  => $request->getAttribute('body'),
            'route' => $request->getAttribute('route'),
        ];

        return new Response();
    }
}

#[MapRequest(query: QueryDto::class, output: 'shared')]
final class CollidingMapRequestHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response();
    }

    #[Post('/colliding', name: 'colliding')]
    #[MapRequest(body: BodyDto::class, output: 'shared')]
    public function colliding(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;

        return new Response();
    }
}

final class MethodFilteredMapRequestHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    /** @var array<string, object> */
    public array $attributes = [];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response();
    }

    #[Post('/methods', name: 'methods')]
    #[MapRequest(body: BodyDto::class, output: 'shared', methods: ['POST'])]
    #[MapRequest(query: QueryDto::class, output: 'shared', methods: ['GET'])]
    public function methods(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;
        $this->attributes = [
            'shared' => $request->getAttribute('shared'),
        ];

        return new Response();
    }
}

final class QueryDto
{
    public static int $created = 0;

    public function __construct()
    {
        ++self::$created;
    }
}

final class BodyDto
{
    public static int $created = 0;

    public function __construct()
    {
        ++self::$created;
    }
}

final class RouteDto
{
    public static int $created = 0;

    public function __construct()
    {
        ++self::$created;
    }
}

final class CountingResponder implements MappingErrorResponderInterface
{
    public int $calls = 0;

    public function respond(MappingErrorContext $context): ResponseInterface
    {
        ++$this->calls;

        return (new Response())->withStatus(422);
    }
}

final class IntegrationContainer implements ContainerInterface
{
    /** @var array<string, int> */
    public array $getCalls = [];

    /** @param array<string, mixed> $services */
    public function __construct(private array $services) {}

    public function get($id): mixed
    {
        $this->getCalls[$id] = ($this->getCalls[$id] ?? 0) + 1;

        if (! $this->has($id)) {
            throw new LogicException("Unknown service: {$id}");
        }

        return $this->services[$id];
    }

    public function has($id): bool
    {
        return isset($this->services[$id]);
    }

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }
}

final class IntegrationRouteCollector implements RouteCollectorInterface
{
    /** @var list<Route> */
    public array $routes = [];

    public function route(string $path, MiddlewareInterface $middleware, ?array $methods = null, ?string $name = null): Route
    {
        $route          = new Route($path, $middleware, $methods, $name);
        $this->routes[] = $route;

        return $route;
    }

    public function get(string $path, MiddlewareInterface $middleware, ?string $name = null): Route
    {
        return $this->route($path, $middleware, ['GET'], $name);
    }

    public function post(string $path, MiddlewareInterface $middleware, ?string $name = null): Route
    {
        return $this->route($path, $middleware, ['POST'], $name);
    }

    public function put(string $path, MiddlewareInterface $middleware, ?string $name = null): Route
    {
        return $this->route($path, $middleware, ['PUT'], $name);
    }

    public function patch(string $path, MiddlewareInterface $middleware, ?string $name = null): Route
    {
        return $this->route($path, $middleware, ['PATCH'], $name);
    }

    public function delete(string $path, MiddlewareInterface $middleware, ?string $name = null): Route
    {
        return $this->route($path, $middleware, ['DELETE'], $name);
    }

    public function any(string $path, MiddlewareInterface $middleware, ?string $name = null): Route
    {
        return $this->route($path, $middleware, null, $name);
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
}

final class UnusedRequestHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new LogicException('The route handler should produce the response.');
    }
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Factory\DefaultMappingErrorResponderFactory;
use Sirix\Mezzio\Valinor\Factory\HttpRequestSourceFactoryFactory;
use Sirix\Mezzio\Valinor\Factory\MappingErrorResponderResolverFactory;
use Sirix\Mezzio\Valinor\Factory\MappingPlanResolverFactory;
use Sirix\Mezzio\Valinor\Factory\MapRequestResolverFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorMapperBuilderFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorRequestMapperMiddlewareFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorTreeMapperFactory;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CollidingFieldRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\IntIdRouteRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\IntPageBodyRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\IntPageQueryRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequestObjectRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\SearchRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\StrictBodyRequest;

use function in_array;
use function json_decode;

/**
 * Validates the actual HTTP-mapping semantics exposed by Valinor's HttpRequest.
 *
 * These integration tests use the real MapperBuilder factory and middleware factory
 * so that builder flags are applied exactly as in production. The goal is to
 * document the boundary between Valinor's HTTP mapping and direct array mapping.
 */
final class HttpMappingSemanticsTest extends TestCase
{
    #[Test]
    public function bodyDtoIgnoresSuperfluousTopLevelKeysWhenFlagIsFalse(): void
    {
        $middleware = $this->middleware([
            'allow_superfluous_keys' => false,
        ]);
        $request = $this->request(RequestMethodInterface::METHOD_POST, [
            'name'  => 'Ada',
            'extra' => 'ignored',
        ]);

        $handler = new #[MapRequest(body: StrictBodyRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(StrictBodyRequest::class));
            }
        };

        $response = $this->processRoute($middleware, $request, $handler);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame('Ada', $body['name']);
        self::assertArrayNotHasKey('extra', $body);
    }

    #[Test]
    public function directArrayMappingFailsWithSuperfluousKeysWhenFlagIsFalse(): void
    {
        $mapper = $this->mapper([
            'allow_superfluous_keys' => false,
        ]);

        $this->expectException(MappingError::class);

        $mapper->map('array{name: string}', [
            'name'  => 'Ada',
            'extra' => 'should fail',
        ]);
    }

    #[Test]
    public function queryStringIsCastToIntWhenScalarCastingIsFalse(): void
    {
        $middleware = $this->middleware([
            'allow_scalar_value_casting' => false,
        ]);
        $request = $this->request(RequestMethodInterface::METHOD_GET, query: [
            'page' => '2',
        ]);

        $handler = new #[MapRequest(query: IntPageQueryRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(IntPageQueryRequest::class));
            }
        };

        $response = $this->processRoute($middleware, $request, $handler);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(2, $body['page']);
    }

    #[Test]
    public function routeStringIsCastToIntWhenScalarCastingIsFalse(): void
    {
        $middleware = $this->middleware([
            'allow_scalar_value_casting' => false,
        ]);
        $request = $this->request(RequestMethodInterface::METHOD_GET);

        $handler = new #[MapRequest(route: IntIdRouteRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(IntIdRouteRequest::class));
            }
        };

        $response = $this->processRoute(
            $middleware,
            $request,
            $handler,
            routeParams: [
                'id' => '42',
            ],
            path: '/items/{id}',
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(42, $body['id']);
    }

    #[Test]
    public function bodyStringIsNotCastToIntWhenScalarCastingIsFalse(): void
    {
        $middleware = $this->middleware([
            'allow_scalar_value_casting' => false,
        ]);
        $request = $this->request(RequestMethodInterface::METHOD_POST, [
            'page' => '2',
        ]);

        $handler = new #[MapRequest(body: IntPageBodyRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $response = $this->processRoute($middleware, $request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function bodyStringIsCastToIntWhenScalarCastingIsTrue(): void
    {
        $middleware = $this->middleware([
            'allow_scalar_value_casting' => true,
        ]);
        $request = $this->request(RequestMethodInterface::METHOD_POST, [
            'page' => '2',
        ]);

        $handler = new #[MapRequest(body: IntPageBodyRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(IntPageBodyRequest::class));
            }
        };

        $response = $this->processRoute($middleware, $request, $handler);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(2, $body['page']);
    }

    #[Test]
    public function sourceDtoWithExplicitFromAttributesReadsOnlySpecifiedSource(): void
    {
        $middleware = $this->middleware([
            'allow_permissive_types' => true,
        ]);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'q'      => 'from body, should be ignored',
            'locale' => 'from body, should be ignored',
        ], [
            'q' => 'search term',
        ]);

        $handler = new #[MapRequest(source: SearchRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(SearchRequest::class));
            }
        };

        $response = $this->processRoute(
            $middleware,
            $request,
            $handler,
            routeParams: [
                'locale' => 'en',
            ],
            path: '/:locale/search',
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('search term', $body['q']);
        self::assertSame('en', $body['locale']);
        self::assertNull($body['filters']);
    }

    #[Test]
    public function sourceDtoWithoutFromAttributesFailsOnCollidingFieldBetweenSources(): void
    {
        $middleware = $this->middleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'value' => 'body value',
        ], [
            'value' => 'query value',
        ]);

        $handler = new #[MapRequest(source: CollidingFieldRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $response = $this->processRoute($middleware, $request, $handler);

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Mapping failed', $body['error']);
        self::assertArrayHasKey('messages', $body);
    }

    #[Test]
    public function dtoWithServerRequestInterfaceReceivesCurrentRequestObject(): void
    {
        $middleware = $this->middleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'Ada',
        ]);
        $request = $request->withHeader('X-Custom', 'present');

        $handler = new #[MapRequest(body: RequestObjectRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var RequestObjectRequest $dto */
                $dto = $request->getAttribute(RequestObjectRequest::class);

                return new JsonResponse([
                    'name'          => $dto->name,
                    'custom_header' => $dto->requestObject->getHeaderLine('X-Custom'),
                ]);
            }
        };

        $response = $this->processRoute($middleware, $request, $handler);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame('Ada', $body['name']);
        self::assertSame('present', $body['custom_header']);
    }

    #[Test]
    public function routeOptionsProduceSameHttpSemanticsAsAttributes(): void
    {
        $middleware = $this->middleware([
            'allow_superfluous_keys'     => false,
            'allow_scalar_value_casting' => false,
        ]);
        $request = $this->request(RequestMethodInterface::METHOD_GET, query: [
            'page' => '5',
        ]);

        $handler = new class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(IntPageQueryRequest::class));
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_GET]);
        $route->setOptions([
            'valinor_mappings' => [[
                'query'   => IntPageQueryRequest::class,
                'methods' => [],
            ]],
        ]);
        $request = $request->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []));

        $response = $middleware->process($request, $this->nextHandler($handler));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(5, $body['page']);
    }

    /**
     * @param array<string, mixed> $mapperConfig
     */
    private function middleware(array $mapperConfig = []): ValinorRequestMapperMiddleware
    {
        return (new ValinorRequestMapperMiddlewareFactory())($this->container($mapperConfig));
    }

    /**
     * @param array<string, mixed> $mapperConfig
     */
    private function mapper(array $mapperConfig = []): TreeMapper
    {
        return (new ValinorTreeMapperFactory())($this->container($mapperConfig));
    }

    /**
     * @param array<string, mixed> $mapperConfig
     */
    private function container(array $mapperConfig = []): ContainerInterface
    {
        $config = [
            'sirix_mezzio_valinor' => [
                'mapper' => $mapperConfig,
            ],
        ];

        return new class($config) implements ContainerInterface {
            /**
             * @param array<string, mixed> $config
             */
            public function __construct(private readonly array $config) {}

            public function get(string $id): mixed
            {
                return match ($id) {
                    'config'                                  => $this->config,
                    MapperBuilder::class                      => (new ValinorMapperBuilderFactory())($this),
                    TreeMapper::class                         => (new ValinorTreeMapperFactory())($this),
                    MappingPlanResolver::class                => (new MappingPlanResolverFactory())($this),
                    MapRequestResolver::class                 => (new MapRequestResolverFactory())($this),
                    HandlerTargetResolver::class              => new HandlerTargetResolver(),
                    MapRequestOptionsParser::class            => new MapRequestOptionsParser(),
                    HttpMethodNormalizer::class               => new HttpMethodNormalizer(),
                    InputEncodingValidator::class             => new InputEncodingValidator(),
                    HttpRequestSourceFactory::class           => (new HttpRequestSourceFactoryFactory())($this),
                    DefaultMappingErrorResponder::class       => (new DefaultMappingErrorResponderFactory())($this),
                    MappingErrorResponderInterface::class     => $this->get(DefaultMappingErrorResponder::class),
                    MappingErrorResponderResolver::class      => (new MappingErrorResponderResolverFactory())($this),
                    ResponseFactoryInterface::class           => new ResponseFactory(),
                    StreamFactoryInterface::class             => new StreamFactory(),
                    default                                   => throw new RuntimeException("Service not found: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [
                    'config',
                    MapperBuilder::class,
                    TreeMapper::class,
                    MappingPlanResolver::class,
                    MapRequestResolver::class,
                    HandlerTargetResolver::class,
                    MapRequestOptionsParser::class,
                    HttpMethodNormalizer::class,
                    InputEncodingValidator::class,
                    HttpRequestSourceFactory::class,
                    DefaultMappingErrorResponder::class,
                    MappingErrorResponderInterface::class,
                    MappingErrorResponderResolver::class,
                    ResponseFactoryInterface::class,
                    StreamFactoryInterface::class,
                ], true);
            }
        };
    }

    /**
     * @param null|array<string, mixed> $body
     * @param array<string, mixed>      $query
     */
    private function request(string $method, ?array $body = null, array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequest())->withMethod($method);

        if (null !== $body) {
            $request = $request->withParsedBody($body);
        }

        if ([] !== $query) {
            return $request->withQueryParams($query);
        }

        return $request;
    }

    /**
     * @param array<string, string> $routeParams
     * @param non-empty-string      $path
     */
    private function processRoute(
        ValinorRequestMapperMiddleware $middleware,
        ServerRequestInterface $request,
        MiddlewareInterface $routeMiddleware,
        array $routeParams = [],
        string $path = '/example',
    ): ResponseInterface {
        $request = $request->withAttribute(
            RouteResult::class,
            RouteResult::fromRoute(new Route($path, $routeMiddleware, [RequestMethodInterface::METHOD_GET]), $routeParams),
        );

        $next = $routeMiddleware instanceof RequestHandlerInterface
            ? $this->nextHandler($routeMiddleware)
            : $this->nextMiddlewareHandler($routeMiddleware);

        return $middleware->process($request, $next);
    }

    private function nextHandler(RequestHandlerInterface $routeHandler): RequestHandlerInterface
    {
        return new class($routeHandler) implements RequestHandlerInterface {
            public function __construct(private readonly RequestHandlerInterface $handler) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->handler->handle($request);
            }
        };
    }

    private function nextMiddlewareHandler(MiddlewareInterface $routeMiddleware): RequestHandlerInterface
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
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
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
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Factory\DefaultMappingErrorResponderFactory;
use Sirix\Mezzio\Valinor\Factory\MappingErrorResponderResolverFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorRequestMapperMiddlewareFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorTreeMapperFactory;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\ProblemDetailsResponder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\UnregisteredResponder;
use stdClass;

use function array_key_exists;
use function in_array;
use function json_decode;

final class ValinorRequestMapperMiddlewareFactoryTest extends TestCase
{
    #[Test]
    public function usesTreeMapperServiceWhenRegistered(): void
    {
        $treeMapper = (new MapperBuilder())
            ->allowScalarValueCasting()
            ->mapper()
        ;
        $container = $this->createContainer([
            'config'          => [
                'sirix_mezzio_valinor' => [
                    'mapper' => [
                        'allow_scalar_value_casting' => false,
                    ],
                ],
            ],
            TreeMapper::class => $treeMapper,
        ]);

        $middleware = (new ValinorRequestMapperMiddlewareFactory())($container);

        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(
                    $request->getAttribute(RequiredRequest::class),
                );
            }
        };

        $request = (new ServerRequest())
            ->withParsedBody([
                'name' => 123,
            ])
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withAttribute(
                RouteResult::class,
                RouteResult::fromRoute(new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]), []),
            )
        ;

        $response = $middleware->process($request, $this->nextHandler($handler));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame('123', $body['name']);
    }

    #[Test]
    public function requiresServicesRegisteredByTheConfigProvider(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException("Service not found: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $this->expectException(MissingContainerServiceException::class);

        (new ValinorRequestMapperMiddlewareFactory())($container);
    }

    #[Test]
    public function resolvesPerMappingErrorResponderFromContainer(): void
    {
        $container = $this->createContainer([
            'config'                       => [
                'sirix_mezzio_valinor' => [],
            ],
            ProblemDetailsResponder::class => new ProblemDetailsResponder(),
        ]);
        $middleware = (new ValinorRequestMapperMiddlewareFactory())($container);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'form', errorResponder: ProblemDetailsResponder::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse([]);
            }
        };

        $request = (new ServerRequest())
            ->withParsedBody([])
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withAttribute(RouteResult::class, RouteResult::fromRoute(new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]), []))
        ;

        $response = $middleware->process($request, $this->nextHandler($handler));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('body', $body['source']);
        self::assertSame(RequiredRequest::class, $body['dto']);
        self::assertSame('form', $body['request_attribute']);
    }

    #[Test]
    public function resolvesPerMappingErrorResponderFromRouteOptions(): void
    {
        $container = $this->createContainer([
            'config'                       => [
                'sirix_mezzio_valinor' => [],
            ],
            ProblemDetailsResponder::class => new ProblemDetailsResponder(),
        ]);
        $middleware = (new ValinorRequestMapperMiddlewareFactory())($container);

        $handler = new class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse([]);
            }
        };
        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [[
                'body'           => RequiredRequest::class,
                'errorResponder' => ProblemDetailsResponder::class,
                'methods'        => [],
            ]],
        ]);
        $request = (new ServerRequest())
            ->withParsedBody([])
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $response = $middleware->process($request, $this->nextHandler($handler));

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function fallsBackWhenPerMappingResponderIsNotRegistered(): void
    {
        $container = $this->createContainer([
            'config'                              => [
                'sirix_mezzio_valinor' => [],
            ],
        ]);
        $middleware = (new ValinorRequestMapperMiddlewareFactory())($container);

        $handler = new #[MapRequest(body: RequiredRequest::class, errorResponder: UnregisteredResponder::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse([]);
            }
        };

        $request = (new ServerRequest())
            ->withParsedBody([])
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withAttribute(RouteResult::class, RouteResult::fromRoute(new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]), []))
        ;

        $response = $middleware->process($request, $this->nextHandler($handler));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('Mapping failed', $body['error']);
    }

    #[Test]
    public function ignoresRemovedLegacyErrorConfiguration(): void
    {
        $container = $this->createContainer([
            'config' => [
                'sirix_mezzio_valinor' => [
                    'error' => [
                        'status_code' => StatusCodeInterface::STATUS_BAD_REQUEST,
                        'key_case'    => 'snake_case',
                        'message_map' => [
                            'Value {source_value} is not a valid string.' => 'Required.',
                        ],
                    ],
                ],
            ],
        ]);
        $middleware = (new ValinorRequestMapperMiddlewareFactory())($container);

        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse([]);
            }
        };

        $request = (new ServerRequest())
            ->withParsedBody([])
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withAttribute(RouteResult::class, RouteResult::fromRoute(new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]), []))
        ;

        $response = $middleware->process($request, $this->nextHandler($handler));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('name', $body['messages']);
    }

    #[Test]
    public function usesTheRegisteredGlobalResponder(): void
    {
        $container = $this->createContainer([
            'config'                              => [
                'sirix_mezzio_valinor' => [],
            ],
            MappingErrorResponderInterface::class => new ProblemDetailsResponder(),
        ]);
        $middleware = (new ValinorRequestMapperMiddlewareFactory())($container);

        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse([]);
            }
        };

        $request = (new ServerRequest())
            ->withParsedBody([])
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withAttribute(RouteResult::class, RouteResult::fromRoute(new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]), []))
        ;

        $response = $middleware->process($request, $this->nextHandler($handler));

        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function rejectsIncorrectlyTypedGlobalResponderService(): void
    {
        $container = $this->createContainer([
            'config'                              => [
                'sirix_mezzio_valinor' => [],
            ],
            MappingErrorResponderInterface::class => new stdClass(),
        ]);

        $this->expectException(InvalidContainerServiceException::class);

        (new ValinorRequestMapperMiddlewareFactory())($container);
    }

    /**
     * @param array<string, mixed> $services
     */
    private function createContainer(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            /**
             * @param array<string, mixed> $services
             */
            public function __construct(private readonly array $services) {}

            public function get(string $id): mixed
            {
                if (array_key_exists($id, $this->services)) {
                    return $this->services[$id];
                }

                return match ($id) {
                    MapperBuilder::class                     => (new MapperBuilder())->allowSuperfluousKeys()->allowScalarValueCasting(),
                    TreeMapper::class                        => (new ValinorTreeMapperFactory())($this),
                    DefaultMappingErrorResponder::class      => (new DefaultMappingErrorResponderFactory())($this),
                    MappingErrorResponderInterface::class    => $this->get(DefaultMappingErrorResponder::class),
                    MappingErrorResponderResolver::class     => (new MappingErrorResponderResolverFactory())($this),
                    ResponseFactoryInterface::class          => new ResponseFactory(),
                    StreamFactoryInterface::class            => new StreamFactory(),
                    default                                  => throw new RuntimeException("Service not found: {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services)
                    || in_array($id, [
                        MapperBuilder::class,
                        TreeMapper::class,
                        DefaultMappingErrorResponder::class,
                        MappingErrorResponderInterface::class,
                        MappingErrorResponderResolver::class,
                        ResponseFactoryInterface::class,
                        StreamFactoryInterface::class,
                    ], true);
            }
        };
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
}

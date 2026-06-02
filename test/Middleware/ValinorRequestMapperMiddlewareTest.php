<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware;

use CuyZ\Valinor\Mapper\Configurator\ConvertKeysToCamelCase;
use CuyZ\Valinor\Mapper\Tree\Message\Formatter\MessageMapFormatter;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Stratigility\Middleware\CallableMiddlewareDecorator;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CreateBodyRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\PaginationRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\SearchRequest;

use function json_decode;

final class ValinorRequestMapperMiddlewareTest extends TestCase
{
    #[Test]
    public function mapsBodyQueryRouteCombinedIntoOneObject(): void
    {
        $middleware = $this->camelCaseMiddleware();
        $request = $this->request('GET', query: ['q' => 'search term']);

        $handler = new #[MapRequest(source: SearchRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(
                    $request->getAttribute(SearchRequest::class),
                );
            }
        };

        $response = $this->processRoute(
            $middleware,
            $request,
            $handler,
            routeParams: ['locale' => 'en'],
            path: '/:locale/search',
            methods: ['GET'],
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('search term', $body['q']);
        self::assertSame('en', $body['locale']);
        self::assertNull($body['filters']);
    }

    #[Test]
    public function mapsBodyAndQuerySeparately(): void
    {
        $middleware = $this->camelCaseMiddleware();
        $request = $this->request('POST', ['balance' => '100', 'currency_code' => 'USD', 'name' => 'foo'], ['page' => '1']);

        $handler = new #[MapRequest(body: CreateBodyRequest::class, output: 'body')]
        #[MapRequest(query: PaginationRequest::class, output: 'pagination')]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse([
                    'body' => $request->getAttribute('body'),
                    'pagination' => $request->getAttribute('pagination'),
                ]);
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: ['POST']);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['body']['name']);
        self::assertSame('100', $body['body']['balance']);
        self::assertSame('USD', $body['body']['currencyCode']);
        self::assertSame(1, $body['pagination']['page']);
    }

    #[Test]
    public function methodFilterSkipsNonMatchingHttpMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('GET', ['name' => 'foo']);

        $handler = new #[MapRequest(body: RequiredRequest::class, methods: ['POST'])]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public bool $mapped = false;

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->mapped = null !== $request->getAttribute(RequiredRequest::class);

                return new EmptyResponse();
            }
        };

        $this->processRoute($middleware, $request, $handler, methods: ['POST', 'GET']);

        self::assertFalse($handler->mapped);
    }

    #[Test]
    public function methodFilterMatchesLowerCaseConfiguredMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('POST', ['name' => 'foo']);

        $handler = new #[MapRequest(body: RequiredRequest::class, methods: ['post'])]
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

        $response = $this->processRoute($middleware, $request, $handler, methods: ['POST']);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsMethodLevelAttributeFromMiddlewareProcessMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('POST', ['name' => 'foo']);

        $handler = new class implements MiddlewareInterface, RequestHandlerInterface {
            #[MapRequest(body: RequiredRequest::class)]
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

        $response = $this->processRoute($middleware, $request, $handler, methods: ['POST']);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsMethodLevelAttributeFromWrappedRequestHandlerHandleMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('POST', ['name' => 'foo']);

        $handler = new class implements RequestHandlerInterface {
            #[MapRequest(body: RequiredRequest::class)]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(
                    $request->getAttribute(RequiredRequest::class),
                );
            }
        };

        $wrapperClass = RequestHandlerMiddleware::class;
        $wrappedHandler = new $wrapperClass($handler);

        $response = $this->processRoute($middleware, $request, $wrappedHandler, methods: ['POST']);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsMethodLevelAttributeFromCallableMiddlewareMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('POST', ['name' => 'foo']);

        $handler = new class {
            #[MapRequest(body: RequiredRequest::class)]
            public function create(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new JsonResponse(
                    $request->getAttribute(RequiredRequest::class),
                );
            }
        };
        $callableMiddleware = new CallableMiddlewareDecorator($handler->create(...));

        $response = $this->processRoute($middleware, $request, $callableMiddleware, methods: ['POST']);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsMethodLevelAttributeFromInvokableCallableMiddleware(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('POST', ['name' => 'foo']);

        $handler = new class {
            #[MapRequest(body: RequiredRequest::class)]
            public function __invoke(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new JsonResponse(
                    $request->getAttribute(RequiredRequest::class),
                );
            }
        };
        $callableMiddleware = new CallableMiddlewareDecorator($handler);

        $response = $this->processRoute($middleware, $request, $callableMiddleware, methods: ['POST']);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsClassAndMethodLevelAttributesInOrder(): void
    {
        $middleware = $this->camelCaseMiddleware(allowPermissiveTypes: false);
        $request = $this->request('POST', ['name' => 'foo', 'balance' => '100', 'currency_code' => 'USD'], ['page' => '2']);

        $handler = new #[MapRequest(query: PaginationRequest::class, output: 'pagination')]
        class implements MiddlewareInterface, RequestHandlerInterface {
            #[MapRequest(body: CreateBodyRequest::class, output: 'body')]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse([
                    'pagination' => $request->getAttribute('pagination'),
                    'body' => $request->getAttribute('body'),
                ]);
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: ['POST']);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(2, $body['pagination']['page']);
        self::assertSame('foo', $body['body']['name']);
        self::assertSame('USD', $body['body']['currencyCode']);
    }

    #[Test]
    public function methodLevelMethodFilterSkipsNonMatchingHttpMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('GET', ['name' => 'foo']);

        $handler = new class implements MiddlewareInterface, RequestHandlerInterface {
            public bool $mapped = false;

            #[MapRequest(body: RequiredRequest::class, methods: ['POST'])]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->mapped = null !== $request->getAttribute(RequiredRequest::class);

                return new EmptyResponse();
            }
        };

        $this->processRoute($middleware, $request, $handler, methods: ['GET', 'POST']);

        self::assertFalse($handler->mapped);
    }

    #[Test]
    public function noopWhenNoMapRequestAttribute(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('GET');

        $handler = new class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $request = $this->withMatchedRoute($request, $handler, methods: ['GET']);

        $called = false;
        $next = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $middleware->process($request, $next);

        self::assertTrue($next->called);
    }

    #[Test]
    public function noopWhenNoRouteResult(): void
    {
        $middleware = $this->defaultMiddleware();

        $request = new ServerRequest();

        $called = false;
        $next = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $middleware->process($request, $next);

        self::assertTrue($next->called);
    }

    #[Test]
    public function returnsErrorResponseOnMappingFailure(): void
    {
        $middleware = new ValinorRequestMapperMiddleware($this->defaultMapper(), ['status_code' => 422]);
        $request = $this->request('POST', []);

        $handler = new #[MapRequest(body: RequiredRequest::class)]
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

        $response = $this->processRoute($middleware, $request, $handler, methods: ['POST']);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Mapping failed', $body['error']);
        self::assertArrayHasKey('messages', $body);
    }

    #[Test]
    public function formatsErrorResponseWithMessageMapAndSnakeCasePaths(): void
    {
        $middleware = new ValinorRequestMapperMiddleware(
            $this->defaultMapper(),
            ['status_code' => 400, 'key_case' => 'snake_case'],
            new MessageMapFormatter([
                'Cannot be empty and must be filled with a value matching type `string`.' => 'Required.',
            ]),
        );

        $request = $this->request('POST', []);

        $handler = new #[MapRequest(body: RequiredRequest::class)]
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

        $response = $this->processRoute($middleware, $request, $handler, methods: ['POST']);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('name', $body['messages']);
    }

    #[Test]
    public function mapsFromRouteOptionsValinorMappings(): void
    {
        $middleware = $this->camelCaseMiddleware();
        $request = $this->request('POST', ['name' => 'foo', 'balance' => '100', 'currency_code' => 'USD']);

        $handler = new class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(
                    $request->getAttribute(CreateBodyRequest::class),
                );
            }
        };

        $route = new Route('/example', $handler, ['POST']);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'body' => CreateBodyRequest::class,
                    'query' => null,
                    'route' => null,
                    'source' => null,
                    'output' => null,
                    'methods' => [],
                ],
            ],
        ]);
        $request = $request->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []));

        $response = $middleware->process($request, $this->nextHandler($handler));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
        self::assertSame('100', $body['balance']);
        self::assertSame('USD', $body['currencyCode']);
    }

    #[Test]
    public function mapsFromRouteOptionsWithMethodFilter(): void
    {
        $middleware = $this->defaultMiddleware();
        $request = $this->request('GET', ['name' => 'foo']);

        $handler = new class implements MiddlewareInterface, RequestHandlerInterface {
            public bool $mapped = false;

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->mapped = null !== $request->getAttribute(RequiredRequest::class);

                return new EmptyResponse();
            }
        };

        $route = new Route('/example', $handler, ['GET', 'POST']);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'body' => RequiredRequest::class,
                    'query' => null,
                    'route' => null,
                    'source' => null,
                    'output' => null,
                    'methods' => ['POST'],
                ],
            ],
        ]);
        $request = $request->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []));

        $middleware->process($request, $this->nextHandler($handler));

        self::assertFalse($handler->mapped);
    }

    private function defaultMapper(): TreeMapper
    {
        return (new MapperBuilder())->mapper();
    }

    private function defaultMiddleware(): ValinorRequestMapperMiddleware
    {
        return new ValinorRequestMapperMiddleware($this->defaultMapper());
    }

    private function camelCaseMiddleware(bool $allowPermissiveTypes = true): ValinorRequestMapperMiddleware
    {
        $builder = (new MapperBuilder())
            ->configureWith(new ConvertKeysToCamelCase())
            ->allowSuperfluousKeys()
        ;

        if ($allowPermissiveTypes) {
            $builder = $builder->allowPermissiveTypes();
        }

        return new ValinorRequestMapperMiddleware($builder->mapper());
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
     * @param list<string>          $methods
     */
    private function processRoute(
        ValinorRequestMapperMiddleware $middleware,
        ServerRequestInterface $request,
        MiddlewareInterface $routeMiddleware,
        array $routeParams = [],
        string $path = '/example',
        array $methods = ['GET'],
    ): ResponseInterface {
        $request = $this->withMatchedRoute($request, $routeMiddleware, $routeParams, $path, $methods);
        $next = $routeMiddleware instanceof RequestHandlerInterface
            ? $this->nextHandler($routeMiddleware)
            : $this->nextMiddlewareHandler($routeMiddleware);

        return $middleware->process($request, $next);
    }

    /**
     * @param array<string, string> $routeParams
     * @param non-empty-string      $path
     * @param list<string>          $methods
     */
    private function withMatchedRoute(
        ServerRequestInterface $request,
        MiddlewareInterface $routeMiddleware,
        array $routeParams = [],
        string $path = '/example',
        array $methods = ['GET'],
    ): ServerRequestInterface {
        return $request->withAttribute(
            RouteResult::class,
            RouteResult::fromRoute(new Route($path, $routeMiddleware, $methods), $routeParams),
        );
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

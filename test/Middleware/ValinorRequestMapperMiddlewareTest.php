<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware;

use CuyZ\Valinor\Mapper\Configurator\ConvertKeysToCamelCase;
use CuyZ\Valinor\MapperBuilder;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
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
        $mapper = (new MapperBuilder())
            ->configureWith(new ConvertKeysToCamelCase())
            ->allowSuperfluousKeys()
            ->allowPermissiveTypes()
            ->mapper()
        ;

        $middleware = new ValinorRequestMapperMiddleware($mapper);

        $request = (new ServerRequest())
            ->withQueryParams(['q' => 'search term'])
            ->withMethod('GET')
        ;

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

        $routeResult = RouteResult::fromRoute(
            new Route('/:locale/search', $handler, ['GET']),
            ['locale' => 'en'],
        );
        $request = $request->withAttribute(RouteResult::class, $routeResult);

        $response = $middleware->process($request, $this->nextHandler($handler));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('search term', $body['q']);
        self::assertSame('en', $body['locale']);
        self::assertNull($body['filters']);
    }

    #[Test]
    public function mapsBodyAndQuerySeparately(): void
    {
        $mapper = (new MapperBuilder())
            ->configureWith(new ConvertKeysToCamelCase())
            ->allowSuperfluousKeys()
            ->allowPermissiveTypes()
            ->mapper()
        ;

        $middleware = new ValinorRequestMapperMiddleware($mapper);

        $request = (new ServerRequest())
            ->withParsedBody(['balance' => '100', 'currency_code' => 'USD', 'name' => 'foo'])
            ->withQueryParams(['page' => '1'])
            ->withMethod('POST')
        ;

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

        $routeResult = RouteResult::fromRoute(
            new Route('/example', $handler, ['POST']),
            [],
        );
        $request = $request->withAttribute(RouteResult::class, $routeResult);

        $response = $middleware->process($request, $this->nextHandler($handler));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['body']['name']);
        self::assertSame('100', $body['body']['balance']);
        self::assertSame('USD', $body['body']['currencyCode']);
        self::assertSame(1, $body['pagination']['page']);
    }

    #[Test]
    public function methodFilterSkipsNonMatchingHttpMethod(): void
    {
        $mapper = (new MapperBuilder())->mapper();
        $middleware = new ValinorRequestMapperMiddleware($mapper);

        $request = (new ServerRequest())
            ->withParsedBody(['name' => 'foo'])
            ->withMethod('GET')
        ;

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

        $routeResult = RouteResult::fromRoute(
            new Route('/example', $handler, ['POST', 'GET']),
            [],
        );
        $request = $request->withAttribute(RouteResult::class, $routeResult);

        $middleware->process($request, $this->nextHandler($handler));

        self::assertFalse($handler->mapped);
    }

    #[Test]
    public function methodFilterMatchesLowerCaseConfiguredMethod(): void
    {
        $mapper = (new MapperBuilder())->mapper();
        $middleware = new ValinorRequestMapperMiddleware($mapper);

        $request = (new ServerRequest())
            ->withParsedBody(['name' => 'foo'])
            ->withMethod('POST')
        ;

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

        $routeResult = RouteResult::fromRoute(
            new Route('/example', $handler, ['POST']),
            [],
        );
        $request = $request->withAttribute(RouteResult::class, $routeResult);

        $response = $middleware->process($request, $this->nextHandler($handler));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function noopWhenNoMapRequestAttribute(): void
    {
        $mapper = (new MapperBuilder())->mapper();
        $middleware = new ValinorRequestMapperMiddleware($mapper);

        $request = (new ServerRequest())->withMethod('GET');

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

        $routeResult = RouteResult::fromRoute(
            new Route('/example', $handler, ['GET']),
            [],
        );
        $request = $request->withAttribute(RouteResult::class, $routeResult);

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
        $mapper = (new MapperBuilder())->mapper();
        $middleware = new ValinorRequestMapperMiddleware($mapper);

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
        $mapper = (new MapperBuilder())->mapper();
        $middleware = new ValinorRequestMapperMiddleware($mapper, ['status_code' => 422]);

        $request = (new ServerRequest())
            ->withParsedBody([])
            ->withMethod('POST')
        ;

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

        $routeResult = RouteResult::fromRoute(
            new Route('/example', $handler, ['POST']),
            [],
        );
        $request = $request->withAttribute(RouteResult::class, $routeResult);

        $response = $middleware->process($request, $this->nextHandler($handler));

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Mapping failed', $body['error']);
        self::assertArrayHasKey('messages', $body);
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

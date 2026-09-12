<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware;

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CaptureResponder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\PaginationRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequestMapperMiddlewareBuilder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\SearchRequest;

use function json_decode;

final class InvalidUtf8IntegrationTest extends TestCase
{
    #[Test]
    public function returnsDefault422ForInvalidUtf8InIntegerQueryValue(): void
    {
        $mapCount   = 0;
        $spyMapper  = $this->spyMapper($mapCount);
        $middleware = $this->middleware($spyMapper);
        $request    = $this->request(RequestMethodInterface::METHOD_GET, null, [
            'page' => "\xB1\x31",
        ]);

        $handler = new #[MapRequest(query: PaginationRequest::class)]
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

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_GET]);

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(0, $mapCount);

        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Mapping failed', $body['error']);
        self::assertSame([
            '' => ['Request input contains invalid UTF-8.'],
        ], $body['messages']);
    }

    #[Test]
    public function invalidUtf8InStringDtoValueProducesRequestInputError(): void
    {
        $responder  = new CaptureResponder();
        $mapCount   = 0;
        $middleware = $this->middleware($this->spyMapper($mapCount), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_GET, null, [
            'name' => "\xB1\x31",
        ]);

        $handler = new #[MapRequest(query: RequiredRequest::class)]
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

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_GET]);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame(0, $mapCount);
        self::assertNotNull($responder->context);
        self::assertInstanceOf(RequestInputError::class, $responder->context->error);
        self::assertSame('invalid_utf8', $responder->context->error->reason);
        self::assertSame('query', $responder->context->error->inputSource);
        self::assertSame(RequiredRequest::class, $responder->context->dtoClass);
        self::assertSame('query', $responder->context->source);
    }

    #[Test]
    public function invalidUtf8InRouteParameterProducesRequestInputError(): void
    {
        $responder  = new CaptureResponder();
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_GET);

        $handler = new #[MapRequest(route: RequiredRequest::class)]
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

        $this->processRoute($middleware, $request, $handler, routeParams: [
            'name' => "\xB1\x31",
        ], methods: [RequestMethodInterface::METHOD_GET]);

        self::assertNotNull($responder->context);
        self::assertInstanceOf(RequestInputError::class, $responder->context->error);
        self::assertSame('route', $responder->context->error->inputSource);
    }

    #[Test]
    public function invalidUtf8InBodyValueProducesRequestInputError(): void
    {
        $responder  = new CaptureResponder();
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => "\xB1\x31",
        ]);

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

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertNotNull($responder->context);
        self::assertInstanceOf(RequestInputError::class, $responder->context->error);
        self::assertSame('body', $responder->context->error->inputSource);
    }

    #[Test]
    public function invalidUtf8InNestedBodyValueProducesRequestInputError(): void
    {
        $responder  = new CaptureResponder();
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'filters' => [
                'tags' => ['valid', "\xB1\x31"],
            ],
        ], [
            'q' => 'search',
        ]);

        $handler = new #[MapRequest(source: SearchRequest::class)]
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

        $this->processRoute($middleware, $request, $handler, routeParams: [
            'locale' => 'en',
        ], methods: [RequestMethodInterface::METHOD_POST]);

        self::assertNotNull($responder->context);
        self::assertInstanceOf(RequestInputError::class, $responder->context->error);
        self::assertSame('body', $responder->context->error->inputSource);
    }

    #[Test]
    public function invalidUtf8InBodyKeyProducesRequestInputError(): void
    {
        $responder  = new CaptureResponder();
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            "\xB1\x31" => 'value',
        ]);

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

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertNotNull($responder->context);
        self::assertInstanceOf(RequestInputError::class, $responder->context->error);
        self::assertSame('body', $responder->context->error->inputSource);
    }

    /** @param array<string, mixed> $body */
    #[Test]
    #[DataProvider('validUtf8BodyValues')]
    public function acceptsValidUtf8ValuesInBodyMapping(string $name, array $body): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, $body);

        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(RequiredRequest::class));
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame($name, $body['name']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function validUtf8BodyValues(): iterable
    {
        yield 'cyrillic'      => [
            'привет', [
                'name' => 'привет',
            ]];

        yield 'emoji'         => [
            '👋🌍', [
                'name' => '👋🌍',
            ]];

        yield 'combining'     => [
            'é', [
                'name' => 'é',
            ]];

        yield 'empty string'  => [
            '', [
                'name' => '',
            ]];
    }

    #[Test]
    public function invalidBodyDoesNotAffectIndependentQueryMapping(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_GET, [
            'name' => "\xB1\x31",
        ], [
            'page' => '7',
        ]);

        $handler = new #[MapRequest(query: PaginationRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(PaginationRequest::class));
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_GET]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(7, $body['page']);
    }

    #[Test]
    public function invalidQueryDoesNotAffectIndependentBodyMapping(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'Ada',
        ], [
            'page' => "\xB1\x31",
        ]);

        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(RequiredRequest::class));
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame('Ada', $body['name']);
    }

    #[Test]
    public function sourceModeReportsRouteBeforeQueryWhenBothAreInvalid(): void
    {
        $responder  = new CaptureResponder();
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'filters' => [
                'active' => true,
            ],
        ], [
            'q' => "\xB1\x31",
        ]);

        $handler = new #[MapRequest(source: SearchRequest::class)]
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

        $this->processRoute($middleware, $request, $handler, routeParams: [
            'locale' => "\xB1\x31",
        ], methods: [RequestMethodInterface::METHOD_POST]);

        self::assertNotNull($responder->context);
        self::assertInstanceOf(RequestInputError::class, $responder->context->error);
        self::assertSame('route', $responder->context->error->inputSource);
    }

    #[Test]
    public function treeMapperIsNotCalledForSourceWithInvalidEncoding(): void
    {
        $mapCount   = 0;
        $spyMapper  = $this->spyMapper($mapCount);
        $middleware = $this->middleware($spyMapper);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => "\xB1\x31",
        ]);

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

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertSame(0, $mapCount);
    }

    #[Test]
    public function originalRequestAndBytesAreUnchangedAfterInputError(): void
    {
        $middleware = $this->defaultMiddleware();
        $body       = [
            'name' => "\xB1\x31",
        ];
        $query      = [
            'page' => "\xB1\x31",
        ];
        $request    = $this->request(RequestMethodInterface::METHOD_POST, $body, $query);

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

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertSame($body, $request->getParsedBody());
        self::assertSame($query, $request->getQueryParams());
    }

    private function defaultMapper(): TreeMapper
    {
        return (new MapperBuilder())->mapper();
    }

    private function defaultMiddleware(): ValinorRequestMapperMiddleware
    {
        return $this->middleware($this->defaultMapper());
    }

    private function middleware(TreeMapper $mapper, ?MappingErrorResponderInterface $responder = null): ValinorRequestMapperMiddleware
    {
        return RequestMapperMiddlewareBuilder::build(
            $mapper,
            $responder ?? $this->defaultResponder(),
            $this->emptyContainer(),
            self::class,
        );
    }

    private function defaultResponder(): DefaultMappingErrorResponder
    {
        return new DefaultMappingErrorResponder(
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException("Service not found: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
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
     * @param list<string>          $methods
     */
    private function processRoute(
        ValinorRequestMapperMiddleware $middleware,
        ServerRequestInterface $request,
        MiddlewareInterface&RequestHandlerInterface $routeMiddleware,
        array $routeParams = [],
        string $path = '/example',
        array $methods = [RequestMethodInterface::METHOD_GET],
    ): ResponseInterface {
        $request = $this->withMatchedRoute($request, $routeMiddleware, $routeParams, $path, $methods);

        return $middleware->process($request, $this->nextHandler($routeMiddleware));
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
        array $methods = [RequestMethodInterface::METHOD_GET],
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

    private function spyMapper(int &$mapCount): TreeMapper
    {
        $inner = (new MapperBuilder())->mapper();
        $mock  = $this->createMock(TreeMapper::class);
        $mock->method('map')->willReturnCallback(
            static function(string $signature, mixed $source) use ($inner, &$mapCount): mixed {
                ++$mapCount;

                return $inner->map($signature, $source);
            }
        );

        return $mock;
    }
}

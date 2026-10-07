<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware;

use Closure;
use CuyZ\Valinor\Mapper\Configurator\ConvertKeysToCamelCase;
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
use Laminas\Stratigility\Middleware\CallableMiddlewareDecorator;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
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
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Factory\MappingErrorResponderResolverFactory;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidatorInterface;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CaptureResponder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CreateBodyRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\IntIdRouteRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\PaginationRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\PositiveIdRouteRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\ProblemDetailsResponder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequestMapperMiddlewareBuilder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequestObjectRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\SearchRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\StatusRouteRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\UnregisteredResponder;
use stdClass;

use function json_decode;

final class ValinorRequestMapperMiddlewareTest extends TestCase
{
    #[Test]
    public function mapsBodyQueryRouteCombinedIntoOneObject(): void
    {
        $middleware = $this->camelCaseMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_GET, query: [
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
                return new JsonResponse(
                    $request->getAttribute(SearchRequest::class),
                );
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
            methods: [RequestMethodInterface::METHOD_GET],
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
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'balance'       => '100',
            'currency_code' => 'USD',
            'name'          => 'foo',
        ], [
            'page' => '1',
        ]);

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
                    'body'       => $request->getAttribute('body'),
                    'pagination' => $request->getAttribute('pagination'),
                ]);
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

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
        $request    = $this->request(RequestMethodInterface::METHOD_GET, [
            'name' => 'foo',
        ]);

        $handler = new #[MapRequest(body: RequiredRequest::class, methods: [RequestMethodInterface::METHOD_POST])]
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

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST, RequestMethodInterface::METHOD_GET]);

        self::assertFalse($handler->mapped);
    }

    #[Test]
    public function methodFilterMatchesLowerCaseConfiguredMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);

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

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function headIsNotTreatedAsGet(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_HEAD);

        $handler = new #[MapRequest(body: RequiredRequest::class, methods: [RequestMethodInterface::METHOD_GET])]
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

        $this->processRoute(
            $middleware,
            $request,
            $handler,
            methods: [RequestMethodInterface::METHOD_HEAD, RequestMethodInterface::METHOD_GET],
        );

        self::assertFalse($handler->mapped);
    }

    #[Test]
    public function mapsMethodLevelAttributeFromMiddlewareProcessMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);

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

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsMethodLevelAttributeFromWrappedRequestHandlerHandleMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);

        $handler = new class implements RequestHandlerInterface {
            #[MapRequest(body: RequiredRequest::class)]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(
                    $request->getAttribute(RequiredRequest::class),
                );
            }
        };

        $wrapperClass   = RequestHandlerMiddleware::class;
        $wrappedHandler = new $wrapperClass($handler);

        $response = $this->processRoute($middleware, $request, $wrappedHandler, methods: [RequestMethodInterface::METHOD_POST]);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function doesNotUnwrapUserMiddlewareWithDecoratorLikePrivateProperties(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);

        $handler = new class implements MiddlewareInterface {
            private const DECORATOR_LIKE_PROPERTIES = ['handler', 'middleware', 'middlewareName'];

            private readonly RequestHandlerInterface $handler;

            private readonly Closure $middleware;

            private readonly string $middlewareName;

            public function __construct()
            {
                $this->handler = new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new EmptyResponse();
                    }
                };
                $this->middleware     = static fn (): ResponseInterface => new EmptyResponse();
                $this->middlewareName = 'not-a-decorator';
            }

            #[MapRequest(body: RequiredRequest::class)]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                foreach (self::DECORATOR_LIKE_PROPERTIES as $property) {
                    if (! isset($this->{$property})) {
                        throw new RuntimeException('Decorator-like properties should be initialized.');
                    }
                }

                return new JsonResponse($request->getAttribute(RequiredRequest::class));
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function ignoresUninitializedDecoratorLikeTypedPropertiesOnUserMiddleware(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);

        $handler = new class implements MiddlewareInterface {
            private const DECORATOR_LIKE_PROPERTIES = ['handler', 'middleware', 'middlewareName'];

            private RequestHandlerInterface $handler;

            private Closure $middleware;

            private string $middlewareName;

            public function initializeDecoratorLikeProperties(): void
            {
                $this->handler = new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new EmptyResponse();
                    }
                };
                $this->middleware     = static fn (): ResponseInterface => new EmptyResponse();
                $this->middlewareName = 'not-a-decorator';
            }

            #[MapRequest(body: RequiredRequest::class)]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                foreach (self::DECORATOR_LIKE_PROPERTIES as $property) {
                    if (isset($this->{$property})) {
                        throw new RuntimeException('Decorator-like properties should remain uninitialized.');
                    }
                }

                return new JsonResponse($request->getAttribute(RequiredRequest::class));
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsMethodLevelAttributeFromCallableMiddlewareMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);

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

        $response = $this->processRoute($middleware, $request, $callableMiddleware, methods: [RequestMethodInterface::METHOD_POST]);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsMethodLevelAttributeFromInvokableCallableMiddleware(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);

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

        $response = $this->processRoute($middleware, $request, $callableMiddleware, methods: [RequestMethodInterface::METHOD_POST]);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function mapsClassAndMethodLevelAttributesInOrder(): void
    {
        $middleware = $this->camelCaseMiddleware(allowPermissiveTypes: false);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name'          => 'foo',
            'balance'       => '100',
            'currency_code' => 'USD',
        ], [
            'page' => '2',
        ]);

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
                    'body'       => $request->getAttribute('body'),
                ]);
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(2, $body['pagination']['page']);
        self::assertSame('foo', $body['body']['name']);
        self::assertSame('USD', $body['body']['currencyCode']);
    }

    #[Test]
    public function methodLevelMethodFilterSkipsNonMatchingHttpMethod(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_GET, [
            'name' => 'foo',
        ]);

        $handler = new class implements MiddlewareInterface, RequestHandlerInterface {
            public bool $mapped = false;

            #[MapRequest(body: RequiredRequest::class, methods: [RequestMethodInterface::METHOD_POST])]
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

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_GET, RequestMethodInterface::METHOD_POST]);

        self::assertFalse($handler->mapped);
    }

    #[Test]
    public function noopWhenNoMapRequestAttribute(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_GET);

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

        $request = $this->withMatchedRoute($request, $handler, methods: [RequestMethodInterface::METHOD_GET]);

        $called = false;
        $next   = new class($called) implements RequestHandlerInterface {
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
        $next   = new class($called) implements RequestHandlerInterface {
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
    public function noopOnUnmatchedRouteResultAndDoesNotCallMapper(): void
    {
        $mapCount   = 0;
        $spyMapper  = $this->spyMapper($mapCount);
        $middleware = $this->middleware($spyMapper);

        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_GET)
            ->withAttribute(
                RouteResult::class,
                RouteResult::fromRouteFailure([RequestMethodInterface::METHOD_GET]),
            )
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

        $middleware->process($request, $next);

        self::assertTrue($next->called);
        self::assertSame(0, $mapCount);
    }

    #[Test]
    public function returnsErrorResponseOnMappingFailure(): void
    {
        $middleware = $this->middleware($this->defaultMapper(), $this->defaultResponder());
        $request    = $this->request(RequestMethodInterface::METHOD_POST, []);

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

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([
            'error'    => 'Mapping failed',
            'messages' => $body['messages'],
        ], $body);
    }

    #[Test]
    public function delegatesMappingErrorsToCustomDefaultResponderWithContext(): void
    {
        $responder = new class implements MappingErrorResponderInterface {
            public ?MappingErrorContext $context = null;

            public function respond(MappingErrorContext $context): ResponseInterface
            {
                $this->context = $context;

                return new JsonResponse([
                    'handled' => true,
                ], StatusCodeInterface::STATUS_CONFLICT);
            }
        };
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, []);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'form')]
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

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertInstanceOf(MappingErrorContext::class, $responder->context);
        self::assertSame(RequiredRequest::class, $responder->context->dtoClass);
        self::assertSame('body', $responder->context->source);
        self::assertSame('form', $responder->context->requestAttributeKey);
        self::assertSame($request->getMethod(), $responder->context->request->getMethod());
    }

    #[Test]
    public function mappingErrorContextForQuerySource(): void
    {
        $responder  = $this->captureResponder();
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_GET, null, []);

        $handler = new #[MapRequest(query: PaginationRequest::class, output: 'pagination')]
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

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_GET]);

        self::assertInstanceOf(MappingErrorContext::class, $responder->context);
        self::assertSame(PaginationRequest::class, $responder->context->dtoClass);
        self::assertSame('query', $responder->context->source);
        self::assertSame('pagination', $responder->context->requestAttributeKey);
        self::assertInstanceOf(MapRequest::class, $responder->context->mapRequest);
    }

    #[Test]
    public function mappingErrorContextForRouteSource(): void
    {
        $responder  = $this->captureResponder();
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_GET);

        $handler = new #[MapRequest(route: IntIdRouteRequest::class, output: 'route')]
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

        $this->processRoute(
            $middleware,
            $request,
            $handler,
            routeParams: [
                'id' => 'not-an-int',
            ],
            path: '/example/{id}',
            methods: [RequestMethodInterface::METHOD_GET],
        );

        self::assertInstanceOf(MappingErrorContext::class, $responder->context);
        self::assertSame(IntIdRouteRequest::class, $responder->context->dtoClass);
        self::assertSame('route', $responder->context->source);
        self::assertSame('route', $responder->context->requestAttributeKey);
    }

    #[Test]
    public function mappingErrorContextForCombinedSourceMode(): void
    {
        $responder  = $this->captureResponder();
        $middleware = $this->middleware($this->permissiveMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_GET);

        $handler = new #[MapRequest(source: SearchRequest::class, output: 'search')]
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

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_GET]);

        self::assertInstanceOf(MappingErrorContext::class, $responder->context);
        self::assertSame(SearchRequest::class, $responder->context->dtoClass);
        self::assertSame('source', $responder->context->source);
        self::assertSame('search', $responder->context->requestAttributeKey);
    }

    #[Test]
    public function rejectsObjectParsedBodyBeforeMappingAndPassesInputErrorToResponder(): void
    {
        $responder = new class implements MappingErrorResponderInterface {
            public ?MappingErrorContext $context = null;

            public function respond(MappingErrorContext $context): ResponseInterface
            {
                $this->context = $context;

                return new JsonResponse([
                    'handled' => true,
                ], StatusCodeInterface::STATUS_CONFLICT);
            }
        };
        $mapCount   = 0;
        $middleware = $this->middleware($this->spyMapper($mapCount), $responder);
        $request    = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody(new stdClass())
        ;

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'form')]
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

        $called  = false;
        $request = $this->withMatchedRoute($request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
        $next    = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $response = $middleware->process($request, $next);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame(0, $mapCount);
        self::assertFalse($next->called);
        self::assertInstanceOf(MappingErrorContext::class, $responder->context);
        $context = $responder->context;
        self::assertInstanceOf(RequestInputError::class, $context->error);
        self::assertSame('unsupported_parsed_body', $context->error->reason);
        self::assertSame('body', $context->error->inputSource);
        self::assertSame(RequiredRequest::class, $context->dtoClass);
        self::assertSame('body', $context->source);
        self::assertSame('form', $context->requestAttributeKey);
    }

    #[Test]
    public function disabledMappingDoesNotReadOrValidateInput(): void
    {
        $mapperCalls = 0;
        $validator   = new MiddlewareCountingInputEncodingValidator();
        $middleware  = $this->middleware(
            $this->spyMapper($mapperCalls),
            sourceFactory: new HttpRequestSourceFactory($validator),
        );
        $reads   = new MiddlewareRequestReads();
        $request = (new MiddlewareCountingServerRequest($reads))
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => 'Ada',
            ])
            ->withQueryParams([
                'page' => "\xB1\x31",
            ])
        ;

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'body')]
        #[MapRequest(query: PaginationRequest::class, output: 'query')]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new EmptyResponse();
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [],
        ]);
        $request = $request->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []));

        $middleware->process($request, $this->nextHandler($handler));

        self::assertSame($request, $handler->request);
        self::assertSame(0, $mapperCalls);
        self::assertSame([], $validator->calls);
        self::assertSame(0, $reads->parsedBodyReads);
        self::assertSame(0, $reads->queryParameterReads);
    }

    #[Test]
    public function firstOperationFailureDoesNotReadOrValidateSourcesOfLaterOperations(): void
    {
        $validator  = new MiddlewareCountingInputEncodingValidator();
        $responder  = new CaptureResponder();
        $middleware = $this->middleware(
            $this->defaultMapper(),
            $responder,
            new HttpRequestSourceFactory($validator),
        );
        $reads   = new MiddlewareRequestReads();
        $request = (new MiddlewareCountingServerRequest($reads))
            ->withMethod(RequestMethodInterface::METHOD_POST)
            ->withParsedBody([
                'name' => "\xB1\x31",
            ])
        ;

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'body')]
        #[MapRequest(source: SearchRequest::class, output: 'source')]
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

        $request = $this->withMatchedRoute($request, $handler, [
            'id' => "\xB1\x31",
        ], methods: [RequestMethodInterface::METHOD_POST]);

        $middleware->process($request, $this->nextHandler($handler));

        self::assertSame(1, $reads->parsedBodyReads);
        self::assertSame(0, $reads->queryParameterReads);
        self::assertSame([
            'body' => 1,
        ], $validator->calls);
        self::assertNotNull($responder->context);
        self::assertSame('body', $responder->context->source);
    }

    #[Test]
    public function nullParsedBodyIsMappedAsAnEmptyArrayInsteadOfAnInputError(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = (new ServerRequest())->withMethod(RequestMethodInterface::METHOD_POST);

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

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertArrayHasKey('name', $body['messages']);
        self::assertSame(null, $request->getParsedBody());
    }

    #[Test]
    public function queryAndRouteMappingsDoNotReadAnUnsupportedParsedBody(): void
    {
        $middleware = $this->defaultMiddleware();
        $handler    = new class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse([
                    'query' => $request->getAttribute('query'),
                    'route' => $request->getAttribute('route'),
                ]);
            }
        };
        $route = new Route('/example/{name}', $handler, [RequestMethodInterface::METHOD_GET]);
        $route->setOptions([
            'valinor_mappings' => [[
                'query'   => PaginationRequest::class,
                'output'  => 'query',
                'methods' => [],
            ], [
                'route'   => RequiredRequest::class,
                'output'  => 'route',
                'methods' => [],
            ]],
        ]);
        $request = (new ServerRequest())
            ->withMethod(RequestMethodInterface::METHOD_GET)
            ->withParsedBody(new stdClass())
            ->withQueryParams([
                'page' => '2',
            ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, [
                'name' => 'Ada',
            ]))
        ;

        $response = $middleware->process($request, $this->nextHandler($handler));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(2, $body['query']['page']);
        self::assertSame('Ada', $body['route']['name']);
    }

    #[Test]
    public function doesNotCatchRuntimeExceptionsThrownByTheMapper(): void
    {
        $mapper = $this->createMock(TreeMapper::class);
        $mapper->method('map')->willThrowException(new RuntimeException('Application mapper failure.'));
        $middleware = $this->middleware($mapper);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'Ada',
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Application mapper failure.');

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
    }

    #[Test]
    public function detectsOutputCollisionBetweenBodyAndQueryBeforeMapping(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ], [
            'page' => '1',
        ]);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'dto')]
        #[MapRequest(query: PaginationRequest::class, output: 'dto')]
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

        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage("Output key 'dto' is used by multiple mapping operations");

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
    }

    #[Test]
    public function collisionPreventsAnyMappingAndDownstreamCall(): void
    {
        $mapCount   = 0;
        $spyMapper  = $this->spyMapper($mapCount);
        $middleware = $this->middleware($spyMapper);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ], [
            'page' => '1',
        ]);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'dto')]
        #[MapRequest(query: PaginationRequest::class, output: 'dto')]
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

        $called = false;
        $next   = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $request = $this->withMatchedRoute($request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        try {
            $middleware->process($request, $next);
            self::fail('Expected InvalidMapRequestConfiguration to be thrown.');
        } catch (InvalidMapRequestConfiguration) {
            self::assertSame(0, $mapCount);
            self::assertFalse($next->called);
        }
    }

    #[Test]
    public function collisionIsDetectedBeforeAnyOperationRunsIncludingAValidFirstOne(): void
    {
        $mapCount   = 0;
        $spyMapper  = $this->spyMapper($mapCount);
        $middleware = $this->middleware($spyMapper);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ], [
            'page' => '1',
        ]);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'body')]
        #[MapRequest(query: PaginationRequest::class, output: 'dto')]
        #[MapRequest(route: RequiredRequest::class, output: 'dto')]
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

        $called = false;
        $next   = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $request = $this->withMatchedRoute($request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        try {
            $middleware->process($request, $next);
            self::fail('Expected InvalidMapRequestConfiguration to be thrown.');
        } catch (InvalidMapRequestConfiguration) {
            self::assertSame(0, $mapCount);
            self::assertNull($request->getAttribute('body'));
            self::assertFalse($next->called);
        }
    }

    #[Test]
    public function existingRequestAttributeIsReplacedByMappingResult(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);
        $request = $request->withAttribute(RequiredRequest::class, new PaginationRequest(99));

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

        self::assertSame('foo', $body['name']);
    }

    #[Test]
    public function responderReceivesRequestWithResultOfFirstOperationWhenSecondFailsAndThirdIsSkipped(): void
    {
        $responder = new class implements MappingErrorResponderInterface {
            public ?MappingErrorContext $context = null;

            public function respond(MappingErrorContext $context): ResponseInterface
            {
                $this->context = $context;

                return new JsonResponse([
                    'body_result' => $context->request->getAttribute('body'),
                ], StatusCodeInterface::STATUS_CONFLICT);
            }
        };

        $mapCount   = 0;
        $spyMapper  = $this->spyMapper($mapCount);
        $middleware = $this->middleware($spyMapper, $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ], [
            'page' => '1',
        ]);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'body')]
        #[MapRequest(query: RequiredRequest::class, output: 'query')]
        #[MapRequest(body: RequiredRequest::class, output: 'third')]
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

        $called = false;
        $next   = new class($called) implements RequestHandlerInterface {
            public function __construct(public bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new EmptyResponse();
            }
        };

        $request = $this->withMatchedRoute($request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        $response = $middleware->process($request, $next);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('foo', $body['body_result']['name']);
        self::assertInstanceOf(MappingErrorContext::class, $responder->context);
        self::assertSame('query', $responder->context->source);
        self::assertSame(2, $mapCount);
        self::assertNull($request->getAttribute('third'));
        self::assertFalse($next->called);
    }

    #[Test]
    public function mapsFromRouteOptionsValinorMappings(): void
    {
        $middleware = $this->camelCaseMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name'          => 'foo',
            'balance'       => '100',
            'currency_code' => 'USD',
        ]);

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

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'body'    => CreateBodyRequest::class,
                    'query'   => null,
                    'route'   => null,
                    'source'  => null,
                    'output'  => null,
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
        $request    = $this->request(RequestMethodInterface::METHOD_GET, [
            'name' => 'foo',
        ]);

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

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_GET, RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'body'    => RequiredRequest::class,
                    'query'   => null,
                    'route'   => null,
                    'source'  => null,
                    'output'  => null,
                    'methods' => [RequestMethodInterface::METHOD_POST],
                ],
            ],
        ]);
        $request = $request->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []));

        $middleware->process($request, $this->nextHandler($handler));

        self::assertFalse($handler->mapped);
    }

    #[Test]
    public function invalidRouteOptionsThrowConfigurationError(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST);

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

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => [
                [
                    'methods' => 'POST',
                ],
            ],
        ]);
        $request = $request->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []));

        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('valinor_mappings[0].methods: expected list<string>.');

        $middleware->process($request, $this->nextHandler($handler));
    }

    #[Test]
    public function explicitNullValinorMappingsThrowsConfigurationError(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST);

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

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);
        $route->setOptions([
            'valinor_mappings' => null,
        ]);
        $request = $request->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []));

        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('valinor_mappings: expected list<map<string, mixed>>.');

        $middleware->process($request, $this->nextHandler($handler));
    }

    #[Test]
    public function dtoWithServerRequestInterfaceSeesAttributeFromPreviousOperation(): void
    {
        $middleware = $this->defaultMiddleware();
        $request    = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'Ada',
        ]);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'first')]
        #[MapRequest(body: RequestObjectRequest::class, output: 'second')]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                /** @var RequestObjectRequest $second */
                $second = $request->getAttribute('second');

                return new JsonResponse([
                    'first_name'  => $second->requestObject->getAttribute('first')->name,
                    'second_name' => $second->name,
                ]);
            }
        };

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame('Ada', $body['first_name']);
        self::assertSame('Ada', $body['second_name']);
    }

    #[Test]
    public function repeatedCallsDoNotLeakAttributesBetweenRequests(): void
    {
        $middleware = $this->defaultMiddleware();

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

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);

        $next = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $firstRequest  = null;
            public ?ServerRequestInterface $secondRequest = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if (! $this->firstRequest instanceof ServerRequestInterface) {
                    $this->firstRequest = $request;
                } else {
                    $this->secondRequest = $request;
                }

                return new EmptyResponse();
            }
        };

        $first = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'first',
        ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;
        $second = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'second',
        ])
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;

        $middleware->process($first, $next);
        self::assertNotNull($next->firstRequest);
        self::assertSame('first', $next->firstRequest->getAttribute(RequiredRequest::class)->name);

        $middleware->process($second, $next);
        self::assertNotNull($next->secondRequest);
        self::assertSame('second', $next->secondRequest->getAttribute(RequiredRequest::class)->name);
    }

    #[Test]
    public function mapsRouteOnlyDto(): void
    {
        $middleware = $this->defaultMiddleware();

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
            $this->request(RequestMethodInterface::METHOD_GET),
            $handler,
            routeParams: [
                'id' => '-5',
            ],
            path: '/example/{id}',
            methods: [RequestMethodInterface::METHOD_GET],
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(-5, $body['id']);
    }

    #[Test]
    public function routeOnlyFailureProduces422ThroughResponder(): void
    {
        $middleware = $this->defaultMiddleware();

        $handler = new #[MapRequest(route: PositiveIdRouteRequest::class)]
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

        $response = $this->processRoute(
            $middleware,
            $this->request(RequestMethodInterface::METHOD_GET),
            $handler,
            routeParams: [
                'id' => '-5',
            ],
            path: '/example/{id}',
            methods: [RequestMethodInterface::METHOD_GET],
        );

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('Mapping failed', $body['error']);
        self::assertArrayHasKey('id', $body['messages']);
    }

    #[Test]
    public function mapsRouteOnlyEnumDto(): void
    {
        $middleware = $this->defaultMiddleware();

        $handler = new #[MapRequest(route: StatusRouteRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse($request->getAttribute(StatusRouteRequest::class));
            }
        };

        $response = $this->processRoute(
            $middleware,
            $this->request(RequestMethodInterface::METHOD_GET),
            $handler,
            routeParams: [
                'status' => 'active',
            ],
            path: '/example/{status}',
            methods: [RequestMethodInterface::METHOD_GET],
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('active', $body['status']);
    }

    #[Test]
    public function routeOnlyEnumFailureProduces422ThroughResponder(): void
    {
        $middleware = $this->defaultMiddleware();

        $handler = new #[MapRequest(route: StatusRouteRequest::class)]
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

        $response = $this->processRoute(
            $middleware,
            $this->request(RequestMethodInterface::METHOD_GET),
            $handler,
            routeParams: [
                'status' => 'invalid',
            ],
            path: '/example/{status}',
            methods: [RequestMethodInterface::METHOD_GET],
        );

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('Mapping failed', $body['error']);
        self::assertArrayHasKey('status', $body['messages']);
    }

    #[Test]
    public function responderExceptionIsNotCaughtByMiddleware(): void
    {
        $responder = new class implements MappingErrorResponderInterface {
            public function respond(MappingErrorContext $context): ResponseInterface
            {
                throw new RuntimeException('Responder failure.');
            }
        };
        $middleware = $this->middleware($this->defaultMapper(), $responder);
        $request    = $this->request(RequestMethodInterface::METHOD_POST, []);

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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Responder failure.');

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('missingExplicitResponderErrorPaths')]
    public function missingExplicitResponderEscapesBothErrorPaths(array $query): void
    {
        $defaultResponderCalls = 0;
        $defaultResponder      = $this->createMock(MappingErrorResponderInterface::class);
        $defaultResponder->method('respond')->willReturnCallback(
            static function() use (&$defaultResponderCalls): ResponseInterface {
                ++$defaultResponderCalls;

                return new EmptyResponse();
            },
        );
        $middleware = RequestMapperMiddlewareBuilder::build(
            $this->defaultMapper(),
            $defaultResponder,
            $this->emptyContainer(),
            MappingErrorResponderResolverFactory::class,
        );
        $handler = new #[MapRequest(query: RequiredRequest::class, errorResponder: UnregisteredResponder::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public int $calls = 0;

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return new EmptyResponse();
            }
        };

        try {
            $this->processRoute($middleware, $this->request(RequestMethodInterface::METHOD_GET, query: $query), $handler);
            self::fail('A missing explicit responder must escape the middleware.');
        } catch (MissingContainerServiceException $caught) {
            self::assertStringContainsString(UnregisteredResponder::class, $caught->getMessage());
            self::assertStringContainsString(MappingErrorResponderResolverFactory::class, $caught->getMessage());
        } finally {
            self::assertSame(0, $defaultResponderCalls);
            self::assertSame(0, $handler->calls);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function missingExplicitResponderErrorPaths(): iterable
    {
        yield 'MappingError: required property missing' => [[]];

        yield 'RequestInputError: invalid UTF-8 query value' => [[
            'name' => "\xB1\x31",
        ]];
    }

    #[Test]
    public function successfulMappingDoesNotResolveExplicitResponder(): void
    {
        $explicitIdLookups = [];
        $container         = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static function(string $id) use (&$explicitIdLookups): bool {
                $explicitIdLookups[] = ['has', $id];

                return false;
            },
        );
        $container->method('get')->willReturnCallback(
            static function(string $id) use (&$explicitIdLookups): never {
                $explicitIdLookups[] = ['get', $id];

                throw new RuntimeException("Service not found: {$id}");
            },
        );
        $middleware = RequestMapperMiddlewareBuilder::build(
            $this->defaultMapper(),
            $this->defaultResponder(),
            $container,
            MappingErrorResponderResolverFactory::class,
        );
        $handler = new #[MapRequest(body: RequiredRequest::class, errorResponder: UnregisteredResponder::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public int $calls = 0;

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return new JsonResponse($request->getAttribute(RequiredRequest::class));
            }
        };

        $response = $this->processRoute(
            $middleware,
            $this->request(RequestMethodInterface::METHOD_POST, [
                'name' => 'mapped',
            ]),
            $handler,
            methods: [RequestMethodInterface::METHOD_POST],
        );

        self::assertSame([], $explicitIdLookups);
        self::assertSame(1, $handler->calls);
        self::assertSame([
            'name' => 'mapped',
        ], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    public function laterMappingUsesItsOwnExplicitResponder(): void
    {
        $firstResponderCalls  = 0;
        $secondResponderCalls = 0;
        $captureResponder     = new CaptureResponder();
        $firstResponder       = $this->createMock(MappingErrorResponderInterface::class);
        $firstResponder->method('respond')->willReturnCallback(
            static function() use (&$firstResponderCalls): ResponseInterface {
                ++$firstResponderCalls;

                return new EmptyResponse();
            },
        );
        $secondResponder = $this->createMock(MappingErrorResponderInterface::class);
        $secondResponder->method('respond')->willReturnCallback(
            static function(MappingErrorContext $context) use (&$secondResponderCalls, $captureResponder): ResponseInterface {
                ++$secondResponderCalls;

                return $captureResponder->respond($context);
            },
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([
            [ProblemDetailsResponder::class, true],
            [CaptureResponder::class, true],
        ]);
        $container->method('get')->willReturnMap([
            [ProblemDetailsResponder::class, $firstResponder],
            [CaptureResponder::class, $secondResponder],
        ]);
        $defaultResponder = new CaptureResponder();
        $middleware       = RequestMapperMiddlewareBuilder::build(
            $this->defaultMapper(),
            $defaultResponder,
            $container,
            MappingErrorResponderResolverFactory::class,
        );
        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'first', errorResponder: ProblemDetailsResponder::class)]
        #[MapRequest(query: RequiredRequest::class, output: 'second', errorResponder: CaptureResponder::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public int $calls = 0;

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return new EmptyResponse();
            }
        };

        $response = $this->processRoute(
            $middleware,
            $this->request(RequestMethodInterface::METHOD_POST, [
                'name' => 'first mapped',
            ]),
            $handler,
            methods: [RequestMethodInterface::METHOD_POST],
        );

        self::assertSame(0, $firstResponderCalls);
        self::assertSame(1, $secondResponderCalls);
        self::assertNotNull($captureResponder->context);
        self::assertSame('second', $captureResponder->context->requestAttributeKey);
        self::assertSame('query', $captureResponder->context->source);
        self::assertSame(RequiredRequest::class, $captureResponder->context->dtoClass);
        self::assertSame(CaptureResponder::class, $captureResponder->context->mapRequest->errorResponder);
        $firstMapped = $captureResponder->context->request->getAttribute('first');
        self::assertInstanceOf(RequiredRequest::class, $firstMapped);
        self::assertSame('first mapped', $firstMapped->name);
        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame([
            'handled' => true,
        ], json_decode((string) $response->getBody(), true));
        self::assertNull($defaultResponder->context);
        self::assertSame(0, $handler->calls);
    }

    /**
     * @param array<string, string>                             $query
     * @param null|class-string<MappingError|RequestInputError> $errorType
     */
    #[Test]
    #[DataProvider('namedResponderRequests')]
    public function namedResponderIsUsedOnlyForErrors(bool $routeOptions, array $query, ?string $errorType): void
    {
        $responder        = new CaptureResponder();
        $defaultResponder = new CaptureResponder();
        $container        = $this->createMock(ContainerInterface::class);
        $container->expects(null === $errorType ? self::never() : self::once())
            ->method('has')->with('problem.details')->willReturn(true)
        ;
        $container->expects(null === $errorType ? self::never() : self::once())
            ->method('get')->with('problem.details')->willReturn($responder)
        ;
        $middleware = RequestMapperMiddlewareBuilder::build(
            $this->defaultMapper(),
            $defaultResponder,
            $container,
            MappingErrorResponderResolverFactory::class,
        );
        $handler = new #[MapRequest(query: RequiredRequest::class, errorResponder: 'problem.details')]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public int $calls = 0;

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return new JsonResponse($request->getAttribute(RequiredRequest::class));
            }
        };
        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_GET]);

        if ($routeOptions) {
            $route->setOptions([
                'valinor_mappings' => [[
                    'query'          => RequiredRequest::class,
                    'errorResponder' => 'problem.details',
                ]],
            ]);
        }

        $request = $this->request(RequestMethodInterface::METHOD_GET, query: $query)
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;
        $response = $middleware->process($request, $this->nextHandler($handler));

        self::assertNull($defaultResponder->context);

        if (null === $errorType) {
            self::assertNull($responder->context);
            self::assertSame(1, $handler->calls);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame([
                'name' => 'mapped',
            ], json_decode((string) $response->getBody(), true));

            return;
        }

        self::assertSame(0, $handler->calls);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'handled' => true,
        ], json_decode((string) $response->getBody(), true));
        self::assertNotNull($responder->context);
        self::assertInstanceOf($errorType, $responder->context->error);
        self::assertSame('problem.details', $responder->context->mapRequest->errorResponder);
        self::assertSame('query', $responder->context->source);
        self::assertSame(RequiredRequest::class, $responder->context->requestAttributeKey);
    }

    /** @return iterable<string, array{bool, array<string, string>, null|class-string<MappingError|RequestInputError>}> */
    public static function namedResponderRequests(): iterable
    {
        yield 'attribute mapping error' => [false, [], MappingError::class];

        yield 'options mapping error' => [true, [], MappingError::class];

        yield 'attribute input error' => [false, [
            'name' => "\xB1\x31",
        ], RequestInputError::class];

        yield 'options input error' => [true, [
            'name' => "\xB1\x31",
        ], RequestInputError::class];

        yield 'attribute success' => [false, [
            'name' => 'mapped',
        ], null];

        yield 'options success' => [true, [
            'name' => 'mapped',
        ], null];
    }

    /**
     * @return MappingErrorResponderInterface&object{context: null|MappingErrorContext}
     */
    private function captureResponder(): MappingErrorResponderInterface
    {
        return new class implements MappingErrorResponderInterface {
            public ?MappingErrorContext $context = null;

            public function respond(MappingErrorContext $context): ResponseInterface
            {
                $this->context = $context;

                return new EmptyResponse();
            }
        };
    }

    private function defaultMapper(): TreeMapper
    {
        return (new MapperBuilder())->mapper();
    }

    private function permissiveMapper(): TreeMapper
    {
        return (new MapperBuilder())
            ->allowPermissiveTypes()
            ->allowSuperfluousKeys()
            ->mapper()
        ;
    }

    private function defaultMiddleware(): ValinorRequestMapperMiddleware
    {
        return $this->middleware($this->defaultMapper());
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

        return $this->middleware($builder->mapper());
    }

    private function middleware(
        TreeMapper $mapper,
        ?MappingErrorResponderInterface $responder = null,
        ?HttpRequestSourceFactory $sourceFactory = null,
    ): ValinorRequestMapperMiddleware {
        return RequestMapperMiddlewareBuilder::build(
            $mapper,
            $responder ?? $this->defaultResponder(),
            $this->emptyContainer(),
            self::class,
            $sourceFactory ?? new HttpRequestSourceFactory(new InputEncodingValidator()),
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
            public function get($id): mixed
            {
                throw new RuntimeException("Service not found: {$id}");
            }

            public function has($id): bool
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
        MiddlewareInterface $routeMiddleware,
        array $routeParams = [],
        string $path = '/example',
        array $methods = [RequestMethodInterface::METHOD_GET],
    ): ResponseInterface {
        $request = $this->withMatchedRoute($request, $routeMiddleware, $routeParams, $path, $methods);
        $next    = $routeMiddleware instanceof RequestHandlerInterface
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

final class MiddlewareCountingServerRequest extends ServerRequest
{
    public function __construct(private readonly MiddlewareRequestReads $reads)
    {
        parent::__construct();
    }

    /** @return null|array<mixed>|object */
    public function getParsedBody(): mixed
    {
        ++$this->reads->parsedBodyReads;

        return parent::getParsedBody();
    }

    /** @return array<mixed> */
    public function getQueryParams(): array
    {
        ++$this->reads->queryParameterReads;

        return parent::getQueryParams();
    }
}

final class MiddlewareRequestReads
{
    public int $parsedBodyReads = 0;

    public int $queryParameterReads = 0;
}

final class MiddlewareCountingInputEncodingValidator implements InputEncodingValidatorInterface
{
    /** @var array<'body'|'query'|'route', int> */
    public array $calls = [];

    private readonly InputEncodingValidator $inner;

    public function __construct()
    {
        $this->inner = new InputEncodingValidator();
    }

    public function assertValid(array $values, string $inputSource): void
    {
        $this->calls[$inputSource] = ($this->calls[$inputSource] ?? 0) + 1;
        $this->inner->assertValid($values, $inputSource);
    }
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use Fig\Http\Message\RequestMethodInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\PaginationRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\SearchRequest;

final class MappingPlanResolverTest extends TestCase
{
    private MappingPlanResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MappingPlanResolver(
            new MapRequestResolver(
                new HandlerTargetResolver(),
                new MapRequestOptionsParser(),
            ),
            new HttpMethodNormalizer(),
        );
    }

    #[Test]
    public function returnsEmptyPlanWhenNoMatchingHttpMethod(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class, methods: [RequestMethodInterface::METHOD_POST])]
        class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_GET, RequestMethodInterface::METHOD_POST]);

        $operations = $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_GET);

        self::assertSame([], $operations);
    }

    #[Test]
    public function expandsSourceIntoSingleOperation(): void
    {
        $handler = new #[MapRequest(source: SearchRequest::class)]
        class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $route = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_GET]);

        $operations = $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_GET);

        self::assertCount(1, $operations);
        self::assertSame(SearchRequest::class, $operations[0]->dtoClass);
        self::assertSame('source', $operations[0]->source);
        self::assertSame(SearchRequest::class, $operations[0]->requestAttributeKey);
    }

    #[Test]
    public function expandsBodyQueryRouteInOrder(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class, query: PaginationRequest::class, route: SearchRequest::class)]
        class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $route = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_GET]);

        $operations = $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_GET);

        self::assertCount(3, $operations);
        self::assertSame('body', $operations[0]->source);
        self::assertSame(RequiredRequest::class, $operations[0]->dtoClass);
        self::assertSame('query', $operations[1]->source);
        self::assertSame(PaginationRequest::class, $operations[1]->dtoClass);
        self::assertSame('route', $operations[2]->source);
        self::assertSame(SearchRequest::class, $operations[2]->dtoClass);
    }

    #[Test]
    #[DataProvider('targetSignatureProvider')]
    public function typeSignatureIsTheDefaultOutputKey(string $target): void
    {
        $handler = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };
        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_GET]);
        $route->setOptions([
            'valinor_mappings' => [[
                'query' => $target,
            ]],
        ]);

        $operations = $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_GET);

        self::assertCount(1, $operations);
        self::assertSame($target, $operations[0]->dtoClass);
        self::assertSame($target, $operations[0]->requestAttributeKey);
    }

    /** @return iterable<string, array{string}> */
    public static function targetSignatureProvider(): iterable
    {
        yield 'DTO class' => [RequiredRequest::class];

        yield 'generic DTO' => ['Example\GenericDto<int>'];

        yield 'array shape' => ['array{page: int}'];
    }

    #[Test]
    public function throwsOnBodyAndQuerySharingOutput(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'dto')]
        #[MapRequest(query: PaginationRequest::class, output: 'dto')]
        class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $route = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);

        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage("Output key 'dto' is used by multiple mapping operations");

        $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_POST);
    }

    #[Test]
    public function throwsOnTwoSameDtosWithoutExplicitOutput(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class)]
        #[MapRequest(query: RequiredRequest::class)]
        class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $route = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);

        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage("Output key '" . RequiredRequest::class . "' is used by multiple mapping operations");

        $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_POST);
    }

    #[Test]
    public function throwsOnClassAndMethodLevelWithSameOutput(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'dto')]
        class implements MiddlewareInterface {
            #[MapRequest(query: PaginationRequest::class, output: 'dto')]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $route = new Route('/example', $handler, [RequestMethodInterface::METHOD_POST]);

        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage("Output key 'dto' is used by multiple mapping operations");

        $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_POST);
    }

    #[Test]
    public function allowsSameOutputForDifferentHttpMethods(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'dto', methods: [RequestMethodInterface::METHOD_POST])]
        #[MapRequest(query: PaginationRequest::class, output: 'dto', methods: [RequestMethodInterface::METHOD_GET])]
        class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $route = new Route('/example', new RequestHandlerMiddleware($handler), [
            RequestMethodInterface::METHOD_GET,
            RequestMethodInterface::METHOD_POST,
        ]);

        $postOperations = $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_POST);
        self::assertCount(1, $postOperations);
        self::assertSame('body', $postOperations[0]->source);

        $getOperations = $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_GET);
        self::assertCount(1, $getOperations);
        self::assertSame('query', $getOperations[0]->source);
    }

    #[Test]
    public function doesNotStickToTheFirstHttpMethodOnTheSameRoute(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class, methods: [RequestMethodInterface::METHOD_POST])]
        #[MapRequest(query: PaginationRequest::class, methods: [RequestMethodInterface::METHOD_GET])]
        #[MapRequest(route: SearchRequest::class, methods: [RequestMethodInterface::METHOD_HEAD])]
        class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $route = new Route('/example', new RequestHandlerMiddleware($handler), [
            RequestMethodInterface::METHOD_GET,
            RequestMethodInterface::METHOD_POST,
            RequestMethodInterface::METHOD_HEAD,
        ]);
        $routeResult = RouteResult::fromRoute($route, []);

        foreach ([
            [RequestMethodInterface::METHOD_GET, 'query'],
            [RequestMethodInterface::METHOD_POST, 'body'],
            [RequestMethodInterface::METHOD_HEAD, 'route'],
            [RequestMethodInterface::METHOD_GET, 'query'],
        ] as [$method, $source]) {
            $operations = $this->resolver->resolve($routeResult, $method);

            self::assertCount(1, $operations);
            self::assertSame($source, $operations[0]->source);
        }
    }

    #[Test]
    public function throwsWhenSameOutputBecomesActiveForSameMethod(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'dto', methods: [RequestMethodInterface::METHOD_POST])]
        #[MapRequest(query: PaginationRequest::class, output: 'dto', methods: [RequestMethodInterface::METHOD_POST, RequestMethodInterface::METHOD_GET])]
        class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $route = new Route('/example', new RequestHandlerMiddleware($handler), [
            RequestMethodInterface::METHOD_GET,
            RequestMethodInterface::METHOD_POST,
        ]);

        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage("Output key 'dto' is used by multiple mapping operations");

        $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_POST);
    }

    #[Test]
    public function routeOptionsAndAttributesProduceSameCollision(): void
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
                    'body'   => RequiredRequest::class,
                    'output' => 'dto',
                ],
                [
                    'query'  => PaginationRequest::class,
                    'output' => 'dto',
                ],
            ],
        ]);

        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage("Output key 'dto' is used by multiple mapping operations");

        $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_POST);
    }

    #[Test]
    public function differentDtoDefaultsDoNotCollide(): void
    {
        $handler = new #[MapRequest(body: RequiredRequest::class)]
        #[MapRequest(query: PaginationRequest::class)]
        class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $route = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);

        $operations = $this->resolver->resolve(RouteResult::fromRoute($route, []), RequestMethodInterface::METHOD_POST);

        self::assertCount(2, $operations);
        self::assertSame(RequiredRequest::class, $operations[0]->requestAttributeKey);
        self::assertSame(PaginationRequest::class, $operations[1]->requestAttributeKey);
    }
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
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
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\ErrorResponseOptions;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidatorInterface;
use Sirix\Mezzio\Valinor\Mapping\InputLimits;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CaptureResponder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\PaginationRequest;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequestMapperMiddlewareBuilder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;

use function json_decode;
use function strlen;

final class InputLimitsIntegrationTest extends TestCase
{
    #[Test]
    public function rejectedInputNeverInvokesCurrentMapper(): void
    {
        $mapper = $this->createMock(TreeMapper::class);
        $mapper->expects(self::never())->method('map');

        $middleware = $this->middleware($mapper, new InputLimits(maxNodes: 1));

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

        $request = $this->request(RequestMethodInterface::METHOD_POST, [
            'name'  => 'foo',
            'extra' => 'bar',
        ]);

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertSame(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame([
            '' => ['Request input exceeds the node limit.'],
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['messages']);
    }

    #[Test]
    public function inputLimitErrorRespectsConfiguredByteCap(): void
    {
        $responder = new DefaultMappingErrorResponder(
            new ResponseFactory(),
            new StreamFactory(),
            new ErrorResponseOptions(maxResponseBytes: 256),
        );
        $middleware = $this->middlewareWithResponder($this->plainMapper(), $responder, new InputLimits(maxNodes: 1));

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

        $request = $this->request(RequestMethodInterface::METHOD_POST, [
            'name'  => 'foo',
            'extra' => 'bar',
        ]);

        $response = $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);
        $body     = (string) $response->getBody();

        self::assertLessThanOrEqual(256, strlen($body));
        self::assertSame(
            ['Request input exceeds the node limit.'],
            json_decode($body, flags: JSON_THROW_ON_ERROR)->messages->{''},
        );
    }

    #[Test]
    public function queryBudgetDoesNotReadBody(): void
    {
        $reads      = new InputLimitRequestReads();
        $middleware = $this->middleware($this->plainMapper(), new InputLimits(maxNodes: 1));

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

        $request = new InputLimitCountingServerRequest($reads);
        $request = $request
            ->withMethod(RequestMethodInterface::METHOD_GET)
            ->withQueryParams([
                'page' => '2',
            ])
            ->withParsedBody([
                'name' => 'foo',
            ])
        ;

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_GET]);

        self::assertSame(0, $reads->parsedBodyReads);
    }

    #[Test]
    public function sourceFailureReportsActualSource(): void
    {
        $responder  = new CaptureResponder();
        $middleware = $this->middleware($this->plainMapper(), new InputLimits(maxNodes: 1), $responder);

        $handler = new #[MapRequest(source: PaginationRequest::class)]
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

        $request = $this->request(RequestMethodInterface::METHOD_GET, null, [
            'page'  => '2',
            'extra' => 'bar',
        ]);

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_GET]);

        self::assertNotNull($responder->context);
        $error = $responder->context->error;
        self::assertInstanceOf(RequestInputError::class, $error);
        self::assertSame('query', $error->inputSource);
        self::assertSame('input_node_limit_exceeded', $error->reason);
    }

    #[Test]
    public function sameSourceIsValidatedOnce(): void
    {
        $validator  = new InputLimitCountingValidator();
        $middleware = $this->middleware($this->plainMapper(), null, null, $validator);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'first')]
        class implements MiddlewareInterface, RequestHandlerInterface {
            #[MapRequest(body: RequiredRequest::class, output: 'second')]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $request = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ]);

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertSame([
            'body' => 1,
        ], $validator->calls);
    }

    #[Test]
    public function secondOperationFailurePreservesFirstDto(): void
    {
        $responder  = new CaptureResponder();
        $middleware = $this->middleware($this->plainMapper(), new InputLimits(maxNodes: 1), $responder);

        $handler = new #[MapRequest(body: RequiredRequest::class, output: 'first')]
        class implements MiddlewareInterface, RequestHandlerInterface {
            #[MapRequest(query: PaginationRequest::class, output: 'second')]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse();
            }
        };

        $request = $this->request(RequestMethodInterface::METHOD_POST, [
            'name' => 'foo',
        ], [
            'page'  => '2',
            'extra' => 'bar',
        ]);

        $this->processRoute($middleware, $request, $handler, methods: [RequestMethodInterface::METHOD_POST]);

        self::assertNotNull($responder->context);
        self::assertInstanceOf(RequiredRequest::class, $responder->context->request->getAttribute('first'));
        $error = $responder->context->error;
        self::assertInstanceOf(RequestInputError::class, $error);
        self::assertSame('query', $error->inputSource);
    }

    private function plainMapper(): TreeMapper
    {
        return (new MapperBuilder())->mapper();
    }

    private function middleware(
        TreeMapper $mapper,
        ?InputLimits $limits = null,
        ?CaptureResponder $responder = null,
        ?InputEncodingValidatorInterface $validator = null,
    ): ValinorRequestMapperMiddleware {
        return $this->middlewareWithResponder(
            $mapper,
            $responder ?? new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory()),
            $limits,
            $validator,
        );
    }

    private function middlewareWithResponder(
        TreeMapper $mapper,
        MappingErrorResponderInterface $responder,
        ?InputLimits $limits = null,
        ?InputEncodingValidatorInterface $validator = null,
    ): ValinorRequestMapperMiddleware {
        return RequestMapperMiddlewareBuilder::build(
            $mapper,
            $responder,
            $this->emptyContainer(),
            self::class,
            new HttpRequestSourceFactory($validator ?? new InputEncodingValidator($limits)),
        );
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
        $request = $request->withAttribute(
            RouteResult::class,
            RouteResult::fromRoute(new Route($path, $routeMiddleware, $methods), $routeParams),
        );

        return $middleware->process($request, new class($routeMiddleware) implements RequestHandlerInterface {
            public function __construct(private readonly RequestHandlerInterface $handler) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->handler->handle($request);
            }
        });
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
}

final class InputLimitCountingServerRequest extends ServerRequest
{
    public function __construct(private readonly InputLimitRequestReads $reads)
    {
        parent::__construct();
    }

    /** @return null|array<mixed>|object */
    public function getParsedBody(): mixed
    {
        ++$this->reads->parsedBodyReads;

        return parent::getParsedBody();
    }
}

final class InputLimitRequestReads
{
    public int $parsedBodyReads = 0;
}

final class InputLimitCountingValidator implements InputEncodingValidatorInterface
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

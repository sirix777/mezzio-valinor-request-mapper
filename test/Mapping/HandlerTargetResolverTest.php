<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use Closure;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Stratigility\Middleware\CallableMiddlewareDecorator;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Laminas\Stratigility\MiddlewarePipe;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\MiddlewareContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedRequestHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CallableMethodHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\DualInterfaceHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\InvokableHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\LazyLoadingMiddlewareHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\LazyLoadingRequestHandler;
use stdClass;

final class HandlerTargetResolverTest extends TestCase
{
    private HandlerTargetResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new HandlerTargetResolver();
    }

    #[Test]
    public function requestHandlerObjectResolvesToHandle(): void
    {
        $target = $this->resolver->resolve(new AttributedRequestHandler());

        self::assertNotNull($target);
        self::assertSame(AttributedRequestHandler::class, $target->className);
        self::assertSame('handle', $target->methodName);
    }

    #[Test]
    public function requestHandlerClassStringResolvesToHandle(): void
    {
        $target = $this->resolver->resolve(AttributedRequestHandler::class);

        self::assertNotNull($target);
        self::assertSame(AttributedRequestHandler::class, $target->className);
        self::assertSame('handle', $target->methodName);
    }

    #[Test]
    public function middlewareObjectResolvesToProcess(): void
    {
        $target = $this->resolver->resolve(new AttributedMiddleware());

        self::assertNotNull($target);
        self::assertSame(AttributedMiddleware::class, $target->className);
        self::assertSame('process', $target->methodName);
    }

    #[Test]
    public function middlewareClassStringResolvesToProcess(): void
    {
        $target = $this->resolver->resolve(AttributedMiddleware::class);

        self::assertNotNull($target);
        self::assertSame(AttributedMiddleware::class, $target->className);
        self::assertSame('process', $target->methodName);
    }

    #[Test]
    public function dualInterfaceResolvesToProcess(): void
    {
        $target = $this->resolver->resolve(new DualInterfaceHandler());

        self::assertNotNull($target);
        self::assertSame(DualInterfaceHandler::class, $target->className);
        self::assertSame('process', $target->methodName);
    }

    #[Test]
    public function invokableObjectResolvesToInvoke(): void
    {
        $target = $this->resolver->resolve(new InvokableHandler());

        self::assertNotNull($target);
        self::assertSame(InvokableHandler::class, $target->className);
        self::assertSame('__invoke', $target->methodName);
    }

    #[Test]
    public function requestHandlerMiddlewareWrapsInnerHandlerHandle(): void
    {
        $target = $this->resolver->resolve(new RequestHandlerMiddleware(new AttributedRequestHandler()));

        self::assertNotNull($target);
        self::assertSame(AttributedRequestHandler::class, $target->className);
        self::assertSame('handle', $target->methodName);
    }

    #[Test]
    public function requestHandlerMiddlewareIgnoresExtraInterfacesOfInnerHandler(): void
    {
        $inner  = new DualInterfaceHandler();
        $target = $this->resolver->resolve(new RequestHandlerMiddleware($inner));

        self::assertNotNull($target);
        self::assertSame(DualInterfaceHandler::class, $target->className);
        self::assertSame('handle', $target->methodName);
    }

    #[Test]
    public function wrappersOfSameClassAroundDifferentHandlersDoNotMixMetadata(): void
    {
        $first  = $this->resolver->resolve(new RequestHandlerMiddleware(new AttributedRequestHandler()));
        $second = $this->resolver->resolve(new RequestHandlerMiddleware(new DualInterfaceHandler()));

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(AttributedRequestHandler::class, $first->className);
        self::assertSame(DualInterfaceHandler::class, $second->className);
    }

    #[Test]
    public function callableMiddlewareDecoratorWithArrayCallableResolvesToExactMethod(): void
    {
        $handler   = new CallableMethodHandler();
        $method    = 'create';
        $decorator = new CallableMiddlewareDecorator([$handler, $method]);

        $target = $this->resolver->resolve($decorator);

        self::assertNotNull($target);
        self::assertSame(CallableMethodHandler::class, $target->className);
        self::assertSame('create', $target->methodName);
    }

    #[Test]
    public function callableMiddlewareDecoratorWithFirstClassCallableResolvesToRealMethod(): void
    {
        $handler   = new CallableMethodHandler();
        $decorator = new CallableMiddlewareDecorator($handler->create(...));

        $target = $this->resolver->resolve($decorator);

        self::assertNotNull($target);
        self::assertSame(CallableMethodHandler::class, $target->className);
        self::assertSame('create', $target->methodName);
    }

    #[Test]
    public function callableMiddlewareDecoratorWithInvokableObjectResolvesToInvoke(): void
    {
        $decorator = new CallableMiddlewareDecorator(new InvokableHandler());

        $target = $this->resolver->resolve($decorator);

        self::assertNotNull($target);
        self::assertSame(InvokableHandler::class, $target->className);
        self::assertSame('__invoke', $target->methodName);
    }

    #[Test]
    public function callableMiddlewareDecoratorWithStaticStringCallableResolvesToClassAndMethod(): void
    {
        $decorator = new CallableMiddlewareDecorator(StaticCallableHandler::class . '::create');

        $target = $this->resolver->resolve($decorator);

        self::assertNotNull($target);
        self::assertSame(StaticCallableHandler::class, $target->className);
        self::assertSame('create', $target->methodName);
    }

    #[Test]
    public function callableMiddlewareDecoratorWithAnonymousClosureReturnsNull(): void
    {
        $decorator = new CallableMiddlewareDecorator(
            static fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => new JsonResponse([])
        );

        self::assertNull($this->resolver->resolve($decorator));
    }

    #[Test]
    public function lazyLoadingMiddlewareWithRequestHandlerResolvesToHandle(): void
    {
        $lazy = new LazyLoadingMiddleware($this->createMock(MiddlewareContainer::class), LazyLoadingRequestHandler::class);

        $target = $this->resolver->resolve($lazy);

        self::assertNotNull($target);
        self::assertSame(LazyLoadingRequestHandler::class, $target->className);
        self::assertSame('handle', $target->methodName);
    }

    #[Test]
    public function lazyLoadingMiddlewareWithMiddlewareResolvesToProcess(): void
    {
        $lazy = new LazyLoadingMiddleware($this->createMock(MiddlewareContainer::class), LazyLoadingMiddlewareHandler::class);

        $target = $this->resolver->resolve($lazy);

        self::assertNotNull($target);
        self::assertSame(LazyLoadingMiddlewareHandler::class, $target->className);
        self::assertSame('process', $target->methodName);
    }

    #[Test]
    public function lazyLoadingMiddlewareWithUnknownClassReturnsNull(): void
    {
        $lazy = new LazyLoadingMiddleware($this->createMock(MiddlewareContainer::class), 'UnknownMiddlewareService');

        self::assertNull($this->resolver->resolve($lazy));
    }

    #[Test]
    public function middlewarePipeReturnsNull(): void
    {
        self::assertNull($this->resolver->resolve(new MiddlewarePipe()));
    }

    #[Test]
    public function unknownStringReturnsNull(): void
    {
        self::assertNull($this->resolver->resolve('NonExistentClass'));
    }

    #[Test]
    public function unrelatedObjectReturnsNull(): void
    {
        self::assertNull($this->resolver->resolve(new stdClass()));
    }

    #[Test]
    public function doesNotUnwrapUserMiddlewareWithDecoratorLikePrivateProperties(): void
    {
        $handler = new class implements MiddlewareInterface {
            private readonly RequestHandlerInterface $handler;

            private readonly Closure $middleware;

            private readonly string $middlewareName;

            public function __construct()
            {
                $this->handler = new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new JsonResponse([]);
                    }
                };
                $this->middleware     = static fn (): ResponseInterface => new JsonResponse([]);
                $this->middlewareName = 'not-a-decorator';
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                foreach (['handler', 'middleware', 'middlewareName'] as $property) {
                    if (! isset($this->{$property})) {
                        throw new RuntimeException('Decorator-like properties should be initialized.');
                    }
                }

                return new JsonResponse([]);
            }
        };

        $target = $this->resolver->resolve($handler);

        self::assertNotNull($target);
        self::assertSame($handler::class, $target->className);
        self::assertSame('process', $target->methodName);
    }
}

final class StaticCallableHandler
{
    public static function create(): ResponseInterface
    {
        return new JsonResponse([]);
    }
}

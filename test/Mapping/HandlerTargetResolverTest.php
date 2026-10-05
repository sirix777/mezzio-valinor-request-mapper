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
use ReflectionProperty;
use RuntimeException;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedMiddleware;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\AttributedRequestHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\CallableMethodHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\DualInterfaceHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\InheritedCallableChild;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\InvokableHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\LazyLoadingMiddlewareHandler;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\LazyLoadingRequestHandler;
use stdClass;
use WeakMap;
use WeakReference;

use function gc_collect_cycles;
use function get_object_vars;

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
    public function inheritedFirstClassCallableKeepsChildClass(): void
    {
        $child            = new InheritedCallableChild();
        $method           = 'work';
        $arrayTarget      = $this->resolver->resolve(new CallableMiddlewareDecorator([$child, $method]));
        $firstClassTarget = $this->resolver->resolve(new CallableMiddlewareDecorator($child->work(...)));

        self::assertNotNull($arrayTarget);
        self::assertNotNull($firstClassTarget);
        self::assertSame(InheritedCallableChild::class, $firstClassTarget->className);
        self::assertEquals($arrayTarget, $firstClassTarget);
        self::assertSame('work', $firstClassTarget->methodName);
    }

    #[Test]
    public function inheritedStaticCallableKeepsCalledClass(): void
    {
        $method           = 'staticWork';
        $arrayTarget      = $this->resolver->resolve(new CallableMiddlewareDecorator([InheritedCallableChild::class, $method]));
        $firstClassTarget = $this->resolver->resolve(new CallableMiddlewareDecorator(InheritedCallableChild::staticWork(...)));

        self::assertNotNull($arrayTarget);
        self::assertNotNull($firstClassTarget);
        self::assertSame(InheritedCallableChild::class, $firstClassTarget->className);
        self::assertEquals($arrayTarget, $firstClassTarget);
        self::assertSame('staticWork', $firstClassTarget->methodName);
    }

    #[Test]
    public function anonymousClosureInsideChildIsNotAClassTargetAndIsReleased(): void
    {
        $decorator = new CallableMiddlewareDecorator((new InheritedCallableChild())->anonymousClosure());
        $reference = WeakReference::create($decorator);

        self::assertNull($this->resolver->resolve($decorator));
        self::assertNull($this->resolver->resolve($decorator));
        unset($decorator);
        gc_collect_cycles();

        self::assertNull($reference->get());
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
    public function positiveObjectTargetIsReturnedFromCache(): void
    {
        $handler = new AttributedRequestHandler();
        $first   = $this->resolver->resolve($handler);
        $second  = $this->resolver->resolve($handler);

        self::assertNotNull($first);
        self::assertSame($first, $second);
    }

    #[Test]
    public function negativeObjectTargetsAreCachedAsNonNullEntries(): void
    {
        $pipe      = new MiddlewarePipe();
        $decorator = new CallableMiddlewareDecorator(
            static fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => new JsonResponse([]),
        );
        $unrelated = new stdClass();

        foreach ([$pipe, $decorator, $unrelated] as $target) {
            self::assertNull($this->resolver->resolve($target));
            self::assertTrue($this->cache()->offsetExists($target));
            $entry = $this->cache()->offsetGet($target);
            self::assertSame([
                'target' => null,
            ], get_object_vars($entry));
            self::assertNull($this->resolver->resolve($target));
        }
    }

    #[Test]
    public function cacheDoesNotKeepObjectTargetsAlive(): void
    {
        $pipe       = new MiddlewarePipe();
        $decorator  = new CallableMiddlewareDecorator(static fn (): ResponseInterface => new JsonResponse([]));
        $unrelated  = new stdClass();
        $references = [
            WeakReference::create($pipe),
            WeakReference::create($decorator),
            WeakReference::create($unrelated),
        ];

        $this->resolver->resolve($pipe);
        $this->resolver->resolve($decorator);
        $this->resolver->resolve($unrelated);
        unset($pipe, $decorator, $unrelated);
        gc_collect_cycles();

        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }

        self::assertCount(0, $this->cache());
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

    /** @return WeakMap<object, object> */
    private function cache(): WeakMap
    {
        $property = new ReflectionProperty($this->resolver, 'cache');

        return $property->getValue($this->resolver);
    }
}

final class StaticCallableHandler
{
    public static function create(): ResponseInterface
    {
        return new JsonResponse([]);
    }
}

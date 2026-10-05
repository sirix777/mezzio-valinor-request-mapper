<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Closure;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use WeakMap;

use function array_is_list;
use function class_exists;
use function explode;
use function in_array;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function property_exists;
use function str_contains;

/**
 * @internal
 */
final readonly class HandlerTargetResolver
{
    private const REQUEST_HANDLER_MIDDLEWARE = 'Laminas\Stratigility\Middleware\RequestHandlerMiddleware';

    private const CALLABLE_MIDDLEWARE_DECORATOR = 'Laminas\Stratigility\Middleware\CallableMiddlewareDecorator';

    private const LAZY_LOADING_MIDDLEWARE = 'Mezzio\Middleware\LazyLoadingMiddleware';

    private const MIDDLEWARE_PIPE = 'Laminas\Stratigility\MiddlewarePipe';

    /** @var WeakMap<object, HandlerTargetCacheEntry> */
    private WeakMap $cache;

    public function __construct()
    {
        $this->cache = new WeakMap();
    }

    public function resolve(object|string $middleware): ?HandlerTarget
    {
        if (is_object($middleware)) {
            if ($this->cache->offsetExists($middleware)) {
                return $this->cache->offsetGet($middleware)->target;
            }

            $target = $this->isKnownWrapper($middleware)
                ? $this->resolveKnownWrapper($middleware)
                : $this->resolveObject($middleware);

            // WeakMap::offsetExists() reports false for a null value. Store an
            // entry object so that unsupported targets are negative-cache hits.
            $this->cache->offsetSet($middleware, new HandlerTargetCacheEntry($target));

            return $target;
        }

        return $this->resolveClassString($middleware);
    }

    private function isKnownWrapper(object $middleware): bool
    {
        return in_array($middleware::class, [
            self::REQUEST_HANDLER_MIDDLEWARE,
            self::CALLABLE_MIDDLEWARE_DECORATOR,
            self::LAZY_LOADING_MIDDLEWARE,
            self::MIDDLEWARE_PIPE,
        ], true);
    }

    private function resolveKnownWrapper(object $middleware): ?HandlerTarget
    {
        $className = $middleware::class;

        if (self::REQUEST_HANDLER_MIDDLEWARE === $className) {
            return $this->resolveRequestHandlerMiddleware($middleware);
        }

        if (self::CALLABLE_MIDDLEWARE_DECORATOR === $className) {
            return $this->resolveCallableMiddlewareDecorator($middleware);
        }

        if (self::LAZY_LOADING_MIDDLEWARE === $className) {
            return $this->resolveLazyLoadingMiddleware($middleware);
        }

        return null;
    }

    private function resolveRequestHandlerMiddleware(object $middleware): ?HandlerTarget
    {
        $handler = $this->readProperty($middleware, 'handler');

        if (! $handler instanceof RequestHandlerInterface) {
            return null;
        }

        return new HandlerTarget($handler::class, 'handle');
    }

    private function resolveCallableMiddlewareDecorator(object $middleware): ?HandlerTarget
    {
        $callable = $this->readProperty($middleware, 'middleware');

        if (is_array($callable) && array_is_list($callable) && isset($callable[0], $callable[1]) && is_string($callable[1])) {
            $class = is_object($callable[0]) ? $callable[0]::class : $callable[0];

            if (is_string($class) && class_exists($class)) {
                return new HandlerTarget($class, $callable[1]);
            }
        }

        if ($callable instanceof Closure) {
            // Only first-class callables of real methods resolve to a target;
            // ordinary anonymous closures must not leak external scope attributes.
            return $this->resolveClosure($callable);
        }

        if (is_object($callable)) {
            return $this->resolveInvokableObject($callable);
        }

        if (is_string($callable) && '' !== $callable && is_callable($callable, true, $callableName)) {
            return $this->resolveCallableString($callableName);
        }

        return null;
    }

    private function resolveLazyLoadingMiddleware(object $middleware): ?HandlerTarget
    {
        // Public property on current Mezzio versions; private on older ones.
        $middlewareName = property_exists($middleware, 'middlewareName')
            ? $this->readProperty($middleware, 'middlewareName')
            : null;

        if (! is_string($middlewareName) || '' === $middlewareName) {
            return null;
        }

        return $this->resolveClassString($middlewareName);
    }

    private function resolveObject(object $middleware): ?HandlerTarget
    {
        if ($middleware instanceof MiddlewareInterface) {
            return new HandlerTarget($middleware::class, 'process');
        }

        if ($middleware instanceof RequestHandlerInterface) {
            return new HandlerTarget($middleware::class, 'handle');
        }

        return $this->resolveInvokableObject($middleware);
    }

    private function resolveClassString(string $className): ?HandlerTarget
    {
        if ('' === $className || ! class_exists($className)) {
            return null;
        }

        $refClass = new ReflectionClass($className);

        if ($refClass->implementsInterface(MiddlewareInterface::class)) {
            return new HandlerTarget($className, 'process');
        }

        if ($refClass->implementsInterface(RequestHandlerInterface::class)) {
            return new HandlerTarget($className, 'handle');
        }

        if ($refClass->hasMethod('__invoke')) {
            return new HandlerTarget($className, '__invoke');
        }

        return null;
    }

    private function resolveClosure(Closure $closure): ?HandlerTarget
    {
        try {
            $refFunction = new ReflectionFunction($closure);
        } catch (ReflectionException) {
            return null;
        }

        $scopeClass = $refFunction->getClosureCalledClass() ?? $refFunction->getClosureScopeClass();

        if (null === $scopeClass) {
            return null;
        }

        $methodName = $refFunction->getName();

        // First-class callables of real methods have a named scope and method.
        // Anonymous closures report a scope but no real method name.
        if (! $scopeClass->hasMethod($methodName)) {
            return null;
        }

        return new HandlerTarget($scopeClass->getName(), $methodName);
    }

    private function resolveInvokableObject(object $object): ?HandlerTarget
    {
        $refClass = new ReflectionClass($object);

        if (! $refClass->hasMethod('__invoke')) {
            return null;
        }

        return new HandlerTarget($object::class, '__invoke');
    }

    private function resolveCallableString(string $callableName): ?HandlerTarget
    {
        if (! str_contains($callableName, '::')) {
            return null;
        }

        [$class, $method] = explode('::', $callableName, 2);

        if ('' === $class || '' === $method || ! class_exists($class)) {
            return null;
        }

        return new HandlerTarget($class, $method);
    }

    private function readProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);

        if (! $reflection->hasProperty($property)) {
            return null;
        }

        $reflectionProperty = $reflection->getProperty($property);

        if (! $reflectionProperty->isInitialized($object)) {
            return null;
        }

        return $reflectionProperty->getValue($object);
    }
}

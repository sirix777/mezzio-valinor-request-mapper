<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Middleware;

use Closure;
use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;

use function array_key_exists;
use function array_unique;
use function array_values;
use function class_exists;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function strtoupper;
use function trim;

final class ValinorRequestMapperMiddleware implements MiddlewareInterface
{
    private const REQUEST_HANDLER_MIDDLEWARE = 'Laminas\Stratigility\Middleware\RequestHandlerMiddleware';

    private const CALLABLE_MIDDLEWARE_DECORATOR = 'Laminas\Stratigility\Middleware\CallableMiddlewareDecorator';

    private const LAZY_LOADING_MIDDLEWARE = 'Mezzio\Middleware\LazyLoadingMiddleware';

    /**
     * @var array<string, list<MapRequest>>
     */
    private array $mapRequestCache = [];

    public function __construct(
        private readonly TreeMapper $mapper,
        private readonly MappingErrorResponderResolver $errorResponderResolver,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class);

        if (! $routeResult instanceof RouteResult) {
            return $handler->handle($request);
        }

        $mapRequests = $this->resolveMapRequests($routeResult, $request->getMethod());

        if ([] === $mapRequests) {
            return $handler->handle($request);
        }

        $routeParams = $routeResult->getMatchedParams();

        foreach ($mapRequests as $mapRequest) {
            try {
                if (null !== $mapRequest->source) {
                    $dtoClass            = $mapRequest->source;
                    $source              = 'source';
                    $requestAttributeKey = $mapRequest->output ?? $dtoClass;
                    $httpRequest         = HttpRequest::fromPsr($request, $routeParams);
                    $dto                 = $this->mapper->map($dtoClass, $httpRequest);
                    $request             = $request->withAttribute($requestAttributeKey, $dto);

                    continue;
                }

                if (null !== $mapRequest->body) {
                    $dtoClass            = $mapRequest->body;
                    $source              = 'body';
                    $requestAttributeKey = $mapRequest->output ?? $dtoClass;
                    $dto                 = $this->mapper->map(
                        $dtoClass,
                        new HttpRequest(
                            bodyValues: (array) $request->getParsedBody(),
                            requestObject: $request,
                        ),
                    );
                    $request = $request->withAttribute($requestAttributeKey, $dto);
                }

                if (null !== $mapRequest->query) {
                    $dtoClass            = $mapRequest->query;
                    $source              = 'query';
                    $requestAttributeKey = $mapRequest->output ?? $dtoClass;
                    $dto                 = $this->mapper->map(
                        $dtoClass,
                        new HttpRequest(
                            queryParameters: $request->getQueryParams(),
                            requestObject: $request,
                        ),
                    );
                    $request = $request->withAttribute($requestAttributeKey, $dto);
                }

                if (null !== $mapRequest->route) {
                    $dtoClass            = $mapRequest->route;
                    $source              = 'route';
                    $requestAttributeKey = $mapRequest->output ?? $dtoClass;
                    $dto                 = $this->mapper->map(
                        $dtoClass,
                        new HttpRequest(
                            routeParameters: $routeParams,
                            requestObject: $request,
                        ),
                    );
                    $request = $request->withAttribute($requestAttributeKey, $dto);
                }
            } catch (MappingError $e) {
                return $this->errorResponderResolver
                    ->resolve($mapRequest->errorResponder)
                    ->respond(new MappingErrorContext(
                        $e,
                        $request,
                        $mapRequest,
                        $dtoClass,
                        $source,
                        $requestAttributeKey,
                    ))
                ;
            }
        }

        return $handler->handle($request);
    }

    /**
     * @return list<MapRequest>
     */
    private function resolveMapRequests(RouteResult $routeResult, string $httpMethod): array
    {
        // 1. Priority: route defaults from routing-attributes package
        $matchedRoute = $routeResult->getMatchedRoute();

        if (false !== $matchedRoute) {
            $valinorMappings = $matchedRoute->getOptions()['valinor_mappings'] ?? [];

            if ([] !== $valinorMappings) {
                return $this->filterByMethod($valinorMappings, $httpMethod);
            }

            // 2. Reflection on the actual route handler
            $handler = $matchedRoute->getMiddleware();

            return $this->resolveFromReflection($handler, $httpMethod);
        }

        return [];
    }

    /**
     * @return list<MapRequest>
     */
    private function resolveFromReflection(object|string $handler, string $httpMethod): array
    {
        [$handlerClass, $methodNames] = $this->resolveHandlerReflectionTarget($handler);

        $cacheKey = $handlerClass . '|' . implode(',', $methodNames) . '|' . $this->normalizeHttpMethod($httpMethod);

        if (array_key_exists($cacheKey, $this->mapRequestCache)) {
            return $this->mapRequestCache[$cacheKey];
        }

        if (! class_exists($handlerClass)) {
            return $this->mapRequestCache[$cacheKey] = [];
        }

        $refClass = new ReflectionClass($handlerClass);

        $result = [];

        foreach ($this->resolveAttributes($refClass, $methodNames) as $attr) {
            if ($this->matchesHttpMethod($attr->methods, $httpMethod)) {
                $result[] = $attr;
            }
        }

        return $this->mapRequestCache[$cacheKey] = $result;
    }

    /**
     * @param ReflectionClass<object> $refClass
     * @param list<string>            $methodNames
     *
     * @return list<MapRequest>
     */
    private function resolveAttributes(ReflectionClass $refClass, array $methodNames): array
    {
        $result = [];

        foreach ($refClass->getAttributes(MapRequest::class) as $refAttr) {
            $result[] = $refAttr->newInstance();
        }

        foreach ($methodNames as $methodName) {
            if (! $refClass->hasMethod($methodName)) {
                continue;
            }

            foreach ($refClass->getMethod($methodName)->getAttributes(MapRequest::class) as $refAttr) {
                $result[] = $refAttr->newInstance();
            }
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function resolveHandlerReflectionTarget(object|string $handler): array
    {
        $unwrapped = $this->unwrapKnownMiddlewareDecorator($handler);

        if (is_array($unwrapped)) {
            return $unwrapped;
        }

        $handlerClass = $this->resolveHandlerClass($unwrapped);

        if (! class_exists($handlerClass)) {
            return [$handlerClass, []];
        }

        $refClass = new ReflectionClass($handlerClass);
        $methods  = [];

        if ($refClass->implementsInterface(MiddlewareInterface::class)) {
            $methods[] = 'process';
        }

        if ($refClass->implementsInterface(RequestHandlerInterface::class)) {
            $methods[] = 'handle';
        }

        if ($refClass->hasMethod('__invoke')) {
            $methods[] = '__invoke';
        }

        return [$handlerClass, array_values(array_unique($methods))];
    }

    /**
     * @return array{0: string, 1: list<string>}|object|string
     *
     * @throws ReflectionException
     */
    private function unwrapKnownMiddlewareDecorator(object|string $handler): array|object|string
    {
        if (! is_object($handler)) {
            return $handler;
        }

        if (self::REQUEST_HANDLER_MIDDLEWARE === $handler::class) {
            $innerHandler = $this->readPrivateProperty($handler, 'handler');

            if ($innerHandler instanceof RequestHandlerInterface) {
                return [$innerHandler::class, ['handle']];
            }
        }

        if (self::CALLABLE_MIDDLEWARE_DECORATOR === $handler::class) {
            $callable = $this->readPrivateProperty($handler, 'middleware');

            if (is_array($callable) && isset($callable[0], $callable[1]) && is_string($callable[1])) {
                $class = is_object($callable[0]) ? $callable[0]::class : $callable[0];

                if (is_string($class)) {
                    return [$class, [$callable[1]]];
                }
            }

            if ($callable instanceof Closure) {
                $refFunction = new ReflectionFunction($callable);
                $scopeClass  = $refFunction->getClosureScopeClass();

                if (null !== $scopeClass && '{closure}' !== $refFunction->getName()) {
                    return [$scopeClass->getName(), [$refFunction->getName()]];
                }
            }

            if (is_object($callable)) {
                return [$callable::class, ['__invoke']];
            }
        }

        if (self::LAZY_LOADING_MIDDLEWARE === $handler::class) {
            $middlewareName = $this->readPrivateProperty($handler, 'middlewareName');

            if (is_string($middlewareName)) {
                return [$middlewareName, ['process']];
            }
        }

        return $handler;
    }

    private function readPrivateProperty(object $object, string $property): mixed
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

    /**
     * @param list<array<string, mixed>> $mappings
     *
     * @return list<MapRequest>
     */
    private function filterByMethod(array $mappings, string $httpMethod): array
    {
        $httpMethod = $this->normalizeHttpMethod($httpMethod);
        $result     = [];

        foreach ($mappings as $mapping) {
            $methods = $this->normalizeMethods((array) ($mapping['methods'] ?? []));

            if ([] === $methods || in_array($httpMethod, $methods, true)) {
                $result[] = new MapRequest(
                    body: $mapping['body'] ?? null,
                    query: $mapping['query'] ?? null,
                    route: $mapping['route'] ?? null,
                    source: $mapping['source'] ?? null,
                    output: $mapping['output'] ?? null,
                    methods: $methods,
                    errorResponder: $mapping['errorResponder'] ?? null,
                );
            }
        }

        return $result;
    }

    private function resolveHandlerClass(object|string $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        return $handler::class;
    }

    /**
     * @param list<string> $methods
     */
    private function matchesHttpMethod(array $methods, string $httpMethod): bool
    {
        return [] === $methods || in_array($this->normalizeHttpMethod($httpMethod), $methods, true);
    }

    private function normalizeHttpMethod(string $httpMethod): string
    {
        return strtoupper(trim($httpMethod));
    }

    /**
     * @param array<mixed, mixed> $methods
     *
     * @return list<string>
     */
    private function normalizeMethods(array $methods): array
    {
        $normalized = [];

        foreach ($methods as $method) {
            if (! is_string($method)) {
                continue;
            }

            if ('' === $method) {
                continue;
            }

            $normalized[] = $this->normalizeHttpMethod($method);
        }

        return array_values(array_unique($normalized));
    }
}

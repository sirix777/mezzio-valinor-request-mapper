<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Middleware;

use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Tree\Message\Formatter\MessageFormatter;
use CuyZ\Valinor\Mapper\TreeMapper;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

use function array_key_exists;
use function array_unique;
use function array_values;
use function class_exists;
use function in_array;
use function is_string;
use function lcfirst;
use function preg_replace;
use function strtolower;
use function strtoupper;
use function trim;

final class ValinorRequestMapperMiddleware implements MiddlewareInterface
{
    /** @var array<MessageFormatter> */
    private readonly array $messageFormatters;

    /**
     * @var array<string, list<MapRequest>>
     */
    private array $mapRequestCache = [];

    /**
     * @param array<string, mixed> $errorConfig
     */
    public function __construct(
        private readonly TreeMapper $mapper,
        private readonly array $errorConfig = [],
        MessageFormatter ...$messageFormatters,
    ) {
        $this->messageFormatters = $messageFormatters;
    }

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
                    $httpRequest = HttpRequest::fromPsr($request, $routeParams);
                    $dto = $this->mapper->map($mapRequest->source, $httpRequest);
                    $key = $mapRequest->output ?? $mapRequest->source;
                    $request = $request->withAttribute($key, $dto);

                    continue;
                }

                if (null !== $mapRequest->body) {
                    $dto = $this->mapper->map(
                        $mapRequest->body,
                        new HttpRequest(
                            bodyValues: (array) $request->getParsedBody(),
                            requestObject: $request,
                        ),
                    );
                    $key = $mapRequest->output ?? $mapRequest->body;
                    $request = $request->withAttribute($key, $dto);
                }

                if (null !== $mapRequest->query) {
                    $dto = $this->mapper->map(
                        $mapRequest->query,
                        new HttpRequest(
                            queryParameters: $request->getQueryParams(),
                            requestObject: $request,
                        ),
                    );
                    $key = $mapRequest->output ?? $mapRequest->query;
                    $request = $request->withAttribute($key, $dto);
                }

                if (null !== $mapRequest->route) {
                    $dto = $this->mapper->map(
                        $mapRequest->route,
                        new HttpRequest(
                            routeParameters: $routeParams,
                            requestObject: $request,
                        ),
                    );
                    $key = $mapRequest->output ?? $mapRequest->route;
                    $request = $request->withAttribute($key, $dto);
                }
            } catch (MappingError $e) {
                return $this->createErrorResponse($e);
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
        $handlerClass = $this->resolveHandlerClass($handler);

        $cacheKey = $handlerClass . '|' . $this->normalizeHttpMethod($httpMethod);

        if (array_key_exists($cacheKey, $this->mapRequestCache)) {
            return $this->mapRequestCache[$cacheKey];
        }

        if (! class_exists($handlerClass)) {
            return $this->mapRequestCache[$cacheKey] = [];
        }

        $refClass = new ReflectionClass($handlerClass);

        $result = [];

        foreach ($refClass->getAttributes(MapRequest::class) as $refAttr) {
            $attr = $refAttr->newInstance();

            if ($this->matchesHttpMethod($attr->methods, $httpMethod)) {
                $result[] = $attr;
            }
        }

        return $this->mapRequestCache[$cacheKey] = $result;
    }

    /**
     * @param list<array<string, mixed>> $mappings
     *
     * @return list<MapRequest>
     */
    private function filterByMethod(array $mappings, string $httpMethod): array
    {
        $httpMethod = $this->normalizeHttpMethod($httpMethod);
        $result = [];

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

        if ($handler instanceof LazyLoadingMiddleware) {
            return $handler->middlewareName;
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

    private function createErrorResponse(MappingError $error): JsonResponse
    {
        $formatted = $error->messages()->formatWith(
            ...$this->messageFormatters,
        );

        $messages = [];

        foreach ($formatted as $msg) {
            $path = '*root*' === $msg->path() ? '' : $msg->path();

            if (($this->errorConfig['key_case'] ?? null) === 'snake_case') {
                $path = strtolower((string) preg_replace('/[A-Z]/', '_$0', lcfirst($path)));
            }

            $messages[$path] ??= [];
            $messages[$path][] = (string) $msg;
        }

        return new JsonResponse([
            'error' => 'Mapping failed',
            'messages' => $messages,
        ], (int) ($this->errorConfig['status_code'] ?? 422));
    }
}

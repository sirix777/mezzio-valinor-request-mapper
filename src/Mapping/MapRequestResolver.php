<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use ReflectionClass;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use WeakMap;

use function array_key_exists;

/**
 * @internal
 */
final class MapRequestResolver
{
    /** @var WeakMap<Route, RouteMetadataEntry> */
    private readonly WeakMap $routeCache;

    /** @var array<string, list<MapRequest>> */
    private array $reflectionCache = [];

    public function __construct(
        private readonly HandlerTargetResolver $handlerTargetResolver,
        private readonly MapRequestOptionsParser $mapRequestOptionsParser,
    ) {
        $this->routeCache = new WeakMap();
    }

    /**
     * @return list<MapRequest>
     */
    public function resolve(RouteResult $routeResult): array
    {
        $matchedRoute = $routeResult->getMatchedRoute();

        if (false === $matchedRoute) {
            return [];
        }

        $options  = $matchedRoute->getOptions();
        $hasKey   = array_key_exists('valinor_mappings', $options);
        $snapshot = $hasKey ? $options['valinor_mappings'] : null;

        $entry = $this->routeCache->offsetExists($matchedRoute)
            ? $this->routeCache->offsetGet($matchedRoute)
            : null;

        if ($entry instanceof RouteMetadataEntry
            && $entry->hasKey === $hasKey
            && $entry->snapshot === $snapshot
        ) {
            return $entry->mapRequests;
        }

        $mapRequests = $this->resolveMapRequests($matchedRoute, $hasKey, $snapshot);

        $this->routeCache->offsetSet($matchedRoute, new RouteMetadataEntry(
            $hasKey,
            $snapshot,
            $mapRequests,
        ));

        return $mapRequests;
    }

    /**
     * @return list<MapRequest>
     */
    private function resolveMapRequests(Route $matchedRoute, bool $hasKey, mixed $snapshot): array
    {
        if ($hasKey) {
            if ([] !== $snapshot) {
                return $this->mapRequestOptionsParser->parse($snapshot);
            }

            // Empty payload [] falls back to reflection metadata (plan 02).
        }

        $handler = $matchedRoute->getMiddleware();
        $target  = $this->handlerTargetResolver->resolve($handler);

        if (! $target instanceof HandlerTarget) {
            return [];
        }

        return $this->resolveFromReflection($target);
    }

    /**
     * @return list<MapRequest>
     */
    private function resolveFromReflection(HandlerTarget $target): array
    {
        $refClass   = new ReflectionClass($target->className);
        $className  = $refClass->getName();
        $methodName = $target->methodName ?? '';
        $cacheKey   = $className . '::' . $methodName;

        if (array_key_exists($cacheKey, $this->reflectionCache)) {
            return $this->reflectionCache[$cacheKey];
        }

        $result   = [];

        foreach ($refClass->getAttributes(MapRequest::class) as $refAttr) {
            $result[] = $refAttr->newInstance();
        }

        if ('' !== $methodName && $refClass->hasMethod($methodName)) {
            foreach ($refClass->getMethod($methodName)->getAttributes(MapRequest::class) as $refAttr) {
                $result[] = $refAttr->newInstance();
            }
        }

        $this->reflectionCache[$cacheKey] = $result;

        return $result;
    }
}

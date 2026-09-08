<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Mezzio\Router\RouteResult;
use ReflectionClass;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

use function array_key_exists;

/**
 * @internal
 */
final readonly class MapRequestResolver
{
    public function __construct(
        private HandlerTargetResolver $handlerTargetResolver,
        private MapRequestOptionsParser $mapRequestOptionsParser,
    ) {}

    /**
     * @return list<MapRequest>
     */
    public function resolve(RouteResult $routeResult): array
    {
        $matchedRoute = $routeResult->getMatchedRoute();

        if (false === $matchedRoute) {
            return [];
        }

        $options = $matchedRoute->getOptions();

        if (array_key_exists('valinor_mappings', $options)) {
            $valinorMappings = $options['valinor_mappings'];

            if ([] !== $valinorMappings) {
                return $this->mapRequestOptionsParser->parse($valinorMappings);
            }

            // Empty payload [] falls back to reflection metadata (plan 02).
        }

        $handler = $matchedRoute->getMiddleware();

        $target = $this->handlerTargetResolver->resolve($handler);

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
        $refClass = new ReflectionClass($target->className);

        $result = [];

        foreach ($refClass->getAttributes(MapRequest::class) as $refAttr) {
            $result[] = $refAttr->newInstance();
        }

        if (null !== $target->methodName && $refClass->hasMethod($target->methodName)) {
            foreach ($refClass->getMethod($target->methodName)->getAttributes(MapRequest::class) as $refAttr) {
                $result[] = $refAttr->newInstance();
            }
        }

        return $result;
    }
}

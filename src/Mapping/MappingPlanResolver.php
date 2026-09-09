<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Mezzio\Router\RouteResult;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;

use function array_key_exists;
use function in_array;
use function sprintf;

/**
 * @internal
 */
final readonly class MappingPlanResolver
{
    public function __construct(private MapRequestResolver $mapRequestResolver, private HttpMethodNormalizer $httpMethodNormalizer) {}

    /**
     * @return list<MappingOperation>
     */
    public function resolve(RouteResult $routeResult, string $httpMethod): array
    {
        $httpMethod = $this->httpMethodNormalizer->normalize($httpMethod);

        $operations = [];

        foreach ($this->mapRequestResolver->resolve($routeResult) as $mapRequest) {
            if ([] !== $mapRequest->methods && ! in_array($httpMethod, $mapRequest->methods, true)) {
                continue;
            }

            foreach ($this->expand($mapRequest) as $operation) {
                $operations[] = $operation;
            }
        }

        $this->assertNoOutputCollisions($operations);

        return $operations;
    }

    /**
     * @return list<MappingOperation>
     */
    private function expand(MapRequest $mapRequest): array
    {
        if (null !== $mapRequest->source) {
            return [
                new MappingOperation(
                    $mapRequest,
                    $mapRequest->source,
                    'source',
                    $mapRequest->output ?? $mapRequest->source,
                ),
            ];
        }

        $operations = [];

        if (null !== $mapRequest->body) {
            $operations[] = new MappingOperation(
                $mapRequest,
                $mapRequest->body,
                'body',
                $mapRequest->output ?? $mapRequest->body,
            );
        }

        if (null !== $mapRequest->query) {
            $operations[] = new MappingOperation(
                $mapRequest,
                $mapRequest->query,
                'query',
                $mapRequest->output ?? $mapRequest->query,
            );
        }

        if (null !== $mapRequest->route) {
            $operations[] = new MappingOperation(
                $mapRequest,
                $mapRequest->route,
                'route',
                $mapRequest->output ?? $mapRequest->route,
            );
        }

        return $operations;
    }

    /**
     * @param list<MappingOperation> $operations
     */
    private function assertNoOutputCollisions(array $operations): void
    {
        $seen = [];

        foreach ($operations as $index => $operation) {
            $key = $operation->requestAttributeKey;

            if (array_key_exists($key, $seen)) {
                $first  = $seen[$key];
                $second = $index + 1;

                throw new InvalidMapRequestConfiguration(sprintf(
                    "Output key '%s' is used by multiple mapping operations: operation %d (%s: %s) and operation %d (%s: %s).",
                    $key,
                    $first,
                    $operations[$first - 1]->source,
                    $operations[$first - 1]->dtoClass,
                    $second,
                    $operation->source,
                    $operation->dtoClass,
                ));
            }

            $seen[$key] = $index + 1;
        }
    }
}

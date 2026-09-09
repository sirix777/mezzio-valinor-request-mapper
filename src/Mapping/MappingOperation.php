<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Sirix\Mezzio\Valinor\Attribute\MapRequest;

/**
 * @internal
 */
final readonly class MappingOperation
{
    /**
     * @param class-string                    $dtoClass
     * @param 'body'|'query'|'route'|'source' $source
     */
    public function __construct(
        public MapRequest $mapRequest,
        public string $dtoClass,
        public string $source,
        public string $requestAttributeKey,
    ) {}
}

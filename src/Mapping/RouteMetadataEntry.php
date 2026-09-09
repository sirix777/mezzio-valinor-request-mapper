<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Sirix\Mezzio\Valinor\Attribute\MapRequest;

/**
 * @internal
 */
final readonly class RouteMetadataEntry
{
    /**
     * @param list<MapRequest> $mapRequests
     */
    public function __construct(public bool $hasKey, public mixed $snapshot, public array $mapRequests) {}
}

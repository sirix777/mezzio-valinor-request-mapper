<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

/**
 * @internal
 */
final readonly class HandlerTargetCacheEntry
{
    public function __construct(public ?HandlerTarget $target) {}
}

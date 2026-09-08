<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

/**
 * @internal
 */
final readonly class HandlerTarget
{
    /**
     * @param class-string $className
     */
    public function __construct(public string $className, public ?string $methodName) {}
}

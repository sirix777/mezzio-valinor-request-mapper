<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

final readonly class PositiveIdRouteRequest
{
    /** @param positive-int $id */
    public function __construct(public int $id) {}
}

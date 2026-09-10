<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

final readonly class StatusRouteRequest
{
    public function __construct(public StatusEnum $status) {}
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

final readonly class CreateBodyRequest
{
    public function __construct(public string $name, public string $balance, public string $currencyCode) {}
}

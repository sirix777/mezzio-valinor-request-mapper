<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

final readonly class CollidingFieldRequest
{
    public function __construct(public string $value) {}
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Error\Fixture;

final readonly class NestedErrorDto
{
    public function __construct(public string $name) {}
}

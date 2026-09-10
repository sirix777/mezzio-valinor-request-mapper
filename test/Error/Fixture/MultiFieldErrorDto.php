<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Error\Fixture;

final readonly class MultiFieldErrorDto
{
    public function __construct(public string $name, public int $age) {}
}

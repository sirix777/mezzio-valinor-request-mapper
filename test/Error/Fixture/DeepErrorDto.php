<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Error\Fixture;

final readonly class DeepErrorDto
{
    public function __construct(public string $name, public NestedErrorDto $nested) {}
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory\Fixture;

final readonly class TreeMapperMixedRequest
{
    public function __construct(public mixed $value) {}
}

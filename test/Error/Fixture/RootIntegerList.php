<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Error\Fixture;

use CuyZ\Valinor\Mapper\Http\FromBody;

final readonly class RootIntegerList
{
    /** @param list<int> $numbers */
    public function __construct(#[FromBody(asRoot: true)] public array $numbers) {}
}

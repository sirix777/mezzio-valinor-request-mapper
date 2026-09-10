<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

enum StatusEnum: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}

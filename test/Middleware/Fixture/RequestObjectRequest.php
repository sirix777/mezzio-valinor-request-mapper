<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Psr\Http\Message\ServerRequestInterface;

final readonly class RequestObjectRequest
{
    public function __construct(public string $name, public ServerRequestInterface $requestObject) {}
}

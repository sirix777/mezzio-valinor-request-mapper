<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use LogicException;
use Psr\Http\Message\ResponseInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;

final class UnregisteredResponder implements MappingErrorResponderInterface
{
    private function __construct() {}

    public function respond(MappingErrorContext $context): ResponseInterface
    {
        throw new LogicException('This responder must be resolved from the container.');
    }
}

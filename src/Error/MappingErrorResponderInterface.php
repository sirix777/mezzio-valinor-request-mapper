<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Error;

use Psr\Http\Message\ResponseInterface;

interface MappingErrorResponderInterface
{
    public function respond(MappingErrorContext $context): ResponseInterface;
}

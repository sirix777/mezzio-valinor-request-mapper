<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Fig\Http\Message\StatusCodeInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;

final class CaptureResponder implements MappingErrorResponderInterface
{
    public ?MappingErrorContext $context = null;

    public function respond(MappingErrorContext $context): ResponseInterface
    {
        $this->context = $context;

        return new JsonResponse([
            'handled' => true,
        ], StatusCodeInterface::STATUS_CONFLICT);
    }
}

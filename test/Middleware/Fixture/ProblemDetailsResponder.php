<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Fig\Http\Message\StatusCodeInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;

final class ProblemDetailsResponder implements MappingErrorResponderInterface
{
    public function respond(MappingErrorContext $context): ResponseInterface
    {
        return new JsonResponse([
            'type'              => 'https://example.test/problems/validation-error',
            'title'             => 'Validation failed',
            'source'            => $context->source,
            'dto'               => $context->dtoClass,
            'request_attribute' => $context->requestAttributeKey,
        ], StatusCodeInterface::STATUS_BAD_REQUEST, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}

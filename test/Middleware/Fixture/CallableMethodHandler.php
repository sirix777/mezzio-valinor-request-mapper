<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

final class CallableMethodHandler
{
    #[MapRequest(body: RequiredRequest::class)]
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($request->getAttribute(RequiredRequest::class));
    }

    #[MapRequest(body: HandleOnlyRequest::class)]
    public function other(): ResponseInterface
    {
        return new JsonResponse(null);
    }
}

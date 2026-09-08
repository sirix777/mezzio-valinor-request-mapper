<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

#[MapRequest(body: RequiredRequest::class)]
final class InvokableHandler
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($request->getAttribute(RequiredRequest::class));
    }
}

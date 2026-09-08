<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

final class DualInterfaceHandler implements MiddlewareInterface, RequestHandlerInterface
{
    #[MapRequest(body: RequiredRequest::class)]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new JsonResponse($request->getAttribute(RequiredRequest::class));
    }

    #[MapRequest(body: HandleOnlyRequest::class)]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($request->getAttribute(HandleOnlyRequest::class));
    }
}

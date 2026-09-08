<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Closure;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

#[MapRequest(body: RequiredRequest::class)]
final class AttributedClosureFactory
{
    public function create(): Closure
    {
        return fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => new JsonResponse([
            'hasDto' => $request->getAttribute(RequiredRequest::class) instanceof RequiredRequest,
        ]);
    }
}

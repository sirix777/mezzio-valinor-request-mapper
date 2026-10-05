<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Closure;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

#[MapRequest(body: RequiredRequest::class, output: 'child')]
final class InheritedCallableChild extends InheritedCallableParent
{
    public function anonymousClosure(): Closure
    {
        return static fn (): ResponseInterface => new JsonResponse([]);
    }
}

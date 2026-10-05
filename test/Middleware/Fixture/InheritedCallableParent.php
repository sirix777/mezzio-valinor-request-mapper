<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;

#[MapRequest(body: RequiredRequest::class, output: 'parent')]
class InheritedCallableParent
{
    #[MapRequest(body: RequiredRequest::class, output: 'work')]
    public function work(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'class'  => $request->getAttribute('child')->name,
            'method' => $request->getAttribute('work')->name,
        ]);
    }

    #[MapRequest(body: RequiredRequest::class, output: 'staticWork')]
    public static function staticWork(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse([
            'class'  => $request->getAttribute('child')->name,
            'method' => $request->getAttribute('staticWork')->name,
        ]);
    }
}

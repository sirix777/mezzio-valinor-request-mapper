<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor;

final class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'factories' => [
                    Middleware\ValinorRequestMapperMiddleware::class => Factory\ValinorRequestMapperMiddlewareFactory::class,
                ],
            ],
        ];
    }
}

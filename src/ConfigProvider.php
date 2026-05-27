<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor;

use CuyZ\Valinor\Mapper\TreeMapper;

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
                    TreeMapper::class => Factory\ValinorTreeMapperFactory::class,
                    Middleware\ValinorRequestMapperMiddleware::class => Factory\ValinorRequestMapperMiddlewareFactory::class,
                ],
            ],
        ];
    }
}

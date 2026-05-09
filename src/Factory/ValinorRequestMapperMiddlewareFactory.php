<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use CuyZ\Valinor\Mapper\Tree\Message\Formatter\MessageMapFormatter;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

final readonly class ValinorRequestMapperMiddlewareFactory
{
    private const CONFIG_KEY = 'sirix_mezzio_valinor';

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): ValinorRequestMapperMiddleware
    {
        /** @var array<string, mixed> $config */
        $config = $container->get('config')[self::CONFIG_KEY] ?? [];

        /** @var array<string, mixed> $errorConfig */
        $errorConfig = $config['error'] ?? [];

        $messageMap = (array) ($errorConfig['message_map'] ?? []);
        $formatters = [];

        if ([] !== $messageMap) {
            $formatters[] = new MessageMapFormatter($messageMap);
        }

        $treeMapperFactory = new ValinorTreeMapperFactory(
            $container,
            (array) ($config['mapper'] ?? []),
        );

        return new ValinorRequestMapperMiddleware(
            $treeMapperFactory(),
            $errorConfig,
            ...$formatters,
        );
    }
}

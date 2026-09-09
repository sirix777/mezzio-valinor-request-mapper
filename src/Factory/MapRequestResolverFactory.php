<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;

final readonly class MapRequestResolverFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): MapRequestResolver
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        return new MapRequestResolver(
            $resolver->get(HandlerTargetResolver::class),
            $resolver->get(MapRequestOptionsParser::class),
        );
    }
}

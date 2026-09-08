<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sirix\ContainerResolver\ContainerResolver;

final readonly class ValinorTreeMapperFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): TreeMapper
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        return $resolver->get(MapperBuilder::class)->mapper();
    }
}

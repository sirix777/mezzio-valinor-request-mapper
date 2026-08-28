<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;

final readonly class MappingErrorResponderResolverFactory
{
    /**
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): MappingErrorResponderResolver
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        return new MappingErrorResponderResolver(
            $resolver->optionalAs(MappingErrorResponderInterface::class, MappingErrorResponderInterface::class)
                ?? $resolver->get(DefaultMappingErrorResponder::class),
            $resolver,
        );
    }
}

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use CuyZ\Valinor\Mapper\TreeMapper;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

final readonly class ValinorRequestMapperMiddlewareFactory
{
    /**
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): ValinorRequestMapperMiddleware
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        $treeMapper             = $resolver->get(TreeMapper::class);
        $errorResponderResolver = $resolver->get(MappingErrorResponderResolver::class);

        return new ValinorRequestMapperMiddleware(
            $treeMapper,
            $errorResponderResolver,
        );
    }
}

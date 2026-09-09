<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use CuyZ\Valinor\Mapper\TreeMapper;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

final readonly class ValinorRequestMapperMiddlewareFactory
{
    /**
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): ValinorRequestMapperMiddleware
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        $treeMapper               = $resolver->get(TreeMapper::class);
        $errorResponderResolver   = $resolver->get(MappingErrorResponderResolver::class);
        $mappingPlanResolver      = $resolver->get(MappingPlanResolver::class);
        $httpRequestSourceFactory = $resolver->get(HttpRequestSourceFactory::class);

        return new ValinorRequestMapperMiddleware(
            $treeMapper,
            $errorResponderResolver,
            $mappingPlanResolver,
            $httpRequestSourceFactory,
        );
    }
}

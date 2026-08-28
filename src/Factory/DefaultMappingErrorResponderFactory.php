<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;

final readonly class DefaultMappingErrorResponderFactory
{
    /**
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): DefaultMappingErrorResponder
    {
        $resolver        = ContainerResolver::forFactory($container, self::class);
        $responseFactory = $resolver->get(ResponseFactoryInterface::class);
        $streamFactory   = $resolver->get(StreamFactoryInterface::class);

        return new DefaultMappingErrorResponder($responseFactory, $streamFactory);
    }
}

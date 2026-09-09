<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;

final readonly class HttpRequestSourceFactoryFactory
{
    /**
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): HttpRequestSourceFactory
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        return new HttpRequestSourceFactory($resolver->get(InputEncodingValidator::class));
    }
}

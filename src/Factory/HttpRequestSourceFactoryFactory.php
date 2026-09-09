<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use Psr\Container\ContainerInterface;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;

final class HttpRequestSourceFactoryFactory
{
    public function __invoke(ContainerInterface $container): HttpRequestSourceFactory
    {
        return new HttpRequestSourceFactory($container->get(InputEncodingValidator::class));
    }
}

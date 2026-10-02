<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sirix\ContainerResolver\ConfigReader;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\ErrorResponseOptions;

final readonly class DefaultMappingErrorResponderFactory
{
    private const CONFIG_KEY = 'sirix_mezzio_valinor';

    /**
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): DefaultMappingErrorResponder
    {
        $resolver        = ContainerResolver::forFactory($container, self::class);
        $responseFactory = $resolver->get(ResponseFactoryInterface::class);
        $streamFactory   = $resolver->get(StreamFactoryInterface::class);

        $config = ConfigReader::fromArray(
            ConfigReader::fromContainer($resolver)->map(self::CONFIG_KEY, default: []),
            self::class,
        )->map('error_response', default: []);

        return new DefaultMappingErrorResponder(
            $responseFactory,
            $streamFactory,
            ErrorResponseOptions::fromArray($config),
        );
    }
}

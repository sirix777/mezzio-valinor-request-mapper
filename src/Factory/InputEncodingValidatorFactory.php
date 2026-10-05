<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ConfigReader;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\InputLimits;

final readonly class InputEncodingValidatorFactory
{
    private const CONFIG_KEY = 'sirix_mezzio_valinor';

    /**
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): InputEncodingValidator
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        $config = ConfigReader::fromArray(
            ConfigReader::fromContainer($resolver)->map(self::CONFIG_KEY, default: []),
            self::class,
        )->map('input_limits', default: []);

        return new InputEncodingValidator(InputLimits::fromArray($config));
    }
}

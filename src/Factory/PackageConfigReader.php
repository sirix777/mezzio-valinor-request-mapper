<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use Sirix\ContainerResolver\ConfigReader;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;

use function in_array;
use function sprintf;

/** @internal */
final readonly class PackageConfigReader
{
    public static function fromContainer(ContainerResolver $resolver): ConfigReader
    {
        $config = ConfigReader::fromContainer($resolver)->map('sirix_mezzio_valinor', default: []);

        foreach ($config as $key => $value) {
            if (! in_array($key, ['mapper', 'input_limits', 'error_response'], true)) {
                throw new InvalidMapRequestConfiguration(sprintf(
                    'sirix_mezzio_valinor.%s: unknown configuration section.',
                    $key,
                ));
            }
        }

        return ConfigReader::fromArray($config, $resolver->context());
    }
}

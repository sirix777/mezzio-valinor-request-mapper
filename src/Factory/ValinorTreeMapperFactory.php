<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use CuyZ\Valinor\Cache\Cache;
use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\Cache\FileWatchingCache;
use CuyZ\Valinor\Mapper\Configurator\MapperBuilderConfigurator;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

use function is_a;
use function is_string;

final readonly class ValinorTreeMapperFactory
{
    private const CONFIG_KEY = 'sirix_mezzio_valinor';

    /**
     * @param null|array<string, mixed> $config
     */
    public function __construct(private ?ContainerInterface $container = null, private ?array $config = null) {}

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(?ContainerInterface $container = null): TreeMapper
    {
        $container ??= $this->container;

        if (! $container instanceof ContainerInterface) {
            throw new RuntimeException('A PSR-11 container is required to create the Valinor tree mapper.');
        }

        /** @var array<string, mixed> $appConfig */
        $appConfig = $container->has('config') ? (array) $container->get('config') : [];

        /** @var array<string, mixed> $rootConfig */
        $rootConfig = $appConfig[self::CONFIG_KEY] ?? [];
        $config = $this->config ?? (array) ($rootConfig['mapper'] ?? []);

        $builder = new MapperBuilder();

        $cache = $this->createCache($config);

        if ($cache instanceof Cache) {
            $builder = $builder->withCache($cache);
        }

        foreach ($config['configurators'] ?? [] as $configurator) {
            if (is_string($configurator)) {
                if ($container->has($configurator)) {
                    $configurator = $container->get($configurator);
                } elseif (is_a($configurator, MapperBuilderConfigurator::class, true)) {
                    $configurator = new $configurator();
                }
            }

            if ($configurator instanceof MapperBuilderConfigurator) {
                $builder = $builder->configureWith($configurator);
            }
        }

        if ($config['allow_superfluous_keys'] ?? true) {
            $builder = $builder->allowSuperfluousKeys();
        }

        if ($config['allow_scalar_value_casting'] ?? true) {
            $builder = $builder->allowScalarValueCasting();
        }

        if ($config['allow_permissive_types'] ?? false) {
            $builder = $builder->allowPermissiveTypes();
        }

        if ($config['allow_undefined_values'] ?? false) {
            $builder = $builder->allowUndefinedValues();
        }

        foreach ((array) ($config['support_date_formats'] ?? []) as $format) {
            if (is_string($format) && '' !== $format) {
                $builder = $builder->supportDateFormats($format);
            }
        }

        return $builder->mapper();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createCache(array $config): ?Cache
    {
        $cacheDir = $config['cache_dir'] ?? null;

        if (null === $cacheDir || '' === $cacheDir) {
            return null;
        }

        $cache = new FileSystemCache($cacheDir);

        if ($config['cache_watch'] ?? false) {
            return new FileWatchingCache($cache);
        }

        return $cache;
    }
}

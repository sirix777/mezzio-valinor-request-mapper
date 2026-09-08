<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Factory;

use CuyZ\Valinor\Cache\Cache;
use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\Cache\FileWatchingCache;
use CuyZ\Valinor\Mapper\Configurator\MapperBuilderConfigurator;
use CuyZ\Valinor\MapperBuilder;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sirix\ContainerResolver\ConfigReader;
use Sirix\ContainerResolver\ContainerResolver;

use function is_a;
use function is_string;

final readonly class ValinorMapperBuilderFactory
{
    private const CONFIG_KEY = 'sirix_mezzio_valinor';

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): MapperBuilder
    {
        $resolver = ContainerResolver::forFactory($container, self::class);

        $config   = ConfigReader::fromArray(
            ConfigReader::fromContainer($resolver)->map(self::CONFIG_KEY, default: []),
            self::class,
        )->map('mapper', default: []);

        $configReader = ConfigReader::fromArray($config, self::class);

        $builder = new MapperBuilder();

        $cache = $this->createCache($configReader);

        if ($cache instanceof Cache) {
            $builder = $builder->withCache($cache);
        }

        foreach ($configReader->array('configurators', default: []) as $configurator) {
            if (is_string($configurator)) {
                $configurator = $resolver->optionalAs($configurator, MapperBuilderConfigurator::class)
                    ?? (is_a($configurator, MapperBuilderConfigurator::class, true) ? new $configurator() : null);
            }

            if ($configurator instanceof MapperBuilderConfigurator) {
                $builder = $builder->configureWith($configurator);
            }
        }

        if ($configReader->bool('allow_superfluous_keys', default: true)) {
            $builder = $builder->allowSuperfluousKeys();
        }

        if ($configReader->bool('allow_scalar_value_casting', default: true)) {
            $builder = $builder->allowScalarValueCasting();
        }

        if ($configReader->bool('allow_permissive_types', default: false)) {
            $builder = $builder->allowPermissiveTypes();
        }

        if ($configReader->bool('allow_undefined_values', default: false)) {
            $builder = $builder->allowUndefinedValues();
        }

        foreach ($configReader->nonEmptyStringList('support_date_formats', default: []) as $format) {
            $builder = $builder->supportDateFormats($format);
        }

        return $builder;
    }

    private function createCache(ConfigReader $config): ?Cache
    {
        if (null === $config->get('cache_dir')) {
            return null;
        }

        $cacheDir = $config->string('cache_dir', '');

        if ('' === $cacheDir) {
            return null;
        }

        $cache = new FileSystemCache($cacheDir);

        if ($config->bool('cache_watch', default: false)) {
            return new FileWatchingCache($cache);
        }

        return $cache;
    }
}

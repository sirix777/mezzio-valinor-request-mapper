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
use ReflectionClass;
use Sirix\ContainerResolver\ConfigReader;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;

use function array_unique;
use function array_values;
use function get_debug_type;
use function in_array;
use function is_a;
use function is_string;
use function sprintf;

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

        $config = PackageConfigReader::fromContainer($resolver)->map('mapper', default: []);

        foreach ($config as $key => $value) {
            if (! in_array($key, [
                'configurators',
                'allow_superfluous_keys',
                'allow_scalar_value_casting',
                'allow_permissive_types',
                'allow_undefined_values',
                'support_date_formats',
                'cache_dir',
                'cache_watch',
            ], true)) {
                throw new InvalidMapRequestConfiguration(sprintf(
                    '%s.mapper.%s: unknown mapper option.',
                    self::CONFIG_KEY,
                    $key,
                ));
            }
        }

        $configReader = ConfigReader::fromArray($config, self::class);

        $builder = new MapperBuilder();

        $cache = $this->createCache($configReader);

        if ($cache instanceof Cache) {
            $builder = $builder->withCache($cache);
        }

        foreach ($configReader->array('configurators', default: []) as $index => $value) {
            $builder = $builder->configureWith($this->resolveConfigurator($value, $index, $resolver));
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

        $configuredFormats = $configReader->nonEmptyStringList('support_date_formats', default: []);

        if ([] !== $configuredFormats) {
            $formats = array_values(array_unique([...$builder->supportedDateFormats(), ...$configuredFormats]));

            $builder = $builder->supportDateFormats(...$formats);
        }

        return $builder;
    }

    private function resolveConfigurator(mixed $value, int|string $index, ContainerResolver $resolver): MapperBuilderConfigurator
    {
        if ($value instanceof MapperBuilderConfigurator) {
            return $value;
        }

        if (is_string($value)) {
            $service = $resolver->optionalAs($value, MapperBuilderConfigurator::class);

            if (null !== $service) {
                return $service;
            }

            if (is_a($value, MapperBuilderConfigurator::class, true)) {
                $class = new ReflectionClass($value);

                if ($class->isInstantiable() && 0 === ($class->getConstructor()?->getNumberOfRequiredParameters() ?? 0)) {
                    return new $value();
                }
            }
        }

        throw new InvalidMapRequestConfiguration(sprintf(
            'mapper.configurators[%s]: expected a registered service or constructible %s; received %s.',
            $index,
            MapperBuilderConfigurator::class,
            is_string($value) ? "'{$value}'" : get_debug_type($value),
        ));
    }

    private function createCache(ConfigReader $config): ?Cache
    {
        $cacheWatch = $config->bool('cache_watch', default: false);

        if (null === $config->get('cache_dir')) {
            return null;
        }

        $cacheDir = $config->string('cache_dir', '');

        if ('' === $cacheDir) {
            return null;
        }

        $cache = new FileSystemCache($cacheDir);

        if ($cacheWatch) {
            return new FileWatchingCache($cache);
        }

        return $cache;
    }
}

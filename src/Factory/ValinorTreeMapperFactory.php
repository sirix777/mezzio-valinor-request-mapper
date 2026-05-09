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

use function is_a;
use function is_string;

final readonly class ValinorTreeMapperFactory
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param null|array<string, mixed> $config
     */
    public function __construct(private ContainerInterface $container, ?array $config = null)
    {
        $this->config = $config ?? [];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(): TreeMapper
    {
        $builder = new MapperBuilder();

        $cache = $this->createCache();

        if ($cache instanceof Cache) {
            $builder = $builder->withCache($cache);
        }

        foreach ($this->config['configurators'] ?? [] as $configurator) {
            if (is_string($configurator)) {
                if ($this->container->has($configurator)) {
                    $configurator = $this->container->get($configurator);
                } elseif (is_a($configurator, MapperBuilderConfigurator::class, true)) {
                    $configurator = new $configurator();
                }
            }

            if ($configurator instanceof MapperBuilderConfigurator) {
                $builder = $builder->configureWith($configurator);
            }
        }

        if ($this->config['allow_superfluous_keys'] ?? true) {
            $builder = $builder->allowSuperfluousKeys();
        }

        if ($this->config['allow_scalar_value_casting'] ?? true) {
            $builder = $builder->allowScalarValueCasting();
        }

        if ($this->config['allow_permissive_types'] ?? false) {
            $builder = $builder->allowPermissiveTypes();
        }

        if ($this->config['allow_undefined_values'] ?? false) {
            $builder = $builder->allowUndefinedValues();
        }

        foreach ((array) ($this->config['support_date_formats'] ?? []) as $format) {
            if (is_string($format) && '' !== $format) {
                $builder = $builder->supportDateFormats($format);
            }
        }

        return $builder->mapper();
    }

    private function createCache(): ?Cache
    {
        $cacheDir = $this->config['cache_dir'] ?? null;

        if (null === $cacheDir || '' === $cacheDir) {
            return null;
        }

        $cache = new FileSystemCache($cacheDir);

        if ($this->config['cache_watch'] ?? false) {
            return new FileWatchingCache($cache);
        }

        return $cache;
    }
}

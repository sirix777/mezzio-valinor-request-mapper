<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\Mapper\Configurator\ConvertKeysToCamelCase;
use CuyZ\Valinor\Mapper\Configurator\MapperBuilderConfigurator;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Sirix\Mezzio\Valinor\Factory\ValinorTreeMapperFactory;
use Throwable;

use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ValinorTreeMapperFactoryTest extends TestCase
{
    #[Test]
    public function defaultConfigCreatesMapper(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer());

        $mapper = $factory();

        self::assertInstanceOf(TreeMapper::class, $mapper);
    }

    #[Test]
    public function configuratorFromContainerIsApplied(): void
    {
        $configurator = new class implements MapperBuilderConfigurator {
            public bool $applied = false;

            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                $this->applied = true;

                return $builder;
            }
        };

        $container = $this->createContainer(['MyConfigurator' => $configurator]);
        $factory = new ValinorTreeMapperFactory($container, [
            'configurators' => ['MyConfigurator'],
        ]);

        $factory();

        self::assertTrue($configurator->applied);
    }

    #[Test]
    public function configuratorClassNameIsInstantiatedDirectly(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [ConvertKeysToCamelCase::class],
        ]);

        $mapper = $factory();

        self::assertInstanceOf(TreeMapper::class, $mapper);
    }

    #[Test]
    public function invalidStringConfiguratorIsSkipped(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => ['NonExistentClass'],
        ]);

        $mapper = $factory();

        self::assertInstanceOf(TreeMapper::class, $mapper);
    }

    #[Test]
    public function allowSuperfluousKeysIsFalse(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [],
            'allow_superfluous_keys' => false,
        ]);

        $mapper = $factory();

        $this->expectException(Throwable::class);

        $mapper->map(
            'array{name: string}',
            ['name' => 'test', 'extra' => 'should fail'],
        );
    }

    #[Test]
    public function allowSuperfluousKeysIsTrue(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [],
            'allow_superfluous_keys' => true,
        ]);

        $mapper = $factory();

        $dto = $mapper->map(
            'array{name: string}',
            ['name' => 'test', 'extra' => 'ignored'],
        );

        self::assertSame('test', $dto['name']);
    }

    #[Test]
    public function allowScalarValueCastingConvertsIntToString(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [],
            'allow_scalar_value_casting' => true,
            'allow_superfluous_keys' => false,
        ]);

        $mapper = $factory();

        $dto = $mapper->map(
            'array{value: string}',
            ['value' => 42],
        );

        self::assertSame('42', $dto['value']);
    }

    #[Test]
    public function allowScalarValueCastingFalseThrowsOnTypeMismatch(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [],
            'allow_scalar_value_casting' => false,
        ]);

        $mapper = $factory();

        $this->expectException(MappingError::class);

        $mapper->map('array{value: string}', ['value' => 42]);
    }

    #[Test]
    public function allowPermissiveTypesAllowsMixed(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [],
            'allow_permissive_types' => true,
            'allow_superfluous_keys' => false,
        ]);

        $mapper = $factory();

        $dto = $mapper->map('array{data: mixed}', ['data' => 42]);

        self::assertSame(42, $dto['data']);
    }

    #[Test]
    public function allowUndefinedValuesFillsMissingWithNull(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [],
            'allow_undefined_values' => true,
            'allow_superfluous_keys' => false,
        ]);

        $mapper = $factory();

        $dto = $mapper->map('array{name: string, age: int|null}', ['name' => 'test']);

        self::assertSame('test', $dto['name']);
        self::assertNull($dto['age']);
    }

    #[Test]
    public function allowUndefinedValuesIsFalseThrowsOnMissingFields(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [],
            'allow_undefined_values' => false,
        ]);

        $mapper = $factory();

        $this->expectException(MappingError::class);

        $mapper->map('array{name: string, age: int}', ['name' => 'test']);
    }

    #[Test]
    public function supportDateFormatsAcceptsCustomFormat(): void
    {
        $factory = new ValinorTreeMapperFactory($this->createContainer(), [
            'configurators' => [],
            'support_date_formats' => ['d/m/Y'],
            'allow_superfluous_keys' => false,
        ]);

        $mapper = $factory();

        $dto = $mapper->map(
            'array{date: DateTimeInterface}',
            ['date' => '25/12/2024'],
        );

        self::assertInstanceOf(DateTimeInterface::class, $dto['date']);
        self::assertSame('2024-12-25', $dto['date']->format('Y-m-d'));
    }

    #[Test]
    public function cacheDirCreatesMapperWithFileSystemCache(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $factory = new ValinorTreeMapperFactory($this->createContainer(), [
                'configurators' => [],
                'cache_dir' => $cacheDir,
                'allow_superfluous_keys' => false,
            ]);

            $mapper = $factory();

            $dto = $mapper->map(DateTimeImmutable::class, '2024-01-01T00:00:00+00:00');

            self::assertInstanceOf(DateTimeImmutable::class, $dto);

            $cacheFiles = $this->findFiles($cacheDir);
            self::assertNotEmpty($cacheFiles, 'Cache files should be created when cache_dir is set');
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function cacheWatchCreatesMapperWithFileWatchingCache(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $factory = new ValinorTreeMapperFactory($this->createContainer(), [
                'configurators' => [],
                'cache_dir' => $cacheDir,
                'cache_watch' => true,
                'allow_superfluous_keys' => false,
            ]);

            $mapper = $factory();

            $dto = $mapper->map(DateTimeImmutable::class, '2024-01-01T00:00:00+00:00');

            self::assertInstanceOf(DateTimeImmutable::class, $dto);

            $cacheFiles = $this->findFiles($cacheDir);
            self::assertNotEmpty($cacheFiles, 'Cache files should be created with cache_watch enabled');
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function warmupCacheForWritesCacheWithoutMapping(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $builder = (new MapperBuilder())
                ->withCache(new FileSystemCache($cacheDir))
            ;

            $builder->warmupCacheFor(self::class);

            $cacheFiles = $this->findFiles($cacheDir);
            self::assertNotEmpty($cacheFiles, 'Cache files should be created after warmup');

            $factory = new ValinorTreeMapperFactory($this->createContainer(), [
                'configurators' => [],
                'cache_dir' => $cacheDir,
                'allow_superfluous_keys' => false,
            ]);

            $mapper = $factory();

            $dto = $mapper->map('array{name: string}', ['name' => 'test']);
            self::assertSame('test', $dto['name']);
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    /**
     * @return list<string>
     */
    private function findFiles(string $dir): array
    {
        $result = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $result[] = $file->getPathname();
            }
        }

        return $result;
    }

    private function createTempDir(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'valinor_cache_');
        unlink($file);
        mkdir($file, 0o755, true);

        return $file;
    }

    private function removeDir(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $services
     */
    private function createContainer(array $services = []): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            /**
             * @param array<string, mixed> $services
             */
            public function __construct(private readonly array $services) {}

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new RuntimeException("Service not found: {$id}");
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }
}

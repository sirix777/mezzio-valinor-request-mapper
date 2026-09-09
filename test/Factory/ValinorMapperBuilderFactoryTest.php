<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\Mapper\Configurator\ConvertKeysToCamelCase;
use CuyZ\Valinor\Mapper\Configurator\MapperBuilderConfigurator;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\MapperBuilder;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Sirix\ContainerResolver\Exception\InvalidConfigValueException;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\Mezzio\Valinor\Factory\ValinorMapperBuilderFactory;
use Sirix\Mezzio\Valinor\Test\Factory\Fixture\CacheableRequest;
use Sirix\Mezzio\Valinor\Test\Factory\Fixture\MixedCacheableRequest;
use stdClass;
use Throwable;

use function array_key_exists;
use function array_map;
use function basename;
use function chdir;
use function file_get_contents;
use function getcwd;
use function mkdir;
use function rmdir;
use function sort;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ValinorMapperBuilderFactoryTest extends TestCase
{
    #[Test]
    public function defaultConfigCreatesMapperBuilder(): void
    {
        self::assertInstanceOf(MapperBuilder::class, $this->builder());
    }

    #[Test]
    public function readsMapperConfigurationFromTheContainer(): void
    {
        $mapper = $this->builder([
            'allow_scalar_value_casting' => false,
        ])->mapper();

        $this->expectException(MappingError::class);

        $mapper->map('array{value: string}', [
            'value' => 42,
        ]);
    }

    #[Test]
    public function rejectsInvalidMapperConfigurationValues(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        $this->builder([
            'allow_scalar_value_casting' => 'true',
        ]);
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

        $this->builder([
            'configurators' => ['MyConfigurator'],
        ], [
            'MyConfigurator' => $configurator,
        ]);

        self::assertTrue($configurator->applied);
    }

    #[Test]
    public function rejectsConfiguratorServiceWithAnIncorrectType(): void
    {
        $this->expectException(InvalidContainerServiceException::class);

        $this->builder([
            'configurators' => ['MyConfigurator'],
        ], [
            'MyConfigurator' => new stdClass(),
        ]);
    }

    #[Test]
    public function configuratorClassNameIsInstantiatedDirectly(): void
    {
        self::assertInstanceOf(MapperBuilder::class, $this->builder([
            'configurators' => [ConvertKeysToCamelCase::class],
        ]));
    }

    #[Test]
    public function invalidStringConfiguratorIsSkipped(): void
    {
        self::assertInstanceOf(MapperBuilder::class, $this->builder([
            'configurators' => ['NonExistentClass'],
        ]));
    }

    #[Test]
    public function arrayMappingFailsWithSuperfluousKeysWhenFlagIsFalse(): void
    {
        $mapper = $this->builder([
            'allow_superfluous_keys' => false,
        ])->mapper();

        $this->expectException(Throwable::class);

        $mapper->map('array{name: string}', [
            'name'  => 'test',
            'extra' => 'should fail',
        ]);
    }

    #[Test]
    public function arrayMappingAllowsSuperfluousKeysWhenFlagIsTrue(): void
    {
        $mapper = $this->builder([
            'allow_superfluous_keys' => true,
        ])->mapper();

        $dto = $mapper->map('array{name: string}', [
            'name'  => 'test',
            'extra' => 'ignored',
        ]);

        self::assertSame('test', $dto['name']);
    }

    #[Test]
    public function arrayMappingCastsIntToStringWhenFlagIsTrue(): void
    {
        $mapper = $this->builder([
            'allow_scalar_value_casting' => true,
            'allow_superfluous_keys'     => false,
        ])->mapper();

        $dto = $mapper->map('array{value: string}', [
            'value' => 42,
        ]);

        self::assertSame('42', $dto['value']);
    }

    #[Test]
    public function arrayMappingThrowsOnTypeMismatchWhenFlagIsFalse(): void
    {
        $mapper = $this->builder([
            'allow_scalar_value_casting' => false,
        ])->mapper();

        $this->expectException(MappingError::class);

        $mapper->map('array{value: string}', [
            'value' => 42,
        ]);
    }

    #[Test]
    public function allowPermissiveTypesAllowsMixed(): void
    {
        $mapper = $this->builder([
            'allow_permissive_types' => true,
            'allow_superfluous_keys' => false,
        ])->mapper();

        $dto = $mapper->map('array{data: mixed}', [
            'data' => 42,
        ]);

        self::assertSame(42, $dto['data']);
    }

    #[Test]
    public function allowUndefinedValuesFillsMissingWithNull(): void
    {
        $mapper = $this->builder([
            'allow_undefined_values' => true,
            'allow_superfluous_keys' => false,
        ])->mapper();

        $dto = $mapper->map('array{name: string, age: int|null}', [
            'name' => 'test',
        ]);

        self::assertSame('test', $dto['name']);
        self::assertNull($dto['age']);
    }

    #[Test]
    public function allowUndefinedValuesIsFalseThrowsOnMissingFields(): void
    {
        $mapper = $this->builder([
            'allow_undefined_values' => false,
        ])->mapper();

        $this->expectException(MappingError::class);

        $mapper->map('array{name: string, age: int}', [
            'name' => 'test',
        ]);
    }

    #[Test]
    public function supportDateFormatsAcceptsCustomFormat(): void
    {
        $mapper = $this->builder([
            'support_date_formats'   => ['d/m/Y'],
            'allow_superfluous_keys' => false,
        ])->mapper();

        $dto = $mapper->map('array{date: DateTimeInterface}', [
            'date' => '25/12/2024',
        ]);

        self::assertInstanceOf(DateTimeInterface::class, $dto['date']);
        self::assertSame('2024-12-25', $dto['date']->format('Y-m-d'));
    }

    #[Test]
    public function supportDateFormatsPreservesMultipleConfiguredFormats(): void
    {
        $mapper = $this->builder([
            'support_date_formats'   => ['Y-m-d', 'd/m/Y'],
            'allow_superfluous_keys' => false,
        ])->mapper();

        $ymd = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '2026-09-08',
        ]);

        self::assertSame('2026-09-08', $ymd['date']->format('Y-m-d'));

        $dmy = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '08/09/2026',
        ]);

        self::assertSame('2026-09-08', $dmy['date']->format('Y-m-d'));
    }

    #[Test]
    public function supportDateFormatsPreservesDefaultRfc3339(): void
    {
        $mapper = $this->builder([
            'support_date_formats'   => ['Y-m-d', 'd/m/Y'],
            'allow_superfluous_keys' => false,
        ])->mapper();

        $rfc3339 = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '2026-09-08T12:30:00+00:00',
        ]);

        self::assertSame('2026-09-08T12:30:00+00:00', $rfc3339['date']->format('Y-m-d\TH:i:sP'));
    }

    #[Test]
    public function supportDateFormatsPreservesDefaultTimestamp(): void
    {
        $mapper = $this->builder([
            'support_date_formats'   => ['Y-m-d', 'd/m/Y'],
            'allow_superfluous_keys' => false,
        ])->mapper();

        $timestamp = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '1700000000',
        ]);

        self::assertSame('1700000000', $timestamp['date']->format('U'));
    }

    #[Test]
    public function emptySupportDateFormatsKeepsDefaultFormats(): void
    {
        $mapper = $this->builder([
            'support_date_formats'   => [],
            'allow_superfluous_keys' => false,
        ])->mapper();

        $rfc3339 = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '2026-09-08T12:30:00+00:00',
        ]);

        self::assertSame('2026-09-08T12:30:00+00:00', $rfc3339['date']->format('Y-m-d\TH:i:sP'));
    }

    #[Test]
    public function supportDateFormatsRemovesDuplicatesKeepingFirstOccurrence(): void
    {
        $builder = $this->builder([
            'support_date_formats'   => ['Y-m-d', 'Y-m-d', 'd/m/Y'],
            'allow_superfluous_keys' => false,
        ]);

        self::assertSame(
            [
                'Y-m-d\TH:i:sP',
                'Y-m-d\TH:i:s.uP',
                'U',
                'U.u',
                'Y-m-d',
                'd/m/Y',
            ],
            $builder->supportedDateFormats(),
        );

        $mapper = $builder->mapper();

        $ymd = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '2026-09-08',
        ]);

        self::assertSame('2026-09-08', $ymd['date']->format('Y-m-d'));

        $dmy = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '08/09/2026',
        ]);

        self::assertSame('2026-09-08', $dmy['date']->format('Y-m-d'));
    }

    #[Test]
    public function supportDateFormatsAppendsToConfiguratorFormats(): void
    {
        $configurator = new class implements MapperBuilderConfigurator {
            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                return $builder->supportDateFormats('m.d.Y');
            }
        };

        $mapper = $this->builder([
            'support_date_formats'   => ['d/m/Y'],
            'allow_superfluous_keys' => false,
            'configurators'          => [$configurator],
        ])->mapper();

        $mdy = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '09.08.2026',
        ]);

        self::assertSame('2026-09-08', $mdy['date']->format('Y-m-d'));

        $dmy = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '08/09/2026',
        ]);

        self::assertSame('2026-09-08', $dmy['date']->format('Y-m-d'));
    }

    #[Test]
    public function configuratorDateFormatsAreTheBaseWhenConfigurationIsEmpty(): void
    {
        $configurator = new class implements MapperBuilderConfigurator {
            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                return $builder->supportDateFormats('m.d.Y');
            }
        };

        $mapper = $this->builder([
            'support_date_formats'   => [],
            'allow_superfluous_keys' => false,
            'configurators'          => [$configurator],
        ])->mapper();

        $mdy = $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '09.08.2026',
        ]);

        self::assertSame('2026-09-08', $mdy['date']->format('Y-m-d'));

        $this->expectException(MappingError::class);

        $mapper->map('array{date: DateTimeImmutable}', [
            'date' => '2026-09-08T12:30:00+00:00',
        ]);
    }

    #[Test]
    public function rejectsBlankDateFormatString(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        $this->builder([
            'support_date_formats'   => [''],
            'allow_superfluous_keys' => false,
        ]);
    }

    #[Test]
    public function rejectsWhitespaceDateFormatString(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        $this->builder([
            'support_date_formats'   => ['   '],
            'allow_superfluous_keys' => false,
        ]);
    }

    #[Test]
    public function cacheDirCreatesMapperWithFileSystemCache(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $mapper = $this->builder([
                'cache_dir'              => $cacheDir,
                'allow_superfluous_keys' => false,
            ])->mapper();

            self::assertInstanceOf(DateTimeImmutable::class, $mapper->map(DateTimeImmutable::class, '2024-01-01T00:00:00+00:00'));
            self::assertNotEmpty($this->findFiles($cacheDir));
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function cacheWatchCreatesMapperWithFileWatchingCache(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $mapper = $this->builder([
                'cache_dir'              => $cacheDir,
                'cache_watch'            => true,
                'allow_superfluous_keys' => false,
            ])->mapper();

            self::assertInstanceOf(DateTimeImmutable::class, $mapper->map(DateTimeImmutable::class, '2024-01-01T00:00:00+00:00'));
            self::assertNotEmpty($this->findFiles($cacheDir));
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function emptyCacheDirDisablesFileSystemCache(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $mapper = $this->builder([
                'cache_dir'              => '',
                'allow_superfluous_keys' => false,
            ])->mapper();

            self::assertInstanceOf(CacheableRequest::class, $mapper->map(CacheableRequest::class, [
                'name' => 'test',
            ]));
            self::assertSame([], $this->findFiles($cacheDir));
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function whitespaceCacheDirDisablesFileSystemCache(): void
    {
        $cacheDir    = $this->createTempDir();
        $originalCwd = getcwd();

        self::assertNotFalse($originalCwd);

        try {
            chdir($cacheDir);

            $mapper = $this->builder([
                'cache_dir'              => '   ',
                'allow_superfluous_keys' => false,
            ])->mapper();

            self::assertInstanceOf(CacheableRequest::class, $mapper->map(CacheableRequest::class, [
                'name' => 'test',
            ]));
            self::assertSame([], $this->findFiles($cacheDir));
        } finally {
            chdir($originalCwd);
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function rejectsInvalidCacheDirType(): void
    {
        $this->expectException(InvalidConfigValueException::class);

        $this->builder([
            'cache_dir'              => 123,
            'allow_superfluous_keys' => false,
        ]);
    }

    #[Test]
    public function warmupCacheForWritesCacheWithoutMapping(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $this->builder([
                'cache_dir'              => $cacheDir,
                'allow_superfluous_keys' => false,
            ])->warmupCacheFor(CacheableRequest::class);

            self::assertNotEmpty($this->findFiles($cacheDir));

            $mapper = $this->builder([
                'cache_dir'              => $cacheDir,
                'allow_superfluous_keys' => false,
            ])->mapper();
            $dto    = $mapper->map(CacheableRequest::class, [
                'name' => 'test',
            ]);

            self::assertInstanceOf(CacheableRequest::class, $dto);
            self::assertSame('test', $dto->name);
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function runtimeAndWarmupShareCacheEntry(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $configurator = new class implements MapperBuilderConfigurator {
                public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
                {
                    return $builder->allowPermissiveTypes();
                }
            };

            $config = [
                'cache_dir'              => $cacheDir,
                'allow_superfluous_keys' => false,
                'configurators'          => [$configurator],
            ];

            $this->builder($config)->warmupCacheFor(MixedCacheableRequest::class);
            $warmupFiles    = $this->findFiles($cacheDir);
            $warmupNames    = $this->fileNames($warmupFiles);
            $warmupContents = $this->fileContents($warmupFiles);
            self::assertNotEmpty($warmupFiles);

            $dto = $this->builder($config)->mapper()->map(MixedCacheableRequest::class, [
                'value' => 42,
            ]);
            self::assertInstanceOf(MixedCacheableRequest::class, $dto);
            self::assertSame(42, $dto->value);

            $runtimeFiles = $this->findFiles($cacheDir);
            self::assertSame($warmupNames, $this->fileNames($runtimeFiles));
            self::assertSame($warmupContents, $this->fileContents($runtimeFiles));
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function baseBuilderWithDifferentFlagsCreatesDifferentCacheKey(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $configuredDir = $this->createTempDir();

            try {
                $configuredConfig = [
                    'cache_dir'              => $configuredDir,
                    'allow_superfluous_keys' => false,
                    'configurators'          => [ConvertKeysToCamelCase::class],
                ];

                $this->builder($configuredConfig)->warmupCacheFor(CacheableRequest::class);
                $configuredFiles = $this->findFiles($configuredDir);
                self::assertNotEmpty($configuredFiles);

                (new MapperBuilder())->withCache(new FileSystemCache($cacheDir))->warmupCacheFor(CacheableRequest::class);
                $baseFiles = $this->findFiles($cacheDir);
                self::assertNotEmpty($baseFiles);

                self::assertNotSame(
                    $this->fileNames($configuredFiles),
                    $this->fileNames($baseFiles),
                    'A plain MapperBuilder must produce different cache keys than the configured builder.',
                );
            } finally {
                $this->removeDir($configuredDir);
            }
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    #[Test]
    public function noCacheFilesWhenCacheDirIsNull(): void
    {
        $cacheDir = $this->createTempDir();

        try {
            $mapper = $this->builder([
                'cache_dir'              => null,
                'allow_superfluous_keys' => false,
            ])->mapper();

            $mapper->map(CacheableRequest::class, [
                'name' => 'test',
            ]);

            self::assertSame([], $this->findFiles($cacheDir));
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    /**
     * @param array<string, mixed> $mapperConfig
     * @param array<string, mixed> $services
     */
    private function builder(array $mapperConfig = [], array $services = []): MapperBuilder
    {
        $services['config'] = [
            'sirix_mezzio_valinor' => [
                'mapper' => $mapperConfig,
            ],
        ];

        return (new ValinorMapperBuilderFactory())($this->createContainer($services));
    }

    /**
     * @return list<string>
     */
    private function findFiles(string $dir): array
    {
        $result   = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $result[] = $file->getPathname();
            }
        }

        sort($result);

        return $result;
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function fileNames(array $files): array
    {
        return array_map(basename(...), $files);
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function fileContents(array $files): array
    {
        return array_map(
            static function(string $path): string {
                $content = file_get_contents($path);

                return false === $content ? '' : $content;
            },
            $files,
        );
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
    private function createContainer(array $services): ContainerInterface
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
                return array_key_exists($id, $this->services);
            }
        };
    }
}

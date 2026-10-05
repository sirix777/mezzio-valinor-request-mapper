<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use ArgumentCountError;
use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\Mapper\Configurator\ConvertKeysToCamelCase;
use CuyZ\Valinor\Mapper\Configurator\MapperBuilderConfigurator;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\MapperBuilder;
use DateTimeImmutable;
use DateTimeInterface;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Sirix\ContainerResolver\Exception\InvalidConfigValueException;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Factory\ValinorMapperBuilderFactory;
use Sirix\Mezzio\Valinor\Test\Factory\Fixture\CacheableRequest;
use Sirix\Mezzio\Valinor\Test\Factory\Fixture\ConstructorRequiredConfigurator;
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
use function preg_quote;
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
    #[DataProvider('configuratorModes')]
    public function rejectsConfiguratorServiceWithAnIncorrectType(bool $strict): void
    {
        $this->expectException(InvalidContainerServiceException::class);

        $this->builder([
            'strict_configurators' => $strict,
            'configurators'        => ['MyConfigurator'],
        ], [
            'MyConfigurator' => new stdClass(),
        ]);
    }

    #[Test]
    #[DataProvider('configuratorModes')]
    public function configuratorClassNameIsInstantiatedDirectly(bool $strict): void
    {
        self::assertInstanceOf(MapperBuilder::class, $this->builder([
            'strict_configurators' => $strict,
            'configurators'        => [ConvertKeysToCamelCase::class],
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
    #[DataProvider('unknownConfigurators')]
    public function strictModeRejectsUnknownConfigurator(string $identifier): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessageMatches('/mapper\.configurators\[missing\].*' . preg_quote($identifier, '/') . '/');

        $this->builder([
            'strict_configurators' => true,
            'configurators'        => [
                'missing' => $identifier,
            ],
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownConfigurators(): iterable
    {
        yield 'service alias' => ['missing.configurator'];

        yield 'class' => ['NonExistentConfigurator'];

        yield 'wrong class' => [stdClass::class];
    }

    #[Test]
    #[DataProvider('wrongConfiguratorElements')]
    public function strictModeRejectsWrongElementType(mixed $value, string $type): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessageMatches('/mapper\.configurators\[7\].*' . preg_quote($type, '/') . '/');

        $this->builder([
            'strict_configurators' => true,
            'configurators'        => [
                7 => $value,
            ],
        ]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function wrongConfiguratorElements(): iterable
    {
        yield 'integer' => [42, 'int'];

        yield 'array' => [[], 'array'];

        yield 'object' => [new stdClass(), 'stdClass'];
    }

    #[Test]
    #[DataProvider('unconstructibleConfigurators')]
    public function strictModeRejectsUnconstructibleClass(string $className, string $legacyException): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessageMatches('/mapper\.configurators\[0\].*' . preg_quote($className, '/') . '/');

        $this->builder([
            'strict_configurators' => true,
            'configurators'        => [$className],
        ]);
    }

    /** @return iterable<string, array{class-string<MapperBuilderConfigurator>, class-string<Throwable>}> */
    public static function unconstructibleConfigurators(): iterable
    {
        yield 'abstract' => [AbstractConfigurator::class, Error::class];

        yield 'constructor dependency' => [ConstructorRequiredConfigurator::class, ArgumentCountError::class];

        yield 'private constructor' => [PrivateConstructorConfigurator::class, Error::class];
    }

    /** @param class-string<Throwable> $legacyException */
    #[Test]
    #[DataProvider('unconstructibleConfigurators')]
    public function legacyModePreservesConstructionFailure(string $className, string $legacyException): void
    {
        $this->expectException($legacyException);

        $this->builder([
            'configurators' => [$className],
        ]);
    }

    #[Test]
    public function legacyModeSkipsInvalidConfiguratorElements(): void
    {
        self::assertInstanceOf(MapperBuilder::class, $this->builder([
            'configurators' => [42, [], new stdClass(), stdClass::class, 'missing.configurator'],
        ]));
    }

    #[Test]
    public function constructorDependencyIsResolvedFromContainer(): void
    {
        $mapper = $this->builder([
            'strict_configurators' => true,
            'configurators'        => [ConstructorRequiredConfigurator::class],
        ], [
            ConstructorRequiredConfigurator::class => new ConstructorRequiredConfigurator('d/m/Y'),
        ])->mapper();

        $date = $mapper->map(DateTimeImmutable::class, '05/10/2026');

        self::assertSame('2026-10-05', $date->format('Y-m-d'));
    }

    #[Test]
    #[DataProvider('configuratorModes')]
    public function configuratorFailureIsNotSwallowed(bool $strict): void
    {
        $exception    = new RuntimeException('Configurator failed');
        $configurator = new class($exception) implements MapperBuilderConfigurator {
            public function __construct(private readonly RuntimeException $exception) {}

            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                throw $this->exception;
            }
        };

        try {
            $this->builder([
                'strict_configurators' => $strict,
                'configurators'        => [$configurator],
            ]);
            self::fail('The configurator exception must propagate.');
        } catch (RuntimeException $actual) {
            self::assertSame($exception, $actual);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function configuratorModes(): iterable
    {
        yield 'legacy' => [false];

        yield 'strict' => [true];
    }

    #[Test]
    #[DataProvider('invalidStrictFlags')]
    public function strictModeRejectsNonBooleanFlag(mixed $value): void
    {
        $this->expectException(InvalidConfigValueException::class);

        $this->builder([
            'strict_configurators' => $value,
        ]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidStrictFlags(): iterable
    {
        yield 'string' => ['true'];

        yield 'integer' => [1];
    }

    #[Test]
    public function falseDoesNotUndoConfiguratorCasting(): void
    {
        self::assertSame([
            'value' => '42',
        ], $this->permissiveBuilder()->mapper()->map('array{value: string}', [
            'value' => 42,
        ]));
    }

    #[Test]
    public function falseDoesNotUndoConfiguratorSuperfluousKeys(): void
    {
        self::assertSame([
            'name' => 'test',
        ], $this->permissiveBuilder()->mapper()->map('array{name: string}', [
            'name'  => 'test',
            'extra' => 'ignored',
        ]));
    }

    #[Test]
    public function falseDoesNotUndoConfiguratorPermissiveTypes(): void
    {
        self::assertSame([
            'data' => 42,
        ], $this->permissiveBuilder()->mapper()->map('array{data: mixed}', [
            'data' => 42,
        ]));
    }

    #[Test]
    public function falseDoesNotUndoConfiguratorUndefinedValues(): void
    {
        self::assertSame([
            'name' => 'test',
            'age'  => null,
        ], $this->permissiveBuilder()->mapper()->map('array{name: string, age: int|null}', [
            'name' => 'test',
        ]));
    }

    #[Test]
    public function configuratorsRunInDeclarationOrderBeforeConfiguredDateFormats(): void
    {
        $first = new class implements MapperBuilderConfigurator {
            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                return $builder->supportDateFormats('m.d.Y');
            }
        };
        $second = new class implements MapperBuilderConfigurator {
            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                return $builder->supportDateFormats(...[...$builder->supportedDateFormats(), 'd-m-Y']);
            }
        };
        $builder = $this->builder([
            'configurators'        => [
                'first'  => $first,
                'second' => $second,
            ],
            'support_date_formats' => ['d/m/Y', 'm.d.Y'],
        ]);

        self::assertSame(['m.d.Y', 'd-m-Y', 'd/m/Y'], $builder->supportedDateFormats());

        foreach (['10.05.2026', '05-10-2026', '05/10/2026'] as $input) {
            self::assertSame('2026-10-05', $builder->mapper()->map(DateTimeImmutable::class, $input)->format('Y-m-d'));
        }
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

    private function permissiveBuilder(): MapperBuilder
    {
        $configurator = new class implements MapperBuilderConfigurator {
            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                return $builder
                    ->allowScalarValueCasting()
                    ->allowSuperfluousKeys()
                    ->allowPermissiveTypes()
                    ->allowUndefinedValues()
                ;
            }
        };

        return $this->builder([
            'configurators'              => [$configurator],
            'allow_scalar_value_casting' => false,
            'allow_superfluous_keys'     => false,
            'allow_permissive_types'     => false,
            'allow_undefined_values'     => false,
        ]);
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

            public function get($id): mixed
            {
                return $this->services[$id] ?? throw new RuntimeException("Service not found: {$id}");
            }

            public function has($id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }
}

abstract class AbstractConfigurator implements MapperBuilderConfigurator {}

final readonly class PrivateConstructorConfigurator implements MapperBuilderConfigurator
{
    private function __construct() {}

    public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
    {
        return $builder;
    }
}

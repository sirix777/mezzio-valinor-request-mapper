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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Sirix\ContainerResolver\Exception\InvalidConfigValueException;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
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
    #[DataProvider('unknownRootSections')]
    public function rejectsUnknownRootSection(string $key): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('sirix_mezzio_valinor.' . $key);

        $config = [
            'sirix_mezzio_valinor' => [
                $key => [],
            ],
        ];
        (new ValinorMapperBuilderFactory())($this->createContainer([
            'config' => $config,
        ]));
    }

    /** @return iterable<string, array{string}> */
    public static function unknownRootSections(): iterable
    {
        yield 'typo' => ['input_limt'];

        yield 'unrelated' => ['unrelated'];
    }

    #[Test]
    #[DataProvider('malformedPackageSections')]
    public function malformedPackageSectionKeepsConfigReaderDiagnostics(mixed $section, string $type): void
    {
        $config = [
            'sirix_mezzio_valinor' => $section,
        ];

        try {
            (new ValinorMapperBuilderFactory())($this->createContainer([
                'config' => $config,
            ]));
            self::fail('The package section must be a string-keyed map.');
        } catch (InvalidConfigValueException $caught) {
            self::assertStringContainsString('sirix_mezzio_valinor', $caught->getMessage());
            self::assertStringContainsString(ValinorMapperBuilderFactory::class, $caught->getMessage());
            self::assertStringContainsString('must be ' . ('array' === $type ? 'map<string, mixed>' : 'array'), $caught->getMessage());
            self::assertStringContainsString($type . ' given', $caught->getMessage());
        }
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedPackageSections(): iterable
    {
        yield 'numeric key' => [[
            0 => [],
        ], 'array'];

        yield 'null' => [null, 'null'];

        yield 'scalar' => [42, 'int'];
    }

    #[Test]
    public function acceptsKnownRootSectionsAndUnrelatedApplicationConfiguration(): void
    {
        $config = [
            'unrelated'            => [
                'input_limt' => true,
            ],
            'sirix_mezzio_valinor' => [
                'mapper'         => [],
                'input_limits'   => [],
                'error_response' => [],
            ],
        ];

        $builder = (new ValinorMapperBuilderFactory())($this->createContainer([
            'config' => $config,
        ]));

        self::assertSame([
            'name' => '42',
        ], $builder->mapper()->map('array{name: string}', [
            'name' => 42,
        ]));
    }

    #[Test]
    public function defaultConfigCreatesMapperBuilder(): void
    {
        self::assertInstanceOf(MapperBuilder::class, $this->builder());
    }

    #[Test]
    public function unknownRootSectionIsRejectedBeforeConfigurators(): void
    {
        $configurator = new class implements MapperBuilderConfigurator {
            public bool $applied = false;

            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                $this->applied = true;

                return $builder;
            }
        };

        try {
            (new ValinorMapperBuilderFactory())($this->createContainer([
                'config' => [
                    'sirix_mezzio_valinor' => [
                        'input_limt' => [],
                        'mapper'     => [
                            'configurators' => [$configurator],
                        ],
                    ],
                ],
            ]));
            self::fail('The unknown root section must be rejected first.');
        } catch (InvalidMapRequestConfiguration $caught) {
            self::assertSame('sirix_mezzio_valinor.input_limt: unknown configuration section.', $caught->getMessage());
        }

        self::assertFalse($configurator->applied);
    }

    /** @param array<string, mixed> $config */
    #[Test]
    #[DataProvider('invalidCacheWatchWithoutDirectory')]
    public function rejectsInvalidCacheWatchWithoutDirectory(array $config, string $type): void
    {
        try {
            $this->builder($config);
            self::fail('cache_watch must be a boolean even without a cache directory.');
        } catch (InvalidConfigValueException $caught) {
            self::assertStringContainsString('cache_watch', $caught->getMessage());
            self::assertStringContainsString(ValinorMapperBuilderFactory::class, $caught->getMessage());
            self::assertStringContainsString('must be bool', $caught->getMessage());
            self::assertStringContainsString($type . ' given', $caught->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidCacheWatchWithoutDirectory(): iterable
    {
        foreach ([
            'absent'     => [],
            'null'       => [
                'cache_dir' => null,
            ],
            'empty'      => [
                'cache_dir' => '',
            ],
            'whitespace' => [
                'cache_dir' => '   ',
            ],
        ] as $directory => $config) {
            foreach ([
                'null'    => [null, 'null'],
                'string'  => ['true', 'string'],
                'integer' => [1, 'int'],
                'array'   => [[], 'array'],
            ] as $name => [$value, $type]) {
                yield $directory . ' / ' . $name => [[
                    'cache_watch' => $value,
                ] + $config, $type];
            }
        }
    }

    /** @param array<string, mixed> $config */
    #[Test]
    #[DataProvider('validCacheWatchWithoutDirectory')]
    public function validCacheWatchWithoutDirectoryKeepsCacheDisabled(array $config): void
    {
        $cacheDir    = $this->createTempDir();
        $originalCwd = getcwd();
        self::assertNotFalse($originalCwd);

        try {
            chdir($cacheDir);
            $builder = $this->builder($config);
            $builder->warmupCacheFor(CacheableRequest::class);
            $dto = $builder->mapper()->map(CacheableRequest::class, [
                'name' => 'test',
            ]);

            self::assertSame('test', $dto->name);
            self::assertSame([], $this->findFiles($cacheDir));
        } finally {
            chdir($originalCwd);
            $this->removeDir($cacheDir);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function validCacheWatchWithoutDirectory(): iterable
    {
        foreach ([
            'absent'     => [],
            'null'       => [
                'cache_dir' => null,
            ],
            'empty'      => [
                'cache_dir' => '',
            ],
            'whitespace' => [
                'cache_dir' => '   ',
            ],
        ] as $directory => $config) {
            foreach ([
                'absent' => [],
                'false'  => [
                    'cache_watch' => false,
                ],
                'true'   => [
                    'cache_watch' => true,
                ],
            ] as $watch => $option) {
                yield $directory . ' / ' . $watch => [$config + $option];
            }
        }
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
    #[DataProvider('invalidConfigurators')]
    public function invalidConfiguratorsAreAlwaysRejected(mixed $value): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('mapper.configurators[0]');

        $this->builder([
            'configurators' => [$value],
        ]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidConfigurators(): iterable
    {
        yield 'typo' => ['typo'];

        yield 'integer' => [123];

        yield 'null' => [null];

        yield 'abstract' => [AbstractConfigurator::class];

        yield 'private constructor' => [PrivateConstructorConfigurator::class];

        yield 'constructor dependency' => [ConstructorRequiredConfigurator::class];
    }

    #[Test]
    #[DataProvider('unknownConfigurators')]
    public function rejectsUnknownConfiguratorAtNamedIndex(string $identifier): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('mapper.configurators[named]');
        $this->expectExceptionMessageMatches('/mapper\.configurators\[named\].*' . preg_quote($identifier, '/') . '/');

        $this->builder([
            'configurators' => [
                'named' => $identifier,
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
    public function rejectsWrongConfiguratorElementType(mixed $value, string $type): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessageMatches('/mapper\.configurators\[7\].*' . preg_quote($type, '/') . '/');

        $this->builder([
            'configurators' => [
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
    public function constructorDependencyIsResolvedFromContainer(): void
    {
        $mapper = $this->builder([
            'configurators' => [ConstructorRequiredConfigurator::class],
        ], [
            ConstructorRequiredConfigurator::class => new ConstructorRequiredConfigurator('d/m/Y'),
        ])->mapper();

        $date = $mapper->map(DateTimeImmutable::class, '05/10/2026');

        self::assertSame('2026-10-05', $date->format('Y-m-d'));
    }

    #[Test]
    public function configuratorFailureIsNotSwallowed(): void
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
                'configurators' => [$configurator],
            ]);
            self::fail('The configurator exception must propagate.');
        } catch (RuntimeException $actual) {
            self::assertSame($exception, $actual);
        }
    }

    #[Test]
    #[DataProvider('removedStrictFlags')]
    public function removedStrictConfiguratorKeyIsRejected(?bool $value): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('sirix_mezzio_valinor.mapper.strict_configurators');

        $this->builder([
            'strict_configurators' => $value,
        ]);
    }

    /** @return iterable<string, array{null|bool}> */
    public static function removedStrictFlags(): iterable
    {
        yield 'true' => [true];

        yield 'false' => [false];

        yield 'null' => [null];
    }

    #[Test]
    #[DataProvider('unknownMapperKeys')]
    public function unknownMapperKeysAreRejected(string $key): void
    {
        $this->expectException(InvalidMapRequestConfiguration::class);
        $this->expectExceptionMessage('sirix_mezzio_valinor.mapper.' . $key);

        $this->builder([
            $key => true,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownMapperKeys(): iterable
    {
        yield 'typo' => ['allow_scaler_value_casting'];

        yield 'unknown' => ['unknown'];
    }

    #[Test]
    public function numericMapperKeysKeepConfigReaderTypeRejection(): void
    {
        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage('map<string, mixed>');

        $container = $this->createContainer([
            'config' => [
                'sirix_mezzio_valinor' => [
                    'mapper' => [
                        17 => false,
                    ],
                ],
            ],
        ]);

        (new ValinorMapperBuilderFactory())($container);
    }

    #[Test]
    public function unknownKeyIsRejectedBeforeCacheHandlingAndConfigurators(): void
    {
        $applied      = 0;
        $configurator = new class($applied) implements MapperBuilderConfigurator {
            public function __construct(private int &$applied) {}

            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                ++$this->applied;

                return $builder;
            }
        };

        try {
            $this->builder([
                'allow_scaler_value_casting' => true,
                'cache_dir'                  => 123,
                'configurators'              => [$configurator],
            ]);
            self::fail('The unknown mapper key must be rejected first.');
        } catch (InvalidMapRequestConfiguration $caught) {
            self::assertSame(
                'sirix_mezzio_valinor.mapper.allow_scaler_value_casting: unknown mapper option.',
                $caught->getMessage(),
            );
        }

        self::assertSame(0, $applied);
    }

    /** @param array<string, mixed> $services */
    #[Test]
    #[DataProvider('absentMapperConfigurations')]
    public function absentMapperConfigurationCreatesBuilder(array $services): void
    {
        self::assertInstanceOf(MapperBuilder::class, (new ValinorMapperBuilderFactory())($this->createContainer($services)));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function absentMapperConfigurations(): iterable
    {
        yield 'no config service' => [[]];

        yield 'no package section' => [[
            'config' => [
                'unrelated' => [
                    'strict_configurators' => false,
                ],
            ],
        ]];

        yield 'no mapper section' => [[
            'config' => [
                'sirix_mezzio_valinor' => [
                    'input_limits' => [],
                ],
            ],
        ]];

        yield 'empty mapper' => [[
            'config' => [
                'sirix_mezzio_valinor' => [
                    'mapper' => [],
                ],
            ],
        ]];
    }

    #[Test]
    public function allSupportedMapperKeysAreAccepted(): void
    {
        self::assertInstanceOf(MapperBuilder::class, $this->builder([
            'configurators'              => [],
            'allow_superfluous_keys'     => false,
            'allow_scalar_value_casting' => false,
            'allow_permissive_types'     => false,
            'allow_undefined_values'     => false,
            'support_date_formats'       => [],
            'cache_dir'                  => null,
            'cache_watch'                => false,
        ]));
    }

    #[Test]
    public function optionalConstructorArgumentsAllowDirectConstruction(): void
    {
        self::assertInstanceOf(MapperBuilder::class, $this->builder([
            'configurators' => [OptionalConstructorConfigurator::class],
        ]));
    }

    #[Test]
    public function constructorFailureIsNotSwallowed(): void
    {
        $original                                   = new RuntimeException('Constructor failed');
        ThrowingConstructorConfigurator::$exception = $original;

        try {
            $this->builder([
                'configurators' => [ThrowingConstructorConfigurator::class],
            ]);
            self::fail('The constructor exception must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($original, $caught);
        } finally {
            ThrowingConstructorConfigurator::$exception = null;
        }
    }

    #[Test]
    public function containerFailureIsNotSwallowed(): void
    {
        $original  = new RuntimeException('Container failed');
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(static function(string $id) use ($original): array {
            if ('config' === $id) {
                return [
                    'sirix_mezzio_valinor' => [
                        'mapper' => [
                            'configurators' => [ConvertKeysToCamelCase::class],
                        ],
                    ],
                ];
            }

            throw $original;
        });

        try {
            (new ValinorMapperBuilderFactory())($container);
            self::fail('The container exception must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($original, $caught);
        }
    }

    #[Test]
    public function containerNotFoundFailureKeepsItsOriginalCause(): void
    {
        $original  = new class('Container lost the service') extends RuntimeException implements NotFoundExceptionInterface {};
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(static function(string $id) use ($original): array {
            if ('config' === $id) {
                return [
                    'sirix_mezzio_valinor' => [
                        'mapper' => [
                            'configurators' => [ConvertKeysToCamelCase::class],
                        ],
                    ],
                ];
            }

            throw $original;
        });

        try {
            (new ValinorMapperBuilderFactory())($container);
            self::fail('A disappearing container service must not fall back to direct construction.');
        } catch (MissingContainerServiceException $caught) {
            self::assertSame($original, $caught->getPrevious());
        }
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

final readonly class OptionalConstructorConfigurator implements MapperBuilderConfigurator
{
    /** @param non-empty-string $format */
    public function __construct(private string $format = 'Y-m-d') {}

    public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
    {
        return $builder->supportDateFormats($this->format);
    }
}

final class ThrowingConstructorConfigurator implements MapperBuilderConfigurator
{
    public static ?RuntimeException $exception = null;

    public function __construct()
    {
        throw self::$exception ?? new RuntimeException('Constructor failed');
    }

    public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
    {
        return $builder;
    }
}

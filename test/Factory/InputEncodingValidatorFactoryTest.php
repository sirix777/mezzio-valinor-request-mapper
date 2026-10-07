<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sirix\ContainerResolver\Exception\InvalidConfigValueException;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Factory\InputEncodingValidatorFactory;

use function range;

final class InputEncodingValidatorFactoryTest extends TestCase
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
        (new InputEncodingValidatorFactory())($this->container($config));
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
            (new InputEncodingValidatorFactory())($this->container($config));
            self::fail('The package section must be a string-keyed map.');
        } catch (InvalidConfigValueException $caught) {
            self::assertStringContainsString('sirix_mezzio_valinor', $caught->getMessage());
            self::assertStringContainsString(InputEncodingValidatorFactory::class, $caught->getMessage());
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

        $validator = (new InputEncodingValidatorFactory())($this->container($config));

        self::expectNotToPerformAssertions();
        $validator->assertValid([
            'name' => 'Ada',
        ], 'body');
    }

    #[Test]
    public function factoryWithoutConfigKeepsDefaults(): void
    {
        $validator = (new InputEncodingValidatorFactory())($this->container());

        $values = [
            'name' => 'Ada',
        ];
        $values['self'] = &$values;

        self::expectNotToPerformAssertions();
        $validator->assertValid($values, 'body');
    }

    #[Test]
    public function factoryReadsInputLimits(): void
    {
        $validator = (new InputEncodingValidatorFactory())($this->container([
            'sirix_mezzio_valinor' => [
                'input_limits' => [
                    'max_nodes' => 7,
                ],
            ],
        ]));

        $validator->assertValid(range(1, 7), 'query');

        try {
            $validator->assertValid(range(1, 8), 'query');
            self::fail('Expected the configured node limit to reject the input.');
        } catch (RequestInputError $error) {
            self::assertSame('input_node_limit_exceeded', $error->reason);
            self::assertSame('query', $error->inputSource);
        }
    }

    #[Test]
    #[DataProvider('malformedInputLimits')]
    public function rejectsMalformedInputLimitsSection(mixed $section): void
    {
        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage('input_limits');

        (new InputEncodingValidatorFactory())($this->container([
            'sirix_mezzio_valinor' => [
                'input_limits' => $section,
            ],
        ]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedInputLimits(): iterable
    {
        yield 'scalar' => [42];

        yield 'boolean' => [false];

        yield 'list' => [['max_nodes']];
    }

    /**
     * @param null|array<string, mixed> $config
     */
    private function container(?array $config = null): ContainerInterface
    {
        $notFound = new class extends Exception implements NotFoundExceptionInterface {};

        return new class($config, $notFound) implements ContainerInterface {
            /**
             * @param null|array<string, mixed>            $config
             * @param Exception&NotFoundExceptionInterface $notFound
             */
            public function __construct(private readonly ?array $config, private readonly Exception $notFound) {}

            public function get($id): mixed
            {
                if ('config' === $id && null !== $this->config) {
                    return $this->config;
                }

                throw new $this->notFound("Service not found: {$id}");
            }

            public function has($id): bool
            {
                return 'config' === $id && null !== $this->config;
            }
        };
    }
}

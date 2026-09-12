<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use CuyZ\Valinor\Mapper\Configurator\MapperBuilderConfigurator;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\Mezzio\Valinor\Factory\ValinorTreeMapperFactory;
use Sirix\Mezzio\Valinor\Test\Factory\Fixture\TreeMapperMixedRequest;
use stdClass;

use function array_key_exists;

final class ValinorTreeMapperFactoryTest extends TestCase
{
    #[Test]
    public function defaultConfigCreatesMapper(): void
    {
        self::assertInstanceOf(TreeMapper::class, $this->mapper([
            MapperBuilder::class => (new MapperBuilder())->allowSuperfluousKeys(),
        ]));
    }

    #[Test]
    public function usesCustomMapperBuilderFromContainer(): void
    {
        $configurator = new class implements MapperBuilderConfigurator {
            public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
            {
                return $builder->allowPermissiveTypes();
            }
        };

        $builder = (new MapperBuilder())
            ->allowSuperfluousKeys()
            ->configureWith($configurator)
        ;

        $mapper = $this->mapper([
            MapperBuilder::class => $builder,
        ]);

        $dto = $mapper->map(TreeMapperMixedRequest::class, [
            'value' => 42,
        ]);

        self::assertInstanceOf(TreeMapperMixedRequest::class, $dto);
        self::assertSame(42, $dto->value);
    }

    #[Test]
    public function throwsWhenMapperBuilderServiceIsMissing(): void
    {
        $this->expectException(MissingContainerServiceException::class);

        $this->mapper([]);
    }

    #[Test]
    public function throwsWhenMapperBuilderServiceHasIncorrectType(): void
    {
        $this->expectException(InvalidContainerServiceException::class);

        $this->mapper([
            MapperBuilder::class => new stdClass(),
        ]);
    }

    /**
     * @param array<string, mixed> $services
     */
    private function mapper(array $services = []): TreeMapper
    {
        $services['config'] = [
            'sirix_mezzio_valinor' => [
                'mapper' => [],
            ],
        ];

        return (new ValinorTreeMapperFactory())($this->createContainer($services));
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

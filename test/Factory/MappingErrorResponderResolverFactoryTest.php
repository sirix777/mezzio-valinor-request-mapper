<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Factory\MappingErrorResponderResolverFactory;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\ProblemDetailsResponder;
use stdClass;

use function array_key_exists;

final class MappingErrorResponderResolverFactoryTest extends TestCase
{
    #[Test]
    public function resolvesTheDefaultResponder(): void
    {
        $defaultResponder = new ProblemDetailsResponder();
        $resolver         = (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => $defaultResponder,
        ]));

        self::assertSame($defaultResponder, $resolver->resolve(null));
    }

    #[Test]
    public function usesTheRegisteredBuiltInResponderWhenTheGlobalAliasIsNotRegistered(): void
    {
        $defaultResponder = new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory());
        $resolver         = (new MappingErrorResponderResolverFactory())($this->container([
            DefaultMappingErrorResponder::class => $defaultResponder,
        ]));

        self::assertSame($defaultResponder, $resolver->resolve(null));
    }

    #[Test]
    public function resolvesAnAttributeResponderFromTheContainer(): void
    {
        $defaultResponder   = new ProblemDetailsResponder();
        $attributeResponder = new ProblemDetailsResponder();
        $resolver           = (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => $defaultResponder,
            ProblemDetailsResponder::class        => $attributeResponder,
        ]));

        self::assertSame($attributeResponder, $resolver->resolve(ProblemDetailsResponder::class));
    }

    #[Test]
    public function rejectsAnIncorrectlyTypedDefaultResponder(): void
    {
        $this->expectException(InvalidContainerServiceException::class);

        (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => new stdClass(),
        ]));
    }

    /**
     * @param array<string, mixed> $services
     */
    private function container(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            /**
             * @param array<string, mixed> $services
             */
            public function __construct(private readonly array $services) {}

            public function get($id): mixed
            {
                if (! array_key_exists($id, $this->services)) {
                    throw new RuntimeException("Service not found: {$id}");
                }

                return $this->services[$id];
            }

            public function has($id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }
}

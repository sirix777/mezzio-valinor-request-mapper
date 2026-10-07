<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Sirix\ContainerResolver\Exception\InvalidContainerServiceException;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Factory\MappingErrorResponderResolverFactory;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\ProblemDetailsResponder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\UnregisteredResponder;
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

    /** @param non-empty-string $serviceId */
    #[Test]
    #[DataProvider('explicitResponderIds')]
    public function resolvesAnAttributeResponderFromTheContainer(string $serviceId): void
    {
        $defaultResponder   = new ProblemDetailsResponder();
        $attributeResponder = new ProblemDetailsResponder();
        $resolver           = (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => $defaultResponder,
            $serviceId                            => $attributeResponder,
        ]));

        self::assertSame($attributeResponder, $resolver->resolve($serviceId));
    }

    #[Test]
    public function rejectsAnIncorrectlyTypedDefaultResponder(): void
    {
        $this->expectException(InvalidContainerServiceException::class);

        (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => new stdClass(),
        ]));
    }

    /** @param non-empty-string $serviceId */
    #[Test]
    #[DataProvider('explicitResponderIds')]
    public function missingExplicitResponderIsRejected(string $serviceId): void
    {
        $resolver = (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => new ProblemDetailsResponder(),
        ]));

        try {
            $resolver->resolve($serviceId);
            self::fail('A missing explicit responder must be rejected.');
        } catch (MissingContainerServiceException $caught) {
            self::assertStringContainsString($serviceId, $caught->getMessage());
            self::assertStringContainsString(MappingErrorResponderResolverFactory::class, $caught->getMessage());
            self::assertNull($caught->getPrevious());
        }
    }

    #[Test]
    public function rejectsUnregisteredResponderClass(): void
    {
        $resolver = (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => new ProblemDetailsResponder(),
        ]));

        $this->expectException(MissingContainerServiceException::class);
        $this->expectExceptionMessage(UnregisteredResponder::class);

        $resolver->resolve(UnregisteredResponder::class);
    }

    /** @param non-empty-string $serviceId */
    #[Test]
    #[DataProvider('explicitResponderIds')]
    public function incorrectlyTypedExplicitResponderIsRejected(string $serviceId): void
    {
        $resolver = (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => new ProblemDetailsResponder(),
            $serviceId                            => new stdClass(),
        ]));

        try {
            $resolver->resolve($serviceId);
            self::fail('An incorrectly typed explicit responder must be rejected.');
        } catch (InvalidContainerServiceException $caught) {
            self::assertStringContainsString($serviceId, $caught->getMessage());
            self::assertStringContainsString(MappingErrorResponderResolverFactory::class, $caught->getMessage());
            self::assertStringContainsString(MappingErrorResponderInterface::class, $caught->getMessage());
            self::assertStringContainsString(stdClass::class, $caught->getMessage());
            self::assertNull($caught->getPrevious());
        }
    }

    /** @param non-empty-string $serviceId */
    #[Test]
    #[DataProvider('explicitResponderIds')]
    public function explicitResponderFactoryFailureIsNotSwallowed(string $serviceId): void
    {
        $thrownByServiceFactory = new RuntimeException('Responder factory failure.');
        $resolver               = (new MappingErrorResponderResolverFactory())($this->failingResponderContainer($thrownByServiceFactory));

        try {
            $resolver->resolve($serviceId);
            self::fail('The responder factory failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($thrownByServiceFactory, $caught);
        }
    }

    /** @param non-empty-string $serviceId */
    #[Test]
    #[DataProvider('explicitResponderIds')]
    public function explicitResponderNotFoundFailurePreservesItsCauseAndFactoryContext(string $serviceId): void
    {
        $original = new class('Responder dependency not found.') extends RuntimeException implements NotFoundExceptionInterface {};
        $resolver = (new MappingErrorResponderResolverFactory())($this->failingResponderContainer($original));

        try {
            $resolver->resolve($serviceId);
            self::fail('The responder not-found failure must propagate.');
        } catch (MissingContainerServiceException $caught) {
            self::assertSame($original, $caught->getPrevious());
            self::assertStringContainsString($serviceId, $caught->getMessage());
            self::assertStringContainsString(MappingErrorResponderResolverFactory::class, $caught->getMessage());
        }
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function explicitResponderIds(): iterable
    {
        yield 'FQCN' => [ProblemDetailsResponder::class];

        yield 'named service' => ['problem.details'];
    }

    private function failingResponderContainer(RuntimeException $exception): ContainerInterface
    {
        $defaultResponder = new ProblemDetailsResponder();
        $container        = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static fn (string $id): MappingErrorResponderInterface => MappingErrorResponderInterface::class === $id
                ? $defaultResponder
                : throw $exception,
        );

        return $container;
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

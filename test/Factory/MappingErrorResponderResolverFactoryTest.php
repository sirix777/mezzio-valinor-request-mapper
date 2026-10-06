<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
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

    #[Test]
    public function missingExplicitResponderIsRejected(): void
    {
        $resolver = (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => new ProblemDetailsResponder(),
        ]));

        $this->expectException(MissingContainerServiceException::class);
        $this->expectExceptionMessage(ProblemDetailsResponder::class);

        $resolver->resolve(ProblemDetailsResponder::class);
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

    #[Test]
    public function incorrectlyTypedExplicitResponderIsRejected(): void
    {
        $resolver = (new MappingErrorResponderResolverFactory())($this->container([
            MappingErrorResponderInterface::class => new ProblemDetailsResponder(),
            ProblemDetailsResponder::class        => new stdClass(),
        ]));

        $this->expectException(InvalidContainerServiceException::class);
        $this->expectExceptionMessage(ProblemDetailsResponder::class);

        $resolver->resolve(ProblemDetailsResponder::class);
    }

    #[Test]
    public function explicitResponderFactoryFailureIsNotSwallowed(): void
    {
        $thrownByServiceFactory = new RuntimeException('Responder factory failure.');
        $resolver               = (new MappingErrorResponderResolverFactory())($this->failingResponderContainer($thrownByServiceFactory));

        try {
            $resolver->resolve(ProblemDetailsResponder::class);
            self::fail('The responder factory failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($thrownByServiceFactory, $caught);
        }
    }

    #[Test]
    public function explicitResponderNotFoundFailurePreservesItsCauseAndFactoryContext(): void
    {
        $original = new class('Responder dependency not found.') extends RuntimeException implements NotFoundExceptionInterface {};
        $resolver = (new MappingErrorResponderResolverFactory())($this->failingResponderContainer($original));

        try {
            $resolver->resolve(ProblemDetailsResponder::class);
            self::fail('The responder not-found failure must propagate.');
        } catch (MissingContainerServiceException $caught) {
            self::assertSame($original, $caught->getPrevious());
            self::assertStringContainsString(ProblemDetailsResponder::class, $caught->getMessage());
            self::assertStringContainsString(MappingErrorResponderResolverFactory::class, $caught->getMessage());
        }
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

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Exception;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sirix\ContainerResolver\Exception\MissingContainerServiceException;
use Sirix\Mezzio\Valinor\ConfigProvider;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Factory\DefaultMappingErrorResponderFactory;
use Sirix\Mezzio\Valinor\Factory\HttpRequestSourceFactoryFactory;
use Sirix\Mezzio\Valinor\Factory\MappingErrorResponderResolverFactory;
use Sirix\Mezzio\Valinor\Factory\MappingPlanResolverFactory;
use Sirix\Mezzio\Valinor\Factory\MapRequestResolverFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorMapperBuilderFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorRequestMapperMiddlewareFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorTreeMapperFactory;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

use function array_key_exists;
use function in_array;
use function is_callable;
use function is_object;

final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function registersExpectedFactories(): void
    {
        $config = (new ConfigProvider())();

        self::assertSame(
            ValinorMapperBuilderFactory::class,
            $config['dependencies']['factories'][MapperBuilder::class] ?? null,
        );
        self::assertSame(
            ValinorTreeMapperFactory::class,
            $config['dependencies']['factories'][TreeMapper::class] ?? null,
        );
        self::assertSame(
            MappingPlanResolverFactory::class,
            $config['dependencies']['factories'][MappingPlanResolver::class] ?? null,
        );
        self::assertSame(
            MapRequestResolverFactory::class,
            $config['dependencies']['factories'][MapRequestResolver::class] ?? null,
        );
        self::assertSame(
            HttpRequestSourceFactoryFactory::class,
            $config['dependencies']['factories'][HttpRequestSourceFactory::class] ?? null,
        );
        self::assertSame(
            ValinorRequestMapperMiddlewareFactory::class,
            $config['dependencies']['factories'][ValinorRequestMapperMiddleware::class] ?? null,
        );
        self::assertSame(
            DefaultMappingErrorResponderFactory::class,
            $config['dependencies']['factories'][DefaultMappingErrorResponder::class] ?? null,
        );
        self::assertSame(
            DefaultMappingErrorResponder::class,
            $config['dependencies']['aliases'][MappingErrorResponderInterface::class] ?? null,
        );
        self::assertSame(
            MappingErrorResponderResolverFactory::class,
            $config['dependencies']['factories'][MappingErrorResponderResolver::class] ?? null,
        );
    }

    #[Test]
    public function registersExpectedInvokables(): void
    {
        $config = (new ConfigProvider())();

        self::assertSame(
            HandlerTargetResolver::class,
            $config['dependencies']['invokables'][HandlerTargetResolver::class] ?? null,
        );
        self::assertSame(
            InputEncodingValidator::class,
            $config['dependencies']['invokables'][InputEncodingValidator::class] ?? null,
        );
        self::assertSame(
            MapRequestOptionsParser::class,
            $config['dependencies']['invokables'][MapRequestOptionsParser::class] ?? null,
        );
        self::assertSame(
            HttpMethodNormalizer::class,
            $config['dependencies']['invokables'][HttpMethodNormalizer::class] ?? null,
        );
    }

    #[Test]
    public function buildsFullServiceGraphFromRegisteredConfiguration(): void
    {
        $container = $this->containerWithConfigProvider();

        $middleware = $container->get(ValinorRequestMapperMiddleware::class);
        self::assertInstanceOf(ValinorRequestMapperMiddleware::class, $middleware);

        $builder = $container->get(MapperBuilder::class);
        self::assertInstanceOf(MapperBuilder::class, $builder);

        $treeMapper = $container->get(TreeMapper::class);
        self::assertInstanceOf(TreeMapper::class, $treeMapper);

        $responderResolver = $container->get(MappingErrorResponderResolver::class);
        self::assertInstanceOf(MappingErrorResponderResolver::class, $responderResolver);

        $planResolver = $container->get(MappingPlanResolver::class);
        self::assertInstanceOf(MappingPlanResolver::class, $planResolver);

        $requestResolver = $container->get(MapRequestResolver::class);
        self::assertInstanceOf(MapRequestResolver::class, $requestResolver);

        $sourceFactory = $container->get(HttpRequestSourceFactory::class);
        self::assertInstanceOf(HttpRequestSourceFactory::class, $sourceFactory);

        $defaultResponder = $container->get(DefaultMappingErrorResponder::class);
        self::assertInstanceOf(DefaultMappingErrorResponder::class, $defaultResponder);

        self::assertSame($builder, $container->get(MapperBuilder::class));
        self::assertSame($treeMapper, $container->get(TreeMapper::class));
    }

    #[Test]
    public function containerUsesLazyLifecycleAndCachesServices(): void
    {
        $container = $this->containerWithConfigProvider();

        self::assertTrue($container->has(MapRequestOptionsParser::class));
        self::assertSame([], $container->factoryCalls());

        $first = $container->get(MapRequestOptionsParser::class);
        self::assertSame([
            MapRequestOptionsParser::class => 1,
        ], $container->factoryCalls());

        $second = $container->get(MapRequestOptionsParser::class);
        self::assertSame($first, $second);
        self::assertSame([
            MapRequestOptionsParser::class => 1,
        ], $container->factoryCalls());
    }

    #[Test]
    public function missingServiceThrowsNotFoundExceptionInterface(): void
    {
        $container = $this->containerWithConfigProvider();

        $this->expectException(NotFoundExceptionInterface::class);

        $container->get('App\Unknown\Service');
    }

    #[Test]
    public function containerResolverWrapsMissingServiceAsMissingContainerService(): void
    {
        $container = $this->containerWithConfigProvider([
            ResponseFactoryInterface::class,
        ]);

        $this->expectException(MissingContainerServiceException::class);

        $container->get(ValinorRequestMapperMiddleware::class);
    }

    /**
     * @param list<class-string> $excludeServices
     */
    private function containerWithConfigProvider(array $excludeServices = []): FixtureContainer
    {
        $config    = (new ConfigProvider())();
        $notFound  = new class extends Exception implements NotFoundExceptionInterface {};

        return new class($config, $notFound, $excludeServices) implements FixtureContainer {
            /** @var array<string, class-string> */
            private readonly array $factories;

            /** @var array<string, string> */
            private readonly array $aliases;

            /** @var array<string, class-string> */
            private readonly array $invokables;

            /** @var array<string, int> */
            private array $factoryCalls = [];

            /** @var array<string, array<string, mixed>|object> */
            private array $services = [];

            /** @var class-string<Exception&NotFoundExceptionInterface> */
            private readonly string $notFoundClass;

            /**
             * @param array<string, mixed> $config
             * @param list<class-string>   $excludeServices
             */
            public function __construct(array $config, Exception&NotFoundExceptionInterface $notFound, array $excludeServices = [])
            {
                $dependencies             = $config['dependencies'] ?? [];
                $this->factories          = $dependencies['factories'] ?? [];
                $this->aliases            = $dependencies['aliases'] ?? [];
                $this->invokables         = $dependencies['invokables'] ?? [];
                $this->notFoundClass      = $notFound::class;
                $this->services['config'] = [
                    'sirix_mezzio_valinor' => [],
                ];

                if (! in_array(ResponseFactoryInterface::class, $excludeServices, true)) {
                    $this->services[ResponseFactoryInterface::class] = new ResponseFactory();
                }

                if (! in_array(StreamFactoryInterface::class, $excludeServices, true)) {
                    $this->services[StreamFactoryInterface::class] = new StreamFactory();
                }
            }

            public function get(string $id): mixed
            {
                if (array_key_exists($id, $this->services)) {
                    return $this->services[$id];
                }

                $resolvedId = $this->aliases[$id] ?? $id;

                if (array_key_exists($resolvedId, $this->services)) {
                    return $this->services[$resolvedId];
                }

                if (array_key_exists($resolvedId, $this->factories)) {
                    return $this->createService($resolvedId, $this->factories[$resolvedId]);
                }

                if (array_key_exists($resolvedId, $this->invokables)) {
                    return $this->createService($resolvedId, $this->invokables[$resolvedId]);
                }

                throw new $this->notFoundClass("Service not found: {$id}");
            }

            public function has(string $id): bool
            {
                $resolvedId = $this->aliases[$id] ?? $id;

                return array_key_exists($id, $this->services)
                    || array_key_exists($resolvedId, $this->services)
                    || array_key_exists($resolvedId, $this->factories)
                    || array_key_exists($resolvedId, $this->invokables);
            }

            /** @return array<string, int> */
            public function factoryCalls(): array
            {
                return $this->factoryCalls;
            }

            /**
             * @param class-string $factoryClass
             */
            private function createService(string $resolvedId, string $factoryClass): object
            {
                $this->factoryCalls[$resolvedId] = ($this->factoryCalls[$resolvedId] ?? 0) + 1;

                if (array_key_exists($resolvedId, $this->factories)) {
                    $factory = new $factoryClass();

                    if (! is_callable($factory)) {
                        throw new $this->notFoundClass("Factory for {$resolvedId} is not callable.");
                    }

                    $service = $factory($this);

                    if (! is_object($service)) {
                        throw new $this->notFoundClass("Factory for {$resolvedId} did not return an object.");
                    }
                } else {
                    $service = new $factoryClass();
                }

                $this->services[$resolvedId] = $service;

                return $service;
            }
        };
    }
}

interface FixtureContainer extends ContainerInterface
{
    /** @return array<string, int> */
    public function factoryCalls(): array;
}

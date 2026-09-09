<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}

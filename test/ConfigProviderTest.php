<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test;

use CuyZ\Valinor\Mapper\TreeMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Valinor\ConfigProvider;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Factory\DefaultMappingErrorResponderFactory;
use Sirix\Mezzio\Valinor\Factory\MappingErrorResponderResolverFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorRequestMapperMiddlewareFactory;
use Sirix\Mezzio\Valinor\Factory\ValinorTreeMapperFactory;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function registersExpectedFactories(): void
    {
        $config = (new ConfigProvider())();

        self::assertSame(
            ValinorTreeMapperFactory::class,
            $config['dependencies']['factories'][TreeMapper::class] ?? null,
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
}

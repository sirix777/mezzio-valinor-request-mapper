<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use CuyZ\Valinor\Mapper\TreeMapper;
use Psr\Container\ContainerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

/** @internal */
final class RequestMapperMiddlewareBuilder
{
    public static function build(
        TreeMapper $mapper,
        MappingErrorResponderInterface $responder,
        ContainerInterface $container,
        string $contextClass,
        ?HttpRequestSourceFactory $sourceFactory = null,
    ): ValinorRequestMapperMiddleware {
        return new ValinorRequestMapperMiddleware(
            $mapper,
            new MappingErrorResponderResolver(
                $responder,
                ContainerResolver::forContext($container, $contextClass),
            ),
            new MappingPlanResolver(
                new MapRequestResolver(
                    new HandlerTargetResolver(),
                    new MapRequestOptionsParser(),
                ),
                new HttpMethodNormalizer(),
            ),
            $sourceFactory ?? new HttpRequestSourceFactory(new InputEncodingValidator()),
        );
    }
}

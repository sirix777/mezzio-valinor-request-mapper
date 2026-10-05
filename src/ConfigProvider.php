<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;

final class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                'factories'  => [
                    MapperBuilder::class                              => Factory\ValinorMapperBuilderFactory::class,
                    TreeMapper::class                                 => Factory\ValinorTreeMapperFactory::class,
                    MappingPlanResolver::class                        => Factory\MappingPlanResolverFactory::class,
                    MapRequestResolver::class                         => Factory\MapRequestResolverFactory::class,
                    HttpRequestSourceFactory::class                   => Factory\HttpRequestSourceFactoryFactory::class,
                    DefaultMappingErrorResponder::class               => Factory\DefaultMappingErrorResponderFactory::class,
                    Error\MappingErrorResponderResolver::class        => Factory\MappingErrorResponderResolverFactory::class,
                    Middleware\ValinorRequestMapperMiddleware::class  => Factory\ValinorRequestMapperMiddlewareFactory::class,
                    InputEncodingValidator::class                     => Factory\InputEncodingValidatorFactory::class,
                ],
                'aliases'    => [
                    MappingErrorResponderInterface::class => DefaultMappingErrorResponder::class,
                ],
                'invokables' => [
                    HandlerTargetResolver::class   => HandlerTargetResolver::class,
                    MapRequestOptionsParser::class => MapRequestOptionsParser::class,
                    HttpMethodNormalizer::class    => HttpMethodNormalizer::class,
                ],
            ],
        ];
    }
}

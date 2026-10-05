<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory\Fixture;

use CuyZ\Valinor\Mapper\Configurator\MapperBuilderConfigurator;
use CuyZ\Valinor\MapperBuilder;

final readonly class ConstructorRequiredConfigurator implements MapperBuilderConfigurator
{
    /** @param non-empty-string $dateFormat */
    public function __construct(private string $dateFormat) {}

    public function configureMapperBuilder(MapperBuilder $builder): MapperBuilder
    {
        return $builder->supportDateFormats($this->dateFormat);
    }
}

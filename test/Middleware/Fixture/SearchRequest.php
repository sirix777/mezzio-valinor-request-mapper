<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Middleware\Fixture;

use CuyZ\Valinor\Mapper\Http\FromBody;
use CuyZ\Valinor\Mapper\Http\FromQuery;
use CuyZ\Valinor\Mapper\Http\FromRoute;

final readonly class SearchRequest
{
    /**
     * @param null|array<string, mixed> $filters
     */
    public function __construct(
        #[FromQuery] public string $q,
        #[FromRoute] public string $locale,
        #[FromBody] public ?array $filters = null,
    ) {}
}

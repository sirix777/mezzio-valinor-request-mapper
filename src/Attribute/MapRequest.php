<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Attribute;

use Attribute;
use InvalidArgumentException;
use Sirix\Mezzio\Routing\Contracts\RouteAttributeModifierInterface;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function is_string;
use function strtoupper;
use function trim;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class MapRequest implements RouteAttributeModifierInterface
{
    /**
     * @var list<string>
     */
    public array $methods;

    /**
     * @param null|class-string $body    Map from parsed body to this DTO
     * @param null|class-string $query   Map from query params to this DTO
     * @param null|class-string $route   Map from route params to this DTO
     * @param null|class-string $source  Map from all three sources combined to this DTO
     * @param null|string       $output  Attribute key in $request (default: DTO FQCN)
     * @param mixed[]           $methods HTTP method filter. Empty = any method.
     */
    public function __construct(
        public ?string $body = null,
        public ?string $query = null,
        public ?string $route = null,
        public ?string $source = null,
        public ?string $output = null,
        array $methods = [],
    ) {
        if (null !== $source && (null !== $body || null !== $query || null !== $route)) {
            throw new InvalidArgumentException(
                'MapRequest: $source is mutually exclusive with $body/$query/$route.',
            );
        }

        $this->methods = array_values(array_unique(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            array_filter($methods, static fn (mixed $method): bool => is_string($method) && '' !== $method),
        )));
    }

    public function getMiddleware(): array
    {
        return [ValinorRequestMapperMiddleware::class];
    }

    public function getDefaults(): array
    {
        return [
            'valinor_mappings' => [
                [
                    'body' => $this->body,
                    'query' => $this->query,
                    'route' => $this->route,
                    'source' => $this->source,
                    'output' => $this->output,
                    'methods' => $this->methods,
                ],
            ],
        ];
    }
}

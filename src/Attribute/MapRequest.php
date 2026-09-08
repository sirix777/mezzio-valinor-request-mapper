<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Attribute;

use Attribute;
use Sirix\Mezzio\Routing\Contracts\RouteAttributeModifierInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

use function sprintf;
use function trim;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class MapRequest implements RouteAttributeModifierInterface
{
    /** @var null|class-string */
    public ?string $body;

    /** @var null|class-string */
    public ?string $query;

    /** @var null|class-string */
    public ?string $route;

    /** @var null|class-string */
    public ?string $source;

    public ?string $output;

    /** @var null|class-string<MappingErrorResponderInterface> */
    public ?string $errorResponder;

    /**
     * @var list<string>
     */
    public array $methods;

    /**
     * @param null|class-string                                 $body           Map from parsed body to this DTO
     * @param null|class-string                                 $query          Map from query params to this DTO
     * @param null|class-string                                 $route          Map from route params to this DTO
     * @param null|class-string                                 $source         Map from all three sources combined to this DTO
     * @param null|string                                       $output         Attribute key in $request (default: DTO FQCN)
     * @param null|class-string<MappingErrorResponderInterface> $errorResponder
     * @param mixed[]                                           $methods        HTTP method filter. Empty = any method.
     */
    public function __construct(
        ?string $body = null,
        ?string $query = null,
        ?string $route = null,
        ?string $source = null,
        ?string $output = null,
        array $methods = [],
        ?string $errorResponder = null,
    ) {
        if (null !== $source && (null !== $body || null !== $query || null !== $route)) {
            throw new InvalidMapRequestConfiguration(
                'MapRequest: $source is mutually exclusive with $body/$query/$route.',
            );
        }

        $this->body           = $this->validateClassString('body', $body);
        $this->query          = $this->validateClassString('query', $query);
        $this->route          = $this->validateClassString('route', $route);
        $this->source         = $this->validateClassString('source', $source);
        $this->output         = $this->validateOptionalString('output', $output);
        $this->errorResponder = $this->validateResponderClassString('errorResponder', $errorResponder);

        if (
            null === $this->body
            && null === $this->query
            && null === $this->route
            && null === $this->source
        ) {
            throw new InvalidMapRequestConfiguration(
                'MapRequest: at least one of $body, $query, $route or $source must be set.',
            );
        }

        $this->methods = (new HttpMethodNormalizer())->normalizeList($methods);
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
                    'body'           => $this->body,
                    'query'          => $this->query,
                    'route'          => $this->route,
                    'source'         => $this->source,
                    'output'         => $this->output,
                    'errorResponder' => $this->errorResponder,
                    'methods'        => $this->methods,
                ],
            ],
        ];
    }

    /**
     * @param null|class-string $value
     *
     * @return null|class-string
     */
    private function validateClassString(string $field, ?string $value): ?string
    {
        $this->assertNonEmptyStringWithoutSurroundingWhitespace($field, $value);

        return $value;
    }

    /**
     * @param null|class-string<MappingErrorResponderInterface> $value
     *
     * @return null|class-string<MappingErrorResponderInterface>
     */
    private function validateResponderClassString(string $field, ?string $value): ?string
    {
        $this->assertNonEmptyStringWithoutSurroundingWhitespace($field, $value);

        return $value;
    }

    private function validateOptionalString(string $field, ?string $value): ?string
    {
        $this->assertNonEmptyStringWithoutSurroundingWhitespace($field, $value);

        return $value;
    }

    private function assertNonEmptyStringWithoutSurroundingWhitespace(string $field, ?string $value): void
    {
        if (null === $value) {
            return;
        }

        if ('' === $value || trim($value) !== $value) {
            throw new InvalidMapRequestConfiguration(
                sprintf('MapRequest: $%s must be a non-empty string without surrounding whitespace.', $field),
            );
        }
    }
}

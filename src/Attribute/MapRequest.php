<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Attribute;

use Attribute;
use Sirix\Mezzio\Routing\Contracts\AggregatingRouteAttributeModifierInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

use function array_key_exists;
use function sprintf;
use function trim;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class MapRequest implements AggregatingRouteAttributeModifierInterface
{
    /** @var null|string Non-empty Valinor target type signature. */
    public ?string $body;

    /** @var null|string Non-empty Valinor target type signature. */
    public ?string $query;

    /** @var null|string Non-empty Valinor target type signature. */
    public ?string $route;

    /** @var null|string Non-empty Valinor target type signature. */
    public ?string $source;

    public ?string $output;

    /** @var null|class-string<MappingErrorResponderInterface> */
    public ?string $errorResponder;

    /**
     * @var list<string>
     */
    public array $methods;

    /**
     * @param null|string                                       $body           Non-empty Valinor target type signature for parsed body
     * @param null|string                                       $query          Non-empty Valinor target type signature for query params
     * @param null|string                                       $route          Non-empty Valinor target type signature for route params
     * @param null|string                                       $source         Non-empty Valinor target type signature for all HTTP sources combined
     * @param null|string                                       $output         Attribute key in $request (default: exact target type signature)
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

        $this->assertDistinctOutputKeys();

        $this->methods = (new HttpMethodNormalizer())->normalizeList($methods);
    }

    public function getMiddleware(): array
    {
        return [];
    }

    public function getDefaults(): array
    {
        return [];
    }

    public function mergeDefaults(array $defaults): array
    {
        $mappings   = $defaults['valinor_mappings'] ?? [];
        $mappings[] = $this->mapping();

        return [
            ...$defaults,
            'valinor_mappings' => $mappings,
        ];
    }

    public function getUniqueMiddleware(): array
    {
        return [
            'sirix.mezzio.valinor.request-mapper' => ValinorRequestMapperMiddleware::class,
        ];
    }

    /** @return array<string, null|list<string>|string> */
    private function mapping(): array
    {
        return [
            'body'           => $this->body,
            'query'          => $this->query,
            'route'          => $this->route,
            'source'         => $this->source,
            'output'         => $this->output,
            'errorResponder' => $this->errorResponder,
            'methods'        => $this->methods,
        ];
    }

    /**
     * @param null|string $value Non-empty Valinor target type signature
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

    private function assertDistinctOutputKeys(): void
    {
        $seen = [];

        foreach ([
            'body'   => $this->body,
            'query'  => $this->query,
            'route'  => $this->route,
            'source' => $this->source,
        ] as $source => $target) {
            if (null === $target) {
                continue;
            }

            $key = $this->output ?? $target;

            if (array_key_exists($key, $seen)) {
                throw new InvalidMapRequestConfiguration(sprintf(
                    "MapRequest: output key '%s' is used by both $%s and $%s.",
                    $key,
                    $seen[$key],
                    $source,
                ));
            }

            $seen[$key] = $source;
        }
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

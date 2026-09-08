<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function is_array;
use function is_string;
use function sprintf;

/**
 * @internal
 */
final readonly class MapRequestOptionsParser
{
    private const KNOWN_KEYS = [
        'body'           => true,
        'query'          => true,
        'route'          => true,
        'source'         => true,
        'output'         => true,
        'methods'        => true,
        'errorResponder' => true,
    ];

    /**
     * @return list<MapRequest>
     */
    public function parse(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidMapRequestConfiguration(
                'valinor_mappings: expected list<map<string, mixed>>.',
            );
        }

        $result = [];

        foreach ($value as $index => $item) {
            $result[] = $this->parseItem($item, $index);
        }

        return $result;
    }

    private function parseItem(mixed $item, int $index): MapRequest
    {
        if (! is_array($item)) {
            throw new InvalidMapRequestConfiguration(
                sprintf('valinor_mappings[%d]: expected map<string, mixed>.', $index),
            );
        }

        $args = [];

        foreach (['body', 'query', 'route', 'source', 'output', 'errorResponder'] as $key) {
            if (! array_key_exists($key, $item)) {
                continue;
            }

            $args[$key] = $this->parseOptionalString($item[$key], $index, $key);
        }

        if (array_key_exists('methods', $item)) {
            $args['methods'] = $this->parseMethods($item['methods'], $index);
        }

        foreach (array_keys($item) as $key) {
            if (! is_string($key) || ! array_key_exists($key, self::KNOWN_KEYS)) {
                throw new InvalidMapRequestConfiguration(
                    sprintf("valinor_mappings[%d]: unknown key '%s'.", $index, is_string($key) ? $key : (string) $key),
                );
            }
        }

        return new MapRequest(...$args);
    }

    private function parseOptionalString(mixed $value, int $index, string $key): ?string
    {
        if (null === $value) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidMapRequestConfiguration(
                sprintf('valinor_mappings[%d].%s: expected string|null.', $index, $key),
            );
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function parseMethods(mixed $value, int $index): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidMapRequestConfiguration(
                sprintf('valinor_mappings[%d].methods: expected list<string>.', $index),
            );
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidMapRequestConfiguration(
                    sprintf('valinor_mappings[%d].methods: expected list<string>.', $index),
                );
            }
        }

        return $value;
    }
}

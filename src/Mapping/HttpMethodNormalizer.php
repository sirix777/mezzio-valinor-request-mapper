<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;

use function array_is_list;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * @internal
 */
final readonly class HttpMethodNormalizer
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/';

    public function normalize(string $method): string
    {
        $normalized = strtoupper(trim($method));

        if ('' === $normalized) {
            throw new InvalidMapRequestConfiguration('HTTP method must not be blank.');
        }

        if (! preg_match(self::TOKEN_PATTERN, $normalized)) {
            throw new InvalidMapRequestConfiguration(
                sprintf('HTTP method "%s" contains invalid characters.', $method),
            );
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $methods
     *
     * @return list<string>
     */
    public function normalizeList(array $methods): array
    {
        if (! array_is_list($methods)) {
            throw new InvalidMapRequestConfiguration('HTTP methods must be a list.');
        }

        $normalized = [];

        foreach ($methods as $index => $method) {
            if (! is_string($method)) {
                throw new InvalidMapRequestConfiguration(
                    sprintf('HTTP method at index %s must be a string.', $index),
                );
            }

            $normalized[] = $this->normalize($method);
        }

        return $this->uniqueOrdered($normalized);
    }

    /**
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private function uniqueOrdered(array $methods): array
    {
        $seen   = [];
        $result = [];

        foreach ($methods as $method) {
            if (in_array($method, $seen, true)) {
                continue;
            }

            $seen[]   = $method;
            $result[] = $method;
        }

        return $result;
    }
}

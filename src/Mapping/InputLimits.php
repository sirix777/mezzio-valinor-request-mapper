<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;

use function array_key_exists;
use function array_keys;
use function is_int;
use function sprintf;

/**
 * @internal
 */
final readonly class InputLimits
{
    private const CONFIG_PATH = 'sirix_mezzio_valinor.input_limits';

    private const KNOWN_KEYS = [
        'max_nodes'              => true,
        'max_depth'              => true,
        'max_total_string_bytes' => true,
    ];

    public function __construct(public ?int $maxNodes = null, public ?int $maxDepth = null, public ?int $maxTotalStringBytes = null)
    {
        $this->assertPositive($maxNodes, 'max_nodes');
        $this->assertPositive($maxDepth, 'max_depth');
        $this->assertPositive($maxTotalStringBytes, 'max_total_string_bytes');
    }

    public function isEnabled(): bool
    {
        return null !== $this->maxNodes
            || null !== $this->maxDepth
            || null !== $this->maxTotalStringBytes;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (! array_key_exists($key, self::KNOWN_KEYS)) {
                throw new InvalidMapRequestConfiguration(
                    sprintf('%s.%s is not a known option.', self::CONFIG_PATH, $key),
                );
            }
        }

        return new self(
            self::read($config, 'max_nodes'),
            self::read($config, 'max_depth'),
            self::read($config, 'max_total_string_bytes'),
        );
    }

    private function assertPositive(?int $value, string $key): void
    {
        if (null !== $value && $value < 1) {
            throw new InvalidMapRequestConfiguration(
                sprintf('%s.%s must be null or a positive integer.', self::CONFIG_PATH, $key),
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function read(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (! is_int($value) || $value < 1) {
            throw new InvalidMapRequestConfiguration(
                sprintf('%s.%s must be null or a positive integer.', self::CONFIG_PATH, $key),
            );
        }

        return $value;
    }
}

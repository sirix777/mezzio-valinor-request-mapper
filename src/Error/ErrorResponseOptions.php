<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Error;

use Sirix\Mezzio\Valinor\Exception\InvalidMapRequestConfiguration;

use function array_key_exists;
use function array_keys;
use function is_int;
use function sprintf;

final readonly class ErrorResponseOptions
{
    private const CONFIG_PATH = 'sirix_mezzio_valinor.error_response';

    private const KNOWN_KEYS = [
        'max_messages'       => true,
        'max_response_bytes' => true,
    ];

    public function __construct(public ?int $maxMessages = null, public ?int $maxResponseBytes = null)
    {
        if (null !== $maxMessages && $maxMessages < 1) {
            throw new InvalidMapRequestConfiguration(
                sprintf('%s.max_messages must be null or a positive integer.', self::CONFIG_PATH),
            );
        }

        if (null !== $maxResponseBytes && $maxResponseBytes < 256) {
            throw new InvalidMapRequestConfiguration(
                sprintf('%s.max_response_bytes must be null or an integer of at least 256.', self::CONFIG_PATH),
            );
        }
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
            self::readPositiveInt($config, 'max_messages'),
            self::readBoundedInt($config, 'max_response_bytes'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function readPositiveInt(array $config, string $key): ?int
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

    /**
     * @param array<string, mixed> $config
     */
    private static function readBoundedInt(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (! is_int($value) || $value < 256) {
            throw new InvalidMapRequestConfiguration(
                sprintf('%s.%s must be null or an integer of at least 256.', self::CONFIG_PATH, $key),
            );
        }

        return $value;
    }
}

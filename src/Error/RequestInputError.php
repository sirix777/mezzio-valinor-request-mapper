<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Error;

use RuntimeException;

final class RequestInputError extends RuntimeException
{
    private function __construct(public readonly string $reason, public readonly string $inputSource, string $message)
    {
        parent::__construct($message);
    }

    public static function unsupportedParsedBody(): self
    {
        return new self(
            'unsupported_parsed_body',
            'body',
            'Parsed request body must be an array or null.',
        );
    }

    public static function invalidUtf8(string $inputSource): self
    {
        return new self(
            'invalid_utf8',
            $inputSource,
            'Request input contains invalid UTF-8.',
        );
    }

    public static function nodeLimitExceeded(string $inputSource): self
    {
        return new self(
            'input_node_limit_exceeded',
            $inputSource,
            'Request input exceeds the node limit.',
        );
    }

    public static function depthLimitExceeded(string $inputSource): self
    {
        return new self(
            'input_depth_limit_exceeded',
            $inputSource,
            'Request input exceeds the nesting depth limit.',
        );
    }

    public static function stringBytesLimitExceeded(string $inputSource): self
    {
        return new self(
            'input_string_bytes_limit_exceeded',
            $inputSource,
            'Request input exceeds the string byte limit.',
        );
    }

    public static function cyclicInput(string $inputSource): self
    {
        return new self(
            'cyclic_input',
            $inputSource,
            'Request input contains a circular array reference.',
        );
    }
}

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
}

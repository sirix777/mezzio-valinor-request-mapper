<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

/**
 * @internal
 */
interface InputEncodingValidatorInterface
{
    /**
     * @param array<mixed>           $values
     * @param 'body'|'query'|'route' $inputSource
     */
    public function assertValid(array $values, string $inputSource): void;
}

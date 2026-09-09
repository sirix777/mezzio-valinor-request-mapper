<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use ReflectionReference;
use Sirix\Mezzio\Valinor\Error\RequestInputError;

use function array_pop;
use function is_array;
use function is_string;
use function preg_match;

/**
 * @internal
 */
final class InputEncodingValidator
{
    /**
     * @param array<mixed>           $values
     * @param 'body'|'query'|'route' $inputSource
     */
    public function assertValid(array $values, string $inputSource): void
    {
        $stack             = [$values];
        $visitedReferences = [];

        while ([] !== $stack) {
            $current = array_pop($stack);

            foreach ($current as $key => $value) {
                if (is_string($key) && 1 !== preg_match('//u', $key)) {
                    throw RequestInputError::invalidUtf8($inputSource);
                }

                if (is_string($value)) {
                    if (1 !== preg_match('//u', $value)) {
                        throw RequestInputError::invalidUtf8($inputSource);
                    }

                    continue;
                }

                if (! is_array($value)) {
                    continue;
                }

                $reference = ReflectionReference::fromArrayElement($current, $key);

                if ($reference instanceof ReflectionReference) {
                    $referenceId = $reference->getId();

                    if (isset($visitedReferences[$referenceId])) {
                        continue;
                    }

                    $visitedReferences[$referenceId] = true;
                }

                $stack[] = $value;
            }
        }
    }
}

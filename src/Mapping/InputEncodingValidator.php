<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use Generator;
use ReflectionReference;
use Sirix\Mezzio\Valinor\Error\RequestInputError;

use function array_pop;
use function count;
use function is_array;
use function is_string;
use function preg_match;
use function strlen;

/**
 * @internal
 */
final readonly class InputEncodingValidator implements InputEncodingValidatorInterface
{
    public function __construct(private ?InputLimits $limits = null) {}

    /**
     * @param array<mixed>           $values
     * @param 'body'|'query'|'route' $inputSource
     */
    public function assertValid(array $values, string $inputSource): void
    {
        $limits = $this->limits;

        if (! $limits instanceof InputLimits || ! $limits->isEnabled()) {
            $this->assertValidUnlimited($values, $inputSource);

            return;
        }

        $this->assertValidWithBudgets($values, $inputSource, $limits);
    }

    /**
     * @param array<mixed>           $values
     * @param 'body'|'query'|'route' $inputSource
     */
    private function assertValidUnlimited(array $values, string $inputSource): void
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

    /**
     * @param array<mixed>           $values
     * @param 'body'|'query'|'route' $inputSource
     */
    private function assertValidWithBudgets(array $values, string $inputSource, InputLimits $limits): void
    {
        /**
         * @var list<array{
         *     values: array<mixed>,
         *     iterator: Generator<int|string, mixed, mixed, mixed>,
         *     depth: int,
         *     refId: ?string,
         * }> $stack
         */
        $stack = [[
            'values'   => $values,
            'iterator' => $this->lazyIterate($values),
            'depth'    => 1,
            'refId'    => null,
        ]];

        $activeRefs     = [];
        $nodeCount      = 0;
        $remainingBytes = $limits->maxTotalStringBytes;

        while ([] !== $stack) {
            $index = count($stack) - 1;
            $frame = $stack[$index];

            if (! $frame['iterator']->valid()) {
                array_pop($stack);

                if (null !== $frame['refId']) {
                    unset($activeRefs[$frame['refId']]);
                }

                continue;
            }

            $key   = $frame['iterator']->key();
            $value = $frame['iterator']->current();
            $frame['iterator']->next();

            if (null !== $limits->maxNodes && ++$nodeCount > $limits->maxNodes) {
                throw RequestInputError::nodeLimitExceeded($inputSource);
            }

            if (is_string($key)) {
                if (null !== $remainingBytes) {
                    $keyLength = strlen($key);

                    if ($keyLength > $remainingBytes) {
                        throw RequestInputError::stringBytesLimitExceeded($inputSource);
                    }

                    $remainingBytes -= $keyLength;
                }

                if (1 !== preg_match('//u', $key)) {
                    throw RequestInputError::invalidUtf8($inputSource);
                }
            }

            if (is_string($value)) {
                if (null !== $remainingBytes) {
                    $valueLength = strlen($value);

                    if ($valueLength > $remainingBytes) {
                        throw RequestInputError::stringBytesLimitExceeded($inputSource);
                    }

                    $remainingBytes -= $valueLength;
                }

                if (1 !== preg_match('//u', $value)) {
                    throw RequestInputError::invalidUtf8($inputSource);
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $reference = ReflectionReference::fromArrayElement($frame['values'], $key);
            $refId     = $reference instanceof ReflectionReference ? $reference->getId() : null;

            if (null !== $refId && isset($activeRefs[$refId])) {
                throw RequestInputError::cyclicInput($inputSource);
            }

            $childDepth = $frame['depth'] + 1;

            if (null !== $limits->maxDepth && $childDepth > $limits->maxDepth) {
                throw RequestInputError::depthLimitExceeded($inputSource);
            }

            if (null !== $refId) {
                $activeRefs[$refId] = true;
            }

            $stack[] = [
                'values'   => $value,
                'iterator' => $this->lazyIterate($value),
                'depth'    => $childDepth,
                'refId'    => $refId,
            ];
        }
    }

    /**
     * @param array<mixed> $values
     *
     * @return Generator<int|string, mixed, mixed, mixed>
     */
    private function lazyIterate(array $values): Generator
    {
        foreach ($values as $key => $value) {
            yield $key => $value;
        }
    }
}

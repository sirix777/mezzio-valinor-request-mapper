<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Error;

use const JSON_THROW_ON_ERROR;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function count;
use function json_encode;
use function max;
use function preg_match;
use function str_starts_with;
use function strlen;

final readonly class DefaultMappingErrorResponder implements MappingErrorResponderInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ?ErrorResponseOptions $options = null,
    ) {}

    public function respond(MappingErrorContext $context): ResponseInterface
    {
        $options = $this->options ?? new ErrorResponseOptions();

        if ($context->error instanceof RequestInputError) {
            $messages = [
                '' => [$context->error->getMessage()],
            ];
        } else {
            $messages = [];
            $all      = $context->error->messages()->formatWith();
            $limit    = $options->maxMessages;

            $hasRemainder = null !== $limit && count($all) > $limit;

            if (null === $limit) {
                foreach ($all as $message) {
                    $path = '*root*' === $message->path() ? '' : $message->path();

                    $messages[$path] ??= [];
                    $messages[$path][] = (string) $message;
                }
            } else {
                $iterator = $all->getIterator();
                $iterator->rewind();
                $retained = 0;

                while ($iterator->valid()) {
                    $message = $iterator->current();
                    $path    = '*root*' === $message->path() ? '' : $message->path();

                    $messages[$path] ??= [];
                    $messages[$path][] = (string) $message;
                    ++$retained;

                    if ($retained >= $limit) {
                        break;
                    }

                    $iterator->next();
                }
            }

            if ($hasRemainder) {
                $messages[''][] = 'Additional mapping errors were omitted.';
            }
        }

        $body = null;

        if (null === $options->maxResponseBytes || ! $this->exceedsMinimumBodyBytes($messages, $options->maxResponseBytes)) {
            $body = json_encode([
                'error'    => 'Mapping failed',
                'messages' => (object) $messages,
            ], JSON_THROW_ON_ERROR);
        }

        if (null === $body || (null !== $options->maxResponseBytes && strlen($body) > $options->maxResponseBytes)) {
            $body = json_encode([
                'error'    => 'Mapping failed',
                'messages' => (object) [
                    '' => ['Mapping error details exceed the response limit.'],
                ],
            ], JSON_THROW_ON_ERROR);
        }

        return $this->responseFactory
            ->createResponse(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body))
        ;
    }

    /**
     * @param array<int|string, list<string>> $messages
     */
    private function exceedsMinimumBodyBytes(array $messages, int $limit): bool
    {
        $remaining = $limit;

        foreach ($messages as $path => $group) {
            $path = (string) $path;

            // JSON omits object properties whose names start with NUL.
            if (str_starts_with($path, "\0") || 1 !== preg_match('//u', $path)) {
                return false;
            }

            $remaining = max(-1, $remaining - strlen($path));

            foreach ($group as $message) {
                if (1 !== preg_match('//u', $message)) {
                    return false;
                }

                $remaining = max(-1, $remaining - strlen($message));
            }
        }

        return $remaining < 0;
    }
}

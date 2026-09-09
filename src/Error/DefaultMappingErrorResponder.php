<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Error;

use const JSON_THROW_ON_ERROR;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function json_encode;

final readonly class DefaultMappingErrorResponder implements MappingErrorResponderInterface
{
    public function __construct(private ResponseFactoryInterface $responseFactory, private StreamFactoryInterface $streamFactory) {}

    public function respond(MappingErrorContext $context): ResponseInterface
    {
        if ($context->error instanceof RequestInputError) {
            $messages = [
                '' => [$context->error->getMessage()],
            ];
        } else {
            $messages = [];

            foreach ($context->error->messages()->formatWith() as $message) {
                $path = '*root*' === $message->path() ? '' : $message->path();

                $messages[$path] ??= [];
                $messages[$path][] = (string) $message;
            }
        }

        $body = json_encode([
            'error'    => 'Mapping failed',
            'messages' => $messages,
        ], JSON_THROW_ON_ERROR);

        return $this->responseFactory
            ->createResponse(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body))
        ;
    }
}

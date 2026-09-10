<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Error;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Tree\Message\ErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\Messages;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;
use CuyZ\Valinor\MapperBuilder;
use Exception;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Test\Error\Fixture\DeepErrorDto;
use Sirix\Mezzio\Valinor\Test\Error\Fixture\MultiFieldErrorDto;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;

use function json_decode;

final class DefaultMappingErrorResponderTest extends TestCase
{
    #[Test]
    public function returnsTheStandard422EnvelopeForRequestInputError(): void
    {
        $response = (new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory()))->respond(
            new MappingErrorContext(
                RequestInputError::unsupportedParsedBody(),
                new ServerRequest(),
                new MapRequest(body: RequiredRequest::class),
                RequiredRequest::class,
                'body',
                'form',
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            'error'    => 'Mapping failed',
            'messages' => [
                '' => ['Parsed request body must be an array or null.'],
            ],
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function groupsMultipleMessagesForSameFieldInEnvelope(): void
    {
        $error = $this->mappingErrorWithMultipleMessagesOnSamePath('email');

        $response = (new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory()))->respond(
            new MappingErrorContext(
                $error,
                new ServerRequest(),
                new MapRequest(body: RequiredRequest::class),
                RequiredRequest::class,
                'body',
                'form',
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            'error'    => 'Mapping failed',
            'messages' => [
                'email' => [
                    'First message for email.',
                    'Second message for email.',
                ],
            ],
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function groupsMultipleFieldMessagesInEnvelope(): void
    {
        $error = $this->mappingError(MultiFieldErrorDto::class, []);

        $response = (new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory()))->respond(
            new MappingErrorContext(
                $error,
                new ServerRequest(),
                new MapRequest(body: MultiFieldErrorDto::class),
                MultiFieldErrorDto::class,
                'body',
                MultiFieldErrorDto::class,
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            'error'    => 'Mapping failed',
            'messages' => [
                'name' => ['Value *missing* is not a valid string.'],
                'age'  => ['Value *missing* is not a valid integer.'],
            ],
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function formatsNestedPathsInEnvelope(): void
    {
        $error = $this->mappingError(DeepErrorDto::class, [
            'name'   => 'valid',
            'nested' => [],
        ]);

        $response = (new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory()))->respond(
            new MappingErrorContext(
                $error,
                new ServerRequest(),
                new MapRequest(body: DeepErrorDto::class),
                DeepErrorDto::class,
                'body',
                DeepErrorDto::class,
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            'error'    => 'Mapping failed',
            'messages' => [
                'nested.name' => ['Value *missing* is not a valid string.'],
            ],
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function formatsRootPathAsEmptyKeyInEnvelope(): void
    {
        $error = $this->mappingError('non-empty-string', '');

        $response = (new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory()))->respond(
            new MappingErrorContext(
                $error,
                new ServerRequest(),
                new MapRequest(body: RequiredRequest::class),
                RequiredRequest::class,
                'body',
                RequiredRequest::class,
            ),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            'error'    => 'Mapping failed',
            'messages' => [
                '' => ["Value '' is not a valid non-empty string."],
            ],
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param class-string<mixed>|non-empty-string $signature
     */
    private function mappingError(string $signature, mixed $source): MappingError
    {
        $mapper = (new MapperBuilder())->mapper();

        try {
            $mapper->map($signature, $source);
        } catch (MappingError $error) {
            return $error;
        }

        self::fail('Expected mapping to fail.');
    }

    private function mappingErrorWithMultipleMessagesOnSamePath(string $path): MappingError
    {
        $message = (static fn (string $body): ErrorMessage => new class($body) implements ErrorMessage {
            public function __construct(private readonly string $body) {}

            public function body(): string
            {
                return $this->body;
            }
        });

        $messages = new Messages(
            new NodeMessage($message('First message for email.'), 'First message for email.', $path, $path, 'string', 'string', ''),
            new NodeMessage($message('Second message for email.'), 'Second message for email.', $path, $path, 'string', 'string', ''),
        );

        return new class($messages) extends Exception implements MappingError {
            public function __construct(private readonly Messages $messages)
            {
                parent::__construct('Mapping failed');
            }

            public function messages(): Messages
            {
                return $this->messages;
            }

            public function type(): string
            {
                return 'test';
            }

            public function source(): mixed
            {
                return [];
            }
        };
    }
}

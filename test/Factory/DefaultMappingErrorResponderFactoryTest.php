<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Tree\Message\ErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\Messages;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Factory\DefaultMappingErrorResponderFactory;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;

use function json_decode;

final class DefaultMappingErrorResponderFactoryTest extends TestCase
{
    #[Test]
    public function buildsTheInputErrorResponderWithPsr17Factories(): void
    {
        $responseFactory = new ResponseFactory();
        $streamFactory   = new StreamFactory();
        $responder       = (new DefaultMappingErrorResponderFactory())(new class($responseFactory, $streamFactory) implements ContainerInterface {
            public function __construct(
                private readonly ResponseFactoryInterface $responseFactory,
                private readonly StreamFactoryInterface $streamFactory,
            ) {}

            public function get($id): mixed
            {
                return match ($id) {
                    ResponseFactoryInterface::class => $this->responseFactory,
                    StreamFactoryInterface::class   => $this->streamFactory,
                    default                         => throw new RuntimeException("Service not found: {$id}"),
                };
            }

            public function has($id): bool
            {
                return ResponseFactoryInterface::class === $id || StreamFactoryInterface::class === $id;
            }
        });

        $response = $responder->respond(new MappingErrorContext(
            RequestInputError::unsupportedParsedBody(),
            new ServerRequest(),
            new MapRequest(body: RequiredRequest::class),
            RequiredRequest::class,
            'body',
            'form',
        ));

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
    public function appliesConfiguredMessageLimit(): void
    {
        $responder = (new DefaultMappingErrorResponderFactory())($this->container([
            'sirix_mezzio_valinor' => [
                'error_response' => [
                    'max_messages' => 1,
                ],
            ],
        ]));

        $messages = new Messages(
            $this->nodeMessage('a', 'first'),
            $this->nodeMessage('b', 'second'),
        );

        $response = $responder->respond(new MappingErrorContext(
            $this->errorFromMessages($messages),
            new ServerRequest(),
            new MapRequest(body: RequiredRequest::class),
            RequiredRequest::class,
            'body',
            RequiredRequest::class,
        ));

        self::assertSame([
            'a' => ['first'],
            ''  => ['Additional mapping errors were omitted.'],
        ], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['messages']);
    }

    #[Test]
    public function missingConfigKeepsUnlimitedDefaults(): void
    {
        $responder = (new DefaultMappingErrorResponderFactory())($this->container([]));

        $response = $responder->respond(new MappingErrorContext(
            RequestInputError::unsupportedParsedBody(),
            new ServerRequest(),
            new MapRequest(body: RequiredRequest::class),
            RequiredRequest::class,
            'body',
            'form',
        ));

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config): ContainerInterface
    {
        $responseFactory = new ResponseFactory();
        $streamFactory   = new StreamFactory();

        return new class($responseFactory, $streamFactory, $config) implements ContainerInterface {
            /**
             * @param array<string, mixed> $config
             */
            public function __construct(
                private readonly ResponseFactoryInterface $responseFactory,
                private readonly StreamFactoryInterface $streamFactory,
                private readonly array $config,
            ) {}

            public function get($id): mixed
            {
                return match ($id) {
                    ResponseFactoryInterface::class => $this->responseFactory,
                    StreamFactoryInterface::class   => $this->streamFactory,
                    'config'                        => $this->config,
                    default                         => throw new RuntimeException("Service not found: {$id}"),
                };
            }

            public function has($id): bool
            {
                return ResponseFactoryInterface::class === $id
                    || StreamFactoryInterface::class === $id
                    || 'config' === $id;
            }
        };
    }

    private function nodeMessage(string $path, string $body): NodeMessage
    {
        $message = (static fn (): ErrorMessage => new class($body) implements ErrorMessage {
            public function __construct(private readonly string $body) {}

            public function body(): string
            {
                return $this->body;
            }
        })();

        return new NodeMessage($message, $body, $path, $path, 'string', 'string', '');
    }

    private function errorFromMessages(Messages $messages): MappingError
    {
        return new class($messages) extends RuntimeException implements MappingError {
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

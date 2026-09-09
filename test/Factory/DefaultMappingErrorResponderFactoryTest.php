<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

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

            public function get(string $id): mixed
            {
                return match ($id) {
                    ResponseFactoryInterface::class => $this->responseFactory,
                    StreamFactoryInterface::class   => $this->streamFactory,
                    default                         => throw new RuntimeException("Service not found: {$id}"),
                };
            }

            public function has(string $id): bool
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
}

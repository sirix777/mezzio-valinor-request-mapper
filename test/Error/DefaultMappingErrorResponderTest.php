<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Error;

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
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
}

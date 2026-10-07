<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Error;

use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\Tree\Message\ErrorMessage;
use CuyZ\Valinor\Mapper\Tree\Message\Formatter\MessageFormatter;
use CuyZ\Valinor\Mapper\Tree\Message\Messages;
use CuyZ\Valinor\Mapper\Tree\Message\NodeMessage;
use CuyZ\Valinor\MapperBuilder;
use CuyZ\Valinor\Utility\String\StringFormatterError;
use Exception;
use JsonException;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\ErrorResponseOptions;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Test\Error\Fixture\DeepErrorDto;
use Sirix\Mezzio\Valinor\Test\Error\Fixture\MultiFieldErrorDto;
use Sirix\Mezzio\Valinor\Test\Error\Fixture\RootIntegerList;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;
use stdClass;

use function array_keys;
use function array_map;
use function array_values;
use function extension_loaded;
use function get_object_vars;
use function json_decode;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function str_repeat;
use function strlen;

final class DefaultMappingErrorResponderTest extends TestCase
{
    #[Test]
    public function numericPathsAreJsonObject(): void
    {
        $response = $this->respondTo($this->rootListError(['x', 'y']));

        $json = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $json->messages);
        self::assertSame(['0', '1'], array_map(strval(...), array_keys(get_object_vars($json->messages))));
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function sparseNumericPathIsJsonObject(): void
    {
        $response = $this->respondTo($this->rootListError([1, 'y']));

        $json = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $json->messages);
        self::assertSame(['1'], array_map(strval(...), array_keys(get_object_vars($json->messages))));
    }

    #[Test]
    public function emptyMessagesAreJsonObject(): void
    {
        $response = $this->respondTo($this->errorFromMessages(new Messages()));

        $json = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $json->messages);
        self::assertSame([], get_object_vars($json->messages));
        self::assertSame(
            '{"error":"Mapping failed","messages":{}}',
            (string) $response->getBody(),
        );
    }

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

    #[Test]
    public function retainsFirstMessagesAndAddsRootMarker(): void
    {
        $options = new ErrorResponseOptions(maxMessages: 2);
        $error   = $this->syntheticError(
            $this->nodeMessage('a', 'm0'),
            $this->nodeMessage('b', 'm1'),
            $this->nodeMessage('c', 'm2'),
        );

        $json = $this->decode($this->respondTo($error, $options));

        self::assertSame(
            [
                'a' => ['m0'],
                'b' => ['m1'],
                ''  => ['Additional mapping errors were omitted.'],
            ],
            (array) $json->messages,
        );
    }

    #[Test]
    public function exactLimitDoesNotAddMarker(): void
    {
        $options = new ErrorResponseOptions(maxMessages: 2);
        $error   = $this->syntheticError(
            $this->nodeMessage('a', 'm0'),
            $this->nodeMessage('b', 'm1'),
        );

        $json = $this->decode($this->respondTo($error, $options));

        self::assertSame(
            [
                'a' => ['m0'],
                'b' => ['m1'],
            ],
            (array) $json->messages,
        );
    }

    #[Test]
    public function rootMarkerAppendsToExistingRoot(): void
    {
        $options = new ErrorResponseOptions(maxMessages: 2);
        $error   = $this->syntheticError(
            $this->nodeMessage('*root*', 'root message'),
            $this->nodeMessage('a', 'm0'),
            $this->nodeMessage('b', 'm1'),
        );

        $json = $this->decode($this->respondTo($error, $options));

        self::assertSame(
            [
                ''  => ['root message', 'Additional mapping errors were omitted.'],
                'a' => ['m0'],
            ],
            (array) $json->messages,
        );
    }

    #[Test]
    public function discardedMessagesDoNotRunFormatters(): void
    {
        $formatter = new class implements MessageFormatter {
            public function format(NodeMessage $message): NodeMessage
            {
                if ('b' === $message->path()) {
                    throw new RuntimeException('Discarded message formatter executed');
                }

                return $message->withBody($message->body() . ' [formatted]');
            }
        };

        $messages = (new Messages(
            $this->nodeMessage('a', 'first'),
            $this->nodeMessage('b', 'second'),
        ))->formatWith($formatter);

        $response = $this->respondTo($this->errorFromMessages($messages), new ErrorResponseOptions(maxMessages: 1));

        self::assertSame(
            [
                'a' => ['first [formatted]'],
                ''  => ['Additional mapping errors were omitted.'],
            ],
            (array) $this->decode($response)->messages,
        );
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function discardedMessagesAreNotFormatted(): void
    {
        $options = new ErrorResponseOptions(maxMessages: 1);
        $error   = $this->syntheticError(
            $this->nodeMessage('a', 'ok'),
            $this->nodeMessage('b', 'discarded', throwing: true),
        );

        $json = $this->decode($this->respondTo($error, $options));

        self::assertSame(
            [
                'a' => ['ok'],
                ''  => ['Additional mapping errors were omitted.'],
            ],
            (array) $json->messages,
        );
    }

    #[Test]
    public function oversizedResponseUsesFixedFallback(): void
    {
        $error   = $this->syntheticError($this->nodeMessage('a', str_repeat('x', 400)));
        $options = new ErrorResponseOptions(maxResponseBytes: 256);

        $response = $this->respondTo($error, $options);
        $body     = (string) $response->getBody();

        self::assertLessThanOrEqual(256, strlen($body));
        self::assertSame(
            ['Mapping error details exceed the response limit.'],
            json_decode($body, flags: JSON_THROW_ON_ERROR)->messages->{''},
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function oversizedPathDoesNotAllocateFullJsonBody(): void
    {
        $responder = new DefaultMappingErrorResponder(
            new ResponseFactory(),
            new StreamFactory(),
            new ErrorResponseOptions(maxMessages: 1, maxResponseBytes: 256),
        );
        $warmup = $responder->respond(new MappingErrorContext(
            $this->syntheticError($this->nodeMessage('a', 'bad')),
            new ServerRequest(),
            new MapRequest(body: RequiredRequest::class),
            RequiredRequest::class,
            'body',
            RequiredRequest::class,
        ));
        unset($warmup);

        $context = new MappingErrorContext(
            $this->syntheticError($this->nodeMessage(str_repeat('x', 8 * 1024 * 1024), 'bad')),
            new ServerRequest(),
            new MapRequest(body: RequiredRequest::class),
            RequiredRequest::class,
            'body',
            RequiredRequest::class,
        );

        memory_reset_peak_usage();
        $before   = memory_get_usage(false);
        $response = $responder->respond($context);
        $extra    = memory_get_peak_usage(false) - $before;

        self::assertLessThan(2 * 1024 * 1024, $extra);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        self::assertSame(
            '{"error":"Mapping failed","messages":{"":['
            . '"Mapping error details exceed the response limit."]}}',
            $body,
        );
        self::assertLessThanOrEqual(256, strlen($body));
    }

    #[Test]
    public function oversizedPathPreservesInvalidUtf8BodyException(): void
    {
        $error = $this->syntheticError($this->nodeMessage(str_repeat('x', 8 * 1024 * 1024), "\xB1"));

        // ICU rejects invalid UTF-8 while formatting; the regex fallback reaches JSON encoding.
        $this->expectException(extension_loaded('intl') ? StringFormatterError::class : JsonException::class);

        $this->respondTo($error, new ErrorResponseOptions(maxMessages: 1, maxResponseBytes: 256));
    }

    #[Test]
    public function oversizedPathPreservesInvalidUtf8KeyException(): void
    {
        $error = $this->syntheticError($this->nodeMessage(str_repeat('x', 8 * 1024 * 1024) . "\xB1", 'bad'));

        $this->expectException(extension_loaded('intl') ? StringFormatterError::class : JsonException::class);

        $this->respondTo($error, new ErrorResponseOptions(maxMessages: 1, maxResponseBytes: 256));
    }

    #[Test]
    #[DataProvider('invalidUtf8AfterOverflow')]
    public function oversizedPathPreservesLaterInvalidUtf8Exception(string $path, string $body): void
    {
        $error = $this->syntheticError(
            $this->nodeMessage(str_repeat('x', 8 * 1024 * 1024), 'bad'),
            $this->nodeMessage($path, $body),
        );

        $this->expectException(extension_loaded('intl') ? StringFormatterError::class : JsonException::class);

        $this->respondTo($error, new ErrorResponseOptions(maxMessages: 2, maxResponseBytes: 256));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidUtf8AfterOverflow(): array
    {
        return [
            'later body' => ['b', "\xB1"],
            'later key'  => ["b\xB1", 'bad'],
        ];
    }

    #[Test]
    public function oversizedFirstMessageDoesNotHideLaterRetainedFormatterFailure(): void
    {
        $failure   = new RuntimeException('Retained formatter failed');
        $formatter = new class($failure) implements MessageFormatter {
            public function __construct(private readonly RuntimeException $failure) {}

            public function format(NodeMessage $message): NodeMessage
            {
                if ('b' === $message->path()) {
                    throw $this->failure;
                }

                return $message;
            }
        };
        $messages = (new Messages(
            $this->nodeMessage(str_repeat('x', 8 * 1024 * 1024), 'bad'),
            $this->nodeMessage('b', 'bad'),
        ))->formatWith($formatter);

        $this->expectExceptionObject($failure);

        $this->respondTo($this->errorFromMessages($messages), new ErrorResponseOptions(maxMessages: 2, maxResponseBytes: 256));
    }

    #[Test]
    #[DataProvider('escapedAndUnicodeMessages')]
    public function escapedAndUnicodeMessagesStillUseExactByteCap(string $message): void
    {
        $error     = $this->syntheticError($this->nodeMessage('a', $message));
        $unlimited = (string) $this->respondTo($error)->getBody();

        self::assertLessThan(256, strlen('a') + strlen($message));
        self::assertGreaterThan(256, strlen($unlimited));

        $body = (string) $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: 256))->getBody();
        self::assertSame(
            '{"error":"Mapping failed","messages":{"":['
            . '"Mapping error details exceed the response limit."]}}',
            $body,
        );
        self::assertLessThanOrEqual(256, strlen($body));
        self::assertSame(
            $unlimited,
            (string) $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: strlen($unlimited)))->getBody(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapedAndUnicodeMessages(): array
    {
        return [
            'escaping' => [str_repeat('"', 150)],
            'unicode'  => [str_repeat('я', 100)],
        ];
    }

    #[Test]
    public function aggregateRetainedStringsRespectByteCap(): void
    {
        $error = $this->syntheticError(
            $this->nodeMessage('a', str_repeat('x', 100)),
            $this->nodeMessage('b', str_repeat('x', 100)),
            $this->nodeMessage('c', str_repeat('x', 100)),
        );

        $body = (string) $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: 256))->getBody();

        self::assertSame(
            '{"error":"Mapping failed","messages":{"":['
            . '"Mapping error details exceed the response limit."]}}',
            $body,
        );
        self::assertLessThanOrEqual(256, strlen($body));
    }

    #[Test]
    public function oversizedNulLeadingPathPreservesVisibleEncodedBody(): void
    {
        $error = $this->mappingError('array<string, int>', [
            'a'                         => 'bad',
            "\0" . str_repeat('x', 300) => 'bad',
        ]);
        $expected = '{"error":"Mapping failed","messages":{"a":["Value \'bad\' is not a valid integer."]}}';

        self::assertSame($expected, (string) $this->respondTo($error)->getBody());

        $response = $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: 256));
        $body     = (string) $response->getBody();

        self::assertSame($expected, $body);
        self::assertLessThanOrEqual(256, strlen($body));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function oversizedMessageUnderNulLeadingPathPreservesVisibleEncodedBody(): void
    {
        $error = $this->syntheticError(
            $this->nodeMessage('a', 'normal detail'),
            $this->nodeMessage("\0hidden", str_repeat('x', 300)),
        );
        $expected = '{"error":"Mapping failed","messages":{"a":["normal detail"]}}';

        self::assertSame($expected, (string) $this->respondTo($error)->getBody());

        $response = $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: 256));
        $body     = (string) $response->getBody();

        self::assertSame($expected, $body);
        self::assertLessThanOrEqual(256, strlen($body));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function nulLeadingPathsPreserveRetainedFormatterFailures(): void
    {
        $error = $this->syntheticError(
            $this->nodeMessage('a', 'normal detail'),
            $this->nodeMessage("\0" . str_repeat('x', 300), 'bad', throwing: true),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('body() must not be called for discarded messages');

        $this->respondTo($error, new ErrorResponseOptions(maxMessages: 2, maxResponseBytes: 256));
    }

    #[Test]
    public function unlimitedAndMessageOnlyLimitsPreserveEncodedBody(): void
    {
        $error = $this->syntheticError(
            $this->nodeMessage('0', 'zero'),
            $this->nodeMessage('*root*', 'root'),
            $this->nodeMessage('0', 'second'),
        );

        self::assertSame(
            '{"error":"Mapping failed","messages":{"0":["zero","second"],"":["root"]}}',
            (string) $this->respondTo($error)->getBody(),
        );
        self::assertSame(
            '{"error":"Mapping failed","messages":{"0":["zero","second"],"":["root"]}}',
            (string) $this->respondTo($error, new ErrorResponseOptions(maxMessages: 3))->getBody(),
        );
        self::assertSame(
            '{"error":"Mapping failed","messages":{"0":["zero"],"":["root",'
            . '"Additional mapping errors were omitted."]}}',
            (string) $this->respondTo($error, new ErrorResponseOptions(maxMessages: 2))->getBody(),
        );
    }

    #[Test]
    public function exactEncodedSizeIsAccepted(): void
    {
        $error = $this->syntheticError($this->nodeMessage('a', str_repeat('x', 400)));

        $unlimited = (string) $this->respondTo($error)->getBody();
        $length    = strlen($unlimited);

        self::assertGreaterThan(256, $length);

        $body = (string) $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: $length))->getBody();

        self::assertSame($unlimited, $body);
    }

    #[Test]
    public function escapingCountsEncodedBytes(): void
    {
        $error = $this->syntheticError($this->nodeMessage('a', str_repeat('"\\' . "\n", 100)));

        $unlimited = (string) $this->respondTo($error)->getBody();
        $length    = strlen($unlimited);

        self::assertGreaterThan(256, $length);

        $rejected = (string) $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: $length - 1))->getBody();
        self::assertSame(
            ['Mapping error details exceed the response limit.'],
            json_decode($rejected, flags: JSON_THROW_ON_ERROR)->messages->{''},
        );

        $accepted = (string) $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: $length))->getBody();
        self::assertSame($unlimited, $accepted);
    }

    #[Test]
    public function longNumericAndNamedPathsRespectByteCap(): void
    {
        $error = $this->syntheticError(
            $this->nodeMessage('0', str_repeat('n', 200)),
            $this->nodeMessage('very.long.named.path', str_repeat('p', 200)),
        );

        $body = (string) $this->respondTo($error, new ErrorResponseOptions(maxResponseBytes: 256))->getBody();

        self::assertLessThanOrEqual(256, strlen($body));
        self::assertSame(
            ['Mapping error details exceed the response limit.'],
            json_decode($body, flags: JSON_THROW_ON_ERROR)->messages->{''},
        );
    }

    #[Test]
    public function inputErrorsRespectByteCap(): void
    {
        $response = $this->respondTo(
            RequestInputError::unsupportedParsedBody(),
            new ErrorResponseOptions(maxResponseBytes: 256),
        );

        $body = (string) $response->getBody();

        self::assertLessThanOrEqual(256, strlen($body));
        self::assertSame([
            'error'    => 'Mapping failed',
            'messages' => [
                '' => ['Parsed request body must be an array or null.'],
            ],
        ], json_decode($body, true, flags: JSON_THROW_ON_ERROR));
    }

    private function respondTo(MappingError|RequestInputError $error, ?ErrorResponseOptions $options = null): ResponseInterface
    {
        return (new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory(), $options))->respond(
            new MappingErrorContext(
                $error,
                new ServerRequest(),
                new MapRequest(body: RequiredRequest::class),
                RequiredRequest::class,
                'body',
                RequiredRequest::class,
            ),
        );
    }

    /**
     * @param array<mixed> $body
     */
    private function rootListError(array $body): MappingError
    {
        $mapper = (new MapperBuilder())->mapper();

        try {
            $mapper->map(RootIntegerList::class, new HttpRequest(bodyValues: $body));
        } catch (MappingError $error) {
            return $error;
        }

        self::fail('Expected mapping to fail.');
    }

    private function errorFromMessages(Messages $messages): MappingError
    {
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

    private function decode(ResponseInterface $response): stdClass
    {
        return json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
    }

    private function nodeMessage(string $path, string $body, bool $throwing = false): NodeMessage
    {
        $message = (static fn (): ErrorMessage => new class($body, $throwing) implements ErrorMessage {
            public function __construct(private readonly string $body, private readonly bool $throwing) {}

            public function body(): string
            {
                if ($this->throwing) {
                    throw new RuntimeException('body() must not be called for discarded messages');
                }

                return $this->body;
            }
        })();

        return new NodeMessage($message, $body, $path, $path, 'string', 'string', '');
    }

    private function syntheticError(NodeMessage ...$messages): MappingError
    {
        return $this->errorFromMessages(new Messages(...array_values($messages)));
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

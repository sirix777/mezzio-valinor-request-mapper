<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidatorInterface;
use stdClass;
use WeakReference;

use function gc_collect_cycles;
use function str_repeat;

final class HttpRequestSourceFactoryTest extends TestCase
{
    private HttpRequestSourceFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new HttpRequestSourceFactory(new InputEncodingValidator());
    }

    /** @param 'body'|'source' $source */
    #[Test]
    #[DataProvider('unsupportedParsedBodySources')]
    public function rejectsObjectParsedBodyForSourcesThatUseIt(string $source, object $parsedBody): void
    {
        $request = (new ServerRequest())->withParsedBody($parsedBody);

        try {
            $this->factory->create($request, [
                'id' => '42',
            ], $source);
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('unsupported_parsed_body', $error->reason);
            self::assertSame('body', $error->inputSource);
            self::assertSame('Parsed request body must be an array or null.', $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, object}>
     */
    public static function unsupportedParsedBodySources(): iterable
    {
        yield 'stdClass body' => ['body', new stdClass()];

        yield 'stdClass source' => ['source', new stdClass()];

        yield 'custom object body' => ['body', new class {}];

        yield 'custom object source' => ['source', new class {}];
    }

    #[Test]
    public function turnsNullParsedBodyIntoEmptyValuesOnlyForMapping(): void
    {
        $request = new ServerRequest();

        $httpRequest = $this->factory->create($request, [
            'id' => '42',
        ], 'source');

        self::assertSame([], $httpRequest->bodyValues);
        self::assertNull($request->getParsedBody());
        self::assertSame($request, $httpRequest->requestObject);
        self::assertSame([
            'id' => '42',
        ], $httpRequest->routeParameters);
    }

    #[Test]
    public function usesTheOriginalArrayParsedBodyWithoutChangingOtherSources(): void
    {
        $request = (new ServerRequest())
            ->withParsedBody([
                'name' => 'Ada',
            ])
            ->withQueryParams([
                'page' => '2',
            ])
        ;

        $httpRequest = $this->factory->create($request, [
            'id' => '42',
        ], 'source');

        self::assertSame([
            'name' => 'Ada',
        ], $httpRequest->bodyValues);
        self::assertSame([
            'page' => '2',
        ], $httpRequest->queryParameters);
        self::assertSame([
            'id' => '42',
        ], $httpRequest->routeParameters);
        self::assertSame([
            'name' => 'Ada',
        ], $request->getParsedBody());
    }

    /** @param 'query'|'route' $source */
    #[Test]
    #[DataProvider('sourcesThatDoNotUseParsedBody')]
    public function doesNotReadUnsupportedParsedBodyForIndependentSources(string $source): void
    {
        $request = (new ServerRequest())
            ->withParsedBody(new stdClass())
            ->withQueryParams([
                'page' => '2',
            ])
        ;

        $httpRequest = $this->factory->create($request, [
            'id' => '42',
        ], $source);

        self::assertSame([], $httpRequest->bodyValues);
        self::assertSame($request, $httpRequest->requestObject);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sourcesThatDoNotUseParsedBody(): iterable
    {
        yield 'query' => ['query'];

        yield 'route' => ['route'];
    }

    #[Test]
    public function rejectsInvalidUtf8InBodyValues(): void
    {
        $request = (new ServerRequest())->withParsedBody([
            'name' => "\xB1\x31",
        ]);

        try {
            $this->factory->create($request, [], 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('invalid_utf8', $error->reason);
            self::assertSame('body', $error->inputSource);
        }
    }

    #[Test]
    public function rejectsInvalidUtf8InQueryParameters(): void
    {
        $request = (new ServerRequest())->withQueryParams([
            'page' => "\xB1\x31",
        ]);

        try {
            $this->factory->create($request, [], 'query');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('invalid_utf8', $error->reason);
            self::assertSame('query', $error->inputSource);
        }
    }

    #[Test]
    public function rejectsInvalidUtf8InRouteParameters(): void
    {
        $request = new ServerRequest();

        try {
            $this->factory->create($request, [
                'name' => "\xB1\x31",
            ], 'route');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('invalid_utf8', $error->reason);
            self::assertSame('route', $error->inputSource);
        }
    }

    #[Test]
    public function validatesSourceModeInRouteQueryBodyOrder(): void
    {
        $request = (new ServerRequest())
            ->withQueryParams([
                'page' => "\xB1\x31",
            ])
            ->withParsedBody([
                'name' => "\xB1\x31",
            ])
        ;

        try {
            $this->factory->create($request, [
                'name' => "\xB1\x31",
            ], 'source');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('invalid_utf8', $error->reason);
            self::assertSame('route', $error->inputSource);
        }
    }

    #[Test]
    public function unsupportedParsedBodyIsReportedBeforeEncodingValidation(): void
    {
        $request = (new ServerRequest())->withParsedBody(new stdClass());

        try {
            $this->factory->create($request, [], 'body');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('unsupported_parsed_body', $error->reason);
            self::assertSame('body', $error->inputSource);
        }
    }

    #[Test]
    public function querySourceDoesNotValidateAnIndependentBody(): void
    {
        $request = (new ServerRequest())
            ->withParsedBody([
                'name' => "\xB1\x31",
            ])
            ->withQueryParams([
                'page' => '2',
            ])
        ;

        $httpRequest = $this->factory->create($request, [], 'query');

        self::assertSame([
            'page' => '2',
        ], $httpRequest->queryParameters);
        self::assertSame([], $httpRequest->bodyValues);
    }

    #[Test]
    public function bodySourceDoesNotValidateAnIndependentQuery(): void
    {
        $request = (new ServerRequest())
            ->withParsedBody([
                'name' => 'Ada',
            ])
            ->withQueryParams([
                'page' => "\xB1\x31",
            ])
        ;

        $httpRequest = $this->factory->create($request, [], 'body');

        self::assertSame([
            'name' => 'Ada',
        ], $httpRequest->bodyValues);
        self::assertSame([], $httpRequest->queryParameters);
    }

    #[Test]
    public function contextValidatesEachSourceOnlyOnceAndCreatesFreshHttpRequests(): void
    {
        $validator = new CountingInputEncodingValidator();
        $factory   = new HttpRequestSourceFactory($validator);
        $request   = new CountingServerRequest(
            queryParams: [
                'page' => '2',
            ],
            parsedBody: [
                'name' => 'Ada',
            ],
        );
        $context = $factory->createContext($request, [
            'id' => '42',
        ]);

        $first  = $context->create($request, 'body');
        $second = $context->create($request, 'body');
        $third  = $context->create($request, 'body');
        $source = $context->create($request, 'source');

        self::assertNotSame($first, $second);
        self::assertNotSame($second, $third);
        self::assertSame($request, $first->requestObject);
        self::assertSame($request, $second->requestObject);
        self::assertSame($request, $source->requestObject);
        self::assertSame(1, $request->parsedBodyReads);
        self::assertSame(1, $request->queryParameterReads);
        self::assertSame([
            'body'  => 1,
            'route' => 1,
            'query' => 1,
        ], $validator->calls);
    }

    #[Test]
    public function contextRetainsSourceErrorPriority(): void
    {
        $factory = new HttpRequestSourceFactory(new InputEncodingValidator());
        $request = (new ServerRequest())
            ->withQueryParams([
                'page' => "\xB1\x31",
            ])
            ->withParsedBody([
                'name' => "\xB1\x31",
            ])
        ;

        try {
            $factory->createContext($request, [
                'id' => "\xB1\x31",
            ])->create($request, 'source');
            self::fail('Expected RequestInputError to be thrown.');
        } catch (RequestInputError $error) {
            self::assertSame('route', $error->inputSource);
        }
    }

    #[Test]
    public function contextDoesNotCacheAnInputThatFailedValidation(): void
    {
        $validator = new CountingInputEncodingValidator();
        $factory   = new HttpRequestSourceFactory($validator);
        $request   = (new ServerRequest())->withQueryParams([
            'page' => "\xB1\x31",
        ]);
        $context = $factory->createContext($request, []);

        foreach ([1, 2] as $_) {
            try {
                $context->create($request, 'query');
                self::fail('Expected RequestInputError to be thrown.');
            } catch (RequestInputError $error) {
                self::assertSame('invalid_utf8', $error->reason);
                self::assertSame('query', $error->inputSource);
            }
        }

        self::assertSame([
            'query' => 2,
        ], $validator->calls);
    }

    #[Test]
    public function factoryDoesNotRetainACompletedContextOrRequest(): void
    {
        $factory = new HttpRequestSourceFactory(new InputEncodingValidator());
        $request = (new ServerRequest())->withParsedBody([
            'name' => str_repeat('x', 10000),
        ]);
        $reference   = WeakReference::create($request);
        $context     = $factory->createContext($request, []);
        $httpRequest = $context->create($request, 'body');

        unset($httpRequest, $context, $request);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }
}

final class CountingServerRequest extends ServerRequest
{
    public int $parsedBodyReads = 0;

    public int $queryParameterReads = 0;

    /** @return null|array<mixed>|object */
    public function getParsedBody(): mixed
    {
        ++$this->parsedBodyReads;

        return parent::getParsedBody();
    }

    /** @return array<mixed> */
    public function getQueryParams(): array
    {
        ++$this->queryParameterReads;

        return parent::getQueryParams();
    }
}

final class CountingInputEncodingValidator implements InputEncodingValidatorInterface
{
    /** @var array<'body'|'query'|'route', int> */
    public array $calls = [];

    private readonly InputEncodingValidator $inner;

    public function __construct()
    {
        $this->inner = new InputEncodingValidator();
    }

    public function assertValid(array $values, string $inputSource): void
    {
        $this->calls[$inputSource] = ($this->calls[$inputSource] ?? 0) + 1;
        $this->inner->assertValid($values, $inputSource);
    }
}

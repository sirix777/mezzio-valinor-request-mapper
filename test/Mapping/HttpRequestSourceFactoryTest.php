<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Mapping;

use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use stdClass;

final class HttpRequestSourceFactoryTest extends TestCase
{
    /** @param 'body'|'source' $source */
    #[Test]
    #[DataProvider('unsupportedParsedBodySources')]
    public function rejectsObjectParsedBodyForSourcesThatUseIt(string $source, object $parsedBody): void
    {
        $request = (new ServerRequest())->withParsedBody($parsedBody);

        try {
            (new HttpRequestSourceFactory())->create($request, [
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

        $httpRequest = (new HttpRequestSourceFactory())->create($request, [
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

        $httpRequest = (new HttpRequestSourceFactory())->create($request, [
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

        $httpRequest = (new HttpRequestSourceFactory())->create($request, [
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
}

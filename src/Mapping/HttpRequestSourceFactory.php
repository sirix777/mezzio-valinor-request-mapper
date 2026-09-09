<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use CuyZ\Valinor\Mapper\Http\HttpRequest;
use Psr\Http\Message\ServerRequestInterface;
use Sirix\Mezzio\Valinor\Error\RequestInputError;

use function is_array;

/**
 * @internal
 */
final readonly class HttpRequestSourceFactory
{
    public function __construct(private InputEncodingValidator $inputEncodingValidator) {}

    /**
     * @param array<string, mixed>            $routeParams
     * @param 'body'|'query'|'route'|'source' $source
     */
    public function create(ServerRequestInterface $request, array $routeParams, string $source): HttpRequest
    {
        return match ($source) {
            'body'   => $this->createBodyRequest($request),
            'query'  => $this->createQueryRequest($request),
            'route'  => $this->createRouteRequest($request, $routeParams),
            'source' => $this->createSourceRequest($request, $routeParams),
        };
    }

    private function createBodyRequest(ServerRequestInterface $request): HttpRequest
    {
        $bodyValues = $this->bodyValues($request);
        $this->inputEncodingValidator->assertValid($bodyValues, 'body');

        return new HttpRequest(
            bodyValues: $bodyValues,
            requestObject: $request,
        );
    }

    private function createQueryRequest(ServerRequestInterface $request): HttpRequest
    {
        $queryParameters = $request->getQueryParams();
        $this->inputEncodingValidator->assertValid($queryParameters, 'query');

        return new HttpRequest(
            queryParameters: $queryParameters,
            requestObject: $request,
        );
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    private function createRouteRequest(ServerRequestInterface $request, array $routeParams): HttpRequest
    {
        $this->inputEncodingValidator->assertValid($routeParams, 'route');

        return new HttpRequest(
            routeParameters: $routeParams,
            requestObject: $request,
        );
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    private function createSourceRequest(ServerRequestInterface $request, array $routeParams): HttpRequest
    {
        $this->inputEncodingValidator->assertValid($routeParams, 'route');

        $queryParameters = $request->getQueryParams();
        $this->inputEncodingValidator->assertValid($queryParameters, 'query');

        $bodyValues = $this->bodyValues($request);
        $this->inputEncodingValidator->assertValid($bodyValues, 'body');

        return new HttpRequest(
            routeParameters: $routeParams,
            queryParameters: $queryParameters,
            bodyValues: $bodyValues,
            requestObject: $request,
        );
    }

    /**
     * @return array<mixed>
     */
    private function bodyValues(ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();

        if (null !== $parsedBody && ! is_array($parsedBody)) {
            throw RequestInputError::unsupportedParsedBody();
        }

        return $parsedBody ?? [];
    }
}

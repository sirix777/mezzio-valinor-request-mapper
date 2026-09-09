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
final class HttpRequestSourceFactory
{
    /**
     * @param array<string, mixed>            $routeParams
     * @param 'body'|'query'|'route'|'source' $source
     */
    public function create(ServerRequestInterface $request, array $routeParams, string $source): HttpRequest
    {
        $bodyValues = match ($source) {
            'body', 'source' => $this->bodyValues($request),
            default          => [],
        };

        return match ($source) {
            'body'   => new HttpRequest(
                bodyValues: $bodyValues,
                requestObject: $request,
            ),
            'query'  => new HttpRequest(
                queryParameters: $request->getQueryParams(),
                requestObject: $request,
            ),
            'route'  => new HttpRequest(
                routeParameters: $routeParams,
                requestObject: $request,
            ),
            'source' => new HttpRequest(
                routeParameters: $routeParams,
                queryParameters: $request->getQueryParams(),
                bodyValues: $bodyValues,
                requestObject: $request,
            ),
        };
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

<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use CuyZ\Valinor\Mapper\Http\HttpRequest;
use Psr\Http\Message\ServerRequestInterface;
use Sirix\Mezzio\Valinor\Error\RequestInputError;

use function is_array;

/**
 * A lazily populated input snapshot for exactly one middleware invocation.
 *
 * @internal
 */
final class HttpRequestSourceContext
{
    /** @var null|array<mixed> */
    private ?array $bodyValues = null;

    /** @var null|array<mixed> */
    private ?array $queryParameters = null;

    /** @var null|array<string, mixed> */
    private ?array $validatedRouteParams = null;

    /** @param array<string, mixed> $routeParams */
    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly array $routeParams,
        private readonly InputEncodingValidator $inputEncodingValidator,
    ) {}

    /** @param 'body'|'query'|'route'|'source' $source */
    public function create(ServerRequestInterface $request, string $source): HttpRequest
    {
        return match ($source) {
            'body'   => new HttpRequest(bodyValues: $this->bodyValues(), requestObject: $request),
            'query'  => new HttpRequest(queryParameters: $this->queryParameters(), requestObject: $request),
            'route'  => new HttpRequest(routeParameters: $this->routeParameters(), requestObject: $request),
            'source' => new HttpRequest(
                routeParameters: $this->routeParameters(),
                queryParameters: $this->queryParameters(),
                bodyValues: $this->bodyValues(),
                requestObject: $request,
            ),
        };
    }

    /** @return array<mixed> */
    private function bodyValues(): array
    {
        if (null !== $this->bodyValues) {
            return $this->bodyValues;
        }

        $parsedBody = $this->request->getParsedBody();

        if (null !== $parsedBody && ! is_array($parsedBody)) {
            throw RequestInputError::unsupportedParsedBody();
        }

        $this->bodyValues = $parsedBody ?? [];
        $this->inputEncodingValidator->assertValid($this->bodyValues, 'body');

        return $this->bodyValues;
    }

    /** @return array<mixed> */
    private function queryParameters(): array
    {
        if (null === $this->queryParameters) {
            $this->queryParameters = $this->request->getQueryParams();
            $this->inputEncodingValidator->assertValid($this->queryParameters, 'query');
        }

        return $this->queryParameters;
    }

    /** @return array<string, mixed> */
    private function routeParameters(): array
    {
        if (null === $this->validatedRouteParams) {
            $this->validatedRouteParams = $this->routeParams;
            $this->inputEncodingValidator->assertValid($this->validatedRouteParams, 'route');
        }

        return $this->validatedRouteParams;
    }
}

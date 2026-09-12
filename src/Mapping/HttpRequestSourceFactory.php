<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Mapping;

use CuyZ\Valinor\Mapper\Http\HttpRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
final readonly class HttpRequestSourceFactory
{
    public function __construct(private InputEncodingValidatorInterface $inputEncodingValidator) {}

    /**
     * @param array<string, mixed> $routeParams
     */
    public function createContext(ServerRequestInterface $request, array $routeParams): HttpRequestSourceContext
    {
        return new HttpRequestSourceContext($request, $routeParams, $this->inputEncodingValidator);
    }

    /**
     * @param array<string, mixed>            $routeParams
     * @param 'body'|'query'|'route'|'source' $source
     */
    public function create(ServerRequestInterface $request, array $routeParams, string $source): HttpRequest
    {
        return $this->createContext($request, $routeParams)->create($request, $source);
    }
}

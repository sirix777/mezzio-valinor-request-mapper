<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Middleware;

use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Mapping\MappingOperation;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;

final readonly class ValinorRequestMapperMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TreeMapper $mapper,
        private MappingErrorResponderResolver $errorResponderResolver,
        private MappingPlanResolver $mappingPlanResolver,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class);

        if (! $routeResult instanceof RouteResult) {
            return $handler->handle($request);
        }

        $operations = $this->mappingPlanResolver->resolve($routeResult, $request->getMethod());

        if ([] === $operations) {
            return $handler->handle($request);
        }

        $routeParams = $routeResult->getMatchedParams();

        foreach ($operations as $operation) {
            try {
                $httpRequest = $this->createHttpRequest($request, $routeParams, $operation);
                $dto         = $this->mapper->map($operation->dtoClass, $httpRequest);
                $request     = $request->withAttribute($operation->requestAttributeKey, $dto);
            } catch (MappingError $e) {
                return $this->errorResponderResolver
                    ->resolve($operation->mapRequest->errorResponder)
                    ->respond(new MappingErrorContext(
                        $e,
                        $request,
                        $operation->mapRequest,
                        $operation->dtoClass,
                        $operation->source,
                        $operation->requestAttributeKey,
                    ))
                ;
            }
        }

        return $handler->handle($request);
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    private function createHttpRequest(ServerRequestInterface $request, array $routeParams, MappingOperation $operation): HttpRequest
    {
        if ('source' === $operation->source) {
            return HttpRequest::fromPsr($request, $routeParams);
        }

        if ('body' === $operation->source) {
            return new HttpRequest(
                bodyValues: (array) $request->getParsedBody(),
                requestObject: $request,
            );
        }

        if ('query' === $operation->source) {
            return new HttpRequest(
                queryParameters: $request->getQueryParams(),
                requestObject: $request,
            );
        }

        return new HttpRequest(
            routeParameters: $routeParams,
            requestObject: $request,
        );
    }
}

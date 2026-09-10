<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Middleware;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\Mapper\TreeMapper;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;

final readonly class ValinorRequestMapperMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TreeMapper $mapper,
        private MappingErrorResponderResolver $errorResponderResolver,
        private MappingPlanResolver $mappingPlanResolver,
        private HttpRequestSourceFactory $httpRequestSourceFactory,
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

        $routeParams  = $routeResult->getMatchedParams();
        $inputContext = $this->httpRequestSourceFactory->createContext($request, $routeParams);

        foreach ($operations as $operation) {
            try {
                $httpRequest = $inputContext->create($request, $operation->source);
                $dto         = $this->mapper->map($operation->dtoClass, $httpRequest);
                $request     = $request->withAttribute($operation->requestAttributeKey, $dto);
            } catch (MappingError|RequestInputError $e) {
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
}

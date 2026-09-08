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
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\MappingErrorContext;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;

use function in_array;

final readonly class ValinorRequestMapperMiddleware implements MiddlewareInterface
{
    private HttpMethodNormalizer $httpMethodNormalizer;

    public function __construct(
        private TreeMapper $mapper,
        private MappingErrorResponderResolver $errorResponderResolver,
        private MapRequestResolver $mapRequestResolver,
    ) {
        $this->httpMethodNormalizer = new HttpMethodNormalizer();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeResult = $request->getAttribute(RouteResult::class);

        if (! $routeResult instanceof RouteResult) {
            return $handler->handle($request);
        }

        $mapRequests = $this->resolveMapRequests($routeResult, $request->getMethod());

        if ([] === $mapRequests) {
            return $handler->handle($request);
        }

        $routeParams = $routeResult->getMatchedParams();

        foreach ($mapRequests as $mapRequest) {
            try {
                if (null !== $mapRequest->source) {
                    $dtoClass            = $mapRequest->source;
                    $source              = 'source';
                    $requestAttributeKey = $mapRequest->output ?? $dtoClass;
                    $httpRequest         = HttpRequest::fromPsr($request, $routeParams);
                    $dto                 = $this->mapper->map($dtoClass, $httpRequest);
                    $request             = $request->withAttribute($requestAttributeKey, $dto);

                    continue;
                }

                if (null !== $mapRequest->body) {
                    $dtoClass            = $mapRequest->body;
                    $source              = 'body';
                    $requestAttributeKey = $mapRequest->output ?? $dtoClass;
                    $dto                 = $this->mapper->map(
                        $dtoClass,
                        new HttpRequest(
                            bodyValues: (array) $request->getParsedBody(),
                            requestObject: $request,
                        ),
                    );
                    $request = $request->withAttribute($requestAttributeKey, $dto);
                }

                if (null !== $mapRequest->query) {
                    $dtoClass            = $mapRequest->query;
                    $source              = 'query';
                    $requestAttributeKey = $mapRequest->output ?? $dtoClass;
                    $dto                 = $this->mapper->map(
                        $dtoClass,
                        new HttpRequest(
                            queryParameters: $request->getQueryParams(),
                            requestObject: $request,
                        ),
                    );
                    $request = $request->withAttribute($requestAttributeKey, $dto);
                }

                if (null !== $mapRequest->route) {
                    $dtoClass            = $mapRequest->route;
                    $source              = 'route';
                    $requestAttributeKey = $mapRequest->output ?? $dtoClass;
                    $dto                 = $this->mapper->map(
                        $dtoClass,
                        new HttpRequest(
                            routeParameters: $routeParams,
                            requestObject: $request,
                        ),
                    );
                    $request = $request->withAttribute($requestAttributeKey, $dto);
                }
            } catch (MappingError $e) {
                return $this->errorResponderResolver
                    ->resolve($mapRequest->errorResponder)
                    ->respond(new MappingErrorContext(
                        $e,
                        $request,
                        $mapRequest,
                        $dtoClass,
                        $source,
                        $requestAttributeKey,
                    ))
                ;
            }
        }

        return $handler->handle($request);
    }

    /**
     * @return list<MapRequest>
     */
    private function resolveMapRequests(RouteResult $routeResult, string $httpMethod): array
    {
        return $this->filterByMethod(
            $this->mapRequestResolver->resolve($routeResult),
            $httpMethod,
        );
    }

    /**
     * @param list<MapRequest> $mapRequests
     *
     * @return list<MapRequest>
     */
    private function filterByMethod(array $mapRequests, string $httpMethod): array
    {
        $httpMethod = $this->httpMethodNormalizer->normalize($httpMethod);
        $result     = [];

        foreach ($mapRequests as $mapRequest) {
            if ([] === $mapRequest->methods || in_array($httpMethod, $mapRequest->methods, true)) {
                $result[] = $mapRequest;
            }
        }

        return $result;
    }
}

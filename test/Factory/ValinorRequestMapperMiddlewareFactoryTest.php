<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Factory;

use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Factory\ValinorRequestMapperMiddlewareFactory;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;

use function json_decode;

final class ValinorRequestMapperMiddlewareFactoryTest extends TestCase
{
    #[Test]
    public function usesTreeMapperServiceWhenRegistered(): void
    {
        $treeMapper = (new MapperBuilder())
            ->allowScalarValueCasting()
            ->mapper()
        ;
        $container = $this->createContainer([
            'config' => [
                'sirix_mezzio_valinor' => [
                    'mapper' => [
                        'allow_scalar_value_casting' => false,
                    ],
                ],
            ],
            TreeMapper::class => $treeMapper,
        ]);

        $middleware = (new ValinorRequestMapperMiddlewareFactory())($container);

        $handler = new #[MapRequest(body: RequiredRequest::class)]
        class implements MiddlewareInterface, RequestHandlerInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->handle($request);
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(
                    $request->getAttribute(RequiredRequest::class),
                );
            }
        };

        $request = (new ServerRequest())
            ->withParsedBody(['name' => 123])
            ->withMethod('POST')
            ->withAttribute(
                RouteResult::class,
                RouteResult::fromRoute(new Route('/example', $handler, ['POST']), []),
            )
        ;

        $response = $middleware->process($request, $this->nextHandler($handler));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame('123', $body['name']);
    }

    /**
     * @param array<string, mixed> $services
     */
    private function createContainer(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            /**
             * @param array<string, mixed> $services
             */
            public function __construct(private readonly array $services) {}

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new RuntimeException("Service not found: {$id}");
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }

    private function nextHandler(RequestHandlerInterface $routeHandler): RequestHandlerInterface
    {
        return new class($routeHandler) implements RequestHandlerInterface {
            public function __construct(private readonly RequestHandlerInterface $handler) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->handler->handle($request);
            }
        };
    }
}

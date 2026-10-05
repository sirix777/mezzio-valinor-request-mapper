<?php

declare(strict_types=1);

namespace Sirix\Mezzio\Valinor\Test\Integration;

use CuyZ\Valinor\MapperBuilder;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\ErrorResponseOptions;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\InputLimits;
use Sirix\Mezzio\Valinor\Test\Error\Fixture\RootIntegerList;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequestMapperMiddlewareBuilder;
use Sirix\Mezzio\Valinor\Test\Middleware\Fixture\RequiredRequest;
use stdClass;

use function array_fill;
use function array_keys;
use function array_map;
use function get_object_vars;
use function json_decode;
use function strlen;

final class AlternatePsrImplementationTest extends TestCase
{
    #[Test]
    public function nyholmSuccessfulMappingPreservesOriginalRequest(): void
    {
        [$original, $handler, $response] = $this->map([
            'name' => 'Ada',
        ], RequiredRequest::class);

        self::assertNull($original->getAttribute('mapped'));
        self::assertNotNull($handler->request);
        self::assertNotSame($original, $handler->request);
        $dto = $handler->request->getAttribute('mapped');
        self::assertInstanceOf(RequiredRequest::class, $dto);
        self::assertSame('Ada', $dto->name);
        self::assertSame('{"name":"Ada"}', (string) $original->getBody());
        self::assertSame('{"name":"Ada"}', (string) $handler->request->getBody());
        self::assertSame('retained', $handler->request->getHeaderLine('X-Request'));
        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function nyholmNumericErrorMessagesAreObjects(): void
    {
        [, $handler, $response] = $this->map(['bad', 'worse'], RootIntegerList::class);
        $json                   = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $json->messages);
        self::assertSame(['0', '1'], array_map(strval(...), array_keys(get_object_vars($json->messages))));
        self::assertIsArray($json->messages->{'0'});
        self::assertNotEmpty($json->messages->{'0'});
        self::assertIsString($json->messages->{'0'}[0]);
        self::assertSame('Mapping failed', $json->error);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertNull($handler->request);
    }

    #[Test]
    public function nyholmInputLimitUsesStandardResponder(): void
    {
        [, $handler, $response] = $this->map(
            [
                'name'  => 'Ada',
                'extra' => 'ignored',
            ],
            RequiredRequest::class,
            new InputLimits(maxNodes: 1),
        );
        $json = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $json->messages);
        self::assertSame(['Request input exceeds the node limit.'], $json->messages->{''});
        self::assertSame('Mapping failed', $json->error);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertNull($handler->request);
    }

    #[Test]
    public function nyholmResponseLimitIsEnforced(): void
    {
        [, $handler, $response] = $this->map(
            array_fill(0, 20, 'invalid'),
            RootIntegerList::class,
            options: new ErrorResponseOptions(maxResponseBytes: 256),
        );
        $body = (string) $response->getBody();
        $json = json_decode($body, flags: JSON_THROW_ON_ERROR);

        self::assertLessThanOrEqual(256, strlen($body));
        self::assertInstanceOf(stdClass::class, $json->messages);
        self::assertSame(['Mapping error details exceed the response limit.'], $json->messages->{''});
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertNull($handler->request);
    }

    /**
     * @param array<mixed> $body
     * @param class-string $dtoClass
     *
     * @return array{ServerRequestInterface, AlternatePsrCaptureHandler, ResponseInterface}
     */
    private function map(array $body, string $dtoClass, ?InputLimits $limits = null, ?ErrorResponseOptions $options = null): array
    {
        $factory = new Psr17Factory();
        $handler = new AlternatePsrCaptureHandler($factory);
        $route   = new Route('/mapped', new RequestHandlerMiddleware($handler), ['POST']);
        $route->setOptions([
            'valinor_mappings' => [[
                'body'   => $dtoClass,
                'output' => 'mapped',
            ]],
        ]);
        $request = $factory->createServerRequest('POST', '/mapped')
            ->withHeader('X-Request', 'retained')
            ->withBody($factory->createStream('{"name":"Ada"}'))
            ->withParsedBody($body)
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
        ;
        $container = new class implements ContainerInterface {
            public function get($id): mixed
            {
                throw new RuntimeException('Unexpected service resolution.');
            }

            public function has($id): bool
            {
                return false;
            }
        };
        $middleware = RequestMapperMiddlewareBuilder::build(
            (new MapperBuilder())->mapper(),
            new DefaultMappingErrorResponder($factory, $factory, $options),
            $container,
            self::class,
            new HttpRequestSourceFactory(new InputEncodingValidator($limits)),
        );

        return [$request, $handler, $middleware->process($request, $handler)];
    }
}

final class AlternatePsrCaptureHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    public function __construct(private readonly Psr17Factory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return $this->factory->createResponse(204);
    }
}

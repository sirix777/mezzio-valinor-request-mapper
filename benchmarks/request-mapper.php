<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CuyZ\Valinor\Cache\FileSystemCache;
use CuyZ\Valinor\Mapper\Http\HttpRequest;
use CuyZ\Valinor\Mapper\TreeMapper;
use CuyZ\Valinor\MapperBuilder;
use Fig\Http\Message\RequestMethodInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Mezzio\Middleware\LazyLoadingMiddleware;
use Mezzio\MiddlewareContainer;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\ErrorResponseOptions;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\InputLimits;
use Sirix\Mezzio\Valinor\Mapping\MappingPlanResolver;
use Sirix\Mezzio\Valinor\Mapping\MapRequestOptionsParser;
use Sirix\Mezzio\Valinor\Mapping\MapRequestResolver;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

final readonly class BenchmarkBodyRequest
{
    public function __construct(public string $name) {}
}

final readonly class BenchmarkQueryRequest
{
    public function __construct(public int $page) {}
}

final readonly class BenchmarkRouteRequest
{
    public function __construct(public string $id) {}
}

final readonly class BenchmarkLargeBodyRequest
{
    public function __construct(public string $name) {}
}

final readonly class BenchmarkLargeSourceRequest
{
    public function __construct(public string $id, public string $page, public string $name) {}
}

#[MapRequest(body: BenchmarkBodyRequest::class)]
final class BenchmarkDirectHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse();
    }
}

#[MapRequest(body: BenchmarkBodyRequest::class)]
final class BenchmarkLazyHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse();
    }
}

#[MapRequest(body: BenchmarkBodyRequest::class, output: 'body')]
#[MapRequest(query: BenchmarkQueryRequest::class, output: 'query')]
#[MapRequest(route: BenchmarkRouteRequest::class, output: 'route')]
final class BenchmarkMultiHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse();
    }
}

#[MapRequest(body: BenchmarkLargeBodyRequest::class)]
final class BenchmarkLargeBodyHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse();
    }
}

#[MapRequest(body: BenchmarkLargeBodyRequest::class, output: 'first')]
#[MapRequest(body: BenchmarkLargeBodyRequest::class, output: 'second')]
#[MapRequest(body: BenchmarkLargeBodyRequest::class, output: 'third')]
final class BenchmarkThreeLargeBodyHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse();
    }
}

#[MapRequest(body: BenchmarkLargeBodyRequest::class, output: 'body')]
#[MapRequest(source: BenchmarkLargeSourceRequest::class, output: 'source')]
final class BenchmarkBodyAndSourceHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse();
    }
}

/** @return array{0: ValinorRequestMapperMiddleware, 1: TreeMapper} */
function createServices(?string $cacheDir = null, ?InputLimits $inputLimits = null, ?ErrorResponseOptions $errorOptions = null): array
{
    $builder = (new MapperBuilder())->allowSuperfluousKeys()->allowScalarValueCasting();

    if (null !== $cacheDir) {
        $builder = $builder->withCache(new FileSystemCache($cacheDir));
    }

    $treeMapper = $builder->mapper();

    $responseFactory  = new ResponseFactory();
    $streamFactory    = new StreamFactory();
    $defaultResponder = $errorOptions instanceof ErrorResponseOptions
        ? new DefaultMappingErrorResponder($responseFactory, $streamFactory, $errorOptions)
        : new DefaultMappingErrorResponder($responseFactory, $streamFactory);

    $container = new class($defaultResponder) implements ContainerInterface {
        /** @var array<class-string, MappingErrorResponderInterface> */
        private readonly array $services;

        public function __construct(MappingErrorResponderInterface $defaultResponder)
        {
            $this->services = [
                DefaultMappingErrorResponder::class   => $defaultResponder,
                MappingErrorResponderInterface::class => $defaultResponder,
            ];
        }

        /** @param string $id */
        public function get($id): mixed
        {
            if (\array_key_exists($id, $this->services)) {
                return $this->services[$id];
            }

            throw new RuntimeException("Service not found: {$id}");
        }

        /** @param string $id */
        public function has($id): bool
        {
            return \array_key_exists($id, $this->services);
        }
    };

    $responderResolver = new MappingErrorResponderResolver(
        $defaultResponder,
        ContainerResolver::forFactory($container, ValinorRequestMapperMiddleware::class),
    );

    $planResolver = new MappingPlanResolver(
        new MapRequestResolver(new HandlerTargetResolver(), new MapRequestOptionsParser()),
        new HttpMethodNormalizer(),
    );

    $validator     = $inputLimits instanceof InputLimits ? new InputEncodingValidator($inputLimits) : new InputEncodingValidator();
    $sourceFactory = new HttpRequestSourceFactory($validator);

    return [
        new ValinorRequestMapperMiddleware($treeMapper, $responderResolver, $planResolver, $sourceFactory),
        $treeMapper,
    ];
}

function passthroughHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new EmptyResponse();
        }
    };
}

/**
 * @param array<string, mixed>  $body
 * @param array<string, string> $query
 * @param array<string, string> $routeParams
 */
function makeRequest(string $method, array $body = [], array $query = [], array $routeParams = []): ServerRequestInterface
{
    $request = (new ServerRequest())
        ->withMethod($method)
        ->withParsedBody($body)
        ->withQueryParams($query)
    ;

    if ([] !== $routeParams) {
        return $request->withAttribute(RouteResult::class, RouteResult::fromRoute(
            new Route('/example', new RequestHandlerMiddleware(\passthroughHandler()), [$method]),
            $routeParams,
        ));
    }

    return $request;
}

/** @return array<string, string> */
function largeFlatPayload(): array
{
    $payload = [
        'name' => 'Ada',
    ];

    for ($field = 0; $field < 10000; ++$field) {
        $payload['field_' . $field] = 'value-' . $field;
    }

    return $payload;
}

/** @return array<string, array<string, string>|string> */
function largeNestedPayload(): array
{
    $payload = [
        'name' => 'Ada',
    ];

    for ($group = 0; $group < 200; ++$group) {
        $values = [];

        for ($field = 0; $field < 100; ++$field) {
            $values['field_' . $field] = 'value-' . $group . '-' . $field;
        }

        $payload['group_' . $group] = $values;
    }

    return $payload;
}

/**
 * @param array<mixed> $payload
 *
 * @return array{fields: int, strings: int, approximate_payload_bytes: int}
 */
function payloadMetadata(array $payload): array
{
    $fields  = 0;
    $strings = 0;
    $stack   = [$payload];

    while ([] !== $stack) {
        $values = \array_pop($stack);

        foreach ($values as $value) {
            ++$fields;

            if (\is_string($value)) {
                ++$strings;

                continue;
            }

            if (\is_array($value)) {
                $stack[] = $value;
            }
        }
    }

    return [
        'fields'                    => $fields,
        'strings'                   => $strings,
        'approximate_payload_bytes' => \strlen(\json_encode($payload, JSON_THROW_ON_ERROR)),
    ];
}

/**
 * @param array<mixed>          $body
 * @param array<string, string> $query
 * @param array<string, string> $routeParams
 *
 * @return array<string, mixed>
 */
function largePayloadScenario(
    string $name,
    RequestHandlerInterface $handler,
    array $body,
    int $iterations,
    int $warmup,
    int $samples,
    array $query = [],
    array $routeParams = [],
): array {
    [$middleware] = \createServices();
    $route        = new Route('/example/{id}', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
    $request      = (new ServerRequest())
        ->withMethod(RequestMethodInterface::METHOD_POST)
        ->withParsedBody($body)
        ->withQueryParams($query)
        ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, $routeParams))
    ;

    $expected = match (true) {
        $handler instanceof BenchmarkThreeLargeBodyHandler => [
            'first'  => new BenchmarkLargeBodyRequest('Ada'),
            'second' => new BenchmarkLargeBodyRequest('Ada'),
            'third'  => new BenchmarkLargeBodyRequest('Ada'),
        ],
        $handler instanceof BenchmarkBodyAndSourceHandler  => [
            'body'   => new BenchmarkLargeBodyRequest('Ada'),
            'source' => new BenchmarkLargeSourceRequest('42', '2', 'Ada'),
        ],
        default                                            => [
            BenchmarkLargeBodyRequest::class => new BenchmarkLargeBodyRequest('Ada'),
        ],
    };

    return \array_merge(
        [
            'name'      => $name,
            'lifecycle' => 'reuse',
            'input'     => \payloadMetadata($body),
        ],
        \measureMappedScenario($middleware, $request, $handler, $expected, $iterations, $warmup, $samples),
    );
}

/**
 * Narrow validator/context benchmark. It measures only
 * HttpRequestSourceFactory::create() — UTF-8 traversal plus HttpRequest
 * construction — without Valinor mapping, so the cost of input validation is
 * isolated from TreeMapper and scheduler noise.
 *
 * @param array<mixed>                                                     $body
 * @param callable(HttpRequestSourceFactory, ServerRequestInterface): void $operation
 * @param array<string, string>                                            $query
 *
 * @return array<string, mixed>
 */
function inputSourceScenario(
    string $name,
    array $body,
    callable $operation,
    int $iterations,
    int $warmup,
    int $samples,
    array $query = [],
): array {
    $sourceFactory = new HttpRequestSourceFactory(new InputEncodingValidator());
    $request       = (new ServerRequest())
        ->withMethod(RequestMethodInterface::METHOD_POST)
        ->withParsedBody($body)
        ->withQueryParams($query)
    ;

    return \array_merge(
        [
            'name'      => $name,
            'lifecycle' => 'reuse',
            'input'     => \payloadMetadata($body),
        ],
        \measure(static fn () => $operation($sourceFactory, $request), $iterations, $warmup, $samples),
    );
}

/**
 * Creates a cache directory owned by this runner.
 *
 * A supplied --cache-dir is a parent directory. It is never cleaned up.
 */
function createScenarioCacheDirectory(?string $cacheDir): string
{
    $parentDirectory = $cacheDir ?? \sys_get_temp_dir();

    if (! \is_dir($parentDirectory) || ! \is_writable($parentDirectory)) {
        throw new InvalidArgumentException("Cache directory is not a writable directory: {$parentDirectory}");
    }

    for ($attempt = 0; $attempt < 10; ++$attempt) {
        $directory = \rtrim($parentDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'valinor-benchmark-'
            . \bin2hex(\random_bytes(12));

        if (\mkdir($directory, 0o700)) {
            return $directory;
        }
    }

    throw new RuntimeException("Failed to create a temporary cache directory in {$parentDirectory}");
}

/**
 * Removes only a directory created by createScenarioCacheDirectory().
 */
function removeScenarioCacheDirectory(string $cacheDir): void
{
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $file) {
        $path = $file->getRealPath();

        if (false !== $path) {
            $file->isDir() ? \rmdir($path) : \unlink($path);
        }
    }

    \rmdir($cacheDir);
}

/**
 * hrtime measures wall-clock elapsed time, not CPU time. Peak memory is PHP
 * allocated memory for the process, not PHP used memory or operating-system RSS.
 *
 * @return array{
 *     median_us_per_op: float,
 *     min_us_per_op: float,
 *     max_us_per_op: float,
 *     samples_us_per_op: list<float>,
 *     peak_memory_bytes: int
 * }
 */
function measure(callable $callback, int $iterations, int $warmup, int $samples): array
{
    // Correctness is checked once, outside every elapsed measurement.
    $probe = $callback();

    if ($probe instanceof ResponseInterface) {
        \assertScenarioResult($probe, 204);
    }

    $sampleTimes = [];

    for ($sample = 0; $sample < $samples; ++$sample) {
        for ($i = 0; $i < $warmup; ++$i) {
            $callback();
        }

        $start = \hrtime(true);

        for ($i = 0; $i < $iterations; ++$i) {
            $callback();
        }

        $end = \hrtime(true);

        $sampleTimes[] = ($end - $start) / 1000.0 / $iterations;
    }

    $sorted = $sampleTimes;
    \sort($sorted);

    return [
        'median_us_per_op'  => \round(\benchmarkMedian($sampleTimes), 3),
        'min_us_per_op'     => \round($sorted[0], 3),
        'max_us_per_op'     => \round($sorted[$samples - 1], 3),
        'samples_us_per_op' => \array_map(
            static fn (float $sample): float => \round($sample, 3),
            $sampleTimes,
        ),
        'peak_memory_bytes' => \memory_get_peak_usage(true),
    ];
}

/** @param list<float|int> $values */
function benchmarkMedian(array $values): float
{
    if ([] === $values) {
        throw new InvalidArgumentException('Cannot calculate a median without samples');
    }

    \sort($values);
    $middle = \intdiv(\count($values), 2);

    return 0 === \count($values) % 2
        ? ($values[$middle - 1] + $values[$middle]) / 2.0
        : (float) $values[$middle];
}

/** @return list<int> */
function compatibilityScenarioIds(): array
{
    return \range(1, 15);
}

function assertScenarioResult(ResponseInterface $response, int $expectedStatus): void
{
    if ($response->getStatusCode() !== $expectedStatus) {
        throw new RuntimeException('Benchmark correctness probe returned unexpected HTTP status: ' . $response->getStatusCode());
    }
}

/**
 * @param array<string, object> $expected
 *
 * @return array<string, mixed>
 */
function measureMappedScenario(
    ValinorRequestMapperMiddleware $middleware,
    ServerRequestInterface $request,
    RequestHandlerInterface $handler,
    array $expected,
    int $iterations,
    int $warmup,
    int $samples,
): array {
    \assertMappedScenario($middleware, $request, $expected);

    return \array_merge([
        'classification' => 'mapping',
        'correctness'    => [
            'status'              => 204,
            'dto_values_verified' => true,
        ],
    ], \measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples));
}

/** @param array<string, object> $expected */
function assertMappedScenario(ValinorRequestMapperMiddleware $middleware, ServerRequestInterface $request, array $expected): void
{
    $verifier = new class($expected) implements RequestHandlerInterface {
        /** @param array<string, object> $expected */
        public function __construct(private readonly array $expected) {}

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            foreach ($this->expected as $key => $dto) {
                $actual = $request->getAttribute($key);

                if (! \is_object($actual) || $actual::class !== $dto::class || \get_object_vars($actual) !== \get_object_vars($dto)) {
                    throw new RuntimeException('Benchmark correctness probe returned incorrect DTO: ' . $key);
                }
            }

            return new EmptyResponse();
        }
    };
    \assertScenarioResult($middleware->process($request, $verifier), 204);
}

/** @return array<string, mixed> */
function runScenario(
    int $scenario,
    int $iterations,
    int $warmup,
    int $samples,
    ?string $cacheDir = null
): array {
    switch ($scenario) {
        case 1:
            [$middleware] = \createServices();
            $request      = \makeRequest(RequestMethodInterface::METHOD_GET);
            $handler      = \passthroughHandler();

            return \array_merge(
                [
                    'name'           => 'No RouteResult passthrough',
                    'lifecycle'      => 'reuse',
                    'classification' => 'control',
                    'correctness'    => [
                        'status'              => 204,
                        'dto_values_verified' => false,
                    ],
                ],
                \measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples),
            );

        case 2:
            [$middleware] = \createServices();
            $handler      = new BenchmarkDirectHandler();
            $route        = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
            $request      = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_POST)
                ->withParsedBody([
                    'name' => 'Ada',
                ])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return \array_merge(
                [
                    'name'      => 'Reflection via direct handler object',
                    'lifecycle' => 'reuse',
                ],
                \measureMappedScenario($middleware, $request, $handler, [
                    BenchmarkBodyRequest::class => new BenchmarkBodyRequest('Ada'),
                ], $iterations, $warmup, $samples),
            );

        case 3:
            [$middleware]  = \createServices();
            $lazyContainer = new class implements ContainerInterface {
                /** @param string $id */
                public function get($id): mixed
                {
                    return new $id();
                }

                /** @param string $id */
                public function has($id): bool
                {
                    return \class_exists($id);
                }
            };
            $route   = new Route(
                '/example',
                new LazyLoadingMiddleware(new MiddlewareContainer($lazyContainer), BenchmarkLazyHandler::class),
                [RequestMethodInterface::METHOD_POST],
            );
            $handler = new BenchmarkLazyHandler();
            $request = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_POST)
                ->withParsedBody([
                    'name' => 'Ada',
                ])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return \array_merge(
                [
                    'name'      => 'Reflection via lazy FQCN handler',
                    'lifecycle' => 'reuse',
                ],
                \measureMappedScenario($middleware, $request, $handler, [
                    BenchmarkBodyRequest::class => new BenchmarkBodyRequest('Ada'),
                ], $iterations, $warmup, $samples),
            );

        case 4:
            [$middleware] = \createServices();
            $handler      = \passthroughHandler();
            $route        = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
            $route->setOptions([
                'valinor_mappings' => [[
                    'body'    => BenchmarkBodyRequest::class,
                    'query'   => null,
                    'route'   => null,
                    'source'  => null,
                    'output'  => null,
                    'methods' => [],
                ]],
            ]);
            $request = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_POST)
                ->withParsedBody([
                    'name' => 'Ada',
                ])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return \array_merge(
                [
                    'name'      => 'Route options single DTO',
                    'lifecycle' => 'reuse',
                ],
                \measureMappedScenario($middleware, $request, $handler, [
                    BenchmarkBodyRequest::class => new BenchmarkBodyRequest('Ada'),
                ], $iterations, $warmup, $samples),
            );

        case 5:
            [$middleware] = \createServices();
            $handler      = new BenchmarkMultiHandler();
            $route        = new Route('/example/{id}', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_GET]);
            $request      = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_GET)
                ->withParsedBody([
                    'name' => 'Ada',
                ])
                ->withQueryParams([
                    'page' => '1',
                ])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, [
                    'id' => '42',
                ]))
            ;

            return \array_merge(
                [
                    'name'      => 'Three operations with different outputs',
                    'lifecycle' => 'reuse',
                ],
                \measureMappedScenario($middleware, $request, $handler, [
                    'body'  => new BenchmarkBodyRequest('Ada'),
                    'query' => new BenchmarkQueryRequest(1),
                    'route' => new BenchmarkRouteRequest('42'),
                ], $iterations, $warmup, $samples),
            );

        case 6:
            [$middleware] = \createServices();
            $handler      = new BenchmarkDirectHandler();
            $route        = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
            $request      = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_POST)
                ->withParsedBody([
                    'name' => 'Ada',
                ])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return \array_merge(
                [
                    'name'      => 'Repeated calls reusing middleware and builder',
                    'lifecycle' => 'reuse',
                ],
                \measureMappedScenario($middleware, $request, $handler, [
                    BenchmarkBodyRequest::class => new BenchmarkBodyRequest('Ada'),
                ], $iterations, $warmup, $samples),
            );

        case 7:
            $scenarioCacheDir = \createScenarioCacheDirectory($cacheDir);

            try {
                $prewarmBuilder = (new MapperBuilder())
                    ->withCache(new FileSystemCache($scenarioCacheDir))
                    ->allowSuperfluousKeys()
                    ->allowScalarValueCasting()
                ;
                $prewarmMapper = $prewarmBuilder->mapper();
                $prewarmMapper->map(
                    BenchmarkBodyRequest::class,
                    new HttpRequest(bodyValues: [
                        'name' => 'warmup',
                    ]),
                );

                $handler = new BenchmarkDirectHandler();
                $route   = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
                $request = (new ServerRequest())
                    ->withMethod(RequestMethodInterface::METHOD_POST)
                    ->withParsedBody([
                        'name' => 'Ada',
                    ])
                    ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
                ;

                $callback = static function() use ($scenarioCacheDir, $request, $handler): ResponseInterface {
                    [$middleware] = \createServices($scenarioCacheDir);

                    return $middleware->process($request, $handler);
                };

                [$probeMiddleware] = \createServices($scenarioCacheDir);
                // Verify the same mapped path before timing fresh service construction.
                \assertMappedScenario($probeMiddleware, $request, [
                    BenchmarkBodyRequest::class => new BenchmarkBodyRequest('Ada'),
                ]);

                return \array_merge(
                    [
                        'name'           => 'New middleware/resolvers/builder per iteration with file cache',
                        'lifecycle'      => 'new-each-iteration',
                        'classification' => 'mapping',
                        'correctness'    => [
                            'status'              => 204,
                            'dto_values_verified' => true,
                        ],
                    ],
                    \measure($callback, $iterations, $warmup, $samples),
                );
            } finally {
                \removeScenarioCacheDirectory($scenarioCacheDir);
            }

        case 8:
            return \largePayloadScenario(
                'Large flat body, one operation',
                new BenchmarkLargeBodyHandler(),
                \largeFlatPayload(),
                $iterations,
                $warmup,
                $samples,
            );

        case 9:
            return \largePayloadScenario(
                'Large flat body, three operations',
                new BenchmarkThreeLargeBodyHandler(),
                \largeFlatPayload(),
                $iterations,
                $warmup,
                $samples,
            );

        case 10:
            return \largePayloadScenario(
                'Large nested body, one operation',
                new BenchmarkLargeBodyHandler(),
                \largeNestedPayload(),
                $iterations,
                $warmup,
                $samples,
            );

        case 11:
            return \largePayloadScenario(
                'Large nested body, three operations',
                new BenchmarkThreeLargeBodyHandler(),
                \largeNestedPayload(),
                $iterations,
                $warmup,
                $samples,
            );

        case 12:
            return \largePayloadScenario(
                'Large body plus combined source',
                new BenchmarkBodyAndSourceHandler(),
                \largeFlatPayload(),
                $iterations,
                $warmup,
                $samples,
                [
                    'page' => '2',
                ],
                [
                    'id' => '42',
                ],
            );

        case 13:
            return \inputSourceScenario(
                'Input source context, body only',
                \largeFlatPayload(),
                static function(HttpRequestSourceFactory $sourceFactory, ServerRequestInterface $request): void {
                    $sourceFactory->create($request, [], 'body');
                },
                $iterations,
                $warmup,
                $samples,
            );

        case 14:
            return \inputSourceScenario(
                'Input source context, combined source',
                \largeFlatPayload(),
                static function(HttpRequestSourceFactory $sourceFactory, ServerRequestInterface $request): void {
                    $sourceFactory->create($request, [
                        'id' => '42',
                    ], 'source');
                },
                $iterations,
                $warmup,
                $samples,
                [
                    'page' => '2',
                ],
            );

        case 15:
            [$middleware] = \createServices();
            $handler      = \passthroughHandler();
            $route        = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
            // Invalid UTF-8 proves this route does not enter source validation/mapping.
            $request = \makeRequest(RequestMethodInterface::METHOD_POST, [
                'name' => "\xFF",
            ])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return \array_merge([
                'name'           => 'Matched route without mappings',
                'lifecycle'      => 'reuse',
                'classification' => 'control',
                'correctness'    => [
                    'status'              => 204,
                    'dto_values_verified' => false,
                ],
            ], \measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples));

        default:
            throw new InvalidArgumentException("Unknown scenario: {$scenario}");
    }
}

function lockPath(): string
{
    return __DIR__ . '/../composer.lock';
}

/**
 * @param non-empty-string $section
 *
 * @return array<string, string>
 */
function lockedPackageVersions(string $lockPath, string $section = 'packages'): array
{
    if (! \file_exists($lockPath)) {
        return [];
    }

    $lock = \json_decode((string) \file_get_contents($lockPath), true);

    if (! \is_array($lock) || ! \array_key_exists($section, $lock) || ! \is_array($lock[$section])) {
        return [];
    }

    $versions = [];

    foreach ($lock[$section] as $package) {
        if (
            \is_array($package)
            && isset($package['name'], $package['version'])
            && \is_string($package['name'])
            && \is_string($package['version'])
        ) {
            $versions[$package['name']] = $package['version'];
        }
    }

    \ksort($versions);

    return $versions;
}

/**
 * Provenance is always computed from the environment, never accepted as a
 * CLI parameter, so labels cannot drift from the measured bytes.
 *
 * @return array{revision: null|string, harness_sha256: null|string, lock_sha256: null|string, versions: array<string, mixed>}
 */
function provenance(): array
{
    $lockPath = \lockPath();

    return [
        'revision'       => \revisionOfDirectory(\dirname(__DIR__)),
        'harness_sha256' => \sha256OfFile(__FILE__),
        'lock_sha256'    => \sha256OfFile($lockPath),
        'versions'       => [
            'php'                    => PHP_VERSION,
            'opcache_cli'            => (bool) \ini_get('opcache.enable_cli'),
            'pcov_enabled'           => \extension_loaded('pcov') && (bool) \ini_get('pcov.enabled'),
            'jit'                    => \ini_get('opcache.jit'),
            'locked_packages'        => \lockedPackageVersions($lockPath, 'packages'),
            'locked_packages_dev'    => \lockedPackageVersions($lockPath, 'packages-dev'),
        ],
    ];
}

function sha256OfFile(string $path): ?string
{
    if (! \file_exists($path)) {
        return null;
    }

    return \hash_file('sha256', $path) ?: null;
}

function revisionOfDirectory(string $directory): ?string
{
    $gitDirectory = $directory . '/.git';

    if (\is_file($gitDirectory)) {
        $contents = (string) \file_get_contents($gitDirectory);

        if (1 === \preg_match('/^gitdir:\s*(.+)$/m', $contents, $matches)) {
            $gitDirectory = \rtrim($matches[1]);

            if (! \str_starts_with($gitDirectory, '/')) {
                $gitDirectory = $directory . '/' . $gitDirectory;
            }
        }
    }

    $headPath = $gitDirectory . '/HEAD';

    if (! \file_exists($headPath)) {
        return null;
    }

    $head = \trim((string) \file_get_contents($headPath));

    if (\preg_match('/^[0-9a-f]{40}$/i', $head)) {
        return $head;
    }

    if (! \str_starts_with($head, 'ref: ')) {
        return null;
    }

    $commonDirectory = $gitDirectory;
    $commonPath      = $gitDirectory . '/commondir';

    if (\is_file($commonPath)) {
        $commonDirectory = \trim((string) \file_get_contents($commonPath));

        if (! \str_starts_with($commonDirectory, '/')) {
            $commonDirectory = $gitDirectory . '/' . $commonDirectory;
        }
    }

    $reference = \substr($head, 5);
    $refPath   = $commonDirectory . '/' . $reference;

    if (\file_exists($refPath)) {
        return \trim((string) \file_get_contents($refPath));
    }

    $packedPath = $commonDirectory . '/packed-refs';

    if (\is_file($packedPath)) {
        foreach (\file($packedPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (1 === \preg_match('/^([0-9a-f]{40}) (.+)$/', $line, $matches) && $matches[2] === $reference) {
                return $matches[1];
            }
        }
    }

    return null;
}

/** @return array<string, bool|string> */
function versions(): array
{
    $versions     = \lockedPackageVersions(\lockPath(), 'packages');
    $devVersions  = \lockedPackageVersions(\lockPath(), 'packages-dev');
    $valinor      = $versions['cuyz/valinor'] ?? $devVersions['cuyz/valinor'] ?? 'unknown';
    $mezzioRouter = $versions['mezzio/mezzio-router'] ?? 'unknown';
    $mezzio       = $devVersions['mezzio/mezzio'] ?? 'unknown';
    $diactoros    = $devVersions['laminas/laminas-diactoros'] ?? 'unknown';
    $stratigility = $devVersions['laminas/laminas-stratigility'] ?? 'unknown';

    return [
        'php'                  => PHP_VERSION,
        'valinor'              => $valinor,
        'mezzio_router'        => $mezzioRouter,
        'mezzio'               => $mezzio,
        'laminas_diactoros'    => $diactoros,
        'laminas_stratigility' => $stratigility,
        'opcache_cli'          => (bool) \ini_get('opcache.enable_cli'),
    ];
}

/**
 * @param array<string, bool|list<mixed>|string> $options
 * @param non-empty-string                       $name
 */
function parseCliIntOption(
    array $options,
    string $name,
    int $default,
    int $min,
    int $max
): int {
    $raw = $options[$name] ?? null;

    if (null === $raw) {
        return $default;
    }

    if (! \is_string($raw) || 1 !== \preg_match('/^\d+$/', $raw)) {
        throw new InvalidArgumentException("Option --{$name} must be a non-negative integer");
    }

    $value = (int) $raw;

    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException("Option --{$name} must be between {$min} and {$max}");
    }

    return $value;
}

function benchmarkMain(): void
{
    $options = \getopt('', [
        'iterations:',
        'warmup:',
        'samples:',
        'scenario:',
        'cache-dir:',
        'list-scenarios',
    ]);

    if (isset($options['list-scenarios'])) {
        echo \json_encode(\compatibilityScenarioIds(), JSON_THROW_ON_ERROR) . PHP_EOL;

        return;
    }

    $iterations  = \parseCliIntOption($options, 'iterations', 10000, 1, 1000000);
    $warmup      = \parseCliIntOption($options, 'warmup', 1000, 0, 100000);
    $samples     = \parseCliIntOption($options, 'samples', 5, 1, 1000);
    $scenarioRaw = $options['scenario'] ?? null;
    $scenario    = null;

    if (\is_string($scenarioRaw)) {
        if (1 !== \preg_match('/^\d+$/', $scenarioRaw)) {
            throw new InvalidArgumentException('Option --scenario must be a positive integer');
        }

        $scenario = (int) $scenarioRaw;

        if (! \in_array($scenario, \compatibilityScenarioIds(), true)) {
            throw new InvalidArgumentException("Unknown scenario: {$scenario}");
        }
    } elseif (null !== $scenarioRaw) {
        throw new InvalidArgumentException('Option --scenario must be a positive integer');
    }

    $cacheDir = \is_string($options['cache-dir'] ?? null) && '' !== $options['cache-dir']
        ? $options['cache-dir']
        : null;

    if (null !== $scenario) {
        $result                = \runScenario($scenario, $iterations, $warmup, $samples, $cacheDir);
        $result['scenario_id'] = $scenario;
        $result['params']      = [
            'iterations' => $iterations,
            'warmup'     => $warmup,
            'samples'    => $samples,
        ];
        $result['provenance'] = \provenance();
        echo \json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;

        return;
    }

    $results = [];

    foreach (\compatibilityScenarioIds() as $id) {
        $command = [
            PHP_BINARY,
            __FILE__,
            '--scenario=' . $id,
            '--iterations=' . $iterations,
            '--warmup=' . $warmup,
            '--samples=' . $samples,
        ];

        if (null !== $cacheDir) {
            $command[] = '--cache-dir=' . $cacheDir;
        }

        \fwrite(STDERR, "running scenario {$id}\n");

        $process = \proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! \is_resource($process)) {
            throw new RuntimeException("Failed to start scenario {$id}");
        }

        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        if (0 !== $exitCode) {
            throw new RuntimeException("Scenario {$id} failed: {$stderr}");
        }

        $decoded = \json_decode((string) $stdout, true);

        if (! \is_array($decoded)) {
            throw new RuntimeException("Scenario {$id} returned invalid JSON: {$stdout}");
        }

        $results[] = $decoded;
    }

    $result = [
        'versions'   => \versions(),
        'params'     => [
            'iterations' => $iterations,
            'warmup'     => $warmup,
            'samples'    => $samples,
        ],
        'scenarios'  => $results,
        'provenance' => \provenance(),
    ];

    echo \json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
}

if (__FILE__ === \realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    \benchmarkMain();
}

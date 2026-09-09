<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CuyZ\Valinor\Cache\FileSystemCache;
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
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderInterface;
use Sirix\Mezzio\Valinor\Error\MappingErrorResponderResolver;
use Sirix\Mezzio\Valinor\Mapping\HandlerTargetResolver;
use Sirix\Mezzio\Valinor\Mapping\HttpMethodNormalizer;
use Sirix\Mezzio\Valinor\Mapping\HttpRequestSourceFactory;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
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

/** @return array{0: ValinorRequestMapperMiddleware, 1: TreeMapper} */
function createServices(?string $cacheDir = null): array
{
    $builder = (new MapperBuilder())->allowSuperfluousKeys()->allowScalarValueCasting();

    if (null !== $cacheDir) {
        $builder = $builder->withCache(new FileSystemCache($cacheDir));
    }

    $treeMapper = $builder->mapper();

    $responseFactory = new ResponseFactory();
    $streamFactory   = new StreamFactory();
    $defaultResponder = new DefaultMappingErrorResponder($responseFactory, $streamFactory);

    $container = new class($defaultResponder) implements ContainerInterface {
        private readonly array $services;

        public function __construct(MappingErrorResponderInterface $defaultResponder)
        {
            $this->services = [
                DefaultMappingErrorResponder::class => $defaultResponder,
                MappingErrorResponderInterface::class => $defaultResponder,
            ];
        }

        public function get(string $id): mixed
        {
            if (array_key_exists($id, $this->services)) {
                return $this->services[$id];
            }

            throw new \RuntimeException("Service not found: {$id}");
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->services);
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

    $sourceFactory = new HttpRequestSourceFactory(new InputEncodingValidator());

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

function makeRequest(string $method, array $body = [], array $query = [], array $routeParams = []): ServerRequestInterface
{
    $request = (new ServerRequest())
        ->withMethod($method)
        ->withParsedBody($body)
        ->withQueryParams($query)
    ;

    if ([] !== $routeParams) {
        $request = $request->withAttribute(RouteResult::class, RouteResult::fromRoute(
            new Route('/example', passthroughHandler(), [$method]),
            $routeParams,
        ));
    }

    return $request;
}

/**
 * Creates a cache directory owned by this runner.
 *
 * A supplied --cache-dir is a parent directory. It is never cleaned up.
 */
function createScenarioCacheDirectory(?string $cacheDir): string
{
    $parentDirectory = $cacheDir ?? sys_get_temp_dir();

    if (! is_dir($parentDirectory) || ! is_writable($parentDirectory)) {
        throw new \InvalidArgumentException("Cache directory is not a writable directory: {$parentDirectory}");
    }

    for ($attempt = 0; $attempt < 10; ++$attempt) {
        $directory = rtrim($parentDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'valinor-benchmark-'
            . bin2hex(random_bytes(12));

        if (mkdir($directory, 0o700)) {
            return $directory;
        }
    }

    throw new \RuntimeException("Failed to create a temporary cache directory in {$parentDirectory}");
}

/**
 * Removes only a directory created by createScenarioCacheDirectory().
 */
function removeScenarioCacheDirectory(string $cacheDir): void
{
    foreach (new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    ) as $file) {
        $path = $file->getRealPath();

        if (false !== $path) {
            $file->isDir() ? rmdir($path) : unlink($path);
        }
    }

    rmdir($cacheDir);
}

/** @return array{median_us_per_op: float, peak_memory_bytes: int} */
function measure(callable $callback, int $iterations, int $warmup, int $samples): array
{
    $sampleTimes = [];

    for ($sample = 0; $sample < $samples; ++$sample) {
        for ($i = 0; $i < $warmup; ++$i) {
            $callback();
        }

        $start = hrtime(true);

        for ($i = 0; $i < $iterations; ++$i) {
            $callback();
        }

        $end          = hrtime(true);
        $sampleTimes[] = ($end - $start) / 1000.0;
    }

    sort($sampleTimes);
    $median = $sampleTimes[(int) floor($samples / 2)];

    return [
        'median_us_per_op'  => round($median / $iterations, 3),
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ];
}

function runScenario(int $scenario, int $iterations, int $warmup, int $samples, ?string $cacheDir = null): array
{
    switch ($scenario) {
        case 1:
            [$middleware] = createServices();
            $request = makeRequest(RequestMethodInterface::METHOD_GET);
            $handler = passthroughHandler();

            return array_merge(
                [
                    'name'      => 'No RouteResult passthrough',
                    'lifecycle' => 'reuse',
                ],
                measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples),
            );

        case 2:
            [$middleware] = createServices();
            $handler = new BenchmarkDirectHandler();
            $route   = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
            $request = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_POST)
                ->withParsedBody(['name' => 'Ada'])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return array_merge(
                [
                    'name'      => 'Reflection via direct handler object',
                    'lifecycle' => 'reuse',
                ],
                measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples),
            );

        case 3:
            [$middleware] = createServices();
            $lazyContainer = new class implements ContainerInterface {
                public function get(string $id): mixed
                {
                    return new $id();
                }

                public function has(string $id): bool
                {
                    return class_exists($id);
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
                ->withParsedBody(['name' => 'Ada'])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return array_merge(
                [
                    'name'      => 'Reflection via lazy FQCN handler',
                    'lifecycle' => 'reuse',
                ],
                measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples),
            );

        case 4:
            [$middleware] = createServices();
            $handler = passthroughHandler();
            $route   = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
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
                ->withParsedBody(['name' => 'Ada'])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return array_merge(
                [
                    'name'      => 'Route options single DTO',
                    'lifecycle' => 'reuse',
                ],
                measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples),
            );

        case 5:
            [$middleware] = createServices();
            $handler = new BenchmarkMultiHandler();
            $route   = new Route('/example/{id}', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_GET]);
            $request = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_GET)
                ->withParsedBody(['name' => 'Ada'])
                ->withQueryParams(['page' => '1'])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, ['id' => '42']))
            ;

            return array_merge(
                [
                    'name'      => 'Three operations with different outputs',
                    'lifecycle' => 'reuse',
                ],
                measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples),
            );

        case 6:
            [$middleware] = createServices();
            $handler = new BenchmarkDirectHandler();
            $route   = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
            $request = (new ServerRequest())
                ->withMethod(RequestMethodInterface::METHOD_POST)
                ->withParsedBody(['name' => 'Ada'])
                ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
            ;

            return array_merge(
                [
                    'name'      => 'Repeated calls reusing middleware and builder',
                    'lifecycle' => 'reuse',
                ],
                measure(static fn () => $middleware->process($request, $handler), $iterations, $warmup, $samples),
            );

        case 7:
            $scenarioCacheDir = createScenarioCacheDirectory($cacheDir);

            try {
                $prewarmBuilder = (new MapperBuilder())
                    ->withCache(new FileSystemCache($scenarioCacheDir))
                    ->allowSuperfluousKeys()
                    ->allowScalarValueCasting()
                ;
                $prewarmMapper = $prewarmBuilder->mapper();
                $prewarmMapper->map(
                    BenchmarkBodyRequest::class,
                    new \CuyZ\Valinor\Mapper\Http\HttpRequest(bodyValues: ['name' => 'warmup']),
                );

                $handler = new BenchmarkDirectHandler();
                $route   = new Route('/example', new RequestHandlerMiddleware($handler), [RequestMethodInterface::METHOD_POST]);
                $request = (new ServerRequest())
                    ->withMethod(RequestMethodInterface::METHOD_POST)
                    ->withParsedBody(['name' => 'Ada'])
                    ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []))
                ;

                $callback = static function () use ($scenarioCacheDir, $request, $handler): void {
                    [$middleware] = createServices($scenarioCacheDir);
                    $middleware->process($request, $handler);
                };

                return array_merge(
                    [
                        'name'      => 'New middleware/resolvers/builder per iteration with file cache',
                        'lifecycle' => 'new-each-iteration',
                    ],
                    measure($callback, $iterations, $warmup, $samples),
                );
            } finally {
                removeScenarioCacheDirectory($scenarioCacheDir);
            }

        default:
            throw new \InvalidArgumentException("Unknown scenario: {$scenario}");
    }
}

function versions(): array
{
    $valinorVersion      = 'unknown';
    $mezzioRouterVersion = 'unknown';
    $lockPath            = __DIR__ . '/../composer.lock';

    if (file_exists($lockPath)) {
        $lock = json_decode((string) file_get_contents($lockPath), true);

        if (is_array($lock) && array_key_exists('packages', $lock) && is_array($lock['packages'])) {
            foreach ($lock['packages'] as $package) {
                if (! is_array($package) || ! array_key_exists('name', $package)) {
                    continue;
                }

                if ('cuyz/valinor' === $package['name'] && array_key_exists('version', $package)) {
                    $valinorVersion = $package['version'];
                }

                if ('mezzio/mezzio-router' === $package['name'] && array_key_exists('version', $package)) {
                    $mezzioRouterVersion = $package['version'];
                }
            }
        }
    }

    return [
        'php'           => PHP_VERSION,
        'valinor'       => $valinorVersion,
        'mezzio_router' => $mezzioRouterVersion,
        'opcache_cli'   => (bool) ini_get('opcache.enable_cli'),
    ];
}

function main(): void
{
    $options = getopt('', ['iterations:', 'warmup:', 'samples:', 'scenario:', 'cache-dir:']);

    $iterations = is_numeric($options['iterations'] ?? null)
        ? (int) $options['iterations']
        : 10000;
    $warmup = is_numeric($options['warmup'] ?? null)
        ? (int) $options['warmup']
        : 1000;
    $samples = is_numeric($options['samples'] ?? null)
        ? (int) $options['samples']
        : 5;
    $scenario = is_numeric($options['scenario'] ?? null)
        ? (int) $options['scenario']
        : null;
    $cacheDir = is_string($options['cache-dir'] ?? null) && '' !== $options['cache-dir']
        ? $options['cache-dir']
        : null;

    if (null !== $scenario) {
        $result = runScenario($scenario, $iterations, $warmup, $samples, $cacheDir);
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;

        return;
    }

    $results = [];

    foreach (range(1, 7) as $id) {
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

        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new \RuntimeException("Failed to start scenario {$id}");
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            throw new \RuntimeException("Scenario {$id} failed: {$stderr}");
        }

        $decoded = json_decode((string) $stdout, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("Scenario {$id} returned invalid JSON: {$stdout}");
        }

        $results[] = $decoded;
    }

    echo json_encode([
        'versions'  => versions(),
        'params'    => [
            'iterations' => $iterations,
            'warmup'     => $warmup,
            'samples'    => $samples,
        ],
        'scenarios' => $results,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
}

main();

<?php

declare(strict_types=1);

require_once __DIR__ . '/request-mapper.php';

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\Mezzio\Valinor\Attribute\MapRequest;
use Sirix\Mezzio\Valinor\Middleware\ValinorRequestMapperMiddleware;

final readonly class WorkerRequest
{
    public function __construct(public int $id) {}
}

#[MapRequest(body: WorkerRequest::class)]
final class WorkerHandler implements RequestHandlerInterface
{
    public int $expectedId = 0;

    public int $mappedId = 0;

    public bool $sample = false;

    /** @var list<WeakReference<object>> */
    public array $references = [];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $dto = $request->getAttribute(WorkerRequest::class);

        if (! $dto instanceof WorkerRequest || $dto->id !== $this->expectedId) {
            throw new RuntimeException('Worker request mapped an incorrect ID');
        }

        $this->mappedId = $dto->id;

        if ($this->sample) {
            $this->references = [WeakReference::create($request), WeakReference::create($dto)];
        }

        return new EmptyResponse();
    }
}

/**
 * @param list<array{request_number: int, used_bytes_after_gc: int, allocated_bytes_after_gc: int}> $checkpoints
 *
 * @return array{passed: bool, range_bytes: int, allowed_range_bytes: int, sustained_growth: bool}
 */
function evaluateWorkerMemoryGate(array $checkpoints): array
{
    $used    = \array_column(\array_slice($checkpoints, -5), 'used_bytes_after_gc');
    $range   = [] === $used ? 0 : \max($used) - \min($used);
    $allowed = (int) \max(1048576, ($used[0] ?? 0) * 0.05);
    $growth  = 5 === \count($used);
    $counter = \count($used);

    for ($index = 1; $index < $counter; ++$index) {
        $growth = $growth && $used[$index] > $used[$index - 1];
    }

    return [
        'passed'              => 5 === \count($used) && $range <= $allowed && ! $growth,
        'range_bytes'         => $range,
        'allowed_range_bytes' => $allowed,
        'sustained_growth'    => $growth,
    ];
}

/** @return list<WeakReference<object>> */
function processWorkerRequest(
    ValinorRequestMapperMiddleware $middleware,
    WorkerHandler $handler,
    ?Route $staticRoute,
    int $id,
    bool $sample
): array {
    $wrapper = null;
    $route   = $staticRoute;

    if (! $route instanceof Route) {
        $wrapper = new RequestHandlerMiddleware($handler);
        $route   = new Route('/worker', $wrapper, ['POST']);
    }

    $handler->expectedId = $id;
    $handler->mappedId   = 0;
    $handler->sample     = $sample;
    $request             = \makeRequest('POST', [
        'id' => $id,
    ])->withAttribute(RouteResult::class, RouteResult::fromRoute($route, []));
    $response = $middleware->process($request, $handler);

    if (204 !== $response->getStatusCode() || $handler->mappedId !== $id || null !== $request->getAttribute(WorkerRequest::class)) {
        throw new RuntimeException('Worker request correctness check failed');
    }

    if (! $sample) {
        return [];
    }

    $references          = [WeakReference::create($request), WeakReference::create($response), ...$handler->references];
    $handler->references = [];

    if ($wrapper instanceof RequestHandlerMiddleware) {
        $references[] = WeakReference::create($route);
        $references[] = WeakReference::create($wrapper);
    }

    return $references;
}

/** @return array{used_bytes: int, allocated_bytes: int} */
function workerMemory(): array
{
    return [
        'used_bytes'      => \memory_get_usage(),
        'allocated_bytes' => \memory_get_usage(true),
    ];
}

function workerRss(): ?int
{
    $path = '/proc/self/status';

    if (! \is_readable($path)) {
        return null;
    }

    return 1 === \preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', (string) \file_get_contents($path), $matches)
        ? (int) $matches[1] * 1024
        : null;
}

/** @param list<string> $arguments
 * @return array{mode: string, warmup: int, requests: int, interval: int, output: null|string}
 */
function workerConfiguration(array $arguments): array
{
    $options = [];

    foreach ($arguments as $argument) {
        if (1 !== \preg_match('/^--(mode|warmup|requests|interval|output)=(.+)$/D', $argument, $matches)) {
            throw new InvalidArgumentException('Invalid worker option: ' . $argument);
        }

        if (isset($options[$matches[1]])) {
            throw new InvalidArgumentException('Duplicate worker option: --' . $matches[1]);
        }

        $options[$matches[1]] = $matches[2];
    }

    $mode = $options['mode'] ?? 'static';

    if (! \in_array($mode, ['static', 'ephemeral'], true)) {
        throw new InvalidArgumentException('Option --mode must be static or ephemeral');
    }

    return [
        'mode'     => $mode,
        'warmup'   => \parseCliIntOption($options, 'warmup', 10000, 1, 1000000),
        'requests' => \parseCliIntOption($options, 'requests', 100000, 1, 1000000),
        'interval' => \parseCliIntOption($options, 'interval', 10000, 1, 1000000),
        'output'   => $options['output'] ?? null,
    ];
}

/** @param list<string> $arguments */
function workerMain(array $arguments): int
{
    try {
        $config       = \workerConfiguration($arguments);
        [$middleware] = \createServices();
        $handler      = new WorkerHandler();
        $routes       = 'static' === $config['mode'] ? [
            new Route('/worker/one', new RequestHandlerMiddleware($handler), ['POST']),
            new Route('/worker/two', new RequestHandlerMiddleware($handler), ['POST']),
        ] : [];
        // Allocate the scalar report before warmup so recording it does not imitate retention.
        $checkpointCount = (int) \ceil($config['requests'] / $config['interval']);
        $checkpoints     = [];
        for ($index = 0; $index < $checkpointCount; ++$index) {
            $checkpoints[] = [
                'request_number'            => \min(($index + 1) * $config['interval'], $config['requests']),
                'used_bytes_before_gc'      => 0,
                'allocated_bytes_before_gc' => 0,
                'used_bytes_after_gc'       => 0,
                'allocated_bytes_after_gc'  => 0,
                'peak_used_bytes'           => 0,
                'peak_allocated_bytes'      => 0,
                'rss_bytes'                 => null,
            ];
        }
        $sampleNumbers = [1, (int) \ceil($config['requests'] / 2), $config['requests']];
        $sampleIds     = \array_fill(0, 3, [
            'expected' => 0,
            'mapped'   => 0,
        ]);
        foreach ($sampleIds as $index => $sampleId) {
            $sampleIds[$index]['expected'] = $config['warmup'] + $sampleNumbers[$index];
        }
        unset($sampleId);
        $release = [
            'checked_weak_references'    => 0,
            'alive_weak_references'      => 0,
            'checked_ephemeral_routes'   => 0,
            'checked_ephemeral_wrappers' => 0,
        ];
        $setup = \workerMemory();

        for ($id = 1; $id <= $config['warmup']; ++$id) {
            \processWorkerRequest($middleware, $handler, $routes[$id % 2] ?? null, $id, false);
        }

        \gc_collect_cycles();
        $afterWarmup     = \workerMemory();
        $started         = \hrtime(true);
        $checkpointIndex = 0;

        for ($number = 1; $number <= $config['requests']; ++$number) {
            $isCheckpoint = 0 === $number % $config['interval'] || $number === $config['requests'];
            $references   = \processWorkerRequest($middleware, $handler, $routes[$number % 2] ?? null, $config['warmup'] + $number, $isCheckpoint);

            foreach ($sampleNumbers as $index => $sampleNumber) {
                if ($number === $sampleNumber) {
                    $sampleIds[$index]['mapped'] = $handler->mappedId;
                }
            }

            if (! $isCheckpoint) {
                continue;
            }

            \gc_collect_cycles();
            foreach ($references as $reference) {
                ++$release['checked_weak_references'];
                $release['alive_weak_references'] += null !== $reference->get() ? 1 : 0;
            }
            unset($reference);
            $references = [];

            if ('ephemeral' === $config['mode']) {
                ++$release['checked_ephemeral_routes'];
                ++$release['checked_ephemeral_wrappers'];
            }

            $usedBeforeGc      = \memory_get_usage();
            $allocatedBeforeGc = \memory_get_usage(true);
            \gc_collect_cycles();
            $checkpoints[$checkpointIndex] = [
                'request_number'            => $number,
                'used_bytes_before_gc'      => $usedBeforeGc,
                'allocated_bytes_before_gc' => $allocatedBeforeGc,
                'used_bytes_after_gc'       => \memory_get_usage(),
                'allocated_bytes_after_gc'  => \memory_get_usage(true),
                'peak_used_bytes'           => \memory_get_peak_usage(),
                'peak_allocated_bytes'      => \memory_get_peak_usage(true),
                'rss_bytes'                 => \workerRss(),
            ];
            ++$checkpointIndex;
        }

        $elapsed                     = \hrtime(true) - $started;
        $gate                        = \evaluateWorkerMemoryGate($checkpoints);
        $qualifies                   = $config['warmup'] >= 10000 && $config['requests'] >= 100000 && 10000 === $config['interval'] && $checkpointCount >= 5;
        $passed                      = 0 === $release['alive_weak_references'] && (! $qualifies || $gate['passed']);
        $provenance                  = \provenance();
        $provenance['worker_sha256'] = \sha256OfFile(__FILE__);
        $result                      = [
            'schema'                  => 'sirix-mezzio-valinor-worker/1',
            'params'                  => $config,
            'environment'             => [
                'os'         => \php_uname(),
                'extensions' => \get_loaded_extensions(),
                ...$provenance['versions'],
            ],
            'provenance'              => $provenance,
            'setup_memory'            => $setup,
            'after_warmup_memory'     => $afterWarmup,
            'elapsed_ns'              => $elapsed,
            'correctness'             => [
                'verified_requests' => $config['warmup'] + $config['requests'],
                'failures'          => 0,
                'sample_ids'        => $sampleIds,
            ],
            'release'                 => $release,
            'checkpoints'             => $checkpoints,
            'qualifies_for_full_gate' => $qualifies,
            'memory_gate'             => $gate,
            'passed'                  => $passed,
        ];
        $json = \json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;

        if (null !== $config['output'] && false === \file_put_contents($config['output'], $json)) {
            throw new RuntimeException('Failed to write worker output: ' . $config['output']);
        }

        echo $json;

        return $passed ? 0 : 1;
    } catch (Throwable $error) {
        \fwrite(STDERR, $error->getMessage() . PHP_EOL);

        return 1;
    }
}

if (__FILE__ === \realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $arguments = [];
    $argv      = $_SERVER['argv'] ?? [];
    if (! \is_array($argv)) {
        exit(1);
    }

    foreach ($argv as $argument) {
        if (! \is_string($argument)) {
            exit(1);
        }
        $arguments[] = $argument;
    }

    exit(\workerMain(\array_slice($arguments, 1)));
}

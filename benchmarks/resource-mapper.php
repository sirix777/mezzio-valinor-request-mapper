<?php

declare(strict_types=1);

require_once __DIR__ . '/request-mapper.php';

use CuyZ\Valinor\Mapper\TreeMapper;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Stratigility\Middleware\RequestHandlerMiddleware;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sirix\ContainerResolver\ContainerResolver;
use Sirix\Mezzio\Valinor\Error\DefaultMappingErrorResponder;
use Sirix\Mezzio\Valinor\Error\ErrorResponseOptions;
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

final readonly class ResourceRecord
{
    public function __construct(public int $id, public string $name) {}
}

final readonly class ResourceRecords
{
    /** @param list<ResourceRecord> $records */
    public function __construct(public array $records) {}
}

final readonly class ResourceIntegers
{
    /** @param list<int> $values */
    public function __construct(public array $values) {}
}

final class ResourceCountingMapper implements TreeMapper
{
    public int $calls = 0;

    public function __construct(private readonly TreeMapper $mapper) {}

    /** @impure */
    public function map(string $signature, mixed $source): mixed
    {
        ++$this->calls;

        return $this->mapper->map($signature, $source);
    }
}

/** @return array{ValinorRequestMapperMiddleware, ResourceCountingMapper} */
function resourceServices(?InputLimits $limits, ?ErrorResponseOptions $options): array
{
    [, $mapper] = \createServices();
    $counter    = new ResourceCountingMapper($mapper);
    $responder  = new DefaultMappingErrorResponder(new ResponseFactory(), new StreamFactory(), $options);
    $container  = new class implements ContainerInterface {
        public function get($id): mixed
        {
            throw new RuntimeException('No custom responders');
        }

        public function has($id): bool
        {
            return false;
        }
    };

    return [new ValinorRequestMapperMiddleware(
        $counter,
        new MappingErrorResponderResolver($responder, ContainerResolver::forFactory($container, ValinorRequestMapperMiddleware::class)),
        new MappingPlanResolver(new MapRequestResolver(new HandlerTargetResolver(), new MapRequestOptionsParser()), new HttpMethodNormalizer()),
        new HttpRequestSourceFactory(new InputEncodingValidator($limits)),
    ), $counter];
}

/** @return array{environment: array<string, mixed>, provenance: array<string, mixed>} */
function resourceMetadata(): array
{
    return [
        'environment' => [
            'php'          => PHP_VERSION,
            'os'           => \php_uname(),
            'opcache_cli'  => \ini_get('opcache.enable_cli'),
            'pcov_enabled' => \extension_loaded('pcov') && (bool) \ini_get('pcov.enabled'),
            'jit'          => \ini_get('opcache.jit'),
            'extensions'   => \get_loaded_extensions(),
        ],
        'provenance'  => [
            'revision'       => \revisionOfDirectory(\dirname(__DIR__)),
            'runner_sha256'  => \hash_file('sha256', __FILE__),
            'harness_sha256' => \hash_file('sha256', __DIR__ . '/request-mapper.php'),
            'lock_sha256'    => \hash_file('sha256', __DIR__ . '/../composer.lock'),
        ],
    ];
}

/**
 * Every invocation runs in a fresh worker, including each error cardinality.
 *
 * @return array<string, mixed>
 */
function resourceWorker(string $scenario, int $iterations, int $warmup, int $samples): array
{
    $setupUsed      = \memory_get_usage(false);
    $setupAllocated = \memory_get_usage(true);
    \memory_reset_peak_usage();
    $metadata = \resourceMetadata();
    $nested   = \in_array($scenario, ['nested', 'cold', 'warm'], true);
    $count    = $nested ? 100 : (\str_starts_with($scenario, 'errors-') ? (int) \substr($scenario, 7) : 10000);
    $body     = $nested ? [
        'records' => \array_map(static fn (int $id): array => [
            'id'   => $id,
            'name' => 'record-' . $id,
        ], \range(1, 100)),
    ] : [
        'values' => \array_fill(0, $count, 'invalid-integer'),
    ];
    [$middleware, $counter] = \resourceServices(
        'input-limit' === $scenario ? new InputLimits(maxNodes: 1000) : null,
        'response-limit' === $scenario ? new ErrorResponseOptions(maxMessages: 100, maxResponseBytes: 8192) : null,
    );
    $handler = \passthroughHandler();
    $route   = new Route('/resources', new RequestHandlerMiddleware($handler), ['POST']);
    $route->setOptions([
        'valinor_mappings' => [[
            'body' => $nested ? ResourceRecords::class : ResourceIntegers::class,
        ]],
    ]);
    $request     = \makeRequest('POST', $body)->withAttribute(RouteResult::class, RouteResult::fromRoute($route));
    $correctness = [];
    $probe       = new class($nested) implements RequestHandlerInterface {
        public ?ServerRequestInterface $request = null;

        public function __construct(private readonly bool $nested) {}

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->request = $request;

            return new EmptyResponse();
        }

        public function verify(): int
        {
            if (! $this->nested) {
                throw new RuntimeException('Invalid input unexpectedly reached the handler');
            }
            $dto = $this->request?->getAttribute(ResourceRecords::class);
            if (! $dto instanceof ResourceRecords || 100 !== \count($dto->records)) {
                throw new RuntimeException('Nested DTO/list hydration failed');
            }

            foreach ($dto->records as $index => $record) {
                if ($record->id !== $index + 1 || $record->name !== 'record-' . ($index + 1)) {
                    throw new RuntimeException('Nested DTO value differs');
                }
            }

            return \count($dto->records);
        }
    };

    // The cold value is the first map on a fresh builder; setup is excluded.
    $start       = \hrtime(true);
    $response    = $middleware->process($request, $probe);
    $coldElapsed = (\hrtime(true) - $start) / 1000;
    \assertScenarioResult($response, $nested ? 204 : 422);
    $responseBytes = \strlen((string) $response->getBody());
    if ($nested) {
        $correctness['hydrated_records'] = $probe->verify();
    } else {
        $error    = \json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $retained = 0;
        foreach ($error['messages'] as $messages) {
            $retained += \count($messages);
        }
        $correctness['retained_messages'] = $retained;
        if ('input-limit' === $scenario) {
            if (0 !== $counter->calls || ! \str_contains((string) $response->getBody(), 'node limit')) {
                throw new RuntimeException('Input budget did not reject before mapping');
            }
            $correctness['mapper_calls'] = $counter->calls;
            $correctness['reason']       = 'input_node_limit_exceeded';
        } elseif ('response-limit' === $scenario) {
            if ($retained > 101 || $responseBytes > 8192) {
                throw new RuntimeException('Response caps exceeded');
            }
        } elseif ($retained !== $count) {
            throw new RuntimeException('Wrong error cardinality');
        }
    }
    $times = [];
    if ('cold' === $scenario) {
        $times[] = $coldElapsed;
    } else {
        for ($i = 0; $i < $warmup; ++$i) {
            $middleware->process($request, $handler);
        }

        for ($sample = 0; $sample < $samples; ++$sample) {
            $start = \hrtime(true);
            for ($i = 0; $i < $iterations; ++$i) {
                $middleware->process($request, $handler);
            }
            $times[] = (\hrtime(true) - $start) / 1000 / $iterations;
        }
    }

    if ('input-limit' === $scenario && 0 !== $counter->calls) {
        throw new RuntimeException('Measured oversized input unexpectedly mapped');
    }

    return [
        ...$metadata,
        'name'                  => $scenario,
        'lifecycle'             => 'cold' === $scenario ? 'cold-first-map' : 'warm-reuse',
        'status'                => $response->getStatusCode(),
        'correctness'           => $correctness,
        'samples_us_per_op'     => $times,
        'median_us_per_op'      => \benchmarkMedian($times),
        'setup_used_bytes'      => $setupUsed,
        'setup_allocated_bytes' => $setupAllocated,
        'peak_used_bytes'       => \memory_get_peak_usage(false),
        'peak_allocated_bytes'  => \memory_get_peak_usage(true),
        'response_bytes'        => $responseBytes,
        'rss_bytes'             => null,
    ];
}

/** @param list<string> $arguments */
function resourceMain(array $arguments): int
{
    try {
        $options = [
            'scenario'   => 'all',
            'iterations' => '100',
            'warmup'     => '10',
            'samples'    => '5',
        ];
        foreach (\array_slice($arguments, 1) as $argument) {
            if (1 !== \preg_match('/^--(scenario|iterations|warmup|samples|output|worker)=(.+)$/D', $argument, $match)) {
                throw new InvalidArgumentException('Invalid argument');
            }
            $options[$match[1]] = $match[2];
        }

        foreach (['iterations', 'warmup', 'samples'] as $key) {
            if (1 !== \preg_match('/^[0-9]+$/D', $options[$key]) || (int) $options[$key] < ('warmup' === $key ? 0 : 1) || (int) $options[$key] > 1000000) {
                throw new InvalidArgumentException('Invalid ' . $key);
            }
        }
        $iterations = (int) $options['iterations'];
        $warmup     = (int) $options['warmup'];
        $samples    = (int) $options['samples'];
        $groups     = [
            'nested'         => ['nested'],
            'errors'         => ['errors-10', 'errors-100', 'errors-1000', 'errors-10000'],
            'input-limit'    => ['input-limit'],
            'response-limit' => ['response-limit'],
            'cold-warm'      => ['cold', 'warm'],
        ];
        $all = \array_merge(...\array_values($groups));
        if (isset($options['worker'])) {
            if (! \in_array($options['worker'], $all, true)) {
                throw new InvalidArgumentException('Unknown worker');
            }
            echo \json_encode(\resourceWorker($options['worker'], $iterations, $warmup, $samples), JSON_THROW_ON_ERROR), "\n";

            return 0;
        }
        $scenarios   = 'all' === $options['scenario'] ? $all : ($groups[$options['scenario']] ?? throw new InvalidArgumentException('Unknown scenario'));
        $provenance  = \resourceMetadata()['provenance'];
        $environment = null;
        $results     = [];
        foreach ($scenarios as $scenario) {
            $command = [
                PHP_BINARY, '-d', 'memory_limit=' . \ini_get('memory_limit'), __FILE__,
                '--worker=' . $scenario, '--iterations=' . $iterations,
                '--warmup=' . $warmup, '--samples=' . $samples,
            ];
            $process = \proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, __DIR__);
            if (! \is_resource($process)) {
                throw new RuntimeException('Cannot start resource worker');
            }
            \fclose($pipes[0]);
            $stdout = (string) \stream_get_contents($pipes[1]);
            $stderr = (string) \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            if (0 !== \proc_close($process)) {
                throw new RuntimeException('Resource worker failed: ' . $stderr);
            }
            $result = \json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            if (! \is_array($result) || ($result['provenance'] ?? null) !== $provenance || ! \is_array($result['environment'] ?? null)) {
                throw new RuntimeException('Resource worker provenance differs');
            }

            if (null !== $environment && $result['environment'] !== $environment) {
                throw new RuntimeException('Resource worker environment differs');
            }
            $environment ??= $result['environment'];
            $results[] = $result;
        }
        $document = [
            'schema'      => 'sirix-mezzio-valinor-resource/1',
            'metric'      => 'elapsed microseconds per operation',
            'environment' => $environment,
            'provenance'  => $provenance,
            'params'      => [
                'iterations' => $iterations,
                'warmup'     => $warmup,
                'samples'    => $samples,
            ],
            'scenarios'   => $results,
        ];
        $json = \json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (isset($options['output'])) {
            $target    = $options['output'];
            $temporary = \tempnam(\dirname($target), '.resource-');
            if (false === $temporary) {
                throw new RuntimeException('Cannot create evidence file');
            }

            try {
                if (false === \file_put_contents($temporary, $json) || ! \rename($temporary, $target)) {
                    throw new RuntimeException('Cannot write evidence');
                }
            } finally {
                if (\is_file($temporary)) {
                    \unlink($temporary);
                }
            }
        } else {
            echo $json;
        }

        return 0;
    } catch (Throwable $error) {
        \fwrite(STDERR, "Usage: php benchmarks/resource-mapper.php --scenario=nested|errors|input-limit|response-limit|cold-warm|all\n"
            . " [--iterations=N --warmup=N --samples=N --output=file]\n" . $error->getMessage() . "\n");

        return 1;
    }
}

if (__FILE__ === \realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(\resourceMain($_SERVER['argv'] ?? []));
}

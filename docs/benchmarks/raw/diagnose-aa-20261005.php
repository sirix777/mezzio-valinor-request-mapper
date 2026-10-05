<?php

declare(strict_types=1);

// Diagnostic only; two equal-length detached paths contain the same source.
// Usage: taskset -c 6 php this.php <absolute-left-worktree> <absolute-right-worktree>
function aaExecute(array $command, string $directory): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    if (! is_resource($process)) {
        throw new RuntimeException('Cannot start A-A worker');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (0 !== proc_close($process)) {
        throw new RuntimeException($stderr);
    }

    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

$paths = ['left' => realpath($_SERVER['argv'][1] ?? ''), 'right' => realpath($_SERVER['argv'][2] ?? '')];
if (false === $paths['left'] || false === $paths['right'] || $paths['left'] === $paths['right'] || strlen($paths['left']) !== strlen($paths['right'])) {
    throw new InvalidArgumentException('Two distinct equal-length absolute worktree paths required');
}
$document = [
    'schema' => 'sirix-mezzio-valinor-aa-diagnostic/1',
    'acceptance_evidence' => false,
    'driver_sha256' => hash_file('sha256', __FILE__),
    'paths' => $paths,
    'order' => ['left', 'right', 'right', 'left', 'left', 'right', 'right', 'left'],
    'params' => ['scenario_ids' => [1, 15], 'iterations' => 100000, 'warmup' => 1000, 'samples' => 7, 'pcov_enabled' => true],
    'runtime' => [],
    'batches' => [],
];
$probe = 'require "benchmarks/request-mapper.php"; echo json_encode(provenance(), JSON_THROW_ON_ERROR);';
foreach ($paths as $label => $path) {
    $document['runtime'][$label] = aaExecute([PHP_BINARY, '-d', 'pcov.enabled=1', '-r', $probe], $path);
}
if ($document['runtime']['left'] !== $document['runtime']['right'] || true !== $document['runtime']['left']['versions']['pcov_enabled']) {
    throw new RuntimeException('A-A source/harness/lock/runtime provenance differs');
}
foreach ($document['order'] as $index => $label) {
    fwrite(STDERR, 'A-A batch ' . ($index + 1) . '/8 ' . $label . "\n");
    $results = [];
    foreach ([1, 15] as $scenario) {
        $result = aaExecute([PHP_BINARY, '-d', 'pcov.enabled=1', $paths[$label] . '/benchmarks/request-mapper.php',
            '--scenario=' . $scenario, '--iterations=100000', '--warmup=1000', '--samples=7'], $paths[$label]);
        if ($result['provenance'] !== $document['runtime'][$label]
            || $result['scenario_id'] !== $scenario
            || $result['params'] !== ['iterations' => 100000, 'warmup' => 1000, 'samples' => 7]) {
            throw new RuntimeException('A-A worker manifest differs');
        }
        $results[] = $result;
    }
    $document['batches'][] = ['label' => $label, 'scenarios' => $results];
}
echo json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

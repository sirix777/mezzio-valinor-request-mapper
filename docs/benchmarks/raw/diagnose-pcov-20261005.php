<?php

declare(strict_types=1);

// Diagnostic only: cannot replace the complete compatibility acceptance run.
// Usage: taskset -c 6 php -d pcov.enabled=0 this.php <absolute-baseline> <absolute-candidate>
function diagnosticExecute(array $command, string $directory): array
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start diagnostic worker'); }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (0 !== proc_close($process)) { throw new RuntimeException($stderr); }

    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

$paths = ['baseline' => realpath($_SERVER['argv'][1] ?? ''), 'optimized' => realpath($_SERVER['argv'][2] ?? '')];
foreach ($paths as $path) { if (false === $path) { throw new InvalidArgumentException('Absolute worktree paths required'); } }
$document = ['schema' => 'sirix-mezzio-valinor-diagnostic/1', 'acceptance_evidence' => false,
    'driver_sha256' => hash_file('sha256', __FILE__), 'order' => ['baseline', 'optimized', 'optimized', 'baseline', 'baseline', 'optimized', 'optimized', 'baseline'],
    'params' => ['controls_iterations' => 100000, 'mapped_iterations' => 10000, 'warmup' => 1000, 'samples' => 7], 'modes' => []];
foreach ([1, 0] as $enabled) {
    $mode = ['pcov_enabled' => $enabled, 'runtime' => [], 'batches' => []];
    foreach ($paths as $label => $path) {
        $mode['runtime'][$label] = diagnosticExecute([PHP_BINARY, '-d', 'pcov.enabled=' . $enabled, '-r',
            'require "benchmarks/request-mapper.php"; echo json_encode(["provenance"=>provenance(),"pcov_enabled"=>ini_get("pcov.enabled"),"jit"=>ini_get("opcache.jit"),"opcache_cli"=>ini_get("opcache.enable_cli")]);'], $path);
    }
    foreach ($document['order'] as $index => $label) {
        fwrite(STDERR, 'pcov=' . $enabled . ' batch=' . ($index + 1) . '/8 ' . $label . "\n");
        $results = [];
        foreach ([1, 15, 2, 3, 4, 5, 6] as $scenario) {
            $results[] = diagnosticExecute([PHP_BINARY, '-d', 'pcov.enabled=' . $enabled,
                $paths[$label] . '/benchmarks/request-mapper.php', '--scenario=' . $scenario,
                '--iterations=' . (in_array($scenario, [1, 15], true) ? 100000 : 10000), '--warmup=1000', '--samples=7'], $paths[$label]);
        }
        $mode['batches'][] = ['label' => $label, 'scenarios' => $results];
    }
    $document['modes'][] = $mode;
}
echo json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

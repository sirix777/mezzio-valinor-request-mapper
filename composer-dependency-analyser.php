<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

require_once __DIR__ . '/benchmarks/worker-soak.php';

require_once __DIR__ . '/benchmarks/resource-mapper.php';

require_once __DIR__ . '/benchmarks/compare.php';

require_once __DIR__ . '/benchmarks/aggregate.php';

return (new Configuration())->addPathToScan(__DIR__ . '/benchmarks', true);

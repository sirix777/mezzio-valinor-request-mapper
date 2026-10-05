<?php

declare(strict_types=1);

use Sirix\Mezzio\Valinor\Error\RequestInputError;
use Sirix\Mezzio\Valinor\Mapping\InputEncodingValidator;
use Sirix\Mezzio\Valinor\Mapping\InputLimits;

require __DIR__ . '/../../../vendor/autoload.php';

$elements = (int) ($argv[1] ?? 1_000_000);
$maxNodes = null === ($argv[2] ?? null) ? null : (int) $argv[2];
$shape    = $argv[3] ?? 'root';

$values = 'nested' === $shape
    ? [
        'outer' => [
            'nested' => \range(1, $elements),
        ],
    ]
    : \range(1, $elements);

try {
    (new InputEncodingValidator(new InputLimits(maxNodes: $maxNodes)))->assertValid($values, 'body');
    echo 'NO_ERROR', "\n";
} catch (RequestInputError $error) {
    echo 'REJECTED:', $error->reason, "\n";
}

<?php

declare(strict_types=1);


use Sirix\CsFixerConfig\ConfigBuilder;

return ConfigBuilder::create()
    ->setRules([
        '@PHP8x2Migration' => true,
        'PedroTroller/line_break_between_method_arguments' => [
            'max-args' => 4,
            'max-length' => 140,
            'automatic-argument-merge' => true,
            'inline-attributes' => true,
        ],
        'Gordinskiy/line_length_limit' => ['max_length' => 160],
        'php_unit_test_class_requires_covers' => false,
        'php_unit_internal_class' => false,
    ])
    ->getConfig()
    ->setUnsupportedPhpVersionAllowed(true)
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__ . '/src')
            ->in(__DIR__ . '/test')
            ->exclude('TestAsset'),
    )
    ;

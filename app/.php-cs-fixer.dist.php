<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([
                __DIR__ . '/bin',
                __DIR__ . '/config',
                __DIR__ . '/src',
                __DIR__ . '/tests',
            ])
            ->append([
                __DIR__ . '/deptrac.php',
                __DIR__ . '/testo.php',
            ]),
    );

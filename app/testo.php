<?php

declare(strict_types=1);

use Testo\Application\Config\ApplicationConfig;
use Testo\Application\Config\SuiteConfig;

return new ApplicationConfig(
    src: ['src'],
    suites: [
        new SuiteConfig(
            name: 'Unit',
            location: ['tests/Unit'],
        ),
        new SuiteConfig(
            name: 'Scenario',
            location: ['tests/Scenario'],
        ),
        new SuiteConfig(
            name: 'Integration',
            location: ['tests/Integration'],
        ),
        new SuiteConfig(
            name: 'Contract',
            location: ['tests/Contract'],
        ),
    ],
);

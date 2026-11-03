<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class ConsoleConfig
{
    public function __construct(
        public string $name,
        public string $version,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('APP_NAME must be a non-empty string.');
        }
        if (trim($this->version) === '') {
            throw new InvalidArgumentException('APP_VERSION must be a non-empty string.');
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            name: $environment->string('APP_NAME', 'geo-registry'),
            version: $environment->string('APP_VERSION', '0.1.0-dev'),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class HttpServerConfig
{
    public function __construct(
        public string $listen,
        public string $userAgent,
        public float $timeoutSeconds,
    ) {
        if (trim($this->listen) === '') {
            throw new InvalidArgumentException('APP_LISTEN must be a non-empty string.');
        }
        if (trim($this->userAgent) === '') {
            throw new InvalidArgumentException('APP_USER_AGENT must be a non-empty string.');
        }
        if ($this->timeoutSeconds <= 0) {
            throw new InvalidArgumentException('HTTP_TIMEOUT_SECONDS must be greater than zero.');
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            listen: $environment->string('APP_LISTEN', '127.0.0.1:8080'),
            userAgent: $environment->string('APP_USER_AGENT', 'geo-registry/0.1'),
            timeoutSeconds: $environment->positiveFloat('HTTP_TIMEOUT_SECONDS', 10),
        );
    }
}

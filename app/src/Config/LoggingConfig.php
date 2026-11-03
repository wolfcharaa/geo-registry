<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class LoggingConfig
{
    public function __construct(
        public string $service,
        public LogFormat $format,
    ) {
        if (trim($this->service) === '') {
            throw new InvalidArgumentException('APP_NAME must be a non-empty string.');
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        $format = $environment->string('LOG_FORMAT', LogFormat::Json->value);

        return new self(
            service: $environment->string('APP_NAME', 'geo-registry'),
            format: LogFormat::tryFrom($format)
                ?? throw new InvalidArgumentException('LOG_FORMAT must be json or console.'),
        );
    }
}

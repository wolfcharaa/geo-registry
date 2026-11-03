<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class PostgresConfig
{
    public function __construct(
        public string $dsn,
        public int $poolSize,
    ) {
        if (trim($this->dsn) === '') {
            throw new InvalidArgumentException('DATABASE_DSN must be a non-empty string.');
        }
        if ($this->poolSize <= 0) {
            throw new InvalidArgumentException('DATABASE_POOL_SIZE must be greater than zero.');
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            dsn: $environment->string(
                'DATABASE_DSN',
                'host=127.0.0.1 port=5432 user=app password=local-app-only db=app application_name=geo-registry',
            ),
            poolSize: $environment->positiveInteger('DATABASE_POOL_SIZE', 10),
        );
    }
}

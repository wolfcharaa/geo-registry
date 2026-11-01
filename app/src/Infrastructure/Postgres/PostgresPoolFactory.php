<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use InvalidArgumentException;

final class PostgresPoolFactory
{
    public static function create(string $dsn, int $maxConnections): PostgresConnectionPool
    {
        if ($maxConnections <= 0) {
            throw new InvalidArgumentException('PostgreSQL pool size must be greater than zero.');
        }

        return new PostgresConnectionPool(
            PostgresConfig::fromString($dsn),
            maxConnections: $maxConnections,
        );
    }
}

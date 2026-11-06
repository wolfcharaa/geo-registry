<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use InvalidArgumentException;

final class PostgresPoolFactory
{
    public static function create(
        string $dsn,
        int $maxConnections,
        int $statementTimeoutMilliseconds = 0,
    ): PostgresConnectionPool {
        if ($maxConnections <= 0) {
            throw new InvalidArgumentException('PostgreSQL pool size must be greater than zero.');
        }
        if ($statementTimeoutMilliseconds < 0) {
            throw new InvalidArgumentException('PostgreSQL statement timeout must not be negative.');
        }

        $config = PostgresConfig::fromString($dsn);
        if ($statementTimeoutMilliseconds > 0) {
            $options = trim((string) $config->getOptions());
            $options .= ($options === '' ? '' : ' ')
                . '-c statement_timeout=' . $statementTimeoutMilliseconds;
            $config = $config->withOptions($options);
        }

        return new PostgresConnectionPool(
            $config,
            maxConnections: $maxConnections,
        );
    }
}

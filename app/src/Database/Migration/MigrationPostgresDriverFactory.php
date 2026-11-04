<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Amp\Postgres\PostgresConfig as AmpPostgresConfig;
use App\Config\PostgresConfig;
use InvalidArgumentException;
use PDO;
use Yiisoft\Db\Pgsql\Driver;

final readonly class MigrationPostgresDriverFactory
{
    public function create(PostgresConfig $config): Driver
    {
        $postgres = AmpPostgresConfig::fromString($config->dsn);
        $driver = new Driver(
            $this->pdoDsn($postgres),
            $postgres->getUser() ?? '',
            $postgres->getPassword() ?? '',
            [PDO::ATTR_EMULATE_PREPARES => false],
        );
        $driver->charset('UTF8');

        return $driver;
    }

    private function pdoDsn(AmpPostgresConfig $config): string
    {
        $parts = [
            'host=' . $this->dsnValue('host', $config->getHost()),
            'port=' . $config->getPort(),
        ];

        $this->append($parts, 'dbname', $config->getDatabase());
        $this->append($parts, 'sslmode', $config->getSslMode());
        $this->append($parts, 'application_name', $config->getApplicationName());
        $this->append($parts, 'options', $config->getOptions());

        return 'pgsql:' . implode(';', $parts);
    }

    /** @param list<string> $parts */
    private function append(array &$parts, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $parts[] = $name . '=' . $this->dsnValue($name, $value);
    }

    private function dsnValue(string $name, string $value): string
    {
        if (str_contains($value, ';') || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException("DATABASE_DSN parameter {$name} contains an unsupported delimiter.");
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use Amp\Postgres\PostgresConfig;
use InvalidArgumentException;
use PDO;

final readonly class PdoConnectionFactory
{
    public function create(string $databaseDsn): PDO
    {
        $config = PostgresConfig::fromString($databaseDsn);

        return new PDO(
            $this->pdoDsn($config),
            $config->getUser() ?? '',
            $config->getPassword() ?? '',
            [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        );
    }

    private function pdoDsn(PostgresConfig $config): string
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
        if ($value !== null && $value !== '') {
            $parts[] = $name . '=' . $this->dsnValue($name, $value);
        }
    }

    private function dsnValue(string $name, string $value): string
    {
        if (str_contains($value, ';') || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException("DATABASE_DSN parameter {$name} contains an unsupported delimiter.");
        }

        return $value;
    }
}

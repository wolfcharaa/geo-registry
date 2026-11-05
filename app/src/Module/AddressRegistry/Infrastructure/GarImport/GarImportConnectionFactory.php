<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use PgSql\Connection;

final readonly class GarImportConnectionFactory
{
    public function __construct(
        private string $databaseDsn,
    ) {}

    public function connect(): Connection
    {
        $connectionString = preg_replace('/(^|\s)db=/', '$1dbname=', $this->databaseDsn);
        if (!is_string($connectionString)) {
            throw new GarImportFailed('Unable to normalize PostgreSQL connection settings for GAR import.');
        }

        $connection = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        if (!$connection instanceof Connection) {
            throw new GarImportFailed('Unable to connect to PostgreSQL for GAR import.');
        }

        return $connection;
    }
}

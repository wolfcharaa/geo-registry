<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use PgSql\Connection;
use Throwable;

/** @implements Task<GarIndexBuildTaskResult, never, never> */
final class GarIndexBuildTask implements Task
{
    private static ?Connection $connection = null;

    private static ?string $connectionDsn = null;

    public function __construct(
        private readonly string $databaseDsn,
        private readonly int $operationId,
        private readonly string $indexName,
    ) {
        if ($this->operationId < 1) {
            throw new GarImportFailed('GAR index task operation id must be positive.');
        }
        if (\preg_match('/^[a-zA-Z_][a-zA-Z0-9_$]*$/D', $this->indexName) !== 1) {
            throw new GarImportFailed('GAR index task received an invalid index name.');
        }
    }

    public function run(Channel $channel, Cancellation $cancellation): GarIndexBuildTaskResult
    {
        $cancellation->throwIfRequested();
        $connection = $this->connection();

        try {
            (new GarInitialLoadStore($connection))->buildIndex($this->operationId, $this->indexName);
        } catch (Throwable $error) {
            if ($error instanceof GarImportFailed) {
                throw $error;
            }

            throw new GarImportFailed('GAR index build failed: ' . $error->getMessage(), previous: $error);
        }

        return new GarIndexBuildTaskResult(
            indexName: $this->indexName,
            backendPid: \pg_get_pid($connection),
        );
    }

    private function connection(): Connection
    {
        if (
            self::$connection instanceof Connection
            && self::$connectionDsn === $this->databaseDsn
            && \pg_connection_status(self::$connection) === PGSQL_CONNECTION_OK
        ) {
            return self::$connection;
        }
        if (self::$connection instanceof Connection) {
            \pg_close(self::$connection);
        }
        self::$connection = new GarImportConnectionFactory($this->databaseDsn)->connect();
        self::$connectionDsn = $this->databaseDsn;

        return self::$connection;
    }
}

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use PgSql\Connection;

/** @implements Task<GarDirectCopyBatchResult, never, never> */
final class GarDirectCopyBatchTask implements Task
{
    private static ?Connection $connection = null;

    private static ?string $connectionDsn = null;

    public readonly int $encodedBytes;

    /**
     * @param list<string> $rows
     */
    public function __construct(
        private readonly string $databaseDsn,
        private readonly int $operationId,
        private readonly string $fileKey,
        private readonly string $copyTarget,
        private readonly array $rows,
        public readonly int $firstRow,
        public readonly int $lastRow,
    ) {
        if (
            $this->operationId < 1
            || $this->rows === []
            || $this->firstRow < 1
            || $this->lastRow - $this->firstRow + 1 !== \count($this->rows)
        ) {
            throw new GarImportFailed('GAR direct-copy batch task received an invalid contiguous range.');
        }
        $this->encodedBytes = \array_sum(\array_map(strlen(...), $this->rows));
        if ($this->encodedBytes < 1) {
            throw new GarImportFailed('GAR direct-copy batch task received an empty encoded payload.');
        }
    }

    public function run(Channel $channel, Cancellation $cancellation): GarDirectCopyBatchResult
    {
        $cancellation->throwIfRequested();
        $connection = $this->connection();
        $processedRows = new PostgresGarImportStore($connection)->commitDirectCopyRange(
            operationId: $this->operationId,
            fileKey: $this->fileKey,
            copyTarget: $this->copyTarget,
            rows: $this->rows,
            firstRow: $this->firstRow,
            lastRow: $this->lastRow,
            encodedBytes: $this->encodedBytes,
        );

        return new GarDirectCopyBatchResult(
            firstRow: $this->firstRow,
            lastRow: $this->lastRow,
            processedRows: $processedRows,
            encodedBytes: $this->encodedBytes,
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

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\AddressSearchRebuild;

use App\Module\AddressRegistry\Capability\RebuildAddressSearch\AddressSearchRebuildFailed;
use App\Module\AddressRegistry\Capability\RebuildAddressSearch\RebuildAddressSearch;
use App\Module\AddressRegistry\Capability\RebuildAddressSearch\RebuildAddressSearchPort;
use App\Module\AddressRegistry\Capability\RebuildAddressSearch\RebuildAddressSearchResult;
use PgSql\Connection;
use Throwable;

final readonly class PostgresAddressSearchRebuildAdapter implements RebuildAddressSearchPort
{
    public function __construct(
        private string $databaseDsn,
    ) {}

    public function rebuild(RebuildAddressSearch $command): RebuildAddressSearchResult
    {
        $connection = $this->connect();
        $store = new PostgresAddressSearchRebuildStore($connection);
        $lockAcquired = false;
        $operationId = null;
        $currentTask = null;
        $startedAt = hrtime(true);
        $elapsedRecorded = false;

        try {
            $store->acquireLock();
            $lockAcquired = true;
            $operation = $store->startOperation($command->batchSize, $command->restart);
            $operationId = $operation->id;

            foreach ($operation->abandonedTables as $abandonedTable) {
                $store->dropShadowTable($abandonedTable);
            }

            if (!$operation->ready) {
                $store->prepareShadow($operation->id, $operation->shadowTable);

                foreach ($store->tasks($operation->id) as $task) {
                    if ($task->completed) {
                        continue;
                    }

                    $currentTask = $task;
                    $store->startTask($operation->id, $task);
                    $lastId = $task->lastHierarchyId;

                    while (($batch = $store->nextBatch($task, $lastId, $command->batchSize)) !== null) {
                        $store->writeBatch(
                            operationId: $operation->id,
                            shadowTable: $operation->shadowTable,
                            task: $task,
                            lastId: $lastId,
                            batch: $batch,
                        );
                        $lastId = $batch->maxHierarchyId;
                    }

                    $store->completeTask($operation->id, $task);
                    $currentTask = null;
                }

                $store->createIndexes($operation->id, $operation->shadowTable);
                $parity = $store->verify($operation->id, $operation->shadowTable);
                if (!$parity->isValid()) {
                    throw new AddressSearchRebuildFailed(sprintf(
                        'address_search parity failed: rows=%d missing=%d duplicates=%d invalid_regions=%d.',
                        $parity->shadowRows,
                        $parity->missingRows,
                        $parity->duplicateRows,
                        $parity->invalidRegionRows,
                    ));
                }

                $store->markReady($operation->id, self::elapsedMilliseconds($startedAt));
                $elapsedRecorded = true;
            }

            if ($command->buildOnly) {
                if (!$elapsedRecorded) {
                    $store->addElapsed($operation->id, self::elapsedMilliseconds($startedAt));
                }

                return $store->result($operation->id, $operation->resumed);
            }

            $store->switch($operation->id, $operation->shadowTable);
            if (!$elapsedRecorded) {
                $store->addElapsed($operation->id, self::elapsedMilliseconds($startedAt));
            }

            return $store->result($operation->id, $operation->resumed);
        } catch (Throwable $error) {
            if ($operationId !== null) {
                $store->failOperation($operationId, $currentTask, $error);
            }
            if ($error instanceof AddressSearchRebuildFailed) {
                throw $error;
            }

            throw new AddressSearchRebuildFailed(
                'address_search rebuild failed: ' . $error->getMessage(),
                previous: $error,
            );
        } finally {
            if ($lockAcquired) {
                try {
                    $store->releaseLock();
                } catch (Throwable) {
                    // Closing the dedicated CLI connection releases the session lock.
                }
            }
            pg_close($connection);
        }
    }

    private function connect(): Connection
    {
        $connectionString = preg_replace('/(^|\s)db=/', '$1dbname=', $this->databaseDsn);
        if (!is_string($connectionString)) {
            throw new AddressSearchRebuildFailed('Unable to normalize PostgreSQL rebuild connection settings.');
        }

        $connection = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        if (!$connection instanceof Connection) {
            throw new AddressSearchRebuildFailed('Unable to connect to PostgreSQL for address_search rebuild.');
        }

        return $connection;
    }

    private static function elapsedMilliseconds(int|float $startedAt): int
    {
        return max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000));
    }
}

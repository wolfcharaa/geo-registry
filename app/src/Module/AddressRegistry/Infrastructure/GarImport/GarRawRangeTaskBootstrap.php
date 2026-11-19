<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Fedor\WorkOrchestrator\Contract\ManagedTaskBootstrap;
use LogicException;
use PgSql\Connection;
use Psr\Container\ContainerInterface;

/** Owns one reusable PostgreSQL connection in each managed range child. */
final class GarRawRangeTaskBootstrap implements ManagedTaskBootstrap
{
    private ?Connection $connection = null;

    public function __construct(private readonly string $databaseDsn) {}

    public function boot(Cancellation $cancellation): ContainerInterface
    {
        $cancellation->throwIfRequested();
        if ($this->connection !== null) {
            throw new LogicException('GAR raw child runtime is already bootstrapped.');
        }

        $connection = new GarImportConnectionFactory($this->databaseDsn)->connect();

        try {
            $cancellation->throwIfRequested();
            $processId = \getmypid();
            $result = \pg_query_params(
                $connection,
                <<<'SQL'
SELECT set_config('application_name', $1, false)
SQL,
                ['geo-registry-gar-raw-child-' . ($processId === false ? 'unknown' : $processId)],
            );
            if (!$result instanceof \PgSql\Result) {
                throw new GarImportFailed('Unable to identify the GAR raw managed-child connection.');
            }
            \pg_free_result($result);
            $this->connection = $connection;

            return new GarRawRangeTaskContainer(new GarRawRangeTaskHandler($connection));
        } catch (\Throwable $failure) {
            \pg_close($connection);
            throw $failure;
        }
    }

    public function close(): void
    {
        $connection = $this->connection;
        $this->connection = null;
        if ($connection !== null) {
            \pg_close($connection);
        }
    }
}

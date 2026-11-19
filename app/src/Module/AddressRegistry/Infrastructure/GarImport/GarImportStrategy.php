<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\BootstrapsApplication;
use Fedor\WorkOrchestrator\WorkEnvelope;
use InvalidArgumentException;
use PgSql\Connection;
use Throwable;

/**
 * Owns GAR worker-local dependencies and one-file import work.
 *
 * @implements BootstrapsApplication<GarXmlSourceFile, string>
 */
final class GarImportStrategy implements BootstrapsApplication
{
    private ?Connection $connection = null;

    private ?PostgresGarImportStore $store = null;

    private ?GarDirectCopyRowMapper $directCopyMapper = null;

    private ?GarXmlFileImporter $fileImporter = null;

    private bool $writeModeVerified = false;

    public function __construct(
        private readonly string $databaseDsn,
        private readonly int $operationId,
        private readonly int $batchSize,
        private readonly bool $directCopy,
    ) {
        if ($this->operationId < 1) {
            throw new InvalidArgumentException('GAR import operation id must be positive.');
        }
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException('GAR import batch size must be positive.');
        }
    }

    public function boot(Cancellation $cancellation): void
    {
        $cancellation->throwIfRequested();
        if ($this->connection !== null) {
            throw new \LogicException('GAR import strategy is already bootstrapped.');
        }

        $connection = new GarImportConnectionFactory($this->databaseDsn)->connect();

        try {
            $this->store = new PostgresGarImportStore($connection);
            $this->directCopyMapper = new GarDirectCopyRowMapper($connection);
            $this->fileImporter = new GarXmlFileImporter(
                new GarXmlRowReader(),
                new GarCopyRowEncoder(),
            );
            $this->connection = $connection;
        } catch (Throwable $failure) {
            \pg_close($connection);
            throw $failure;
        }
    }

    /** @param WorkEnvelope<GarXmlSourceFile> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): string
    {
        $store = $this->store
            ?? throw new \LogicException('GAR import strategy is not bootstrapped.');
        $directCopyMapper = $this->directCopyMapper
            ?? throw new \LogicException('GAR direct-copy mapper is not bootstrapped.');
        $fileImporter = $this->fileImporter
            ?? throw new \LogicException('GAR XML importer is not bootstrapped.');

        if (!$this->writeModeVerified) {
            $store->assertWorkerWriteMode($this->operationId, $this->directCopy);
            $this->writeModeVerified = true;
        }

        return $fileImporter->import(
            store: $store,
            directCopyMapper: $directCopyMapper,
            operationId: $this->operationId,
            batchSize: $this->batchSize,
            directCopy: $this->directCopy,
            file: $work->payload,
            cancellation: $cancellation,
        );
    }

    public function close(): void
    {
        $connection = $this->connection;
        $this->connection = null;
        $this->store = null;
        $this->directCopyMapper = null;
        $this->fileImporter = null;
        $this->writeModeVerified = false;

        if ($connection !== null) {
            \pg_close($connection);
        }
    }

}

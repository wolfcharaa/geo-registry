<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarForeignKeyValidationWorker;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarForeignKeyValidationWorkerPort;
use Throwable;

final readonly class GarForeignKeyValidationWorkerAdapter implements RunGarForeignKeyValidationWorkerPort
{
    public function __construct(private GarImportConnectionFactory $connectionFactory) {}

    public function run(RunGarForeignKeyValidationWorker $command): void
    {
        $connection = $this->connectionFactory->connect();
        try {
            (new GarInitialLoadStore($connection))->validateForeignKey(
                $command->operationId,
                $command->tableName,
                $command->constraintName,
            );
        } catch (Throwable $error) {
            if ($error instanceof GarImportFailed) {
                throw $error;
            }

            throw new GarImportFailed('GAR foreign-key validation failed: ' . $error->getMessage(), previous: $error);
        } finally {
            \pg_close($connection);
        }
    }
}

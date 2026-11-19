<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarIndexBuildWorker;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarIndexBuildWorkerPort;
use Throwable;

final readonly class GarIndexBuildWorkerAdapter implements RunGarIndexBuildWorkerPort
{
    public function __construct(private GarImportConnectionFactory $connectionFactory) {}

    public function run(RunGarIndexBuildWorker $command): void
    {
        $connection = $this->connectionFactory->connect();
        try {
            (new GarInitialLoadStore($connection))->buildIndex($command->operationId, $command->indexName);
        } catch (Throwable $error) {
            if ($error instanceof GarImportFailed) {
                throw $error;
            }

            throw new GarImportFailed('GAR index build failed: ' . $error->getMessage(), previous: $error);
        } finally {
            \pg_close($connection);
        }
    }
}

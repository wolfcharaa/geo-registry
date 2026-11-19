<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\RunGarIndexScheduler;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarIndexSchedulerPort;

final readonly class GarIndexSchedulerAdapter implements RunGarIndexSchedulerPort
{
    public function __construct(private GarInitialLoadIndexPool $indexPool) {}

    public function run(RunGarIndexScheduler $command): void
    {
        $this->indexPool->runForCompletedTables(
            $command->operationId,
            $command->workerCount,
            $command->startPercent,
            $command->persistentWorkers,
        );
    }
}

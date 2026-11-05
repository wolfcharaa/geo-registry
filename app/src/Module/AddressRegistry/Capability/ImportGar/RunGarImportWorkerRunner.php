<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

final readonly class RunGarImportWorkerRunner
{
    public function __construct(
        private RunGarImportWorkerPort $worker,
    ) {}

    public function run(RunGarImportWorker $command): void
    {
        $this->worker->run($command);
    }
}

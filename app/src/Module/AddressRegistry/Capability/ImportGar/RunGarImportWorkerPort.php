<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

interface RunGarImportWorkerPort
{
    public function run(RunGarImportWorker $command): void;
}

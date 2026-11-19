<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

interface RunGarForeignKeyValidationWorkerPort
{
    public function run(RunGarForeignKeyValidationWorker $command): void;
}

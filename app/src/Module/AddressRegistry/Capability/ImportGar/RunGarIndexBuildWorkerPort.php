<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

interface RunGarIndexBuildWorkerPort
{
    public function run(RunGarIndexBuildWorker $command): void;
}

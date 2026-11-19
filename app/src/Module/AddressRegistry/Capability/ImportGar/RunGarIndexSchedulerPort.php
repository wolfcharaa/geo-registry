<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

interface RunGarIndexSchedulerPort
{
    public function run(RunGarIndexScheduler $command): void;
}

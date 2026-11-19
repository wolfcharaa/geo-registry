<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGarRaw;

interface ImportGarRawPort
{
    public function import(ImportGarRaw $command): ImportGarRawResult;
}

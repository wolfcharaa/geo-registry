<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

interface ImportGarPort
{
    public function import(ImportGar $command): ImportGarResult;
}

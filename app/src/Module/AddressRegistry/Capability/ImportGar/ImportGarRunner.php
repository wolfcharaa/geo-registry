<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

final readonly class ImportGarRunner
{
    public function __construct(
        private ImportGarPort $importer,
    ) {}

    public function run(ImportGar $command): ImportGarResult
    {
        return $this->importer->import($command);
    }
}

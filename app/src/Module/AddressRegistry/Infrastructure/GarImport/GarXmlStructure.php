<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarXmlStructure
{
    public function __construct(
        public string $rootElement,
        public string $rowElement,
    ) {}
}

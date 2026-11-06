<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

final readonly class AddressDocument
{
    /**
     * @param list<string> $cadastralNumbers
     * @param list<string> $landCadastralNumbers
     */
    public function __construct(
        public int $objectId,
        public ?string $objectGuid,
        public string $address,
        public AddressHierarchy $hierarchy,
        public int $level,
        public ?string $postalCode,
        public ?string $okato,
        public ?string $oktmo,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $source,
        public ?string $sourceId,
        public ?int $dataMosGlobalId,
        public ?int $dataMosUnom,
        public ?int $dataMosNreg,
        public ?string $dataMosAdministrativeArea,
        public ?string $dataMosDistrict,
        public array $cadastralNumbers,
        public array $landCadastralNumbers,
        public int $aliasCount,
        public ?int $regionCode = null,
    ) {}
}

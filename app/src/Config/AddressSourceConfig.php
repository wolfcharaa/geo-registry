<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

final readonly class AddressSourceConfig
{
    public function __construct(
        public string $dataMosAddressRegistrySource,
        public ?string $dataMosApiKey,
        public string $osmAddressAliasesSource,
        public int $moscowRegionCode,
    ) {
        if (trim($this->dataMosAddressRegistrySource) === '') {
            throw new InvalidArgumentException('DATAMOS_ADDRESS_REGISTRY_SOURCE must be a non-empty string.');
        }
        if (trim($this->osmAddressAliasesSource) === '') {
            throw new InvalidArgumentException('OSM_ADDRESS_ALIASES_SOURCE must be a non-empty string.');
        }
        if ($this->moscowRegionCode < 1 || $this->moscowRegionCode > 99) {
            throw new InvalidArgumentException('MOSCOW_REGION_CODE must be between 1 and 99.');
        }
    }

    public static function fromEnvironment(EnvironmentSnapshot $environment): self
    {
        return new self(
            dataMosAddressRegistrySource: $environment->string(
                'DATAMOS_ADDRESS_REGISTRY_SOURCE',
                '/data/datamos/addressreestr.jsonl.gz',
            ),
            dataMosApiKey: $environment->optionalString('DATAMOS_API_KEY'),
            osmAddressAliasesSource: $environment->string(
                'OSM_ADDRESS_ALIASES_SOURCE',
                '/data/geodata/moscow-address-aliases.jsonl',
            ),
            moscowRegionCode: $environment->positiveInteger('MOSCOW_REGION_CODE', 77),
        );
    }
}

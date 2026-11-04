<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

final readonly class ProgressiveAddressSuggestion
{
    public SuggestionStage $nextStage;
    public bool $readyForSearch;

    public function __construct(
        public int $objectId,
        public string $objectGuid,
        public string $name,
        public string $typeName,
        public AddressHierarchy $hierarchy,
        public int $level,
        public int $regionCode,
        public ?SuggestionDistrict $district,
    ) {
        $this->nextStage = match (true) {
            $level >= 7 => SuggestionStage::Search,
            $level >= 5 => SuggestionStage::Thoroughfare,
            default => SuggestionStage::AdministrativeArea,
        };
        $this->readyForSearch = $this->nextStage === SuggestionStage::Search;
    }

    public function displayValue(): string
    {
        return trim($this->typeName . ' ' . $this->name);
    }
}

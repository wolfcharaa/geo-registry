<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

use function in_array;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function sprintf;
use function trim;

use UnexpectedValueException;

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
            $this->isFederalCity() => SuggestionStage::Thoroughfare,
            $level <= 1 => SuggestionStage::AdministrativeArea,
            $level <= 7 => SuggestionStage::Thoroughfare,
            $level === 8 => SuggestionStage::Search,
            default => throw new UnexpectedValueException(sprintf(
                'GAR suggestion level %d is not supported.',
                $level,
            )),
        };
        $this->readyForSearch = $this->nextStage === SuggestionStage::Search;
    }

    public function displayValue(): string
    {
        return trim($this->typeName . ' ' . $this->name);
    }

    private function isFederalCity(): bool
    {
        if ($this->level !== 1) {
            return false;
        }

        if (in_array($this->normalized($this->name), ['москва', 'санкт-петербург', 'севастополь'], true)) {
            return true;
        }

        return preg_match(
            '/^(?:г\.?\s+|город\s+)?(?:москва|санкт-петербург|севастополь)(?:$|,)/u',
            $this->normalized($this->displayValue()),
        ) === 1;
    }

    private function normalized(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}

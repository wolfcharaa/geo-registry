<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestAddress;

use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;

final readonly class SuggestAddressResult
{
    /**
     * @param list<ProgressiveAddressSuggestion> $progressiveSuggestions
     * @param list<SearchAddressMatch> $searchMatches
     */
    private function __construct(
        public array $progressiveSuggestions,
        public array $searchMatches,
    ) {}

    /** @param list<ProgressiveAddressSuggestion> $suggestions */
    public static function progressive(array $suggestions): self
    {
        return new self($suggestions, []);
    }

    /** @param list<SearchAddressMatch> $matches */
    public static function search(array $matches): self
    {
        return new self([], $matches);
    }

    public function count(): int
    {
        return count($this->progressiveSuggestions) + count($this->searchMatches);
    }
}

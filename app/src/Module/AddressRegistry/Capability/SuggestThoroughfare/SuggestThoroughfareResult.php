<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestThoroughfare;

use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;

final readonly class SuggestThoroughfareResult
{
    /** @param list<ProgressiveAddressSuggestion> $suggestions */
    public function __construct(public array $suggestions) {}
}

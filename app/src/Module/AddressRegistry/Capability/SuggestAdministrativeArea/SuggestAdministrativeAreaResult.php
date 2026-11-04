<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestAdministrativeArea;

use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;

final readonly class SuggestAdministrativeAreaResult
{
    /** @param list<ProgressiveAddressSuggestion> $suggestions */
    public function __construct(public array $suggestions) {}
}

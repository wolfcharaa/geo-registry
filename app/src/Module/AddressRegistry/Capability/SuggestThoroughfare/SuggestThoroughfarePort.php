<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestThoroughfare;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\SuggestionCriteria;

interface SuggestThoroughfarePort
{
    /** @return list<ProgressiveAddressSuggestion> */
    public function suggestThoroughfares(
        SuggestionCriteria $criteria,
        Cancellation $cancellation,
    ): array;
}

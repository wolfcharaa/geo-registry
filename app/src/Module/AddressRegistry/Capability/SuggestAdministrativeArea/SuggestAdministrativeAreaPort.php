<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestAdministrativeArea;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\SuggestionCriteria;

interface SuggestAdministrativeAreaPort
{
    /** @return list<ProgressiveAddressSuggestion> */
    public function suggestAdministrativeAreas(
        SuggestionCriteria $criteria,
        Cancellation $cancellation,
    ): array;
}

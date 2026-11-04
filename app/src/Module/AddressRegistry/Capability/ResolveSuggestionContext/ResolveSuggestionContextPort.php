<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ResolveSuggestionContext;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SuggestionContext;

interface ResolveSuggestionContextPort
{
    public function resolve(
        int $parentId,
        AddressHierarchy $hierarchy,
        Cancellation $cancellation,
    ): ?SuggestionContext;
}

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ResolveSuggestionContext;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SuggestionContext;
use Fedor\MessageBus\Message\Query;

/** @implements Query<SuggestionContext|null> */
final readonly class ResolveSuggestionContext implements Query
{
    public function __construct(
        public int $parentId,
        public AddressHierarchy $hierarchy,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SuggestAddress;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SuggestionStage;
use Fedor\MessageBus\Message\Query;

/** @implements Query<SuggestAddressResult> */
final readonly class SuggestAddress implements Query
{
    public string $exact;
    public string $suggest;
    public int $count;

    public function __construct(
        string $suggest,
        public SuggestionStage $stage,
        public AddressHierarchy $hierarchy,
        string $exact = '',
        int $count = 5,
        public ?int $parentId = null,
    ) {
        $this->exact = self::normalize($exact);
        $this->suggest = self::normalize($suggest);
        $this->count = max(1, min(7, $count));
    }

    private static function normalize(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return $normalized ?? trim($value);
    }
}

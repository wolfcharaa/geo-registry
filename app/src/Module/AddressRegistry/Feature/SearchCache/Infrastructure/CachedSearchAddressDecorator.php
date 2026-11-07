<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\SearchCache\Infrastructure;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;

final readonly class CachedSearchAddressDecorator implements SearchAddressPort
{
    private const KEY_VERSION = 'address-search-v1';

    public function __construct(
        private SearchAddressPort $reader,
        private AddressSearchResultCache $cache,
        private string $namespace,
    ) {}

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        return $this->cache->remember(
            $this->key($criteria),
            $cancellation,
            fn(): array => $this->reader->search($criteria, $cancellation),
        );
    }

    private function key(SearchAddressCriteria $criteria): string
    {
        $identity = json_encode([
            'query' => $criteria->query,
            'hierarchy' => $criteria->hierarchy->value,
            'profile' => $criteria->profile->value,
            'limit' => $criteria->limit,
            'region_code' => $criteria->regionCode,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return self::KEY_VERSION . ':' . $this->namespace . ':' . hash('sha256', $identity);
    }
}

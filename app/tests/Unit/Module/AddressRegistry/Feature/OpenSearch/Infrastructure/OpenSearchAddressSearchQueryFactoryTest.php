<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Feature\OpenSearch\Infrastructure;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressSearchQueryFactory;

use function is_array;

use Testo\Assert;
use Testo\Test;

#[Test]
final class OpenSearchAddressSearchQueryFactoryTest
{
    public function testItBoostsHousesForAQueryEndingWithAHouseNumber(): void
    {
        $query = (new OpenSearchAddressSearchQueryFactory())->create(
            $this->criteria('Одинцово Можайское 1'),
        );
        $functionScore = $this->array($query['function_score'] ?? null);
        $nestedQuery = $this->array($functionScore['query'] ?? null);
        $bool = $this->array($nestedQuery['bool'] ?? null);

        Assert::same($functionScore['functions'] ?? null, [[
            'filter' => ['term' => ['level' => 10]],
            'weight' => 1000,
        ]]);
        Assert::same($functionScore['score_mode'] ?? null, 'sum');
        Assert::same($functionScore['boost_mode'] ?? null, 'sum');
        Assert::same(
            $bool['filter'] ?? null,
            [
                ['term' => ['hierarchy_type' => 'adm']],
                ['term' => ['region_code' => 50]],
            ],
        );
    }

    public function testItDoesNotBoostAQueryThatTargetsAUnit(): void
    {
        $query = (new OpenSearchAddressSearchQueryFactory())->create(
            $this->criteria('Одинцово Можайское д. 1 к. 1 кв. 1'),
        );

        Assert::true(isset($query['bool']));
        Assert::false(isset($query['function_score']));
    }

    private function criteria(string $query): SearchAddressCriteria
    {
        return new SearchAddressCriteria(
            query: $query,
            hierarchy: AddressHierarchy::Administrative,
            limit: 5,
            profile: SearchProfile::Search,
            regionCode: 50,
        );
    }

    /** @return array<array-key, mixed> */
    private function array(mixed $value): array
    {
        Assert::true(is_array($value));

        return $value;
    }
}

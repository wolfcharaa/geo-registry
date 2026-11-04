<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure;

use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;

final class OpenSearchAddressSearchQueryFactory
{
    /** @return array<string, mixed> */
    public function create(SearchAddressCriteria $criteria): array
    {
        $filters = [
            ['term' => ['hierarchy_type' => $criteria->hierarchy->value]],
        ];
        if ($criteria->regionCode !== null) {
            $filters[] = ['term' => ['region_code' => $criteria->regionCode]];
        }

        return [
            'bool' => [
                'filter' => $filters,
                'must' => [[
                    'multi_match' => [
                        'query' => $criteria->query,
                        'fields' => ['address^4', 'aliases.value^2'],
                        'fuzziness' => 'AUTO',
                        'operator' => 'and',
                    ],
                ]],
                'should' => [[
                    'term' => [
                        'address.keyword' => [
                            'value' => $criteria->query,
                            'case_insensitive' => true,
                            'boost' => 8,
                        ],
                    ],
                ]],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sort(): array
    {
        return [
            ['_score' => ['order' => 'desc']],
            ['level' => ['order' => 'desc']],
            ['address.keyword' => ['order' => 'asc']],
            ['object_id' => ['order' => 'asc']],
        ];
    }
}

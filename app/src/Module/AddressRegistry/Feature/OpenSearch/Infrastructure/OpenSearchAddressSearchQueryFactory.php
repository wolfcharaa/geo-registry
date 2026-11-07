<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure;

use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;

final class OpenSearchAddressSearchQueryFactory
{
    public function __construct(
        private readonly OpenSearchAddressQueryIntent $intent = new OpenSearchAddressQueryIntent(),
    ) {}

    /** @return array<string, mixed> */
    public function create(SearchAddressCriteria $criteria): array
    {
        $filters = [
            ['term' => ['hierarchy_type' => $criteria->hierarchy->value]],
        ];
        if ($criteria->regionCode !== null) {
            $filters[] = ['term' => ['region_code' => $criteria->regionCode]];
        }

        $query = [
            'bool' => [
                'filter' => $filters,
                'minimum_should_match' => 1,
                'should' => [
                    [
                        'term' => [
                            'address.keyword' => [
                                'value' => $criteria->query,
                                'case_insensitive' => true,
                                'boost' => 100,
                            ],
                        ],
                    ],
                    [
                        'term' => [
                            'aliases.value.keyword' => [
                                'value' => $criteria->query,
                                'case_insensitive' => true,
                                'boost' => 80,
                            ],
                        ],
                    ],
                    [
                        'match' => [
                            'aliases.value.literal' => [
                                'query' => $criteria->query,
                                'minimum_should_match' => '-1',
                                'boost' => 120,
                            ],
                        ],
                    ],
                    [
                        'match_phrase' => [
                            'address.literal' => [
                                'query' => $criteria->query,
                                'boost' => 48,
                            ],
                        ],
                    ],
                    [
                        'match_phrase' => [
                            'aliases.value.literal' => [
                                'query' => $criteria->query,
                                'boost' => 36,
                            ],
                        ],
                    ],
                    [
                        'match_phrase' => [
                            'address' => [
                                'query' => $criteria->query,
                                'boost' => 24,
                            ],
                        ],
                    ],
                    [
                        'match_phrase' => [
                            'aliases.value' => [
                                'query' => $criteria->query,
                                'boost' => 18,
                            ],
                        ],
                    ],
                    [
                        'multi_match' => [
                            'query' => $criteria->query,
                            'fields' => ['address.literal^12', 'aliases.value.literal^8'],
                            'type' => 'cross_fields',
                            'operator' => 'and',
                            'boost' => 8,
                        ],
                    ],
                    [
                        'multi_match' => [
                            'query' => $criteria->query,
                            'fields' => ['address^8', 'aliases.value^5'],
                            'type' => 'cross_fields',
                            'operator' => 'and',
                            'boost' => 4,
                        ],
                    ],
                    [
                        'multi_match' => [
                            'query' => $criteria->query,
                            'fields' => ['address^2', 'aliases.value'],
                            'fuzziness' => 'AUTO',
                            'prefix_length' => 1,
                            'operator' => 'and',
                            'boost' => 1,
                        ],
                    ],
                ],
            ],
        ];

        if (!$this->intent->targetsHouse($criteria->query)) {
            return $query;
        }

        return [
            'function_score' => [
                'query' => $query,
                'functions' => [
                    [
                        'filter' => ['term' => ['level' => 10]],
                        'weight' => 1000,
                    ],
                ],
                'score_mode' => 'sum',
                'boost_mode' => 'sum',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sort(): array
    {
        return [
            ['_score' => ['order' => 'desc']],
            ['address.keyword' => ['order' => 'asc']],
            ['level' => ['order' => 'desc']],
            ['object_id' => ['order' => 'asc']],
        ];
    }
}

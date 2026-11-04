<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Tests\Support\Database\AddressRegistryParityFixture;
use App\Tests\Support\Database\IntegrationDatabase;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class AddressRegistryParityFixtureIntegrationTest
{
    public function testItLoadsVersionedRegionalHierarchyAndResetsIt(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);

            Assert::same(AddressRegistryParityFixture::VERSION, '3');
            Assert::same(AddressRegistryParityFixture::checksum(), AddressRegistryParityFixture::CHECKSUM);

            $counts = $pool->query(<<<'SQL'
SELECT
    (SELECT count(*) FROM reestr_objects) AS canonical_objects,
    (SELECT count(*) FROM reestr_objects WHERE is_active) AS active_objects,
    (SELECT count(*) FROM address_search) AS search_documents,
    (SELECT count(*) FROM address_search WHERE search_vector IS NOT NULL) AS indexed_documents,
    (SELECT count(*) FROM municipal_reestr_hierarchy) AS municipal_nodes
SQL)->fetchRow();

            Assert::notNull($counts);
            Assert::same((int) ($counts['canonical_objects'] ?? -1), 11);
            Assert::same((int) ($counts['active_objects'] ?? -1), 10);
            Assert::same((int) ($counts['search_documents'] ?? -1), 20);
            Assert::same((int) ($counts['indexed_documents'] ?? -1), 20);
            Assert::same((int) ($counts['municipal_nodes'] ?? -1), 10);

            $regionalKeys = [];
            $result = $pool->query(<<<'SQL'
SELECT DISTINCT region_code, left(okato, 2) AS okato_prefix
FROM address_search
WHERE object_id IN (770005, 780005)
ORDER BY region_code
SQL);
            while (($row = $result->fetchRow()) !== null) {
                $regionalKeys[] = [
                    'region_code' => (int) ($row['region_code'] ?? 0),
                    'okato_prefix' => $row['okato_prefix'] ?? null,
                ];
            }

            Assert::same($regionalKeys, [
                ['region_code' => 77, 'okato_prefix' => '45'],
                ['region_code' => 78, 'okato_prefix' => '40'],
            ]);

            $regionalHierarchy = [];
            $result = $pool->query(<<<'SQL'
SELECT object_id, parent_obj_id, region_code, path_arr
FROM administrative_reestr_hierarchy
WHERE object_id IN (770005, 780005)
ORDER BY object_id
SQL);
            while (($row = $result->fetchRow()) !== null) {
                $regionalHierarchy[] = [
                    'object_id' => (int) ($row['object_id'] ?? 0),
                    'parent_obj_id' => (int) ($row['parent_obj_id'] ?? 0),
                    'region_code' => (int) ($row['region_code'] ?? 0),
                    'path_arr' => self::integerList($row['path_arr'] ?? null),
                ];
            }

            Assert::same($regionalHierarchy, [
                [
                    'object_id' => 770005,
                    'parent_obj_id' => 770004,
                    'region_code' => 77,
                    'path_arr' => [770001, 770002, 770003, 770004, 770005],
                ],
                [
                    'object_id' => 780005,
                    'parent_obj_id' => 780004,
                    'region_code' => 78,
                    'path_arr' => [780001, 780002, 780003, 780004, 780005],
                ],
            ]);

            $ambiguousStreetDocuments = [];
            $result = $pool->query(<<<'SQL'
SELECT object_id, hierarchy_type, address_string
FROM address_search
WHERE level = 7 AND address_string LIKE '%ул. Общая'
ORDER BY object_id, hierarchy_type
SQL);
            while (($row = $result->fetchRow()) !== null) {
                $objectId = $row['object_id'] ?? null;
                $hierarchyType = $row['hierarchy_type'] ?? null;
                $address = $row['address_string'] ?? null;
                if (!is_int($objectId) || !is_string($hierarchyType) || !is_string($address)) {
                    throw new RuntimeException('Parity fixture returned an invalid search document.');
                }

                $ambiguousStreetDocuments[] = [$objectId, $hierarchyType, $address];
            }

            Assert::same($ambiguousStreetDocuments, [
                [770004, 'adm', 'Регион Альфа, р-н Центральный, г. Тестоград, ул. Общая'],
                [770004, 'mun', 'Регион Альфа, муниципальный округ Центральный, г. Тестоград, ул. Общая'],
                [780004, 'adm', 'Регион Бета, р-н Центральный, г. Тестоград, ул. Общая'],
                [780004, 'mun', 'Регион Бета, муниципальный округ Центральный, г. Тестоград, ул. Общая'],
            ]);

            $findById = [];
            $result = $pool->query(<<<'SQL'
SELECT object_id, hierarchy_type
FROM address_search
WHERE object_guid = '00000000-0000-4000-8000-000000770005'
ORDER BY hierarchy_type
SQL);
            while (($row = $result->fetchRow()) !== null) {
                $objectId = $row['object_id'] ?? null;
                $hierarchyType = $row['hierarchy_type'] ?? null;
                if (!is_int($objectId) || !is_string($hierarchyType)) {
                    throw new RuntimeException('Parity fixture returned an invalid find-by-id document.');
                }

                $findById[] = [
                    'object_id' => $objectId,
                    'hierarchy_type' => $hierarchyType,
                ];
            }

            Assert::same($findById, [
                ['object_id' => 770005, 'hierarchy_type' => 'adm'],
                ['object_id' => 770005, 'hierarchy_type' => 'mun'],
            ]);

            $inactiveProjectionCount = $pool->query(
                'SELECT count(*) AS count FROM address_search WHERE object_id = 770006',
            )->fetchRow();
            Assert::notNull($inactiveProjectionCount);
            Assert::same((int) ($inactiveProjectionCount['count'] ?? -1), 0);

            AddressRegistryParityFixture::reset($pool);

            $remaining = $pool->query(<<<'SQL'
SELECT
    (SELECT count(*) FROM reestr_objects) AS canonical_objects,
    (SELECT count(*) FROM address_search) AS search_documents
SQL)->fetchRow();
            Assert::notNull($remaining);
            Assert::same((int) ($remaining['canonical_objects'] ?? -1), 0);
            Assert::same((int) ($remaining['search_documents'] ?? -1), 0);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    /** @return list<int> */
    private static function integerList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Parity fixture returned an invalid hierarchy path.');
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_int($item)) {
                throw new RuntimeException('Parity fixture hierarchy path must contain integers.');
            }
            $result[] = $item;
        }

        return $result;
    }
}

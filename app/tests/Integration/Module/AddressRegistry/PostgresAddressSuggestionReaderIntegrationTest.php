<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use Amp\NullCancellation;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\SuggestionCriteria;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressSuggestionReader;
use App\Tests\Support\Database\AddressRegistryParityFixture;
use App\Tests\Support\Database\IntegrationDatabase;
use Testo\Assert;
use Testo\Test;

#[Test]
final class PostgresAddressSuggestionReaderIntegrationTest
{
    public function testItMovesFromGlobalCityToRegionalDistrictAndStreet(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);
            $reader = new PostgresAddressSuggestionReader($pool, new PostgresAddressDocumentHydrator());
            $cancellation = new NullCancellation();

            $cities = $reader->suggestAdministrativeAreas(new SuggestionCriteria(
                query: 'Тестоград',
                hierarchy: AddressHierarchy::Municipal,
                limit: 7,
                context: null,
            ), $cancellation);
            $region = $reader->resolve(770001, AddressHierarchy::Municipal, $cancellation);
            Assert::notNull($region);
            $districts = $reader->suggestAdministrativeAreas(new SuggestionCriteria(
                query: 'Центральный',
                hierarchy: AddressHierarchy::Municipal,
                limit: 7,
                context: $region,
            ), $cancellation);
            $district = $reader->resolve(770002, AddressHierarchy::Municipal, $cancellation);
            Assert::notNull($district);
            $streets = $reader->suggestThoroughfares(new SuggestionCriteria(
                query: 'Общая',
                hierarchy: AddressHierarchy::Municipal,
                limit: 7,
                context: $district,
            ), $cancellation);

            Assert::same(self::ids($cities), [770003, 780003]);
            Assert::same($region->regionCode, 77);
            Assert::same(self::ids($districts), [770002]);
            Assert::same(self::ids($streets), [770004]);
            $street = $streets[0] ?? null;
            Assert::notNull($street);
            $streetDistrict = $street->district;
            Assert::notNull($streetDistrict);
            Assert::same($streetDistrict->objectId, 770002);
            Assert::same($street->nextStage->value, 'search');
            Assert::true($street->readyForSearch);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItReturnsEmptyResultsForUnknownInputAndContext(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);
            $reader = new PostgresAddressSuggestionReader($pool, new PostgresAddressDocumentHydrator());
            $cancellation = new NullCancellation();

            Assert::null($reader->resolve(999999999, AddressHierarchy::Municipal, $cancellation));
            Assert::same($reader->suggestAdministrativeAreas(new SuggestionCriteria(
                query: 'Неизвестный',
                hierarchy: AddressHierarchy::Administrative,
                limit: 5,
                context: null,
            ), $cancellation), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItIncludesGarRoadNetworkLevelEight(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);
            $pool->execute(<<<'SQL'
UPDATE address_search
SET level = 8
WHERE object_id = 770004
  AND hierarchy_type = 'mun'
SQL);

            $reader = new PostgresAddressSuggestionReader($pool, new PostgresAddressDocumentHydrator());
            $district = $reader->resolve(770002, AddressHierarchy::Municipal, new NullCancellation());
            Assert::notNull($district);

            $streets = $reader->suggestThoroughfares(new SuggestionCriteria(
                query: 'Общая',
                hierarchy: AddressHierarchy::Municipal,
                limit: 7,
                context: $district,
            ), new NullCancellation());

            Assert::same(self::ids($streets), [770004]);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    /**
     * @param list<ProgressiveAddressSuggestion> $suggestions
     * @return list<int>
     */
    private static function ids(array $suggestions): array
    {
        return array_map(
            static fn(ProgressiveAddressSuggestion $suggestion): int => $suggestion->objectId,
            $suggestions,
        );
    }
}

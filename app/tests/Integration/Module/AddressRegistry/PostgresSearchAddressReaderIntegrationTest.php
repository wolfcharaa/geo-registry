<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use Amp\NullCancellation;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresSearchAddressReader;
use App\Tests\Support\Database\AddressRegistryParityFixture;
use App\Tests\Support\Database\IntegrationDatabase;
use Testo\Assert;
use Testo\Test;

#[Test]
final class PostgresSearchAddressReaderIntegrationTest
{
    public function testItSearchesTheRegionalProjectionWithoutNationwideDeduplication(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);
            $reader = new PostgresSearchAddressReader($pool, new PostgresAddressDocumentHydrator());
            $cancellation = new NullCancellation();

            $region77 = $reader->search(new SearchAddressCriteria(
                query: 'Тестоград Общая',
                hierarchy: AddressHierarchy::Administrative,
                profile: SearchProfile::Search,
                limit: 10,
                regionCode: 77,
            ), $cancellation);
            $region78 = $reader->search(new SearchAddressCriteria(
                query: 'Тестоград Общая 10',
                hierarchy: AddressHierarchy::Municipal,
                profile: SearchProfile::Search,
                limit: 10,
                regionCode: 78,
            ), $cancellation);
            $nationwide = $reader->search(new SearchAddressCriteria(
                query: 'Тестоград Общая 10',
                hierarchy: AddressHierarchy::Administrative,
                profile: SearchProfile::Search,
                limit: 10,
                regionCode: null,
            ), $cancellation);
            $literalWildcard = $reader->search(new SearchAddressCriteria(
                query: '%',
                hierarchy: AddressHierarchy::Administrative,
                profile: SearchProfile::Search,
                limit: 10,
                regionCode: 77,
            ), $cancellation);

            Assert::true($region77 !== []);
            Assert::true(array_all(
                $region77,
                static fn(SearchAddressMatch $match): bool => str_starts_with((string) $match->document->objectId, '77'),
            ));
            Assert::same(array_map(
                static fn(SearchAddressMatch $match): int => $match->document->objectId,
                $region78,
            ), [780005]);
            Assert::same(array_map(
                static fn(SearchAddressMatch $match): int => $match->document->objectId,
                $nationwide,
            ), [770005, 780005]);
            Assert::same($literalWildcard, []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }

    public function testItReturnsAliasEvidenceWithTheCanonicalDocument(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);
            $pool->execute(<<<'SQL'
INSERT INTO address_aliases (
    object_id,
    object_guid,
    hierarchy_type,
    canonical_address_string,
    alias_string,
    alias_hash,
    alias_kind,
    source,
    source_object_type,
    source_object_id,
    source_address_rank
) VALUES (
    770005,
    '00000000-0000-4000-8000-000000770005',
    'adm',
    'Регион Альфа, р-н Центральный, г. Тестоград, ул. Общая, д. 10',
    'Регион Альфа, набережная Тихая, д. 10',
    md5('регион альфа набережная тихая 10'),
    'alternative_address',
    'osm',
    'way',
    770005,
    2
)
SQL);
            $reader = new PostgresSearchAddressReader($pool, new PostgresAddressDocumentHydrator());

            $matches = $reader->search(new SearchAddressCriteria(
                query: 'набережная Тихая',
                hierarchy: AddressHierarchy::Administrative,
                profile: SearchProfile::RemediationDebug,
                limit: 5,
                regionCode: 77,
            ), new NullCancellation());

            Assert::same(array_map(
                static fn(SearchAddressMatch $match): array => [
                    $match->document->objectId,
                    $match->document->aliasCount,
                    $match->matchedAlias,
                    $match->aliases[0]->value ?? null,
                ],
                $matches,
            ), [[
                770005,
                1,
                'Регион Альфа, набережная Тихая, д. 10',
                'Регион Альфа, набережная Тихая, д. 10',
            ]]);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\OpenSearch;

use Amp\Cancellation;
use Amp\NullCancellation;
use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Infrastructure\OpenSearch\OpenSearchClientFactory;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressIndexDefinition;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressSearchQueryFactory;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressSearchResultMapper;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchSearchAddressDecorator;
use Psr\Log\NullLogger;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class OpenSearchClientIntegrationTest
{
    public function testItUsesTheNativeClientAgainstAnEphemeralIndex(): void
    {
        $httpClient = AmpHttpClientFactory::create(10);
        $client = (new OpenSearchClientFactory($httpClient))->create(
            self::environment('INTEGRATION_OPENSEARCH_URL', 'http://127.0.0.1:59200'),
            'geo-registry-integration-test/0.1',
        );
        $index = 'geo-registry-integration-' . bin2hex(random_bytes(6));

        $health = $client->health();
        Assert::true(in_array($health['status'] ?? null, ['green', 'yellow'], true));

        $client->createIndex($index, OpenSearchAddressIndexDefinition::create());

        try {
            $client->indexDocument($index, '770005-adm', self::document(77, 770005, 'Альфа', 10));
            $client->indexDocument($index, '770006-adm', self::document(77, 770006, 'Альфа', 11, ', кв. 1'));
            $client->indexDocument($index, '780005-adm', self::document(78, 780005, 'Бета', 10));
            $client->refreshIndex($index);

            $matches = (new OpenSearchSearchAddressDecorator(
                client: $client,
                index: $index,
                fallback: new FailingSearchAddressPort(),
                queries: new OpenSearchAddressSearchQueryFactory(),
                results: new OpenSearchAddressSearchResultMapper(),
                logger: new NullLogger(),
            ))->search(new SearchAddressCriteria(
                query: 'Тестоград Общая 10',
                hierarchy: AddressHierarchy::Administrative,
                profile: SearchProfile::Search,
                limit: 5,
                regionCode: 77,
            ), new NullCancellation());

            Assert::same(count($matches), 2);
            $match = $matches[0] ?? null;
            Assert::notNull($match);
            Assert::same($match->document->objectId, 770005);
            Assert::same($match->document->level, 10);
            Assert::same($match->backend, 'opensearch');
            Assert::same($match->document->aliasCount, 1);
            $alias = $match->aliases[0] ?? null;
            Assert::notNull($alias);
            Assert::same($alias->source, 'osm');
        } finally {
            $client->deleteIndex($index);
        }
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @return array<string, mixed> */
    private static function document(
        int $regionCode,
        int $objectId,
        string $region,
        int $level,
        string $suffix = '',
    ): array
    {
        return [
            'object_id' => $objectId,
            'object_guid' => sprintf('00000000-0000-4000-8000-%012d', $objectId),
            'address' => "Регион {$region}, г. Тестоград, ул. Общая, д. 10{$suffix}",
            'hierarchy_type' => 'adm',
            'region_code' => $regionCode,
            'level' => $level,
            'postal_code' => (string) ($regionCode * 10000 + 1),
            'geo_lat' => 55.750001,
            'geo_lon' => 37.610001,
            'source' => 'gar',
            'alias_count' => 1,
            'aliases' => [[
                'value' => 'Тестоград, Общая улица, дом 10',
                'kind' => 'alternative_address',
                'source' => 'osm',
                'source_object_type' => 'way',
                'source_object_id' => $objectId,
                'rank' => 2,
            ]],
        ];
    }
}

final class FailingSearchAddressPort implements SearchAddressPort
{
    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        throw new RuntimeException('PostgreSQL fallback must not be called for a matching OpenSearch document.');
    }
}

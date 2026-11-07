<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Feature\OpenSearch;

use function Amp\ByteStream\buffer;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\NullCancellation;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchRequestFactory;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressSearchQueryFactory;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressSearchResultMapper;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchSearchAddressDecorator;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Testo\Assert;
use Testo\Test;

#[Test]
final class OpenSearchSearchAddressDecoratorTest
{
    public function testItReturnsNormalizedRegionalOpenSearchHits(): void
    {
        $http = new FakeOpenSearchHttpClient(body: json_encode([
            'hits' => [
                'hits' => [[
                    '_score' => 12.5,
                    '_source' => [
                        'object_id' => 770005,
                        'region_code' => 77,
                        'object_guid' => '00000000-0000-4000-8000-000000770005',
                        'address' => 'Регион Альфа, г. Тестоград, ул. Общая, д. 10',
                        'hierarchy_type' => 'mun',
                        'level' => 8,
                        'postal_code' => '770001',
                        'geo_lat' => 55.750001,
                        'geo_lon' => 37.610001,
                        'source' => 'gar',
                        'alias_count' => 1,
                        'aliases' => [[
                            'value' => 'Тестоград, Общая улица, дом 10',
                            'kind' => 'alternative_address',
                            'source' => 'osm',
                            'source_object_type' => 'way',
                            'source_object_id' => 770005,
                            'rank' => 2,
                        ]],
                    ],
                ]],
            ],
        ], JSON_THROW_ON_ERROR));
        $fallback = new RecordingSearchAddressFallback();

        $matches = $this->decorator($http, $fallback)->search($this->criteria(), new NullCancellation());

        Assert::same(count($matches), 1);
        $match = $matches[0] ?? null;
        Assert::notNull($match);
        Assert::same($match->backend, 'opensearch');
        Assert::same($match->document->objectId, 770005);
        Assert::same($match->document->hierarchy, AddressHierarchy::Municipal);
        Assert::same($match->score, 12.5);
        $alias = $match->aliases[0] ?? null;
        Assert::notNull($alias);
        Assert::same($alias->value, 'Тестоград, Общая улица, дом 10');
        Assert::same($fallback->calls, 0);
        Assert::notNull($http->request);
        $body = self::jsonObject(buffer($http->request->getBody()->getContent()));
        $query = self::object($body['query'] ?? null);
        $functionScore = self::object($query['function_score'] ?? null);
        Assert::same($functionScore['functions'] ?? null, [[
            'filter' => ['term' => ['level' => 10]],
            'weight' => 1000,
        ]]);
        $nestedQuery = self::object($functionScore['query'] ?? null);
        $bool = self::object($nestedQuery['bool'] ?? null);
        $filters = self::list($bool['filter'] ?? null);
        Assert::same($filters, [
            ['term' => ['hierarchy_type' => 'mun']],
            ['term' => ['region_code' => 77]],
        ]);
        Assert::same($bool['minimum_should_match'] ?? null, 1);
        $ranking = self::list($bool['should'] ?? null);
        Assert::same(count($ranking), 10);
        Assert::same($ranking[0] ?? null, [
            'term' => [
                'address.keyword' => [
                    'value' => 'Тестоград Общая 10',
                    'case_insensitive' => true,
                    'boost' => 100,
                ],
            ],
        ]);
        Assert::same($ranking[2] ?? null, [
            'match' => [
                'aliases.value.literal' => [
                    'query' => 'Тестоград Общая 10',
                    'minimum_should_match' => '-1',
                    'boost' => 120,
                ],
            ],
        ]);
        Assert::same($ranking[7] ?? null, [
            'multi_match' => [
                'query' => 'Тестоград Общая 10',
                'fields' => ['address.literal^12', 'aliases.value.literal^8'],
                'type' => 'cross_fields',
                'operator' => 'and',
                'boost' => 8,
            ],
        ]);
        Assert::same($ranking[8] ?? null, [
            'multi_match' => [
                'query' => 'Тестоград Общая 10',
                'fields' => ['address^8', 'aliases.value^5'],
                'type' => 'cross_fields',
                'operator' => 'and',
                'boost' => 4,
            ],
        ]);
        Assert::same($ranking[9] ?? null, [
            'multi_match' => [
                'query' => 'Тестоград Общая 10',
                'fields' => ['address^2', 'aliases.value'],
                'fuzziness' => 'AUTO',
                'prefix_length' => 1,
                'operator' => 'and',
                'boost' => 1,
            ],
        ]);
        Assert::same($body['size'] ?? null, 5);
        Assert::same(self::list($body['sort'] ?? null), [
            ['_score' => ['order' => 'desc']],
            ['address.keyword' => ['order' => 'asc']],
            ['level' => ['order' => 'desc']],
            ['object_id' => ['order' => 'asc']],
        ]);
    }

    public function testItFallsBackOnEmptyMalformedAndUnavailableProjection(): void
    {
        $cases = [
            new FakeOpenSearchHttpClient(body: '{"hits":{"hits":[]}}'),
            new FakeOpenSearchHttpClient(body: '{"hits":{"hits":"broken"}}'),
            new FakeOpenSearchHttpClient(status: 503, body: '{"error":"unavailable"}'),
        ];

        foreach ($cases as $http) {
            $fallback = new RecordingSearchAddressFallback();
            $matches = $this->decorator($http, $fallback)->search($this->criteria(), new NullCancellation());

            Assert::same($fallback->calls, 1);
            $match = $matches[0] ?? null;
            Assert::notNull($match);
            Assert::same($match->backend, 'postgres');
        }
    }

    public function testItLogsTheDegradedFallbackWithoutLeakingTheResponse(): void
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $this->decorator(
            new FakeOpenSearchHttpClient(status: 503, body: '{"secret":"must-not-be-logged"}'),
            new RecordingSearchAddressFallback(),
            $logger,
        )->search($this->criteria(), new NullCancellation());

        Assert::true($handler->hasRecordThatContains('fell back to PostgreSQL', Level::Warning));
        $records = $handler->getRecords();
        Assert::same(count($records), 1);
        $record = $records[0] ?? null;
        Assert::notNull($record);
        Assert::false(str_contains(json_encode($record->context, JSON_THROW_ON_ERROR), 'must-not-be-logged'));
    }

    public function testItPropagatesCancellationWithoutCallingPostgres(): void
    {
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();
        $fallback = new RecordingSearchAddressFallback();
        $error = null;

        try {
            $this->decorator(new FakeOpenSearchHttpClient(), $fallback)->search(
                $this->criteria(),
                $cancellation->getCancellation(),
            );
        } catch (CancelledException $exception) {
            $error = $exception;
        }

        Assert::instanceOf($error, CancelledException::class);
        Assert::same($fallback->calls, 0);
    }

    private function decorator(
        FakeOpenSearchHttpClient $http,
        RecordingSearchAddressFallback $fallback,
        ?Logger $logger = null,
    ): OpenSearchSearchAddressDecorator {
        return new OpenSearchSearchAddressDecorator(
            new OpenSearchClient(
                new HttpClient($http, []),
                new OpenSearchRequestFactory('http://opensearch.test:9200', 'test/1.0'),
            ),
            'addresses-current',
            $fallback,
            new OpenSearchAddressSearchQueryFactory(),
            new OpenSearchAddressSearchResultMapper(),
            $logger ?? new Logger('test'),
        );
    }

    private function criteria(): SearchAddressCriteria
    {
        return new SearchAddressCriteria(
            query: 'Тестоград Общая 10',
            hierarchy: AddressHierarchy::Municipal,
            profile: SearchProfile::Search,
            limit: 5,
            regionCode: 77,
        );
    }

    /** @return array<array-key, mixed> */
    private static function jsonObject(string $json): array
    {
        return self::object(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<array-key, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \LogicException('Expected an object.');
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \LogicException('Expected a list.');
        }

        return $value;
    }
}

final class FakeOpenSearchHttpClient implements DelegateHttpClient
{
    public ?Request $request = null;

    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = '{"hits":{"hits":[]}}',
    ) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $this->request = $request;
        $cancellation->throwIfRequested();

        return new Response(
            '1.1',
            $this->status,
            null,
            ['content-type' => 'application/json'],
            $this->body,
            $request,
        );
    }
}

final class RecordingSearchAddressFallback implements SearchAddressPort
{
    public int $calls = 0;

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        ++$this->calls;

        return [new SearchAddressMatch(
            document: new AddressDocument(
                objectId: 770005,
                objectGuid: '00000000-0000-4000-8000-000000770005',
                address: 'Регион Альфа, г. Тестоград, ул. Общая, д. 10',
                hierarchy: $criteria->hierarchy,
                level: 8,
                postalCode: null,
                okato: null,
                oktmo: null,
                latitude: null,
                longitude: null,
                source: 'gar',
                sourceId: null,
                dataMosGlobalId: null,
                dataMosUnom: null,
                dataMosNreg: null,
                dataMosAdministrativeArea: null,
                dataMosDistrict: null,
                cadastralNumbers: [],
                landCadastralNumbers: [],
                aliasCount: 0,
            ),
            score: 1.0,
            aliases: [],
            matchedAlias: null,
        )];
    }
}

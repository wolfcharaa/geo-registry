<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Feature\OpenSearch;

use App\Module\AddressRegistry\Capability\AddressAlias;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\OpenSearchProjectionDocument;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild\OpenSearchBulkPayload;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild\OpenSearchVersionedIndexName;
use DateTimeImmutable;
use LogicException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class OpenSearchBulkPayloadTest
{
    public function testItBuildsDeterministicRegionalDocumentIdsAndBoundedNdjson(): void
    {
        $payload = OpenSearchBulkPayload::fromDocuments([new OpenSearchProjectionDocument(
            regionCode: 77,
            document: new AddressDocument(
                objectId: 123,
                objectGuid: '00000000-0000-4000-8000-000000000123',
                address: 'г. Москва, ул. Тестовая, д. 1',
                hierarchy: AddressHierarchy::Administrative,
                level: 10,
                postalCode: '101000',
                okato: '45000000000',
                oktmo: '45000000',
                latitude: 55.75,
                longitude: 37.61,
                source: 'gar',
                sourceId: null,
                dataMosGlobalId: null,
                dataMosUnom: null,
                dataMosNreg: null,
                dataMosAdministrativeArea: null,
                dataMosDistrict: null,
                cadastralNumbers: [],
                landCadastralNumbers: [],
                aliasCount: 1,
            ),
            aliases: [new AddressAlias('Москва, Тестовая, 1', 'alternative_address', 'osm', 'way', 55, 2)],
        )]);

        $lines = explode("\n", trim($payload->ndjson));
        Assert::same($payload->documents, 1);
        Assert::same(count($lines), 2);
        Assert::same(
            json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR),
            ['index' => ['_id' => '77:adm:123']],
        );
        $source = self::object(json_decode(
            $lines[1] ?? throw new LogicException('Missing bulk source.'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        Assert::same($source['region_code'] ?? null, 77);
        Assert::same($source['alias_count'] ?? null, 1);
        $aliases = self::list($source['aliases'] ?? null);
        $alias = self::object($aliases[0] ?? null);
        Assert::same($alias['source_object_id'] ?? null, 55);
    }

    public function testItGeneratesAStableUtcPhysicalIndexName(): void
    {
        Assert::same(
            OpenSearchVersionedIndexName::generate(
                'places',
                new DateTimeImmutable('2026-11-04 12:34:56.789 UTC'),
            ),
            'places-v20261104123456789',
        );
    }

    /** @return array<array-key, mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new LogicException('Expected a JSON object.');
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new LogicException('Expected a JSON list.');
        }

        return $value;
    }
}

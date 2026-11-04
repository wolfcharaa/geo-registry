<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\AddressRegistry;

use Amp\NullCancellation;
use App\Infrastructure\Postgres\PostgresPoolFactory;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresFindAddressByIdReader;
use App\Tests\Support\Database\AddressRegistryParityFixture;
use App\Tests\Support\Database\IntegrationDatabase;
use Testo\Assert;
use Testo\Test;

#[Test]
final class PostgresFindAddressByIdReaderIntegrationTest
{
    public function testItFindsSyntheticDocumentsByGuidAndNumericId(): void
    {
        $dsn = IntegrationDatabase::dsn();
        $pool = PostgresPoolFactory::create($dsn, 1);

        try {
            IntegrationDatabase::resetSchema($pool);
            IntegrationDatabase::runMigrationCommand($dsn, 'migrate:up', ['--force-yes' => true]);
            AddressRegistryParityFixture::load($pool);
            $reader = new PostgresFindAddressByIdReader($pool, new PostgresAddressDocumentHydrator());
            $cancellation = new NullCancellation();

            $byGuid = $reader->find('00000000-0000-4000-8000-000000770005', 20, $cancellation);
            $byObjectId = $reader->find('770005', 1, $cancellation);

            Assert::same(array_map(
                static fn(AddressDocument $document): array => [
                    $document->objectId,
                    $document->hierarchy->value,
                    $document->address,
                ],
                $byGuid,
            ), [
                [770005, 'adm', 'Регион Альфа, р-н Центральный, г. Тестоград, ул. Общая, д. 10'],
                [770005, 'mun', 'Регион Альфа, муниципальный округ Центральный, г. Тестоград, ул. Общая, д. 10'],
            ]);
            Assert::same(array_map(
                static fn(AddressDocument $document): array => [
                    $document->postalCode,
                    $document->latitude,
                    $document->source,
                    $document->aliasCount,
                ],
                $byObjectId,
            ), [['770001', 55.750001, 'gar', 0]]);
            Assert::same($reader->find('not-an-id', 20, $cancellation), []);
            Assert::same($reader->find('00000000-0000-4000-8000-000000000000', 20, $cancellation), []);
        } finally {
            IntegrationDatabase::resetSchema($pool);
            $pool->close();
        }
    }
}

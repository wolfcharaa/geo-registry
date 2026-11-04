<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarXmlCatalogTest
{
    public function testItRecognizesAndOrdersRepresentativeGarFilesByDependencies(): void
    {
        $files = new GarXmlCatalog()->files(dirname(__DIR__, 5) . '/Fixture/Gar/representative');

        Assert::same(
            array_map(static fn($file): string => $file->descriptor->table, $files),
            [
                'address_object_types',
                'object_levels',
                'operation_types',
                'reestr_objects',
                'address_objects',
                'administrative_reestr_hierarchy',
            ],
        );
    }
}

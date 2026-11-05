<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRowReader;
use LogicException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class GarXmlRowReaderTest
{
    public function testItReadsForwardOnlyRowsAndBuildsTheHierarchyPathArray(): void
    {
        $files = new GarXmlCatalog()->files(dirname(__DIR__, 5) . '/Fixture/Gar/representative');
        $hierarchy = null;
        foreach ($files as $file) {
            if ($file->descriptor->table === 'administrative_reestr_hierarchy') {
                $hierarchy = $file;
            }
        }
        $hierarchy ??= throw new LogicException('Hierarchy fixture is missing.');

        $rows = iterator_to_array(new GarXmlRowReader()->rows($hierarchy, 0), false);

        Assert::same(count($rows), 1);
        Assert::same($rows[0][0] ?? null, '77000101');
        Assert::same(count($rows[0]), 18);
        Assert::same(array_slice($rows[0], 2, 2), [null, null]);
        Assert::same(array_slice($rows[0], 10, 2), [null, null]);
        Assert::same($rows[0][16] ?? null, '770001');
        Assert::same($rows[0][17] ?? null, '{770001}');
        Assert::same(iterator_to_array(new GarXmlRowReader()->rows($hierarchy, 1)), []);
    }

    #[ExpectException(GarImportFailed::class)]
    public function testItRejectsAPartialXmlFileAfterYieldingItsValidPrefix(): void
    {
        $file = new GarXmlCatalog()->files(dirname(__DIR__, 5) . '/Fixture/Gar/partial')[0]
            ?? throw new LogicException('Partial GAR fixture is missing.');

        iterator_to_array(new GarXmlRowReader()->rows($file, 0));
    }
}

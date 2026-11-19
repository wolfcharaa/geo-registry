<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class GarXmlCatalogTest
{
    public function testItBuildsOneCanonicalManifestIdentityForTheSelectedFiles(): void
    {
        $source = \dirname(__DIR__, 5) . '/Fixture/Gar/regions/../regions';
        $canonicalSource = \realpath($source);
        Assert::true(\is_string($canonicalSource));

        $manifest = new GarXmlCatalog()->manifest($source, [50]);

        Assert::same($manifest->sourcePath, $canonicalSource);
        Assert::same(
            $manifest->hash,
            \hash('sha256', \implode("\n", \array_map(
                static fn(GarXmlSourceFile $file): string => $file->key,
                $manifest->files,
            ))),
        );
        Assert::same($manifest->file($manifest->files[0]->relativePath), $manifest->files[0]);
    }

    public function testItRecognizesAndOrdersRepresentativeGarFilesByDependencies(): void
    {
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/representative')->files;

        Assert::same(
            \array_map(static fn($file): string => $file->descriptor->table, $files),
            [
                'address_object_types',
                'normative_doc_kinds',
                'normative_doc_types',
                'object_levels',
                'operation_types',
                'reestr_objects',
                'normative_docs',
                'address_objects',
                'administrative_reestr_hierarchy',
            ],
        );
    }

    public function testItKeepsSharedFilesAndOnlySelectedRegionalDirectories(): void
    {
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/regions', [50, 77])->files;

        Assert::same(
            \array_map(static fn($file): string => $file->relativePath, $files),
            [
                'AS_OPERATION_TYPES_20261104.XML',
                '50/AS_ADDR_OBJ_20261104.XML',
                '77/AS_ADDR_OBJ_20261104.XML',
            ],
        );
    }

    public function testItFailsWhenARequestedRegionIsAbsentFromTheRelease(): void
    {
        Expect::exception(GarImportFailed::class);

        new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/regions', [50, 99]);
    }

    public function testItRejectsAFileOutsideTheCanonicalManifest(): void
    {
        $manifest = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/regions', [50]);

        Expect::exception(GarImportFailed::class);
        $manifest->file('77/AS_ADDR_OBJ_20261104.XML');
    }
}

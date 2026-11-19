<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportPipelineSelector;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class GarImportPipelineSelectorTest
{
    public function testItSelectsEveryCompatibleFileFromEveryDataPhase(): void
    {
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/pipeline')->files;
        $selection = new GarImportPipelineSelector()->select(GarImportPipelineSelector::AUTO, $files);

        Assert::same(
            \array_map(static fn($file): string => $file->relativePath, $selection),
            [
                'AS_REESTR_OBJECTS_20261104.XML',
                'AS_HOUSES_PARAMS_20261104.XML',
                'AS_ADDR_OBJ_PARAMS_20261104.XML',
            ],
        );
    }

    public function testItKeepsAnExactCompatibleFileSelection(): void
    {
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/pipeline')->files;
        $selector = new GarImportPipelineSelector();
        $selection = $selector->select('AS_ADDR_OBJ_PARAMS_20261104.XML', $files);
        if ($selection === []) {
            throw new \RuntimeException('Representative GAR fixture has no selected pipeline file.');
        }

        Assert::same(\count($selection), 1);
        Assert::same($selection[0]->relativePath, 'AS_ADDR_OBJ_PARAMS_20261104.XML');
        Assert::same($selector->identity($selection), $selection[0]->key);
    }

    public function testItRejectsAnExactFileThatRequiresStageTransformation(): void
    {
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/representative')->files;

        Expect::exception(GarImportFailed::class);
        new GarImportPipelineSelector()->select('AS_ADDR_OBJ_20261104.XML', $files);
    }
}

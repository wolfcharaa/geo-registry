<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportWorkerPlan;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarImportWorkerPlanTest
{
    public function testItBalancesFilesAcrossWorkersWithoutCrossingDependencyPhases(): void
    {
        $files = new GarXmlCatalog()->files(dirname(__DIR__, 5) . '/Fixture/Gar/representative');
        $phases = new GarImportWorkerPlan()->phases($files, 2);

        Assert::same(count($phases), 5);
        Assert::same(array_map('count', $phases), [2, 1, 1, 1, 1]);

        $plannedPaths = [];
        $previousPriority = 0;
        foreach ($phases as $assignments) {
            Assert::true(count($assignments) <= 2);
            $priorities = [];
            foreach ($assignments as $assignment) {
                foreach ($assignment as $file) {
                    $plannedPaths[] = $file->relativePath;
                    $priorities[$file->descriptor->priority] = true;
                }
            }
            Assert::same(count($priorities), 1);
            $priority = array_key_first($priorities);
            Assert::true(is_int($priority));
            Assert::true($priority > $previousPriority);
            $previousPriority = $priority;
        }

        $expectedPaths = array_map(
            static fn(GarXmlSourceFile $file): string => $file->relativePath,
            $files,
        );
        sort($expectedPaths);
        sort($plannedPaths);
        Assert::same($plannedPaths, $expectedPaths);
    }
}

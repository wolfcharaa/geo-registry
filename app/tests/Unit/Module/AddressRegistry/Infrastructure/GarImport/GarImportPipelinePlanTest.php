<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportPipelinePlan;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportWorkerPlan;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarImportPipelinePlanTest
{
    public function testItExtractsThePipelineTargetAndDefersSiblingsAtOneGlobalWriter(): void
    {
        $workerPlan = new GarImportWorkerPlan();
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/representative')->files;
        $assignments = $workerPlan->phases($files, 2)[0] ?? null;
        if ($assignments === null || !isset($assignments[0][0])) {
            throw new RuntimeException('Representative GAR fixture has no first worker phase.');
        }
        $target = $assignments[0][0];
        $plan = new GarImportPipelinePlan($workerPlan);

        Assert::true($plan->contains($assignments, $target->key));
        $remaining = $plan->remainingFiles($assignments, [$target]);
        Assert::same(\count($remaining), \array_sum(\array_map('count', $assignments)) - 1);
        Assert::same($plan->concurrentAssignments($remaining, 1), []);
    }

    public function testItRampsWithinTheGlobalWriterLimit(): void
    {
        $plan = new GarImportPipelinePlan(new GarImportWorkerPlan());

        Assert::same($plan->capacity(4, 3, 3), 1);
        Assert::same($plan->capacity(4, 3, 1), 3);
        Assert::same($plan->capacity(1, 1, 0), 1);
    }

    public function testItFindsEverySelectedFileInADependencyPhase(): void
    {
        $workerPlan = new GarImportWorkerPlan();
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/pipeline')->files;
        $phases = $workerPlan->phases($files, 4);
        $plan = new GarImportPipelinePlan($workerPlan);
        $pipelineFiles = \array_values(\array_filter(
            $files,
            static fn($file): bool => \in_array($file->relativePath, [
                'AS_REESTR_OBJECTS_20261104.XML',
                'AS_HOUSES_PARAMS_20261104.XML',
                'AS_ADDR_OBJ_PARAMS_20261104.XML',
            ], true),
        ));
        if (\count($pipelineFiles) !== 3) {
            throw new RuntimeException('Representative GAR fixture has no expected pipeline files.');
        }
        $phasesByPriority = [];
        foreach ($phases as $phase) {
            $firstFile = $phase[0][0] ?? null;
            if ($firstFile === null) {
                throw new RuntimeException('Representative GAR fixture has an empty worker phase.');
            }
            $phasesByPriority[$firstFile->descriptor->priority] = $phase;
        }
        $dictionaryPhase = $phasesByPriority[10] ?? null;
        $registryPhase = $phasesByPriority[20] ?? null;
        $parameterPhase = $phasesByPriority[50] ?? null;
        if ($dictionaryPhase === null || $registryPhase === null || $parameterPhase === null) {
            throw new RuntimeException('Representative GAR fixture has no expected dependency phases.');
        }

        Assert::same($plan->filesForPhase($dictionaryPhase, $pipelineFiles), []);
        Assert::same($plan->filesForPhase($registryPhase, $pipelineFiles), [$pipelineFiles[0]]);
        Assert::same($plan->filesForPhase($parameterPhase, $pipelineFiles), [
            $pipelineFiles[1],
            $pipelineFiles[2],
        ]);
        Assert::same($plan->tableGroups($pipelineFiles), [
            [$pipelineFiles[0]],
            [$pipelineFiles[1], $pipelineFiles[2]],
        ]);
    }
}

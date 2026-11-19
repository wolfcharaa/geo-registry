<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

final readonly class GarImportPipelinePlan
{
    public function __construct(
        private GarImportWorkerPlan $workerPlan,
    ) {}

    /** @param list<non-empty-list<GarXmlSourceFile>> $assignments */
    public function contains(array $assignments, string $fileKey): bool
    {
        foreach ($assignments as $files) {
            foreach ($files as $file) {
                if (\hash_equals($file->key, $fileKey)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<non-empty-list<GarXmlSourceFile>> $assignments
     * @param list<GarXmlSourceFile> $pipelineFiles
     * @return list<GarXmlSourceFile>
     */
    public function filesForPhase(array $assignments, array $pipelineFiles): array
    {
        $selected = [];
        foreach ($pipelineFiles as $pipelineFile) {
            if ($this->contains($assignments, $pipelineFile->key)) {
                $selected[] = $pipelineFile;
            }
        }

        return $selected;
    }

    /**
     * @param list<non-empty-list<GarXmlSourceFile>> $assignments
     * @param list<GarXmlSourceFile> $pipelineFiles
     * @return list<GarXmlSourceFile>
     */
    public function remainingFiles(array $assignments, array $pipelineFiles): array
    {
        $pipelineFileKeys = \array_fill_keys(\array_map(
            static fn(GarXmlSourceFile $file): string => $file->key,
            $pipelineFiles,
        ), true);
        $remaining = [];
        foreach ($assignments as $files) {
            foreach ($files as $file) {
                if (!isset($pipelineFileKeys[$file->key])) {
                    $remaining[] = $file;
                }
            }
        }

        return $remaining;
    }

    /**
     * @param list<GarXmlSourceFile> $files
     * @return list<non-empty-list<GarXmlSourceFile>>
     */
    public function tableGroups(array $files): array
    {
        /** @var array<string, non-empty-list<GarXmlSourceFile>> $groups */
        $groups = [];
        foreach ($files as $file) {
            $groups[$file->descriptor->table][] = $file;
        }

        return \array_values($groups);
    }

    /**
     * @param list<GarXmlSourceFile> $remainingFiles
     * @return list<non-empty-list<GarXmlSourceFile>>
     */
    public function concurrentAssignments(array $remainingFiles, int $globalWriters): array
    {
        if ($remainingFiles === [] || $globalWriters <= 1) {
            return [];
        }

        return $this->workerPlan->phases($remainingFiles, $globalWriters - 1)[0] ?? [];
    }

    /** @return positive-int */
    public function capacity(int $globalWriters, int $pipelineWorkers, int $runningFileWorkers): int
    {
        if (
            $globalWriters < 1
            || $pipelineWorkers < 1
            || $pipelineWorkers > $globalWriters
            || $runningFileWorkers < 0
            || $runningFileWorkers >= $globalWriters
        ) {
            throw new GarImportFailed('GAR pipeline writer capacity is inconsistent with the global worker limit.');
        }

        $capacity = \min($pipelineWorkers, $globalWriters - $runningFileWorkers);
        if ($capacity < 1) {
            throw new GarImportFailed('GAR pipeline has no writer capacity.');
        }

        return $capacity;
    }
}

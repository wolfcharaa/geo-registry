<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use LogicException;

final class GarImportWorkerPlan
{
    /**
     * @param list<GarXmlSourceFile> $files
     * @return list<list<non-empty-list<GarXmlSourceFile>>>
     */
    public function phases(array $files, int $workerCount): array
    {
        /** @var array<int, list<GarXmlSourceFile>> $filesByPriority */
        $filesByPriority = [];
        foreach ($files as $file) {
            $filesByPriority[$file->descriptor->priority][] = $file;
        }
        ksort($filesByPriority);

        $phases = [];
        foreach ($filesByPriority as $phaseFiles) {
            if ($phaseFiles !== []) {
                $phases[] = $this->distribute($phaseFiles, $workerCount);
            }
        }

        return $phases;
    }

    /**
     * @param non-empty-list<GarXmlSourceFile> $files
     * @return non-empty-list<non-empty-list<GarXmlSourceFile>>
     */
    private function distribute(array $files, int $workerCount): array
    {
        usort(
            $files,
            static fn(GarXmlSourceFile $left, GarXmlSourceFile $right): int => [
                -$left->size,
                $left->relativePath,
            ] <=> [
                -$right->size,
                $right->relativePath,
            ],
        );

        $assignmentCount = min(max(1, $workerCount), count($files));
        /** @var non-empty-list<array{size: int, files: list<GarXmlSourceFile>}> $assignments */
        $assignments = [['size' => 0, 'files' => []]];
        for ($index = 1; $index < $assignmentCount; ++$index) {
            $assignments[] = ['size' => 0, 'files' => []];
        }

        foreach ($files as $file) {
            $target = null;
            $smallestSize = PHP_INT_MAX;
            foreach ($assignments as $index => $assignment) {
                if ($assignment['size'] < $smallestSize) {
                    $target = $index;
                    $smallestSize = $assignment['size'];
                }
            }
            if ($target === null || !isset($assignments[$target])) {
                throw new LogicException('GAR import worker assignment target was not initialized.');
            }
            $assignment = $assignments[$target];
            $assignment['files'][] = $file;
            $assignment['size'] += $file->size;
            $assignments[$target] = $assignment;
        }

        $distributed = [];
        foreach ($assignments as $assignment) {
            usort(
                $assignment['files'],
                static fn(GarXmlSourceFile $left, GarXmlSourceFile $right): int => [
                    $left->descriptor->key,
                    $left->relativePath,
                ] <=> [
                    $right->descriptor->key,
                    $right->relativePath,
                ],
            );
            $distributed[] = $assignment['files'];
        }

        return $distributed;
    }
}

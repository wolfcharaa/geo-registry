<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Scheduling\GroupedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;
use InvalidArgumentException;

/** Builds strict GAR dependency phases with largest-first admission inside each phase. */
final class GarRawFileAdmissionQueue
{
    /**
     * @param list<GarXmlSourceFile> $files
     * @return WorkQueue<GarXmlSourceFile>
     */
    public static function create(array $files): WorkQueue
    {
        if ($files === []) {
            throw new InvalidArgumentException('GAR raw admission requires at least one file.');
        }

        /** @var array<int, list<GarXmlSourceFile>> $filesByPriority */
        $filesByPriority = [];
        foreach ($files as $file) {
            $filesByPriority[$file->descriptor->priority][] = $file;
        }
        \ksort($filesByPriority);

        $groups = [];
        foreach ($filesByPriority as $phaseFiles) {
            \usort(
                $phaseFiles,
                static fn(GarXmlSourceFile $left, GarXmlSourceFile $right): int => [
                    -$left->size,
                    $left->relativePath,
                ] <=> [
                    -$right->size,
                    $right->relativePath,
                ],
            );
            $groups[] = \array_map(
                static fn(GarXmlSourceFile $file): WorkEnvelope => new WorkEnvelope(
                    new WorkId($file->path),
                    $file,
                ),
                $phaseFiles,
            );
        }

        return new GroupedWorkQueue($groups);
    }

    private function __construct() {}
}

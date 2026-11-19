<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Fedor\WorkOrchestrator\Exception\RunFailed;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\WorkId;

/** Schedules ordinary GAR XML files on reusable work-orchestrator workers. */
final readonly class GarAsyncFileImporter implements GarFilePhaseImporter
{
    public function __construct(private string $databaseDsn) {}

    public function import(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        bool $directCopy,
        array $assignments,
        Cancellation $cancellation,
    ): void {
        $files = [];
        foreach ($assignments as $workerFiles) {
            foreach ($workerFiles as $file) {
                $files[] = $file;
            }
        }
        if ($files === []) {
            throw new GarImportFailed('GAR import worker phase has no files.');
        }

        $result = Orchestrator::fromQueue(GarFileAdmissionQueue::create($files))
            ->primaryWorkers(\count($assignments))
            ->run(
                new GarImportStrategy(
                    databaseDsn: $this->databaseDsn,
                    operationId: $operationId,
                    batchSize: $batchSize,
                    directCopy: $directCopy,
                ),
                $cancellation,
            );

        try {
            $result->throwIfFailed();
        } catch (RunFailed $failure) {
            throw new GarImportFailed('GAR import worker run failed.', previous: $failure);
        }

        foreach ($files as $file) {
            $actualKey = $result->value(new WorkId($file->path));
            if (!\hash_equals($file->key, $actualKey)) {
                throw new GarImportFailed("GAR import worker returned an invalid result for file: {$file->path}");
            }
        }
    }
}

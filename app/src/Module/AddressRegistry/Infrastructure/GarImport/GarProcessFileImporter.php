<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Throwable;

/** Legacy ordinary-file adapter backed by the hidden CLI worker command. */
final readonly class GarProcessFileImporter implements GarFilePhaseImporter
{
    public function __construct(private GarImportWorkerProcessPool $processPool) {}

    public function import(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        bool $directCopy,
        array $assignments,
        Cancellation $cancellation,
    ): void {
        /** @var list<GarImportWorkerProcess> $processes */
        $processes = [];

        try {
            foreach ($assignments as $files) {
                $cancellation->throwIfRequested();
                $processes[] = $this->processPool->start(
                    $operationId,
                    $sourcePath,
                    $batchSize,
                    $directCopy,
                    $files,
                );
            }

            $this->processPool->wait($processes, $cancellation);
        } catch (Throwable $error) {
            $this->processPool->terminate($processes);
            throw $error;
        }
    }
}

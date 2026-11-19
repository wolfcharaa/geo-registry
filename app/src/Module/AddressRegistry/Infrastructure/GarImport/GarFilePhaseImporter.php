<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;

/** Replaceable process strategy for one dependency-safe ordinary-file phase. */
interface GarFilePhaseImporter
{
    /** @param list<non-empty-list<GarXmlSourceFile>> $assignments */
    public function import(
        int $operationId,
        string $sourcePath,
        int $batchSize,
        bool $directCopy,
        array $assignments,
        Cancellation $cancellation,
    ): void;
}

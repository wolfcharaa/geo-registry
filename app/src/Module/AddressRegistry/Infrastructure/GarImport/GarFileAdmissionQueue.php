<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Fedor\WorkOrchestrator\Contract\WorkQueue;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;
use InvalidArgumentException;

/** Builds the root-local largest-first policy for one verified GAR manifest. */
final class GarFileAdmissionQueue
{
    /**
     * @param list<GarXmlSourceFile> $files
     *
     * @return WorkQueue<GarXmlSourceFile>
     */
    public static function create(array $files): WorkQueue
    {
        if ($files === []) {
            throw new InvalidArgumentException('GAR file admission requires at least one file.');
        }

        \usort($files, static fn(GarXmlSourceFile $left, GarXmlSourceFile $right): int =>
            ($right->size <=> $left->size) !== 0
                ? $right->size <=> $left->size
                : $left->path <=> $right->path);

        return new OrderedWorkQueue(\array_map(
            static fn(GarXmlSourceFile $file): WorkEnvelope => new WorkEnvelope(
                new WorkId($file->path),
                $file,
            ),
            $files,
        ));
    }

    private function __construct() {}
}

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarResult;

/** Builds the stable machine-readable one-line GAR import summary. */
final class ImportGarResultFormatter
{
    public static function format(ImportGar $command, ImportGarResult $result): string
    {
        /** @var array<string, int|string> $fields */
        $fields = [
            'status' => $result->alreadyCompleted ? 'already-completed' : 'completed',
            'operation' => $result->operationId,
            'mode' => $result->initialLoad ? 'initial-load' : 'standard',
            'write' => $result->directCopy ? 'direct-copy' : 'merge',
            'pipeline' => $result->pipelineFile ?? 'off',
            'pipeline_writers' => $result->pipelineWriters ?? 'off',
            'pipeline_parsers' => $command->pipelineParsers ?? 'off',
            'pipeline_fused_ranges' => self::yesNo($command->pipelineFusedRanges),
            'pipeline_file_concurrency' => $command->pipelineFileConcurrency,
            'pipeline_buffer_mib' => $command->pipelineBufferMiB,
            'pipeline_fragment_mib' => $command->pipelineFragmentMiB,
            'pipeline_table_aware_files' => self::yesNo($command->pipelineTableAwareFiles),
            'overlap_table_indexes' => self::yesNo($command->overlapTableIndexes),
            'overlap_index_workers' => $command->overlapIndexWorkers,
            'overlap_index_start_percent' => $command->overlapIndexStartPercent,
            'persistent_index_workers' => self::yesNo($command->persistentIndexWorkers),
            'primary_keys' => $result->deferPrimaryKeys ? 'deferred' : 'active',
            'overlap_index_foreign_keys' => self::yesNo($command->overlapIndexForeignKeys),
            'phase' => $result->phase,
            'files' => "{$result->filesCompleted}/{$result->filesTotal}",
            'rows' => $result->rowsProcessed,
            'merged' => $result->rowsMerged,
            'workers' => $command->workers,
            'index_workers' => $command->indexWorkers,
            'foreign_key_workers' => $command->foreignKeyWorkers,
            'indexes' => "{$result->indexesCompleted}/{$result->indexesTotal}",
            'regions' => $command->regions === [] ? 'all' : \implode(',', $command->regions),
            'resumed' => self::yesNo($result->resumed),
            'pipeline_batches' => $result->pipelineBatches,
            'pipeline_bytes' => $result->pipelineBytes,
            'pipeline_peak_batches' => $result->pipelinePeakInFlightBatches,
            'pipeline_peak_bytes' => $result->pipelinePeakInFlightBytes,
            'foreign_keys' => $result->deferForeignKeys ? 'deferred' : 'active',
            'foreign_keys_completed' => "{$result->foreignKeysCompleted}/{$result->foreignKeysTotal}",
        ];

        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = "{$name}={$value}";
        }

        return \implode(' ', $parts);
    }

    private static function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function __construct() {}
}

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRaw;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawResult;

/** Builds the machine-readable terminal summary for the raw-only boundary. */
final class ImportGarRawResultFormatter
{
    public static function format(ImportGarRaw $command, ImportGarRawResult $result): string
    {
        /** @var array<string, int|string> $fields */
        $fields = [
            'status' => $result->alreadyCompleted ? 'already-completed' : 'raw-completed',
            'boundary' => 'raw',
            'operation' => $result->operationId,
            'durable_phase' => $result->durablePhase,
            'files' => "{$result->filesCompleted}/{$result->filesTotal}",
            'rows' => $result->rowsProcessed,
            'copied' => $result->rowsMerged,
            'primary_workers' => $command->primaryWorkers,
            'child_workers_per_primary' => $command->childWorkersPerPrimary,
            'process_budget' => $command->primaryWorkers * (1 + $command->childWorkersPerPrimary),
            'pipeline' => $result->pipelineFile ?? 'off',
            'fragment_mib' => $command->fragmentMiB,
            'range_tasks' => $result->rangeTasks,
            'managed_ipc_request_bytes' => $result->managedTaskRequestBytes,
            'managed_ipc_result_bytes' => $result->managedTaskResultBytes,
            'managed_ipc_bytes' => $result->managedTaskRequestBytes + $result->managedTaskResultBytes,
            'pipeline_batches' => $result->pipelineBatches,
            'pipeline_bytes' => $result->pipelineBytes,
            'primary_keys' => $result->deferPrimaryKeys ? 'deferred' : 'active',
            'foreign_keys' => $result->deferForeignKeys ? 'deferred' : 'active',
            'indexes_deferred' => $result->indexesDeferred,
            'foreign_keys_deferred' => $result->foreignKeysDeferred,
            'regions' => $command->regions === [] ? 'all' : \implode(',', $command->regions),
            'resumed' => $result->resumed ? 'yes' : 'no',
            'raw_seconds' => \number_format($result->rawSeconds, 6, '.', ''),
            'diagnostics' => \count($result->diagnostics),
        ];

        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = "{$name}={$value}";
        }

        return \implode(' ', $parts);
    }

    private function __construct() {}
}

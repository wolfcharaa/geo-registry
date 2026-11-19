<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawDiagnostic;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawDiagnosticCause;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawDiagnosticFrame;

/** Renders bounded raw-import diagnostics as one machine-readable JSON line each. */
final class ImportGarRawDiagnosticFormatter
{
    /** @param list<ImportGarRawDiagnostic> $diagnostics */
    public static function format(array $diagnostics): string
    {
        $lines = [];
        foreach ($diagnostics as $index => $diagnostic) {
            $lines[] = \sprintf(
                'diagnostic[%d]=%s',
                $index,
                \json_encode(
                    self::diagnostic($diagnostic),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
                ),
            );
        }

        return \implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private static function diagnostic(ImportGarRawDiagnostic $diagnostic): array
    {
        return [
            'kind' => $diagnostic->kind,
            'origin' => [
                'role' => $diagnostic->role,
                'executor' => $diagnostic->executor,
                'process_id' => $diagnostic->processId,
                'work_id' => $diagnostic->workId,
                'task_id' => $diagnostic->taskId,
                'attempt' => $diagnostic->attempt,
            ],
            'type' => $diagnostic->type,
            'message' => $diagnostic->message,
            'code' => $diagnostic->code,
            'file' => $diagnostic->file,
            'line' => $diagnostic->line,
            'frames' => \array_map(self::frame(...), $diagnostic->frames),
            'causes' => \array_map(self::cause(...), $diagnostic->causes),
            'omitted_frames' => $diagnostic->omittedFrames,
            'omitted_causes' => $diagnostic->omittedCauses,
            'message_truncated' => $diagnostic->messageTruncated,
        ];
    }

    /** @return array<string, int|string|null> */
    private static function frame(ImportGarRawDiagnosticFrame $frame): array
    {
        return [
            'file' => $frame->file,
            'line' => $frame->line,
            'class' => $frame->class,
            'type' => $frame->type,
            'function' => $frame->function,
        ];
    }

    /** @return array<string, mixed> */
    private static function cause(ImportGarRawDiagnosticCause $cause): array
    {
        return [
            'type' => $cause->type,
            'message' => $cause->message,
            'code' => $cause->code,
            'file' => $cause->file,
            'line' => $cause->line,
            'frames' => \array_map(self::frame(...), $cause->frames),
            'omitted_frames' => $cause->omittedFrames,
            'message_truncated' => $cause->messageTruncated,
        ];
    }

    private function __construct() {}
}

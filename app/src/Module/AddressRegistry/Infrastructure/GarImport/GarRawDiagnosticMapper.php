<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawDiagnostic;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawDiagnosticCause;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawDiagnosticFrame;
use Fedor\WorkOrchestrator\Result\FailureCause;
use Fedor\WorkOrchestrator\Result\FailureDiagnostic;
use Fedor\WorkOrchestrator\Result\TraceFrame;

/** Maps package failure evidence without exposing package types through the capability port. */
final class GarRawDiagnosticMapper
{
    public static function map(FailureDiagnostic $diagnostic): ImportGarRawDiagnostic
    {
        return new ImportGarRawDiagnostic(
            kind: $diagnostic->kind->value,
            role: $diagnostic->origin->role?->value,
            executor: $diagnostic->origin->executor?->value,
            processId: $diagnostic->origin->processId,
            workId: $diagnostic->origin->workId?->value,
            taskId: $diagnostic->origin->taskId,
            attempt: $diagnostic->origin->attempt,
            type: $diagnostic->type,
            message: $diagnostic->message,
            code: $diagnostic->code,
            file: $diagnostic->file,
            line: $diagnostic->line,
            frames: \array_map(self::frame(...), $diagnostic->frames),
            causes: \array_map(self::cause(...), $diagnostic->causes),
            omittedFrames: $diagnostic->omittedFrames,
            omittedCauses: $diagnostic->omittedCauses,
            messageTruncated: $diagnostic->messageTruncated,
        );
    }

    private static function cause(FailureCause $cause): ImportGarRawDiagnosticCause
    {
        return new ImportGarRawDiagnosticCause(
            type: $cause->type,
            message: $cause->message,
            code: $cause->code,
            file: $cause->file,
            line: $cause->line,
            frames: \array_map(self::frame(...), $cause->frames),
            omittedFrames: $cause->omittedFrames,
            messageTruncated: $cause->messageTruncated,
        );
    }

    private static function frame(TraceFrame $frame): ImportGarRawDiagnosticFrame
    {
        return new ImportGarRawDiagnosticFrame(
            file: $frame->file,
            line: $frame->line,
            class: $frame->class,
            type: $frame->type,
            function: $frame->function,
        );
    }

    private function __construct() {}
}

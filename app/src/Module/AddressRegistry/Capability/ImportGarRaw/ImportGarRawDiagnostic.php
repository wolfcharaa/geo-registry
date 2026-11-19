<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGarRaw;

/** Application-owned, bounded failure evidence exposed by the experimental raw port. */
final readonly class ImportGarRawDiagnostic
{
    /**
     * @param list<ImportGarRawDiagnosticFrame> $frames
     * @param list<ImportGarRawDiagnosticCause> $causes
     */
    public function __construct(
        public string $kind,
        public ?string $role,
        public ?string $executor,
        public int $processId,
        public ?string $workId,
        public ?string $taskId,
        public ?int $attempt,
        public string $type,
        public string $message,
        public int $code,
        public string $file,
        public int $line,
        public array $frames,
        public array $causes,
        public int $omittedFrames,
        public int $omittedCauses,
        public bool $messageTruncated,
    ) {}
}

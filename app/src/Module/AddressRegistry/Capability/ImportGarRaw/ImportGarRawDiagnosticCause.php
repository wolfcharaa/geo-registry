<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGarRaw;

/** One bounded previous-cause entry in raw-import failure evidence. */
final readonly class ImportGarRawDiagnosticCause
{
    /** @param list<ImportGarRawDiagnosticFrame> $frames */
    public function __construct(
        public string $type,
        public string $message,
        public int $code,
        public string $file,
        public int $line,
        public array $frames,
        public int $omittedFrames,
        public bool $messageTruncated,
    ) {}
}

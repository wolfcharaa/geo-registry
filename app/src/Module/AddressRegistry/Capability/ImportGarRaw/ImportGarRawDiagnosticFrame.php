<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGarRaw;

/** Safe structural trace frame copied from work-orchestrator evidence. */
final readonly class ImportGarRawDiagnosticFrame
{
    public function __construct(
        public ?string $file,
        public ?int $line,
        public ?string $class,
        public ?string $type,
        public string $function,
    ) {}
}

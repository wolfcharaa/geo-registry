<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

final readonly class GarImportPipelineCandidate
{
    public function __construct(
        public string $relativePath,
        public int $size,
        public int $priority,
        public bool $automatic,
    ) {}
}

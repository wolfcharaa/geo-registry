<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

final readonly class GarImportPlan
{
    /** @param list<GarImportPipelineCandidate> $pipelineCandidates */
    public function __construct(
        public string $manifestHash,
        public int $filesTotal,
        public int $bytesTotal,
        public array $pipelineCandidates,
    ) {}
}

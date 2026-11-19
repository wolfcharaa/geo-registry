<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportPipelineCandidate;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportPlan;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportPlanner;

final readonly class GarXmlImportPlanner implements GarImportPlanner
{
    public function __construct(
        private GarXmlCatalog $catalog,
        private GarImportPipelineSelector $pipelineSelector,
    ) {}

    public function plan(string $sourcePath, array $regions): GarImportPlan
    {
        $manifest = $this->catalog->manifest($sourcePath, $regions);
        $files = $manifest->files;
        $candidates = [];
        $automaticFiles = $this->pipelineSelector->automatic($files);
        $automaticKeys = \array_fill_keys(\array_map(
            static fn(GarXmlSourceFile $file): string => $file->key,
            $automaticFiles,
        ), true);
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += $file->size;
            if ($this->pipelineSelector->supports($file)) {
                $candidates[] = new GarImportPipelineCandidate(
                    $file->relativePath,
                    $file->size,
                    $file->descriptor->priority,
                    isset($automaticKeys[$file->key]),
                );
            }
        }
        \usort(
            $candidates,
            static fn(GarImportPipelineCandidate $left, GarImportPipelineCandidate $right): int => [
                -$left->size,
                $left->relativePath,
            ] <=> [
                -$right->size,
                $right->relativePath,
            ],
        );

        return new GarImportPlan(
            manifestHash: $manifest->hash,
            filesTotal: \count($files),
            bytesTotal: $bytes,
            pipelineCandidates: $candidates,
        );
    }
}

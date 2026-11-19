<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGarRaw;

use InvalidArgumentException;

/** Raw-only direct initial-load request for the experimental orchestrator path. */
final readonly class ImportGarRaw
{
    public const MAX_PROCESS_BUDGET = 16;

    /** @param list<int> $regions */
    public function __construct(
        public string $sourcePath,
        public int $batchSize = 50_000,
        public int $primaryWorkers = 1,
        public int $childWorkersPerPrimary = 8,
        public array $regions = [],
        public string $pipelineFile = 'auto',
        public int $fragmentMiB = 8,
        public bool $deferPrimaryKeys = false,
        public bool $deferForeignKeys = false,
    ) {
        if (\trim($this->sourcePath) === '') {
            throw new InvalidArgumentException('GAR raw import source path must not be empty.');
        }
        if ($this->batchSize < 1 || $this->batchSize > 100_000) {
            throw new InvalidArgumentException('GAR raw import batch size must be between 1 and 100000.');
        }
        if ($this->primaryWorkers < 1 || $this->primaryWorkers > self::MAX_PROCESS_BUDGET) {
            throw new InvalidArgumentException('GAR raw primary worker count must be between 1 and 16.');
        }
        if ($this->childWorkersPerPrimary < 1 || $this->childWorkersPerPrimary > 8) {
            throw new InvalidArgumentException('GAR raw child worker count must be between 1 and 8 per primary.');
        }
        if ($this->primaryWorkers * (1 + $this->childWorkersPerPrimary) > self::MAX_PROCESS_BUDGET) {
            throw new InvalidArgumentException(
                'GAR raw primary and child workers must fit the 16-process topology budget.',
            );
        }
        if ($this->fragmentMiB < 8 || $this->fragmentMiB > 16) {
            throw new InvalidArgumentException('GAR raw fragment size must be between 8 and 16 MiB.');
        }
        if (
            \trim($this->pipelineFile) === ''
            || (\str_starts_with($this->pipelineFile, '/') && $this->pipelineFile !== 'auto')
            || \str_contains($this->pipelineFile, "\0")
        ) {
            throw new InvalidArgumentException(
                'GAR raw pipeline file must be auto or a manifest-relative XML path.',
            );
        }
        if ($this->deferPrimaryKeys && !$this->deferForeignKeys) {
            throw new InvalidArgumentException(
                'GAR raw deferred primary keys require deferred foreign keys.',
            );
        }

        $uniqueRegions = [];
        foreach ($this->regions as $region) {
            if ($region < 1 || $region > 99 || isset($uniqueRegions[$region])) {
                throw new InvalidArgumentException(
                    'GAR raw import regions must contain unique codes from 1 to 99.',
                );
            }
            $uniqueRegions[$region] = true;
        }
    }
}

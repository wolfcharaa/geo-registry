<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class ImportGar
{
    /**
     * @param list<int> $regions
     */
    public function __construct(
        public string $sourcePath,
        public int $batchSize = 50_000,
        public int $workers = 2,
        public int $indexWorkers = 2,
        public bool $restart = false,
        public array $regions = [],
        public bool $initialLoad = false,
        public bool $directCopy = false,
        public ?string $pipelineFile = null,
        public ?int $pipelineWriters = null,
        public ?int $pipelineParsers = null,
        public bool $pipelineFusedRanges = false,
        public int $pipelineFileConcurrency = 1,
        public int $pipelineBufferMiB = 16,
        public int $pipelineFragmentMiB = 8,
        public bool $pipelineTableAwareFiles = false,
        public bool $overlapTableIndexes = false,
        public int $overlapIndexWorkers = 1,
        public int $overlapIndexStartPercent = 0,
        public bool $persistentIndexWorkers = false,
        public bool $deferPrimaryKeys = false,
        public bool $deferForeignKeys = false,
        public int $foreignKeyWorkers = 2,
        public bool $overlapIndexForeignKeys = false,
    ) {
        if (\trim($this->sourcePath) === '') {
            throw new InvalidArgumentException('GAR import source path must not be empty.');
        }
        if ($this->batchSize < 1 || $this->batchSize > 100_000) {
            throw new InvalidArgumentException('GAR import batch size must be between 1 and 100000.');
        }
        if ($this->workers < 1 || $this->workers > 16) {
            throw new InvalidArgumentException('GAR import worker count must be between 1 and 16.');
        }
        if ($this->indexWorkers < 1 || $this->indexWorkers > 4) {
            throw new InvalidArgumentException('GAR index worker count must be between 1 and 4.');
        }
        if ($this->foreignKeyWorkers < 1 || $this->foreignKeyWorkers > 4) {
            throw new InvalidArgumentException('GAR foreign-key worker count must be between 1 and 4.');
        }
        $uniqueRegions = [];
        foreach ($this->regions as $region) {
            if ($region < 1 || $region > 99 || isset($uniqueRegions[$region])) {
                throw new InvalidArgumentException('GAR import regions must contain unique codes from 1 to 99.');
            }
            $uniqueRegions[$region] = true;
        }
        if ($this->initialLoad && $this->restart) {
            throw new InvalidArgumentException(
                'GAR initial-load cannot discard its index recovery state; resume it or recreate the dedicated database.',
            );
        }
        if ($this->directCopy && !$this->initialLoad) {
            throw new InvalidArgumentException('GAR direct-copy requires --initial-load on an empty dedicated database.');
        }
        if ($this->deferForeignKeys && (!$this->initialLoad || !$this->directCopy)) {
            throw new InvalidArgumentException(
                'GAR deferred foreign keys require --initial-load and --direct-copy.',
            );
        }
        if (
            $this->deferPrimaryKeys
            && (!$this->initialLoad || !$this->directCopy || !$this->deferForeignKeys)
        ) {
            throw new InvalidArgumentException(
                'GAR deferred primary keys require --initial-load, --direct-copy and --defer-foreign-keys.',
            );
        }
        if (!$this->deferForeignKeys && $this->foreignKeyWorkers !== 2) {
            throw new InvalidArgumentException(
                'GAR --foreign-key-workers requires --defer-foreign-keys.',
            );
        }
        if ($this->overlapIndexForeignKeys && (!$this->deferForeignKeys || !$this->overlapTableIndexes)) {
            throw new InvalidArgumentException(
                'GAR --overlap-index-foreign-keys requires --defer-foreign-keys and --overlap-table-indexes.',
            );
        }
        if (($this->pipelineFile === null) !== ($this->pipelineWriters === null)) {
            throw new InvalidArgumentException(
                'GAR --pipeline-file and --pipeline-writers must be provided together.',
            );
        }
        if ($this->pipelineParsers !== null && $this->pipelineFile === null) {
            throw new InvalidArgumentException(
                'GAR --pipeline-parsers requires --pipeline-file and --pipeline-writers.',
            );
        }
        if ($this->pipelineFusedRanges && $this->pipelineParsers === null) {
            throw new InvalidArgumentException(
                'GAR --pipeline-fused-ranges requires --pipeline-parsers.',
            );
        }
        if ($this->pipelineFileConcurrency < 1 || $this->pipelineFileConcurrency > 2) {
            throw new InvalidArgumentException('GAR pipeline file concurrency must be between 1 and 2.');
        }
        if (!$this->pipelineFusedRanges && $this->pipelineFileConcurrency !== 1) {
            throw new InvalidArgumentException(
                'GAR --pipeline-file-concurrency requires --pipeline-fused-ranges.',
            );
        }
        if ($this->pipelineBufferMiB < 16 || $this->pipelineBufferMiB > 64) {
            throw new InvalidArgumentException('GAR pipeline buffer must be between 16 and 64 MiB.');
        }
        if (!$this->pipelineFusedRanges && $this->pipelineBufferMiB !== 16) {
            throw new InvalidArgumentException(
                'GAR --pipeline-buffer-mib requires --pipeline-fused-ranges.',
            );
        }
        if ($this->pipelineFragmentMiB < 8 || $this->pipelineFragmentMiB > 16) {
            throw new InvalidArgumentException('GAR pipeline fragment must be between 8 and 16 MiB.');
        }
        if (!$this->pipelineFusedRanges && $this->pipelineFragmentMiB !== 8) {
            throw new InvalidArgumentException(
                'GAR --pipeline-fragment-mib requires --pipeline-fused-ranges.',
            );
        }
        if ($this->pipelineTableAwareFiles && !$this->pipelineFusedRanges) {
            throw new InvalidArgumentException(
                'GAR --pipeline-table-aware-files requires --pipeline-fused-ranges.',
            );
        }
        if ($this->pipelineTableAwareFiles && $this->pipelineFileConcurrency < 2) {
            throw new InvalidArgumentException(
                'GAR --pipeline-table-aware-files requires file concurrency of at least 2.',
            );
        }
        if ($this->overlapTableIndexes && (!$this->initialLoad || !$this->directCopy)) {
            throw new InvalidArgumentException(
                'GAR --overlap-table-indexes requires --initial-load and --direct-copy.',
            );
        }
        if ($this->overlapIndexWorkers < 1 || $this->overlapIndexWorkers > 4) {
            throw new InvalidArgumentException('GAR overlap index worker count must be between 1 and 4.');
        }
        if ($this->overlapIndexWorkers > $this->indexWorkers) {
            throw new InvalidArgumentException('GAR overlap index workers must not exceed the final index worker count.');
        }
        if (!$this->overlapTableIndexes && $this->overlapIndexWorkers !== 1) {
            throw new InvalidArgumentException(
                'GAR --overlap-index-workers requires --overlap-table-indexes.',
            );
        }
        if ($this->overlapIndexStartPercent < 0 || $this->overlapIndexStartPercent > 99) {
            throw new InvalidArgumentException('GAR overlap index start percent must be between 0 and 99.');
        }
        if (!$this->overlapTableIndexes && $this->overlapIndexStartPercent !== 0) {
            throw new InvalidArgumentException(
                'GAR --overlap-index-start-percent requires --overlap-table-indexes.',
            );
        }
        if ($this->persistentIndexWorkers && !$this->initialLoad) {
            throw new InvalidArgumentException('GAR --persistent-index-workers requires --initial-load.');
        }
        if ($this->pipelineFile !== null) {
            if (!$this->initialLoad || !$this->directCopy) {
                throw new InvalidArgumentException(
                    'GAR batch pipeline requires --initial-load and --direct-copy.',
                );
            }
            if (
                \trim($this->pipelineFile) === ''
                || \str_starts_with($this->pipelineFile, '/')
                || \str_contains($this->pipelineFile, "\0")
            ) {
                throw new InvalidArgumentException('GAR pipeline file must be a manifest-relative XML path.');
            }
            if ($this->pipelineWriters === null || $this->pipelineWriters < 1 || $this->pipelineWriters > 4) {
                throw new InvalidArgumentException('GAR pipeline writer count must be between 1 and 4.');
            }
            if ($this->pipelineWriters > $this->workers) {
                throw new InvalidArgumentException('GAR pipeline writers must not exceed the global worker count.');
            }
            if ($this->pipelineParsers !== null) {
                $maxParsers = $this->pipelineFusedRanges ? 8 : 4;
                if ($this->pipelineParsers < 1 || $this->pipelineParsers > $maxParsers) {
                    throw new InvalidArgumentException(\sprintf(
                        'GAR pipeline parser count must be between 1 and %d.',
                        $maxParsers,
                    ));
                }
                if ($this->pipelineWriters + $this->pipelineParsers > $this->workers) {
                    throw new InvalidArgumentException(
                        'GAR pipeline parsers and writers must fit the global worker count.',
                    );
                }
            }
        }
    }
}

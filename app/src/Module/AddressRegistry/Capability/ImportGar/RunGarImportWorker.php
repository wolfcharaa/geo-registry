<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class RunGarImportWorker
{
    /** @param list<GarImportWorkerFile> $files */
    public function __construct(
        public int $operationId,
        public string $sourcePath,
        public int $batchSize,
        public array $files,
    ) {
        if ($this->operationId < 1) {
            throw new InvalidArgumentException('GAR import worker operation id must be positive.');
        }
        if (trim($this->sourcePath) === '') {
            throw new InvalidArgumentException('GAR import worker source path must not be empty.');
        }
        if ($this->batchSize < 1 || $this->batchSize > 100_000) {
            throw new InvalidArgumentException('GAR import worker batch size must be between 1 and 100000.');
        }
        if ($this->files === []) {
            throw new InvalidArgumentException('GAR import worker must receive at least one file.');
        }
    }
}

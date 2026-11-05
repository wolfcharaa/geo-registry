<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\ImportGar;

use InvalidArgumentException;

final readonly class GarImportWorkerFile
{
    public function __construct(
        public string $relativePath,
        public string $fileKey,
    ) {
        if (trim($this->relativePath) === '') {
            throw new InvalidArgumentException('GAR import worker relative path must not be empty.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->fileKey) !== 1) {
            throw new InvalidArgumentException('GAR import worker file key must be a SHA-256 hash.');
        }
    }
}

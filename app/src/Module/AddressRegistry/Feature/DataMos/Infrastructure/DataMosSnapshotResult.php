<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\Infrastructure;

final readonly class DataMosSnapshotResult
{
    public function __construct(
        public string $path,
        public string $manifestPath,
        public int $rows,
        public int $bytes,
        public string $sha256,
        public DataMosDatasetMetadata $dataset,
    ) {}
}

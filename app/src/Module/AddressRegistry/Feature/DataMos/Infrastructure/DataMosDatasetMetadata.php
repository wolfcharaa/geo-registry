<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\Infrastructure;

final readonly class DataMosDatasetMetadata
{
    public function __construct(
        public int $datasetId,
        public ?int $versionNumber,
        public ?int $releaseNumber,
        public int $rows,
    ) {}
}

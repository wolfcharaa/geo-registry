<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\Releases;

interface GarReleaseMetadataReader
{
    public function endpoint(bool $all): string;

    public function latest(?string $url, int $timeoutSeconds): GarReleaseMetadata;

    /** @return list<GarReleaseMetadata> */
    public function all(?string $url, int $timeoutSeconds): array;
}

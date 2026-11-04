<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarXmlSourceFile
{
    public function __construct(
        public string $path,
        public string $relativePath,
        public int $size,
        public int $modifiedAt,
        public string $key,
        public GarXmlFileDescriptor $descriptor,
    ) {}
}

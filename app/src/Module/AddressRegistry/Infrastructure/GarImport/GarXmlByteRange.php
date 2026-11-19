<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

final readonly class GarXmlByteRange
{
    public function __construct(
        public int $firstByte,
        public int $lastByte,
        public int $firstRow,
        public int $lastRow,
    ) {
        if (
            $this->firstByte < 0
            || $this->lastByte <= $this->firstByte
            || $this->firstRow < 1
            || $this->lastRow < $this->firstRow
        ) {
            throw new GarImportFailed('GAR XML byte range is invalid.');
        }
    }

    public function bytes(): int
    {
        return $this->lastByte - $this->firstByte;
    }

    public function rows(): int
    {
        return $this->lastRow - $this->firstRow + 1;
    }
}

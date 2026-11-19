<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final readonly class GarParsedXmlRange
{
    /**
     * @param non-empty-list<string> $rows
     */
    public function __construct(
        public array $rows,
        public int $firstRow,
        public int $lastRow,
        public int $encodedBytes,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRowReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use Generator;
use IteratorAggregate;

/** @implements IteratorAggregate<int, list<?string>> */
final class GarXmlRowPrefixSource implements IteratorAggregate
{
    private int $rows = 0;

    private bool $started = false;

    private bool $completed = false;

    public function __construct(
        private readonly GarXmlSourceFile $file,
        private readonly int $rowLimit,
    ) {
        if ($this->rowLimit < 1) {
            throw new GarImportFailed('GAR XML benchmark row limit must be positive.');
        }
    }

    /** @return Generator<int, list<?string>> */
    public function getIterator(): Generator
    {
        if ($this->started) {
            throw new GarImportFailed('GAR XML benchmark prefix source is one-shot.');
        }
        $this->started = true;

        foreach (new GarXmlRowReader()->rows($this->file, 0) as $values) {
            ++$this->rows;
            yield $values;

            if ($this->rows >= $this->rowLimit) {
                break;
            }
        }

        $this->completed = true;
    }

    public function rows(): int
    {
        if (!$this->completed) {
            throw new GarImportFailed('GAR XML benchmark prefix source has not completed.');
        }

        return $this->rows;
    }
}

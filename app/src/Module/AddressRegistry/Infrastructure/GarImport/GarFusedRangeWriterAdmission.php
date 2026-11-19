<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Amp\DeferredFuture;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

final class GarFusedRangeWriterAdmission
{
    /** @var array<string, positive-int> */
    private array $writerBytes = [];

    /** @var DeferredFuture<void> */
    private DeferredFuture $availability;

    public function __construct(
        private readonly int $maxWriters,
        private readonly int $maxBytes,
    ) {
        if ($this->maxWriters < 1 || $this->maxBytes < 1) {
            throw new GarImportFailed('GAR fused range writer admission requires positive limits.');
        }

        $this->availability = new DeferredFuture();
    }

    public function tryAcquire(string $token, int $bytes): bool
    {
        if ($token === '' || $bytes < 1) {
            throw new GarImportFailed('GAR fused range writer admission received invalid range metadata.');
        }
        if (isset($this->writerBytes[$token])) {
            throw new GarImportFailed('GAR fused range writer admission received a duplicate range token.');
        }
        if (\count($this->writerBytes) >= $this->maxWriters) {
            return false;
        }

        $inFlightBytes = $this->bytes();
        if ($this->writerBytes !== [] && $inFlightBytes + $bytes > $this->maxBytes) {
            return false;
        }

        $this->writerBytes[$token] = $bytes;

        return true;
    }

    public function release(string $token): void
    {
        if (!isset($this->writerBytes[$token])) {
            throw new GarImportFailed('GAR fused range writer admission cannot release an unknown range token.');
        }

        unset($this->writerBytes[$token]);
        $availability = $this->availability;
        $this->availability = new DeferredFuture();
        $availability->complete();
    }

    public function awaitAvailability(Cancellation $cancellation): void
    {
        $this->availability->getFuture()->await($cancellation);
    }

    /** @return non-negative-int */
    public function writers(): int
    {
        return \count($this->writerBytes);
    }

    /** @return non-negative-int */
    public function bytes(): int
    {
        return \array_sum($this->writerBytes);
    }
}

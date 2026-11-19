<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use Amp\DeferredFuture;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

final class GarFusedRangeTaskAdmission
{
    /** @var array<string, true> */
    private array $tasks = [];

    /** @var DeferredFuture<void> */
    private DeferredFuture $availability;

    public function __construct(
        private readonly int $maxTasks,
    ) {
        if ($this->maxTasks < 1) {
            throw new GarImportFailed('GAR fused range task admission requires a positive limit.');
        }

        $this->availability = new DeferredFuture();
    }

    public function tryAcquire(string $token): bool
    {
        if ($token === '') {
            throw new GarImportFailed('GAR fused range task admission received an empty range token.');
        }
        if (isset($this->tasks[$token])) {
            throw new GarImportFailed('GAR fused range task admission received a duplicate range token.');
        }
        if (\count($this->tasks) >= $this->maxTasks) {
            return false;
        }

        $this->tasks[$token] = true;

        return true;
    }

    public function release(string $token): void
    {
        if (!isset($this->tasks[$token])) {
            throw new GarImportFailed('GAR fused range task admission cannot release an unknown range token.');
        }

        unset($this->tasks[$token]);
        $availability = $this->availability;
        $this->availability = new DeferredFuture();
        $availability->complete();
    }

    public function awaitAvailability(Cancellation $cancellation): void
    {
        $this->availability->getFuture()->await($cancellation);
    }

    /** @return non-negative-int */
    public function tasks(): int
    {
        return \count($this->tasks);
    }
}

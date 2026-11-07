<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use InvalidArgumentException;

final class HttpWorkerPoolState
{
    /** @var array<int, array{pid: int, last_seen: float}> */
    private array $workers = [];

    private bool $reachedCapacity = false;
    private bool $degraded = false;

    public function __construct(
        private readonly int $expectedWorkers,
        private readonly float $heartbeatTimeoutSeconds,
    ) {
        if ($this->expectedWorkers < 1) {
            throw new InvalidArgumentException('Expected HTTP worker count must be positive.');
        }
        if ($this->heartbeatTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('HTTP worker heartbeat timeout must be positive.');
        }
    }

    public function observe(HttpWorkerSignal $signal, float $now): void
    {
        $this->assertTime($now);

        if ($signal->type === HttpWorkerSignalType::Offline) {
            unset($this->workers[$signal->workerId]);

            return;
        }

        $this->workers[$signal->workerId] = [
            'pid' => $signal->pid,
            'last_seen' => $now,
        ];
    }

    /** @param \Closure(int): bool $isProcessAlive */
    public function inspect(float $now, \Closure $isProcessAlive): HttpWorkerPoolObservation
    {
        $this->assertTime($now);
        $removedWorkerIds = [];
        foreach ($this->workers as $workerId => $worker) {
            if (($now - $worker['last_seen']) <= $this->heartbeatTimeoutSeconds
                && $isProcessAlive($worker['pid'])
            ) {
                continue;
            }

            $removedWorkerIds[] = $workerId;
            unset($this->workers[$workerId]);
        }

        sort($removedWorkerIds);
        $activeWorkers = count($this->workers);
        $atCapacity = $activeWorkers >= $this->expectedWorkers;
        $transition = null;

        if (!$this->reachedCapacity && $atCapacity) {
            $this->reachedCapacity = true;
            $transition = HttpWorkerPoolTransition::Ready;
        } elseif ($this->reachedCapacity && !$atCapacity && !$this->degraded) {
            $this->degraded = true;
            $transition = HttpWorkerPoolTransition::Degraded;
        } elseif ($this->reachedCapacity && $atCapacity && $this->degraded) {
            $this->degraded = false;
            $transition = HttpWorkerPoolTransition::Restored;
        }

        return new HttpWorkerPoolObservation(
            activeWorkers: $activeWorkers,
            expectedWorkers: $this->expectedWorkers,
            removedWorkerIds: $removedWorkerIds,
            transition: $transition,
        );
    }

    public function activeWorkers(): int
    {
        return count($this->workers);
    }

    private function assertTime(float $now): void
    {
        if (!is_finite($now) || $now < 0) {
            throw new InvalidArgumentException('Monotonic HTTP worker observation time must be finite and non-negative.');
        }
    }
}

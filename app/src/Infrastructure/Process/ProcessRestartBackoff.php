<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use InvalidArgumentException;

final class ProcessRestartBackoff
{
    private int $initialStartsRemaining;

    /** @var list<float> */
    private array $restartStartedAt = [];

    public function __construct(
        int $initialStartCount,
        private readonly int $restartBurst,
        private readonly float $windowSeconds,
        private readonly float $initialDelaySeconds,
        private readonly float $maximumDelaySeconds,
    ) {
        if ($initialStartCount < 1) {
            throw new InvalidArgumentException('Initial process start count must be positive.');
        }
        if ($this->restartBurst < 0) {
            throw new InvalidArgumentException('Process restart burst must be non-negative.');
        }
        if ($this->windowSeconds <= 0) {
            throw new InvalidArgumentException('Process restart window must be positive.');
        }
        if ($this->initialDelaySeconds <= 0) {
            throw new InvalidArgumentException('Initial process restart delay must be positive.');
        }
        if ($this->maximumDelaySeconds < $this->initialDelaySeconds) {
            throw new InvalidArgumentException('Maximum process restart delay must not be less than the initial delay.');
        }

        $this->initialStartsRemaining = $initialStartCount;
    }

    public function beforeStart(float $now): ProcessRestartBackoffDecision
    {
        if (!is_finite($now) || $now < 0) {
            throw new InvalidArgumentException('Monotonic process start time must be finite and non-negative.');
        }

        if ($this->initialStartsRemaining > 0) {
            --$this->initialStartsRemaining;

            return new ProcessRestartBackoffDecision(0, 0);
        }

        $windowStartedAt = $now - $this->windowSeconds;
        $this->restartStartedAt = array_values(array_filter(
            $this->restartStartedAt,
            static fn(float $startedAt): bool => $startedAt > $windowStartedAt,
        ));
        $this->restartStartedAt[] = $now;
        $restartsInWindow = count($this->restartStartedAt);

        if ($restartsInWindow <= $this->restartBurst) {
            return new ProcessRestartBackoffDecision(0, $restartsInWindow);
        }

        $delay = $this->initialDelaySeconds;
        $doublings = $restartsInWindow - $this->restartBurst - 1;
        while ($doublings > 0 && $delay < $this->maximumDelaySeconds) {
            $delay = min($this->maximumDelaySeconds, $delay * 2);
            --$doublings;
        }

        return new ProcessRestartBackoffDecision($delay, $restartsInWindow);
    }
}

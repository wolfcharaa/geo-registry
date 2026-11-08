<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use Closure;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class HttpStaleWorkerTerminator
{
    /** @var Closure(int, int): bool */
    private readonly Closure $sendSignal;

    /** @var Closure(int): ?string */
    private readonly Closure $processIdentity;

    /** @var array<int, string> */
    private array $forceKillWatchers = [];

    /**
     * @param null|Closure(int, int): bool $sendSignal
     * @param null|Closure(int): ?string $processIdentity
     */
    public function __construct(
        private readonly float $graceSeconds,
        private readonly LoggerInterface $logger,
        ?Closure $sendSignal = null,
        ?Closure $processIdentity = null,
    ) {
        if ($this->graceSeconds <= 0 || !is_finite($this->graceSeconds)) {
            throw new InvalidArgumentException('HTTP stale worker termination grace must be finite and positive.');
        }

        $this->sendSignal = $sendSignal ?? static fn(int $pid, int $signal): bool => posix_kill($pid, $signal);
        $this->processIdentity = $processIdentity ?? self::readProcessIdentity(...);
    }

    public function terminate(HttpWorkerProcess $worker): void
    {
        if (isset($this->forceKillWatchers[$worker->workerId])) {
            return;
        }

        $identity = ($this->processIdentity)($worker->pid);
        if ($identity === null) {
            $this->logger->notice('Stale HTTP worker exited before termination', [
                'event' => 'http.cluster.worker.stale.already_exited',
                'worker_id' => $worker->workerId,
                'pid' => $worker->pid,
            ]);

            return;
        }

        if (!(($this->sendSignal)($worker->pid, SIGTERM))) {
            $this->logger->error('Unable to terminate stale HTTP worker', [
                'event' => 'http.cluster.worker.stale.termination_failed',
                'worker_id' => $worker->workerId,
                'pid' => $worker->pid,
                'signal' => SIGTERM,
            ]);

            return;
        }

        $this->logger->warning('Terminating stale HTTP worker', [
            'event' => 'http.cluster.worker.stale.termination_requested',
            'worker_id' => $worker->workerId,
            'pid' => $worker->pid,
            'grace_seconds' => $this->graceSeconds,
        ]);

        $watcherId = EventLoop::delay(
            $this->graceSeconds,
            function (string $watcherId) use ($worker, $identity): void {
                unset($this->forceKillWatchers[$worker->workerId]);

                $currentIdentity = ($this->processIdentity)($worker->pid);
                if ($currentIdentity === null || $currentIdentity !== $identity) {
                    return;
                }

                if (($this->sendSignal)($worker->pid, SIGKILL)) {
                    $this->logger->critical('Force-killed stale HTTP worker after termination grace expired', [
                        'event' => 'http.cluster.worker.stale.force_killed',
                        'worker_id' => $worker->workerId,
                        'pid' => $worker->pid,
                        'grace_seconds' => $this->graceSeconds,
                    ]);

                    return;
                }

                $this->logger->error('Unable to force-kill stale HTTP worker', [
                    'event' => 'http.cluster.worker.stale.force_kill_failed',
                    'worker_id' => $worker->workerId,
                    'pid' => $worker->pid,
                    'signal' => SIGKILL,
                ]);
            },
        );
        $this->forceKillWatchers[$worker->workerId] = $watcherId;
        EventLoop::unreference($watcherId);
    }

    public function stop(): void
    {
        foreach ($this->forceKillWatchers as $watcherId) {
            EventLoop::cancel($watcherId);
        }
        $this->forceKillWatchers = [];
    }

    public function pendingTerminations(): int
    {
        return count($this->forceKillWatchers);
    }

    private static function readProcessIdentity(int $pid): ?string
    {
        $stat = @file_get_contents(sprintf('/proc/%d/stat', $pid));
        if (!is_string($stat)) {
            return null;
        }

        $commandEnd = strrpos($stat, ')');
        if ($commandEnd === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $commandEnd + 1)));
        $startedAtClockTick = is_array($fields) ? ($fields[19] ?? null) : null;

        return is_string($startedAtClockTick) && ctype_digit($startedAtClockTick)
            ? $startedAtClockTick
            : null;
    }
}

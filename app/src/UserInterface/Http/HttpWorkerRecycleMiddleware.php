<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use Amp\Cluster\Cluster;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

final class HttpWorkerRecycleMiddleware implements Middleware
{
    private readonly HttpWorkerRecyclePolicy $policy;

    private bool $recycleScheduled = false;

    public function __construct(
        private readonly int $maxRequests,
        private readonly int $maxResidentMemoryBytes,
        private readonly LoggerInterface $logger,
    ) {
        $this->policy = new HttpWorkerRecyclePolicy($this->maxRequests, $this->maxResidentMemoryBytes);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        try {
            return $requestHandler->handleRequest($request);
        } finally {
            $this->scheduleRecycleWhenRequired();
        }
    }

    private function scheduleRecycleWhenRequired(): void
    {
        if ($this->recycleScheduled || !Cluster::isWorker()) {
            return;
        }

        $residentMemoryBytes = self::residentMemoryBytes();
        $reason = $this->policy->observe($residentMemoryBytes);
        if ($reason === null) {
            return;
        }

        $this->recycleScheduled = true;
        $this->logger->notice('HTTP worker recycle requested', [
            'event' => 'http.worker.recycle.requested',
            'handled_requests' => $this->policy->handledRequests(),
            'max_requests' => $this->maxRequests,
            'max_resident_memory_bytes' => $this->maxResidentMemoryBytes,
            'pid' => getmypid(),
            'reason' => $reason->value,
            'resident_memory_bytes' => $residentMemoryBytes,
            'worker_id' => Cluster::getContextId(),
        ]);

        EventLoop::queue(static function (): void {
            Cluster::shutdown();
        });
    }

    private static function residentMemoryBytes(): int
    {
        $status = is_readable('/proc/self/status') ? file_get_contents('/proc/self/status') : false;
        if (is_string($status) && preg_match('/^VmRSS:\s+([0-9]+)\s+kB$/m', $status, $matches) === 1) {
            return (int) $matches[1] * 1024;
        }

        return memory_get_usage(true);
    }
}

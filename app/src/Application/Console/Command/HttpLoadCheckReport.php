<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

final readonly class HttpLoadCheckReport
{
    /**
     * @param array<int, int> $statusCounts
     * @param array<string, int> $errorCounts
     */
    public function __construct(
        public int $attempts,
        public int $successes,
        public int $failures,
        public float $durationMilliseconds,
        public float $requestsPerSecond,
        public float $p50Milliseconds,
        public float $p95Milliseconds,
        public float $p99Milliseconds,
        public int $responseBytes,
        public array $statusCounts,
        public array $errorCounts,
    ) {}

    public function passes(int $maxFailures, float $maxP95Milliseconds): bool
    {
        return $this->failures <= $maxFailures
            && ($maxP95Milliseconds === 0.0 || $this->p95Milliseconds <= $maxP95Milliseconds);
    }

    /**
     * @return array{
     *     attempts: int,
     *     successes: int,
     *     failures: int,
     *     duration_ms: float,
     *     requests_per_second: float,
     *     p50_ms: float,
     *     p95_ms: float,
     *     p99_ms: float,
     *     response_bytes: int,
     *     http_statuses: array<int, int>,
     *     errors: array<string, int>
     * }
     */
    public function toArray(): array
    {
        return [
            'attempts' => $this->attempts,
            'successes' => $this->successes,
            'failures' => $this->failures,
            'duration_ms' => $this->durationMilliseconds,
            'requests_per_second' => $this->requestsPerSecond,
            'p50_ms' => $this->p50Milliseconds,
            'p95_ms' => $this->p95Milliseconds,
            'p99_ms' => $this->p99Milliseconds,
            'response_bytes' => $this->responseBytes,
            'http_statuses' => $this->statusCounts,
            'errors' => $this->errorCounts,
        ];
    }
}

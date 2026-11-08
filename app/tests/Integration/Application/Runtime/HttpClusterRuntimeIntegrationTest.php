<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application\Runtime;

use function Amp\async;
use function Amp\ByteStream\buffer;

use Amp\ByteStream\ReadableStream;

use function Amp\delay;

use Amp\Future;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use RuntimeException;
use Testo\Assert;
use Testo\Test;
use Throwable;

#[Test]
final class HttpClusterRuntimeIntegrationTest
{
    public function testTwoWorkersShareOneSocketAndStopCleanly(): void
    {
        $port = $this->freeTcpPort();
        $root = dirname(__DIR__, 4);
        $process = Process::start(
            [PHP_BINARY, $root . '/bin/app', 'server:run'],
            $root,
            [
                'APP_HTTP_WORKERS' => '2',
                'APP_HTTP_WORKER_HEARTBEAT_INTERVAL_SECONDS' => '0.05',
                'APP_HTTP_WORKER_HEARTBEAT_TIMEOUT_SECONDS' => '0.3',
                'APP_LISTEN' => '127.0.0.1:' . $port,
                'DATABASE_DSN' => 'host=127.0.0.1 dbname=geo_registry_cluster_test',
                'DATABASE_POOL_SIZE' => '1',
                'LOG_FORMAT' => 'json',
                'NOMINATIM_ENABLED' => '0',
                'OPENSEARCH_ENABLED' => '0',
                'REVOLT_DRIVER' => 'Revolt\\EventLoop\\Driver\\StreamSelectDriver',
            ],
        );
        $stdout = $this->capture($process->getStdout());
        $stderr = $this->capture($process->getStderr());

        try {
            $this->awaitLiveness($port);
            [$loadExitCode, $loadOutput, $loadErrors] = $this->runLoadCheck($root, $port);
            $process->signal(SIGTERM);
            $exitCode = $process->join(new TimeoutCancellation(10));
        } finally {
            $process->kill();
        }

        $logs = $stdout->await();
        $errors = $stderr->await();

        Assert::same($exitCode, 0, $errors);
        Assert::same(substr_count($logs, '"message":"HTTP server started"'), 2, $logs);
        Assert::same(substr_count($logs, '"message":"HTTP worker is online"'), 2, $logs);
        Assert::true(str_contains($logs, '"event":"http.cluster.capacity.ready"'), $logs);
        Assert::false(str_contains($logs, '"event":"http.cluster.capacity.degraded"'), $logs);
        Assert::false(str_contains($logs, '"event":"http.worker.heartbeat.failed"'), $logs);
        Assert::same(substr_count($logs, 'terminated cleanly'), 2, $logs);
        Assert::true(str_contains($logs, '"event":"http.cluster.stopped"'), $logs);
        Assert::same($loadExitCode, 0, $loadErrors);
        $loadReport = json_decode($loadOutput, true, flags: JSON_THROW_ON_ERROR);
        Assert::true(is_array($loadReport));
        Assert::same($loadReport['status'] ?? null, 'ok');
        Assert::same($loadReport['attempts'] ?? null, 20);
    }

    public function testWorkerIsReplacedAfterItsRequestBudgetIsExhausted(): void
    {
        $port = $this->freeTcpPort();
        $root = dirname(__DIR__, 4);
        $process = Process::start(
            [PHP_BINARY, $root . '/bin/app', 'server:run'],
            $root,
            [
                'APP_HTTP_WORKERS' => '2',
                'APP_HTTP_WORKER_MAX_REQUESTS' => '1',
                'APP_HTTP_WORKER_RESTART_BURST' => '0',
                'APP_HTTP_WORKER_RESTART_WINDOW_SECONDS' => '10',
                'APP_HTTP_WORKER_RESTART_BACKOFF_INITIAL_SECONDS' => '0.01',
                'APP_HTTP_WORKER_RESTART_BACKOFF_MAX_SECONDS' => '0.02',
                'APP_LISTEN' => '127.0.0.1:' . $port,
                'DATABASE_DSN' => 'host=127.0.0.1 dbname=geo_registry_cluster_test',
                'DATABASE_POOL_SIZE' => '1',
                'LOG_FORMAT' => 'json',
                'NOMINATIM_ENABLED' => '0',
                'OPENSEARCH_ENABLED' => '0',
                'REVOLT_DRIVER' => 'Revolt\\EventLoop\\Driver\\StreamSelectDriver',
            ],
        );
        $stdout = $this->capture($process->getStdout());
        $stderr = $this->capture($process->getStderr());

        try {
            $this->awaitLiveness($port);
            delay(0.5);
            $process->signal(SIGTERM);
            $exitCode = $process->join(new TimeoutCancellation(10));
        } finally {
            $process->kill();
        }

        $logs = $stdout->await();
        $errors = $stderr->await();

        Assert::same($exitCode, 0, $errors);
        Assert::true(str_contains($logs, '"event":"http.worker.recycle.requested"'), $logs);
        Assert::true(str_contains($logs, '"event":"http.cluster.worker.restart.delayed"'), $logs);
        Assert::true(substr_count($logs, '"message":"HTTP server started"') >= 3, $logs);
        Assert::true(str_contains($logs, 'terminated cleanly, restarting'), $logs);
    }

    public function testShutdownInterruptsAWorkerRestartBackoff(): void
    {
        $port = $this->freeTcpPort();
        $root = dirname(__DIR__, 4);
        $process = Process::start(
            [PHP_BINARY, $root . '/bin/app', 'server:run'],
            $root,
            [
                'APP_HTTP_WORKERS' => '2',
                'APP_HTTP_WORKER_MAX_REQUESTS' => '1',
                'APP_HTTP_WORKER_RESTART_BURST' => '0',
                'APP_HTTP_WORKER_RESTART_WINDOW_SECONDS' => '10',
                'APP_HTTP_WORKER_RESTART_BACKOFF_INITIAL_SECONDS' => '5',
                'APP_HTTP_WORKER_RESTART_BACKOFF_MAX_SECONDS' => '5',
                'APP_HTTP_WORKER_HEARTBEAT_INTERVAL_SECONDS' => '0.05',
                'APP_HTTP_WORKER_HEARTBEAT_TIMEOUT_SECONDS' => '0.15',
                'APP_LISTEN' => '127.0.0.1:' . $port,
                'DATABASE_DSN' => 'host=127.0.0.1 dbname=geo_registry_cluster_test',
                'DATABASE_POOL_SIZE' => '1',
                'LOG_FORMAT' => 'json',
                'NOMINATIM_ENABLED' => '0',
                'OPENSEARCH_ENABLED' => '0',
                'REVOLT_DRIVER' => 'Revolt\\EventLoop\\Driver\\StreamSelectDriver',
            ],
        );
        $stdout = $this->capture($process->getStdout());
        $stderr = $this->capture($process->getStderr());

        try {
            $this->awaitLiveness($port);
            delay(0.2);
            $shutdownStartedAt = microtime(true);
            $process->signal(SIGTERM);
            $exitCode = $process->join(new TimeoutCancellation(3));
            $shutdownDuration = microtime(true) - $shutdownStartedAt;
        } finally {
            $process->kill();
        }

        $logs = $stdout->await();
        $errors = $stderr->await();

        Assert::same($exitCode, 0, $errors);
        Assert::true(str_contains($logs, '"event":"http.cluster.worker.restart.delayed"'), $logs);
        Assert::true(str_contains($logs, '"event":"http.cluster.capacity.degraded"'), $logs);
        Assert::true($shutdownDuration < 2, sprintf('Shutdown took %.3f seconds.', $shutdownDuration));
        Assert::true(str_contains($logs, '"event":"http.cluster.stopped"'), $logs);
    }

    public function testStaleStoppedWorkerIsForceKilledAndReplaced(): void
    {
        $port = $this->freeTcpPort();
        $root = dirname(__DIR__, 4);
        $process = Process::start(
            [PHP_BINARY, $root . '/bin/app', 'server:run'],
            $root,
            [
                'APP_HTTP_WORKERS' => '2',
                'APP_HTTP_WORKER_HEARTBEAT_INTERVAL_SECONDS' => '0.05',
                'APP_HTTP_WORKER_HEARTBEAT_TIMEOUT_SECONDS' => '0.15',
                'APP_HTTP_WORKER_STALE_TERMINATION_GRACE_SECONDS' => '0.05',
                'APP_LISTEN' => '127.0.0.1:' . $port,
                'DATABASE_DSN' => 'host=127.0.0.1 dbname=geo_registry_cluster_test',
                'DATABASE_POOL_SIZE' => '1',
                'LOG_FORMAT' => 'json',
                'NOMINATIM_ENABLED' => '0',
                'OPENSEARCH_ENABLED' => '0',
                'REVOLT_DRIVER' => 'Revolt\\EventLoop\\Driver\\StreamSelectDriver',
            ],
        );
        $stdout = $this->capture($process->getStdout());
        $stderr = $this->capture($process->getStderr());
        $stoppedWorkerPid = null;
        $failure = null;
        $exitCode = null;

        try {
            $this->awaitLiveness($port);
            $workerPids = $this->awaitWorkerProcesses($process->getPid(), 2);
            $stoppedWorkerPid = $workerPids[0]
                ?? throw new RuntimeException('The HTTP worker fixture returned an empty process list.');
            if (!posix_kill($stoppedWorkerPid, SIGSTOP)) {
                throw new RuntimeException('Unable to stop an HTTP worker process for the stale-worker fixture.');
            }

            $this->awaitWorkerReplacement($process->getPid(), $stoppedWorkerPid);
            delay(0.2);
            $this->awaitLiveness($port);
            $process->signal(SIGTERM);
            $exitCode = $process->join(new TimeoutCancellation(10));
        } catch (Throwable $exception) {
            $failure = $exception;
            $process->signal(SIGTERM);
        } finally {
            if ($stoppedWorkerPid !== null) {
                @posix_kill($stoppedWorkerPid, SIGKILL);
            }
            $process->kill();
        }

        $logs = $stdout->await();
        $errors = $stderr->await();

        if ($failure !== null) {
            throw new RuntimeException(sprintf(
                "%s\nSupervisor logs:\n%s\nSupervisor errors:\n%s",
                $failure->getMessage(),
                $logs,
                $errors,
            ), previous: $failure);
        }

        Assert::same($exitCode, 0, $errors);
        Assert::true(str_contains(
            $logs,
            '"event":"http.cluster.worker.stale.termination_requested"',
        ), $logs);
        Assert::true(str_contains($logs, '"event":"http.cluster.worker.stale.force_killed"'), $logs);
        Assert::true(str_contains($logs, '"event":"http.cluster.capacity.degraded"'), $logs);
        Assert::true(str_contains($logs, '"event":"http.cluster.capacity.restored"'), $logs);
        Assert::true(substr_count($logs, '"message":"HTTP server started"') >= 3, $logs);
    }

    private function awaitLiveness(int $port): void
    {
        $client = HttpClientBuilder::buildDefault();
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            try {
                $response = $client->request(new Request(sprintf(
                    'http://127.0.0.1:%d/health/live',
                    $port,
                )));
                buffer($response->getBody());
                if ($response->getStatus() === 200) {
                    return;
                }
            } catch (Throwable) {
                // The supervisor is still starting its worker processes.
            }

            delay(0.05);
        }

        throw new RuntimeException('HTTP cluster did not become live within five seconds.');
    }

    private function freeTcpPort(): int
    {
        $errorCode = 0;
        $errorMessage = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($server === false) {
            throw new RuntimeException(sprintf('Unable to reserve a TCP port: %s', $errorMessage));
        }

        try {
            $address = stream_socket_get_name($server, false);
            if ($address === false) {
                throw new RuntimeException('Unable to read the reserved TCP port.');
            }

            if (preg_match('/:(\d+)$/', $address, $matches) !== 1) {
                throw new RuntimeException('Unable to read the reserved TCP port.');
            }

            return (int) $matches[1];
        } finally {
            fclose($server);
        }
    }

    /** @return list<positive-int> */
    private function awaitWorkerProcesses(int $supervisorPid, int $minimumCount): array
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $pids = $this->workerProcesses($supervisorPid);
            if (count($pids) >= $minimumCount) {
                return $pids;
            }

            delay(0.05);
        }

        throw new RuntimeException(sprintf(
            'HTTP supervisor %d did not start %d child processes within five seconds.',
            $supervisorPid,
            $minimumCount,
        ));
    }

    private function awaitWorkerReplacement(int $supervisorPid, int $replacedPid): void
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $pids = $this->workerProcesses($supervisorPid);
            if (count($pids) >= 2 && !in_array($replacedPid, $pids, true)) {
                return;
            }

            delay(0.05);
        }

        throw new RuntimeException(sprintf(
            'Stale HTTP worker %d was not replaced within five seconds.',
            $replacedPid,
        ));
    }

    /** @return list<positive-int> */
    private function workerProcesses(int $supervisorPid): array
    {
        $descendants = [];
        $pending = [$supervisorPid];
        while ($pending !== []) {
            $parentPid = array_shift($pending);
            foreach ($this->childProcesses($parentPid) as $childPid) {
                $descendants[] = $childPid;
                $pending[] = $childPid;
            }
        }

        return array_values(array_filter($descendants, static function (int $pid): bool {
            $commandName = @file_get_contents(sprintf('/proc/%d/comm', $pid));

            return is_string($commandName) && str_starts_with(trim($commandName), 'php');
        }));
    }

    /** @return list<positive-int> */
    private function childProcesses(int $parentPid): array
    {
        $children = @file_get_contents(sprintf('/proc/%d/task/%d/children', $parentPid, $parentPid));
        if (!is_string($children) || trim($children) === '') {
            return [];
        }

        $parts = preg_split('/\s+/', trim($children));
        if (!is_array($parts)) {
            return [];
        }
        $pids = array_map('intval', $parts);

        return array_values(array_filter($pids, static fn(int $pid): bool => $pid > 0));
    }

    /** @return array{int, string, string} */
    private function runLoadCheck(string $root, int $port): array
    {
        $process = Process::start(
            [
                PHP_BINARY,
                $root . '/bin/app',
                'server:load-check',
                sprintf('http://127.0.0.1:%d/health/live', $port),
                '--requests=20',
                '--concurrency=4',
                '--max-failures=0',
                '--max-p95-ms=5000',
                '--json',
            ],
            $root,
            [
                'NOMINATIM_ENABLED' => '0',
                'OPENSEARCH_ENABLED' => '0',
                'REVOLT_DRIVER' => 'Revolt\\EventLoop\\Driver\\StreamSelectDriver',
            ],
        );
        $stdout = $this->capture($process->getStdout());
        $stderr = $this->capture($process->getStderr());

        try {
            $exitCode = $process->join(new TimeoutCancellation(10));
        } finally {
            $process->kill();
        }

        return [$exitCode, $stdout->await(), $stderr->await()];
    }

    /** @return Future<string> */
    private function capture(ReadableStream $stream): Future
    {
        return async(static fn(): string => buffer($stream));
    }
}

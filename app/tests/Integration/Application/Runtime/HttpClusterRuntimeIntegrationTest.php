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
            $process->signal(SIGTERM);
            $exitCode = $process->join(new TimeoutCancellation(10));
        } finally {
            $process->kill();
        }

        $logs = $stdout->await();
        $errors = $stderr->await();

        Assert::same($exitCode, 0, $errors);
        Assert::same(substr_count($logs, '"message":"HTTP server started"'), 2, $logs);
        Assert::same(substr_count($logs, 'terminated cleanly'), 2, $logs);
        Assert::true(str_contains($logs, '"event":"http.cluster.stopped"'), $logs);
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

    /** @return Future<string> */
    private function capture(ReadableStream $stream): Future
    {
        return async(static fn(): string => buffer($stream));
    }
}

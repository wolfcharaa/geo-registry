<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use Amp\DeferredFuture;
use PgSql\Connection;
use PgSql\Result;
use Revolt\EventLoop;
use RuntimeException;

/** Component probe for ext-pgsql COPY driven by socket readiness. */
final class NonBlockingPgCopy
{
    /** @var resource */
    private $socket;

    private int $readableWaits = 0;

    private int $writableWaits = 0;

    private int $copyBackpressure = 0;

    public function __construct(private readonly Connection $connection)
    {
        $socket = \pg_socket($this->connection);
        if (!\is_resource($socket)) {
            throw new RuntimeException('Unable to access the PostgreSQL connection socket.');
        }
        $this->socket = $socket;
        $this->setNonBlocking();
    }

    /**
     * @param iterable<array-key, string> $chunks
     * @return array{readable_waits: int, writable_waits: int, copy_backpressure: int}
     */
    public function copy(string $copySql, iterable $chunks): array
    {
        $sent = \pg_send_query($this->connection, $copySql);
        if ($sent === false) {
            throw new RuntimeException('Unable to initiate non-blocking PostgreSQL COPY.');
        }
        if ($sent === 0) {
            $this->flush();
        }

        $copyIn = $this->awaitResult();
        if (\pg_result_status($copyIn) !== PGSQL_COPY_IN) {
            throw new RuntimeException('PostgreSQL did not enter COPY IN mode.');
        }

        foreach ($chunks as $chunk) {
            $this->putData($chunk);
        }
        $this->putEnd();
        $this->flush();

        $completed = $this->awaitResult();
        if (\pg_result_status($completed) !== PGSQL_COMMAND_OK) {
            $message = \pg_result_error($completed);
            throw new RuntimeException(\is_string($message) && $message !== '' ? $message : 'PostgreSQL COPY failed.');
        }

        $this->setBlocking();

        return [
            'readable_waits' => $this->readableWaits,
            'writable_waits' => $this->writableWaits,
            'copy_backpressure' => $this->copyBackpressure,
        ];
    }

    private function putData(string $chunk): void
    {
        do {
            $result = \pg_put_copy_data($this->connection, $chunk);
            if ($result === -1) {
                throw new RuntimeException('Unable to queue PostgreSQL COPY data.');
            }
            if ($result === 0) {
                ++$this->copyBackpressure;
                $this->awaitWritable();
                $this->flush();
            }
        } while ($result === 0);

        $this->flush();
    }

    private function putEnd(): void
    {
        do {
            $result = \pg_put_copy_end($this->connection);
            if ($result === -1) {
                throw new RuntimeException('Unable to finish PostgreSQL COPY data.');
            }
            if ($result === 0) {
                ++$this->copyBackpressure;
                $this->awaitWritable();
                $this->flush();
            }
        } while ($result === 0);
    }

    private function flush(): void
    {
        do {
            $result = \pg_flush($this->connection);
            if ($result === false) {
                throw new RuntimeException('Unable to flush PostgreSQL output.');
            }
            if ($result === 0) {
                $this->awaitWritable();
            }
        } while ($result === 0);
    }

    private function awaitResult(): Result
    {
        while (true) {
            $busy = \pg_connection_busy($this->connection);
            $this->setNonBlocking();
            if (!$busy) {
                $result = \pg_get_result($this->connection);
                if ($result instanceof Result) {
                    return $result;
                }

                throw new RuntimeException('PostgreSQL returned no result for an active COPY operation.');
            }

            $this->awaitReadable();
            if (!\pg_consume_input($this->connection)) {
                throw new RuntimeException('Unable to consume PostgreSQL input.');
            }
        }
    }

    private function awaitReadable(): void
    {
        ++$this->readableWaits;
        $this->awaitSocket(readable: true);
    }

    private function awaitWritable(): void
    {
        ++$this->writableWaits;
        $this->awaitSocket(readable: false);
    }

    private function awaitSocket(bool $readable): void
    {
        $deferred = new DeferredFuture();
        $watcher = $readable
            ? EventLoop::onReadable($this->socket, static function (string $watcher) use ($deferred): void {
                EventLoop::cancel($watcher);
                $deferred->complete();
            })
            : EventLoop::onWritable($this->socket, static function (string $watcher) use ($deferred): void {
                EventLoop::cancel($watcher);
                $deferred->complete();
            });

        try {
            $deferred->getFuture()->await();
        } finally {
            EventLoop::cancel($watcher);
        }
    }

    private function setNonBlocking(): void
    {
        if (!\stream_set_blocking($this->socket, false)) {
            throw new RuntimeException('Unable to switch the PostgreSQL stream to non-blocking mode.');
        }
    }

    private function setBlocking(): void
    {
        if (!\stream_set_blocking($this->socket, true)) {
            throw new RuntimeException('Unable to restore blocking mode on the PostgreSQL stream.');
        }
    }
}

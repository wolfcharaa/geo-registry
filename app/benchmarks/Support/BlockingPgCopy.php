<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use PgSql\Connection;
use PgSql\Result;
use RuntimeException;

/** Blocking control for the same chunked ext-pgsql COPY protocol. */
final readonly class BlockingPgCopy
{
    public function __construct(private Connection $connection) {}

    /**
     * @param iterable<array-key, string> $chunks
     * @return array{readable_waits: 0, writable_waits: 0, copy_backpressure: 0}
     */
    public function copy(string $copySql, iterable $chunks): array
    {
        $copyIn = \pg_query($this->connection, $copySql);
        if (!$copyIn instanceof Result || \pg_result_status($copyIn) !== PGSQL_COPY_IN) {
            throw new RuntimeException('PostgreSQL did not enter blocking COPY IN mode.');
        }

        foreach ($chunks as $chunk) {
            if (\pg_put_copy_data($this->connection, $chunk) !== 1) {
                throw new RuntimeException('Unable to send blocking PostgreSQL COPY data.');
            }
        }
        if (\pg_put_copy_end($this->connection) !== 1) {
            throw new RuntimeException('Unable to finish blocking PostgreSQL COPY data.');
        }

        $completed = \pg_get_result($this->connection);
        if (!$completed instanceof Result || \pg_result_status($completed) !== PGSQL_COMMAND_OK) {
            throw new RuntimeException('Blocking PostgreSQL COPY failed.');
        }

        return [
            'readable_waits' => 0,
            'writable_waits' => 0,
            'copy_backpressure' => 0,
        ];
    }
}

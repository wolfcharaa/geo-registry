<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use function Amp\async;

use Amp\Cancellation;
use Amp\Postgres\PostgresConnectionPool;
use App\Application\Health\HealthCheckInterface;
use RuntimeException;

final readonly class PostgresHealthCheck implements HealthCheckInterface
{
    public function __construct(private PostgresConnectionPool $postgres) {}

    public function check(Cancellation $cancellation): void
    {
        $query = async($this->query(...));
        $query->ignore();
        $query->await($cancellation);
    }

    private function query(): void
    {
        foreach ($this->postgres->query('SELECT 1 AS ready') as $_row) {
            return;
        }

        throw new RuntimeException('PostgreSQL readiness query returned no rows.');
    }
}

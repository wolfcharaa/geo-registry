<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenSearch;

use Amp\Cancellation;
use App\Application\Health\HealthCheckInterface;

final readonly class OpenSearchHealthCheck implements HealthCheckInterface
{
    public function __construct(private OpenSearchClient $client) {}

    public function check(Cancellation $cancellation): void
    {
        $this->client->health($cancellation);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Nominatim;

use Amp\Cancellation;
use App\Application\Health\HealthCheckInterface;

final readonly class NominatimHealthCheck implements HealthCheckInterface
{
    public function __construct(private NominatimClient $client) {}

    public function check(Cancellation $cancellation): void
    {
        $this->client->status($cancellation);
    }
}

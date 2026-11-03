<?php

declare(strict_types=1);

namespace App\Application\Health;

use Amp\Cancellation;

interface HealthCheckInterface
{
    public function check(Cancellation $cancellation): void;
}

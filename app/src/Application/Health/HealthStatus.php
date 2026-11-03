<?php

declare(strict_types=1);

namespace App\Application\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
}

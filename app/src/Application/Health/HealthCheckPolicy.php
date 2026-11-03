<?php

declare(strict_types=1);

namespace App\Application\Health;

enum HealthCheckPolicy: string
{
    case Required = 'required';
    case Optional = 'optional';
}

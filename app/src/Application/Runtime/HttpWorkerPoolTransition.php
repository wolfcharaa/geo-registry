<?php

declare(strict_types=1);

namespace App\Application\Runtime;

enum HttpWorkerPoolTransition: string
{
    case Ready = 'ready';
    case Degraded = 'degraded';
    case Restored = 'restored';
}

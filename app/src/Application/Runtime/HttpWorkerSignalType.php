<?php

declare(strict_types=1);

namespace App\Application\Runtime;

enum HttpWorkerSignalType: string
{
    case Online = 'online';
    case Heartbeat = 'heartbeat';
    case Offline = 'offline';
}

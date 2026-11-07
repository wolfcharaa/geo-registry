<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

enum HttpWorkerRecycleReason: string
{
    case MaxRequests = 'max_requests';
    case MaxResidentMemory = 'max_resident_memory';
}

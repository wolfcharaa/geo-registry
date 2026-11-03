<?php

declare(strict_types=1);

namespace App\Config;

enum LogFormat: string
{
    case Json = 'json';
    case Console = 'console';
}

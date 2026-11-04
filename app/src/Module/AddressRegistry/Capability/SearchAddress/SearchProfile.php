<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability\SearchAddress;

enum SearchProfile: string
{
    case Search = 'search';
    case RemediationDebug = 'remediation-debug';
}

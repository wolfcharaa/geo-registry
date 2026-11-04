<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

enum AddressHierarchy: string
{
    case Administrative = 'adm';
    case Municipal = 'mun';
}

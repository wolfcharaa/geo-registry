<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Capability;

enum SuggestionStage: string
{
    case AdministrativeArea = 'administrative_area';
    case Thoroughfare = 'thoroughfare';
    case Search = 'search';
}

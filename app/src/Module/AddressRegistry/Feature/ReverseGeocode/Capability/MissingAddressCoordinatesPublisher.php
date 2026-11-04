<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\Capability;

use App\Module\AddressRegistry\Capability\AddressDocument;
use Fedor\MessageBus\Context\MessageContextInterface;

interface MissingAddressCoordinatesPublisher
{
    public function publishIfMissing(
        AddressDocument $document,
        ReverseGeocodeLocation $location,
        MessageContextInterface $context,
    ): void;
}

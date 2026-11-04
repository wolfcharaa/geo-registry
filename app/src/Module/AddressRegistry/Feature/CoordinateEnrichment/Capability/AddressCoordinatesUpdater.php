<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability;

use Amp\Cancellation;

interface AddressCoordinatesUpdater
{
    public function update(
        AddressCoordinatesResolved $event,
        Cancellation $cancellation,
    ): AddressCoordinatesUpdateResult;
}

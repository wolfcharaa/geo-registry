<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\CoordinateEnrichment\Infrastructure;

use Amp\Cancellation;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesProjectionUpdater;
use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Capability\AddressCoordinatesResolved;

final class NullAddressCoordinatesProjectionUpdater implements AddressCoordinatesProjectionUpdater
{
    public function update(AddressCoordinatesResolved $event, Cancellation $cancellation): int
    {
        $cancellation->throwIfRequested();

        return 0;
    }
}

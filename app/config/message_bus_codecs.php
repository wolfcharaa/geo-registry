<?php

declare(strict_types=1);

use App\Module\AddressRegistry\Feature\CoordinateEnrichment\Infrastructure\AddressCoordinatesResolvedCodec;
use Fedor\MessageBus\Codec\MessageCodecRegistry;

return new MessageCodecRegistry([
    new AddressCoordinatesResolvedCodec(),
]);

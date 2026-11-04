<?php

declare(strict_types=1);

use App\Application\MessageBus\MessageBusRegistrySource;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeHandler;

/** @var MessageBusRegistrySource $core */
$core = require __DIR__ . '/message_bus_registry.php';
$services = $core->services;
$services[ReverseGeocodeHandler::class] = ReverseGeocodeHandler::class;

return new MessageBusRegistrySource(
    classes: [...$core->classes, ReverseGeocodeHandler::class],
    services: $services,
    flows: $core->flows,
);

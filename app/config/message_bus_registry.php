<?php

declare(strict_types=1);

use App\Application\MessageBus\MessageBusRegistrySource;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;

return new MessageBusRegistrySource(
    classes: [FindAddressByIdHandler::class],
    services: [FindAddressByIdHandler::class => FindAddressByIdHandler::class],
    flows: MessageBusRegistrySource::empty()->flows,
);

<?php

declare(strict_types=1);

use App\Application\MessageBus\MessageBusRegistrySource;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;

return new MessageBusRegistrySource(
    classes: [FindAddressByIdHandler::class, SearchAddressHandler::class],
    services: [
        FindAddressByIdHandler::class => FindAddressByIdHandler::class,
        SearchAddressHandler::class => SearchAddressHandler::class,
    ],
    flows: MessageBusRegistrySource::empty()->flows,
);

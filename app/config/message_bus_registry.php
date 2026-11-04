<?php

declare(strict_types=1);

use App\Application\MessageBus\MessageBusRegistrySource;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;
use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContextHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddressHandler;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeAreaHandler;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfareHandler;

return new MessageBusRegistrySource(
    classes: [
        FindAddressByIdHandler::class,
        ResolveSuggestionContextHandler::class,
        SearchAddressHandler::class,
        SuggestAddressHandler::class,
        SuggestAdministrativeAreaHandler::class,
        SuggestThoroughfareHandler::class,
    ],
    services: [
        FindAddressByIdHandler::class => FindAddressByIdHandler::class,
        ResolveSuggestionContextHandler::class => ResolveSuggestionContextHandler::class,
        SearchAddressHandler::class => SearchAddressHandler::class,
        SuggestAddressHandler::class => SuggestAddressHandler::class,
        SuggestAdministrativeAreaHandler::class => SuggestAdministrativeAreaHandler::class,
        SuggestThoroughfareHandler::class => SuggestThoroughfareHandler::class,
    ],
    flows: MessageBusRegistrySource::empty()->flows,
);

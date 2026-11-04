<?php

declare(strict_types=1);

use App\Application\MessageBus\CorrelationContextInterceptor;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Application\MessageBus\MessageBusTelemetryInterceptor;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;
use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContextHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddressHandler;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeAreaHandler;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfareHandler;
use Fedor\MessageBus\Flow\FlowDefinition;
use Fedor\MessageBus\Flow\FlowRegistry;

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
    flows: new FlowRegistry(
        FlowDefinition::sync('default')->middleware(
            CorrelationContextInterceptor::class,
            MessageBusTelemetryInterceptor::class,
        ),
        FlowDefinition::async('address_async')
            ->transport('postgres', 'address')
            ->middleware(
                CorrelationContextInterceptor::class,
                MessageBusTelemetryInterceptor::class,
            ),
        FlowDefinition::async('runtime_async')
            ->transport('postgres', 'runtime')
            ->middleware(
                CorrelationContextInterceptor::class,
                MessageBusTelemetryInterceptor::class,
            ),
    ),
);

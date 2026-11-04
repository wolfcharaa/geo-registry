<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;
use App\Module\AddressRegistry\Capability\ResolveSuggestionContext\ResolveSuggestionContextHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddressHandler;
use App\Module\AddressRegistry\Capability\SuggestAdministrativeArea\SuggestAdministrativeAreaHandler;
use App\Module\AddressRegistry\Capability\SuggestThoroughfare\SuggestThoroughfareHandler;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressSearchQueryFactory;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressSearchResultMapper;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchSearchAddressDecorator;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeHandler;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressSuggestionReader;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresFindAddressByIdReader;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresSearchAddressReader;
use Thesis\Dic;
use Thesis\Dic\Ref;

final class AddressRegistryMessageBusServices
{
    /**
     * @param Ref<PostgresConnectionPool> $postgres
     * @param Ref<object>|null $reverseGeocodeLocator
     * @param Ref<object>|null $openSearch
     * @param Ref<object>|null $logger
     * @return array<string, Ref<object>>
     */
    public static function configure(
        Dic $dic,
        MessageBusRegistrySource $source,
        Ref $postgres,
        ?Ref $reverseGeocodeLocator = null,
        ?Ref $openSearch = null,
        ?string $openSearchIndex = null,
        ?Ref $logger = null,
    ): array {
        $findById = ($source->services[FindAddressByIdHandler::class] ?? null) === FindAddressByIdHandler::class;
        $search = ($source->services[SearchAddressHandler::class] ?? null) === SearchAddressHandler::class;
        $resolveSuggestionContext = ($source->services[ResolveSuggestionContextHandler::class] ?? null)
            === ResolveSuggestionContextHandler::class;
        $suggestAddress = ($source->services[SuggestAddressHandler::class] ?? null) === SuggestAddressHandler::class;
        $suggestAdministrativeArea = ($source->services[SuggestAdministrativeAreaHandler::class] ?? null)
            === SuggestAdministrativeAreaHandler::class;
        $suggestThoroughfare = ($source->services[SuggestThoroughfareHandler::class] ?? null)
            === SuggestThoroughfareHandler::class;
        $reverseGeocode = ($source->services[ReverseGeocodeHandler::class] ?? null)
            === ReverseGeocodeHandler::class;
        if (
            !$findById
            && !$search
            && !$resolveSuggestionContext
            && !$suggestAddress
            && !$suggestAdministrativeArea
            && !$suggestThoroughfare
            && !$reverseGeocode
        ) {
            return [];
        }

        $hydrator = $dic->object(PostgresAddressDocumentHydrator::class)->canBeScoped();
        $services = [];
        if ($findById) {
            $reader = $dic
                ->object(PostgresFindAddressByIdReader::class)
                ->args(['postgres' => $postgres, 'hydrator' => $hydrator])
                ->canBeScoped();
            $services[FindAddressByIdHandler::class] = $dic
                ->object(FindAddressByIdHandler::class)
                ->arg('reader', $reader)
                ->canBeScoped();
        }
        if ($search) {
            $reader = $dic
                ->object(PostgresSearchAddressReader::class)
                ->args(['postgres' => $postgres, 'hydrator' => $hydrator])
                ->canBeScoped();
            if ($openSearch !== null) {
                if ($openSearchIndex === null || $logger === null) {
                    throw new \LogicException('OpenSearch search decorator requires an index and logger.');
                }
                $reader = $dic
                    ->object(OpenSearchSearchAddressDecorator::class)
                    ->args([
                        'client' => $openSearch,
                        'index' => $openSearchIndex,
                        'fallback' => $reader,
                        'queries' => $dic->object(OpenSearchAddressSearchQueryFactory::class),
                        'results' => $dic->object(OpenSearchAddressSearchResultMapper::class),
                        'logger' => $logger,
                    ])
                    ->canBeScoped();
            }
            $services[SearchAddressHandler::class] = $dic
                ->object(SearchAddressHandler::class)
                ->arg('reader', $reader)
                ->canBeScoped();
        }
        if ($resolveSuggestionContext || $suggestAdministrativeArea || $suggestThoroughfare) {
            $suggestionReader = $dic
                ->object(PostgresAddressSuggestionReader::class)
                ->args(['postgres' => $postgres, 'hydrator' => $hydrator])
                ->canBeScoped();
            if ($resolveSuggestionContext) {
                $services[ResolveSuggestionContextHandler::class] = $dic
                    ->object(ResolveSuggestionContextHandler::class)
                    ->arg('reader', $suggestionReader)
                    ->canBeScoped();
            }
            if ($suggestAdministrativeArea) {
                $services[SuggestAdministrativeAreaHandler::class] = $dic
                    ->object(SuggestAdministrativeAreaHandler::class)
                    ->arg('reader', $suggestionReader)
                    ->canBeScoped();
            }
            if ($suggestThoroughfare) {
                $services[SuggestThoroughfareHandler::class] = $dic
                    ->object(SuggestThoroughfareHandler::class)
                    ->arg('reader', $suggestionReader)
                    ->canBeScoped();
            }
        }
        if ($suggestAddress) {
            $services[SuggestAddressHandler::class] = $dic
                ->object(SuggestAddressHandler::class)
                ->canBeScoped();
        }
        if ($reverseGeocode) {
            if ($reverseGeocodeLocator === null) {
                throw new \LogicException('ReverseGeocode handler requires a composed locator.');
            }
            $services[ReverseGeocodeHandler::class] = $dic
                ->object(ReverseGeocodeHandler::class)
                ->arg('locator', $reverseGeocodeLocator)
                ->canBeScoped();
        }

        return $services;
    }

    private function __construct() {}
}

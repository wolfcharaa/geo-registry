<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressHandler;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresFindAddressByIdReader;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresSearchAddressReader;
use Thesis\Dic;
use Thesis\Dic\Ref;

final class AddressRegistryMessageBusServices
{
    /**
     * @param Ref<PostgresConnectionPool> $postgres
     * @return array<string, Ref<object>>
     */
    public static function configure(Dic $dic, MessageBusRegistrySource $source, Ref $postgres): array
    {
        $findById = ($source->services[FindAddressByIdHandler::class] ?? null) === FindAddressByIdHandler::class;
        $search = ($source->services[SearchAddressHandler::class] ?? null) === SearchAddressHandler::class;
        if (!$findById && !$search) {
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
            $services[SearchAddressHandler::class] = $dic
                ->object(SearchAddressHandler::class)
                ->arg('reader', $reader)
                ->canBeScoped();
        }

        return $services;
    }

    private function __construct() {}
}

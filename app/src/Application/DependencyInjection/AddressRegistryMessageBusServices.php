<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Postgres\PostgresConnectionPool;
use App\Application\MessageBus\MessageBusRegistrySource;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdHandler;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresFindAddressByIdReader;
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
        if (($source->services[FindAddressByIdHandler::class] ?? null) !== FindAddressByIdHandler::class) {
            return [];
        }

        $reader = $dic
            ->object(PostgresFindAddressByIdReader::class)
            ->arg('postgres', $postgres)
            ->canBeScoped();
        $handler = $dic
            ->object(FindAddressByIdHandler::class)
            ->arg('reader', $reader)
            ->canBeScoped();

        return [FindAddressByIdHandler::class => $handler];
    }

    private function __construct() {}
}

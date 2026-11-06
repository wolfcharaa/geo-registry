<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Config\AddressSourceConfig;
use App\Config\PostgresConfig;
use App\Infrastructure\Postgres\PdoConnectionFactory;
use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\BsonDocumentStream;
use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\DataMosAddressRegistryEnricher;
use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\DataMosAddressRegistryImporter;
use App\Module\AddressRegistry\Feature\DataMos\UserInterface\Cli\EnrichAddressSearchFromDataMosCommand;
use App\Module\AddressRegistry\Feature\DataMos\UserInterface\Cli\ImportDataMosAddressRegistryCommand;
use App\Module\AddressRegistry\Feature\Osm\Infrastructure\OsmAddressAliasImporter;
use App\Module\AddressRegistry\Feature\Osm\UserInterface\Cli\ImportOsmAddressAliasesCommand;
use PDO;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\AutoconfigureCommands;

/** @implements Module<list<Ref<Command>>> */
final readonly class AddressSourceCliModule implements Module
{
    public function __construct(
        private PostgresConfig $postgresConfig,
        private AddressSourceConfig $addressSourceConfig,
    ) {}

    /** @return list<Ref<Command>> */
    public function configure(Dic $dic): array
    {
        $dic->apply(new AutoconfigureCommands());

        $connectionFactory = $dic->object(PdoConnectionFactory::class);
        $connection = $dic
            ->object(PDO::class, [$connectionFactory, 'create'])
            ->arg('databaseDsn', $dic->value($this->postgresConfig->dsn));
        $enricher = $dic
            ->object(DataMosAddressRegistryEnricher::class)
            ->args([
                'db' => $connection,
                'regionCode' => $dic->value($this->addressSourceConfig->moscowRegionCode),
            ]);
        $importer = $dic
            ->object(DataMosAddressRegistryImporter::class)
            ->args([
                'db' => $connection,
                'bsonDocuments' => $dic->object(BsonDocumentStream::class),
            ]);
        $osmImporter = $dic
            ->object(OsmAddressAliasImporter::class)
            ->args([
                'db' => $connection,
                'regionCode' => $dic->value($this->addressSourceConfig->moscowRegionCode),
            ]);

        return [
            $dic
                ->object(ImportDataMosAddressRegistryCommand::class)
                ->args([
                    'importer' => $importer,
                    'enricher' => $enricher,
                    'defaultSource' => $dic->value($this->addressSourceConfig->dataMosAddressRegistrySource),
                ]),
            $dic
                ->object(EnrichAddressSearchFromDataMosCommand::class)
                ->arg('enricher', $enricher),
            $dic
                ->object(ImportOsmAddressAliasesCommand::class)
                ->args([
                    'importer' => $osmImporter,
                    'defaultSource' => $dic->value($this->addressSourceConfig->osmAddressAliasesSource),
                ]),
        ];
    }
}

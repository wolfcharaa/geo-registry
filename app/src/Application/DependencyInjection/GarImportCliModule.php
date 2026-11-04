<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Config\PostgresConfig;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarRunner;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarCopyRowEncoder;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlImportAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRowReader;
use App\Module\AddressRegistry\UserInterface\Cli\ImportGarCommand;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;

/** @implements Module<Ref<Command>> */
final readonly class GarImportCliModule implements Module
{
    public function __construct(
        private PostgresConfig $postgresConfig,
    ) {}

    /** @return Ref<Command> */
    public function configure(Dic $dic): Ref
    {
        $catalog = $dic->object(GarXmlCatalog::class);
        $reader = $dic->object(GarXmlRowReader::class);
        $encoder = $dic->object(GarCopyRowEncoder::class);
        $adapter = $dic
            ->object(GarXmlImportAdapter::class)
            ->args([
                'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                'catalog' => $catalog,
                'reader' => $reader,
                'encoder' => $encoder,
            ]);
        $runner = $dic
            ->object(ImportGarRunner::class)
            ->arg('importer', $adapter);

        return $dic
            ->object(ImportGarCommand::class)
            ->arg('runner', $runner);
    }
}

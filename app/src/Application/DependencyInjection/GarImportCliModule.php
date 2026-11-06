<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Config\PostgresConfig;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarRunner;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarImportWorkerRunner;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarCopyRowEncoder;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportConnectionFactory;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportWorkerPlan;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportWorkerPool;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlImportAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlImportWorkerAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRowReader;
use App\Module\AddressRegistry\UserInterface\Cli\ImportGarCommand;
use App\Module\AddressRegistry\UserInterface\Cli\RunGarImportWorkerCommand;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\LegacyCommandTag;

/** @implements Module<list<Ref<Command>>> */
final readonly class GarImportCliModule implements Module
{
    public function __construct(
        private PostgresConfig $postgresConfig,
    ) {}

    /** @return list<Ref<Command>> */
    public function configure(Dic $dic): array
    {
        $catalog = $dic->object(GarXmlCatalog::class);
        $reader = $dic->object(GarXmlRowReader::class);
        $encoder = $dic->object(GarCopyRowEncoder::class);
        $connectionFactory = $dic
            ->object(GarImportConnectionFactory::class)
            ->arg('databaseDsn', $dic->value($this->postgresConfig->dsn));
        $workerAdapter = $dic
            ->object(GarXmlImportWorkerAdapter::class)
            ->args([
                'connectionFactory' => $connectionFactory,
                'catalog' => $catalog,
                'reader' => $reader,
                'encoder' => $encoder,
            ]);
        $workerRunner = $dic
            ->object(RunGarImportWorkerRunner::class)
            ->arg('worker', $workerAdapter);
        $workerPool = $dic
            ->object(GarImportWorkerPool::class)
            ->args([
                'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                'consolePath' => $dic->value(dirname(__DIR__, 3) . '/bin/app'),
                'plan' => $dic->object(GarImportWorkerPlan::class),
            ]);
        $adapter = $dic
            ->object(GarXmlImportAdapter::class)
            ->args([
                'connectionFactory' => $connectionFactory,
                'catalog' => $catalog,
                'workerPool' => $workerPool,
            ]);
        $runner = $dic
            ->object(ImportGarRunner::class)
            ->arg('importer', $adapter);

        return [
            $dic
                ->object(ImportGarCommand::class)
                ->arg('runner', $runner)
                ->tag(new LegacyCommandTag(
                    'gar:import',
                    'Import supported GAR XML files through bounded PostgreSQL COPY batches.',
                )),
            $dic
                ->object(RunGarImportWorkerCommand::class)
                ->arg('runner', $workerRunner)
                ->tag(new LegacyCommandTag(
                    'gar:import:worker',
                    'Run one internal GAR XML import worker assignment.',
                    isHidden: true,
                )),
        ];
    }
}

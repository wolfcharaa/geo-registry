<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Config\PostgresConfig;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarAsyncFileImporter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarCopyRowEncoder;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarForeignKeyValidationPool;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarForeignKeyValidationWorkerAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarFusedRangeFileImporter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportConnectionFactory;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportPipelinePlan;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportPipelineSelector;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportWorkerPlan;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportWorkerPool;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportWorkerProcessPool;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarIndexBuildWorkerAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarIndexSchedulerAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarInitialLoadFinalizer;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarInitialLoadIndexPool;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarInitialLoadOverlapPool;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarParallelFileImporter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarParallelRangeFileImporter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarRawImportCoordinator;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarWorkOrchestratedRawImportAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlByteRangeReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlFileImporter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlImportAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlImportPlanner;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlImportWorkerAdapter;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlRowReader;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlStructureInspector;
use App\Module\AddressRegistry\UserInterface\Cli\ImportGarCommand;
use App\Module\AddressRegistry\UserInterface\Cli\ImportGarRawCommand;
use App\Module\AddressRegistry\UserInterface\Cli\PlanGarImportCommand;
use App\Module\AddressRegistry\UserInterface\Cli\RunGarForeignKeyValidationWorkerCommand;
use App\Module\AddressRegistry\UserInterface\Cli\RunGarImportWorkerCommand;
use App\Module\AddressRegistry\UserInterface\Cli\RunGarIndexBuildWorkerCommand;
use App\Module\AddressRegistry\UserInterface\Cli\RunGarIndexSchedulerCommand;
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
        $fileImporter = $dic
            ->object(GarXmlFileImporter::class)
            ->args([
                'reader' => $reader,
                'encoder' => $encoder,
            ]);
        $pipelineSelector = $dic->object(GarImportPipelineSelector::class);
        $workerAdapter = $dic
            ->object(GarXmlImportWorkerAdapter::class)
            ->args([
                'connectionFactory' => $connectionFactory,
                'catalog' => $catalog,
                'fileImporter' => $fileImporter,
            ]);
        $workerProcessPool = $dic
            ->object(GarImportWorkerProcessPool::class)
            ->args([
                'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                'consolePath' => $dic->value(\dirname(__DIR__, 3) . '/bin/app'),
            ]);
        $workerPool = $dic
            ->object(GarImportWorkerPool::class)
            ->args([
                'plan' => $dic->object(GarImportWorkerPlan::class),
                'pipelinePlan' => $dic
                    ->object(GarImportPipelinePlan::class)
                    ->arg('workerPlan', $dic->object(GarImportWorkerPlan::class)),
                'fileImporter' => $dic
                    ->object(GarAsyncFileImporter::class)
                    ->arg('databaseDsn', $dic->value($this->postgresConfig->dsn)),
                'processPool' => $workerProcessPool,
                'parallelFileImporter' => $dic
                    ->object(GarParallelFileImporter::class)
                    ->args([
                        'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                        'connectionFactory' => $connectionFactory,
                        'reader' => $reader,
                        'encoder' => $encoder,
                    ]),
                'parallelRangeFileImporter' => $dic
                    ->object(GarParallelRangeFileImporter::class)
                    ->args([
                        'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                        'connectionFactory' => $connectionFactory,
                        'ranges' => $dic
                            ->object(GarXmlByteRangeReader::class)
                            ->arg('inspector', $dic->object(GarXmlStructureInspector::class)),
                    ]),
                'fusedRangeFileImporter' => $dic
                    ->object(GarFusedRangeFileImporter::class)
                    ->args([
                        'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                        'connectionFactory' => $connectionFactory,
                        'ranges' => $dic
                            ->object(GarXmlByteRangeReader::class)
                            ->arg('inspector', $dic->object(GarXmlStructureInspector::class)),
                    ]),
            ]);
        $indexWorker = $dic
            ->object(GarIndexBuildWorkerAdapter::class)
            ->arg('connectionFactory', $connectionFactory);
        $indexPool = $dic
            ->object(GarInitialLoadIndexPool::class)
            ->args([
                'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                'consolePath' => $dic->value(\dirname(__DIR__, 3) . '/bin/app'),
                'connectionFactory' => $connectionFactory,
            ]);
        $indexScheduler = $dic
            ->object(GarIndexSchedulerAdapter::class)
            ->arg('indexPool', $indexPool);
        $foreignKeyWorker = $dic
            ->object(GarForeignKeyValidationWorkerAdapter::class)
            ->arg('connectionFactory', $connectionFactory);
        $foreignKeyPool = $dic
            ->object(GarForeignKeyValidationPool::class)
            ->args([
                'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                'consolePath' => $dic->value(\dirname(__DIR__, 3) . '/bin/app'),
            ]);
        $adapter = $dic
            ->object(GarXmlImportAdapter::class)
            ->args([
                'connectionFactory' => $connectionFactory,
                'catalog' => $catalog,
                'rawImport' => $dic
                    ->object(GarRawImportCoordinator::class)
                    ->args([
                        'workerPool' => $workerPool,
                        'indexPool' => $indexPool,
                    ]),
                'initialLoadFinalizer' => $dic
                    ->object(GarInitialLoadFinalizer::class)
                    ->args([
                        'catalog' => $catalog,
                        'indexPool' => $indexPool,
                        'foreignKeyPool' => $foreignKeyPool,
                        'overlapPool' => $dic
                            ->object(GarInitialLoadOverlapPool::class)
                            ->args([
                                'indexPool' => $indexPool,
                                'foreignKeyPool' => $foreignKeyPool,
                            ]),
                    ]),
                'pipelineSelector' => $pipelineSelector,
            ]);
        $rawAdapter = $dic
            ->object(GarWorkOrchestratedRawImportAdapter::class)
            ->args([
                'databaseDsn' => $dic->value($this->postgresConfig->dsn),
                'connectionFactory' => $connectionFactory,
                'catalog' => $catalog,
                'pipelineSelector' => $pipelineSelector,
            ]);
        return [
            $dic
                ->object(ImportGarCommand::class)
                ->arg('importer', $adapter)
                ->tag(new LegacyCommandTag(
                    'gar:import',
                    'Import supported GAR XML files through bounded PostgreSQL COPY batches.',
                )),
            $dic
                ->object(ImportGarRawCommand::class)
                ->arg('importer', $rawAdapter)
                ->tag(new LegacyCommandTag(
                    'gar:import:raw',
                    'Run the experimental raw-only GAR work-orchestrator path.',
                )),
            $dic
                ->object(PlanGarImportCommand::class)
                ->arg(
                    'planner',
                    $dic->object(GarXmlImportPlanner::class)->args([
                        'catalog' => $catalog,
                        'pipelineSelector' => $pipelineSelector,
                    ]),
                )
                ->tag(new LegacyCommandTag(
                    'gar:import:plan',
                    'Inspect a GAR manifest and list eligible batch-pipeline XML files without importing.',
                )),
            $dic
                ->object(RunGarImportWorkerCommand::class)
                ->arg('worker', $workerAdapter)
                ->tag(new LegacyCommandTag(
                    'gar:import:worker',
                    'Run one internal GAR XML import worker assignment.',
                    isHidden: true,
                )),
            $dic
                ->object(RunGarIndexBuildWorkerCommand::class)
                ->arg('worker', $indexWorker)
                ->tag(new LegacyCommandTag(
                    'gar:import:index-worker',
                    'Restore one secondary GAR index for an initial-load operation.',
                    isHidden: true,
                )),
            $dic
                ->object(RunGarIndexSchedulerCommand::class)
                ->arg('scheduler', $indexScheduler)
                ->tag(new LegacyCommandTag(
                    'gar:import:index-scheduler',
                    'Restore indexes for GAR tables completed by a concurrent initial-load import.',
                    isHidden: true,
                )),
            $dic
                ->object(RunGarForeignKeyValidationWorkerCommand::class)
                ->arg('worker', $foreignKeyWorker)
                ->tag(new LegacyCommandTag(
                    'gar:import:foreign-key-worker',
                    'Validate one restored GAR foreign key for an initial-load operation.',
                    isHidden: true,
                )),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Config\PostgresConfig;
use App\Database\Migration\MigrationPostgresDriverFactory;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\AutoconfigureCommands;
use Yiisoft\Db\Migration\Command\CreateCommand;
use Yiisoft\Db\Migration\Command\DownCommand;
use Yiisoft\Db\Migration\Command\HistoryCommand;
use Yiisoft\Db\Migration\Command\MarkCommand;
use Yiisoft\Db\Migration\Command\NewCommand;
use Yiisoft\Db\Migration\Command\RedoCommand;
use Yiisoft\Db\Migration\Command\UpdateCommand;
use Yiisoft\Db\Migration\Informer\ConsoleMigrationInformer;
use Yiisoft\Db\Migration\Migrator;
use Yiisoft\Db\Migration\Runner\DownRunner;
use Yiisoft\Db\Migration\Runner\UpdateRunner;
use Yiisoft\Db\Migration\Service\Generate\CreateService;
use Yiisoft\Db\Migration\Service\MigrationService;
use Yiisoft\Db\Pgsql\Connection;
use Yiisoft\Db\Pgsql\Driver;
use Yiisoft\Injector\Injector;

/** @implements Module<list<Ref<Command>>> */
final readonly class MigrationCliModule implements Module
{
    private const MIGRATION_NAMESPACE = 'App\\Database\\Migration';

    public function __construct(
        private PostgresConfig $postgresConfig,
    ) {}

    /** @return list<Ref<Command>> */
    public function configure(Dic $dic): array
    {
        $dic->apply(new AutoconfigureCommands());

        $driverFactory = $dic->object(MigrationPostgresDriverFactory::class);
        $driver = $dic
            ->object(Driver::class, [$driverFactory, 'create'])
            ->arg('config', $dic->value($this->postgresConfig));
        $connection = $dic
            ->object(Connection::class)
            ->arg('driver', $driver);
        $informer = $dic->object(ConsoleMigrationInformer::class);
        $migrator = $dic
            ->object(Migrator::class)
            ->args([
                'db' => $connection,
                'informer' => $informer,
            ]);
        $injector = $dic->object(Injector::class, static fn(): Injector => new Injector());
        $migrationService = $dic
            ->object(MigrationService::class)
            ->args([
                'db' => $connection,
                'injector' => $injector,
                'migrator' => $migrator,
            ])
            ->call('setNewMigrationNamespace', [self::MIGRATION_NAMESPACE])
            ->call('setSourceNamespaces', [[self::MIGRATION_NAMESPACE]]);
        $updateRunner = $dic
            ->object(UpdateRunner::class)
            ->arg('migrator', $migrator);
        $downRunner = $dic
            ->object(DownRunner::class)
            ->arg('migrator', $migrator);
        $createService = $dic
            ->object(CreateService::class)
            ->arg('db', $connection);

        return [
            $dic->object(CreateCommand::class)->args([
                'createService' => $createService,
                'migrationService' => $migrationService,
                'migrator' => $migrator,
            ]),
            $dic->object(UpdateCommand::class)->args([
                'updateRunner' => $updateRunner,
                'migrationService' => $migrationService,
                'migrator' => $migrator,
            ]),
            $dic->object(DownCommand::class)->args([
                'downRunner' => $downRunner,
                'migrationService' => $migrationService,
                'migrator' => $migrator,
            ]),
            $dic->object(NewCommand::class)->args([
                'migrationService' => $migrationService,
                'migrator' => $migrator,
            ]),
            $dic->object(HistoryCommand::class)->args([
                'migrationService' => $migrationService,
                'migrator' => $migrator,
            ]),
            $dic->object(RedoCommand::class)->args([
                'migrationService' => $migrationService,
                'migrator' => $migrator,
                'downRunner' => $downRunner,
                'updateRunner' => $updateRunner,
            ]),
            $dic->object(MarkCommand::class)->args([
                'migrationService' => $migrationService,
                'migrator' => $migrator,
            ]),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Application\Console\Command\SafeWorkerControlActionCommand;
use App\Application\Console\Command\SafeWorkerControlCommand;
use Fedor\MessageBus\Worker\Cli\BootstrapResolver;
use Fedor\MessageBus\Worker\Cli\Command\WorkerPostgresSchemaCommand;
use Fedor\MessageBus\Worker\Cli\Command\WorkerRunCommand;
use Fedor\MessageBus\Worker\Cli\Command\WorkerStatusCommand;
use Fedor\MessageBus\Worker\Control\WorkerControlCommandType;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\LegacyCommandTag;

/** @implements Module<list<Ref<Command>>> */
final readonly class WorkerCliModule implements Module
{
    /** @return list<Ref<Command>> */
    public function configure(Dic $dic): array
    {
        $bootstrapResolver = $dic->object(
            BootstrapResolver::class,
            static fn(): BootstrapResolver => new BootstrapResolver([
                dirname(__DIR__, 3) . '/config/message_bus_worker.php',
            ]),
        );

        return [
            $dic
                ->object(WorkerRunCommand::class)
                ->arg('bootstrapResolver', $bootstrapResolver)
                ->tag(new LegacyCommandTag('worker:run', 'Run the AMPHP queue worker.')),
            $dic
                ->object(SafeWorkerControlCommand::class)
                ->arg('bootstrapResolver', $bootstrapResolver)
                ->tag(new LegacyCommandTag('worker:control', 'Send a worker control command.')),
            ...$this->controlCommands($dic, $bootstrapResolver),
            $dic
                ->object(WorkerStatusCommand::class)
                ->arg('bootstrapResolver', $bootstrapResolver)
                ->tag(new LegacyCommandTag('worker:status', 'Show registered worker status.')),
            $dic
                ->object(WorkerPostgresSchemaCommand::class)
                ->tag(new LegacyCommandTag('worker:schema:postgres', 'Generate PostgreSQL worker schema.')),
        ];
    }

    /**
     * @param Ref<BootstrapResolver> $bootstrapResolver
     * @return list<Ref<Command>>
     */
    private function controlCommands(Dic $dic, Ref $bootstrapResolver): array
    {
        $commands = [];
        foreach (WorkerControlCommandType::cases() as $type) {
            $name = 'worker:' . $type->value;
            $commands[] = $dic
                ->object(SafeWorkerControlActionCommand::class)
                ->args([
                    'type' => $dic->value($type),
                    'name' => $dic->value($name),
                    'bootstrapResolver' => $bootstrapResolver,
                ])
                ->tag(new LegacyCommandTag($name, 'Send worker ' . $type->value . ' command.'));
        }

        return $commands;
    }
}

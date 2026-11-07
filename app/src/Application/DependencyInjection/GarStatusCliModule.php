<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresGarStatusReader;
use App\Module\AddressRegistry\UserInterface\Cli\GarStatusCommand;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\AutoconfigureCommands;

/** @implements Module<Ref<Command>> */
final readonly class GarStatusCliModule implements Module
{
    /** @param Ref<PostgresConnectionPool> $postgres */
    public function __construct(private Ref $postgres) {}

    /** @return Ref<Command> */
    public function configure(Dic $dic): Ref
    {
        $dic->apply(new AutoconfigureCommands());

        $reader = $dic
            ->object(PostgresGarStatusReader::class)
            ->arg('postgres', $this->postgres);

        return $dic
            ->object(GarStatusCommand::class)
            ->arg('reader', $reader);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Postgres\PostgresConnectionPool;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresGarIntegrityChecker;
use App\Module\AddressRegistry\UserInterface\Cli\GarIntegrityCommand;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\AutoconfigureCommands;

/** @implements Module<Ref<Command>> */
final readonly class GarIntegrityCliModule implements Module
{
    /** @param Ref<PostgresConnectionPool> $postgres */
    public function __construct(private Ref $postgres) {}

    /** @return Ref<Command> */
    public function configure(Dic $dic): Ref
    {
        $dic->apply(new AutoconfigureCommands());

        $checker = $dic
            ->object(PostgresGarIntegrityChecker::class)
            ->arg('postgres', $this->postgres);

        return $dic
            ->object(GarIntegrityCommand::class)
            ->arg('checker', $checker);
    }
}

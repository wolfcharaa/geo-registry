<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use App\Config\PostgresConfig;
use App\Module\AddressRegistry\Capability\RebuildAddressSearch\RebuildAddressSearchRunner;
use App\Module\AddressRegistry\Infrastructure\AddressSearchRebuild\PostgresAddressSearchRebuildAdapter;
use App\Module\AddressRegistry\UserInterface\Cli\RebuildAddressSearchCommand;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\AutoconfigureCommands;

/** @implements Module<Ref<Command>> */
final readonly class AddressSearchRebuildCliModule implements Module
{
    public function __construct(
        private PostgresConfig $postgresConfig,
    ) {}

    /** @return Ref<Command> */
    public function configure(Dic $dic): Ref
    {
        $dic->apply(new AutoconfigureCommands());

        $adapter = $dic
            ->object(PostgresAddressSearchRebuildAdapter::class)
            ->arg('databaseDsn', $dic->value($this->postgresConfig->dsn));
        $runner = $dic
            ->object(RebuildAddressSearchRunner::class)
            ->arg('rebuilder', $adapter);

        return $dic
            ->object(RebuildAddressSearchCommand::class)
            ->arg('runner', $runner);
    }
}

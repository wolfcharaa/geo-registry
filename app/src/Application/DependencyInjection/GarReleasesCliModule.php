<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Http\Client\HttpClient;
use App\Config\ConsoleConfig;
use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Module\AddressRegistry\Infrastructure\Http\HttpGarReleaseMetadataReader;
use App\Module\AddressRegistry\UserInterface\Cli\GarReleasesCommand;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\AutoconfigureCommands;

/** @implements Module<Ref<Command>> */
final readonly class GarReleasesCliModule implements Module
{
    public function __construct(private ConsoleConfig $consoleConfig) {}

    /** @return Ref<Command> */
    public function configure(Dic $dic): Ref
    {
        $dic->apply(new AutoconfigureCommands());

        $httpClient = $dic->object(
            HttpClient::class,
            static fn(): HttpClient => AmpHttpClientFactory::create(60),
        );
        $reader = $dic
            ->object(HttpGarReleaseMetadataReader::class)
            ->args([
                'httpClient' => $httpClient,
                'userAgent' => $this->consoleConfig->userAgent,
            ]);

        return $dic
            ->object(GarReleasesCommand::class)
            ->arg('reader', $reader);
    }
}

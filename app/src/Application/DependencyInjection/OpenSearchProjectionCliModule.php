<?php

declare(strict_types=1);

namespace App\Application\DependencyInjection;

use Amp\Http\Client\HttpClient;
use Amp\Postgres\PostgresConnectionPool;
use App\Config\ConsoleConfig;
use App\Config\OpenSearchConfig;
use App\Infrastructure\Http\AmpHttpClientFactory;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Infrastructure\OpenSearch\OpenSearchClientFactory;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\CleanupProjection\CleanupOpenSearchIndexRunner;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\RebuildOpenSearchProjectionRunner;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Cleanup\OpenSearchProjectionCleaner;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild\OpenSearchProjectionRebuilder;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild\PostgresOpenSearchProjectionSource;
use App\Module\AddressRegistry\Feature\OpenSearch\UserInterface\Cli\CleanupOpenSearchIndexCommand;
use App\Module\AddressRegistry\Feature\OpenSearch\UserInterface\Cli\RebuildOpenSearchProjectionCommand;
use App\Module\AddressRegistry\Infrastructure\Postgres\PostgresAddressDocumentHydrator;
use Symfony\Component\Console\Command\Command;
use Thesis\Dic;
use Thesis\Dic\Module;
use Thesis\Dic\Ref;
use Thesis\SymfonyConsoleModule\AutoconfigureCommands;

/** @implements Module<list<Ref<Command>>> */
final readonly class OpenSearchProjectionCliModule implements Module
{
    /** @param Ref<PostgresConnectionPool> $postgres */
    public function __construct(
        private Ref $postgres,
        private ConsoleConfig $consoleConfig,
        private OpenSearchConfig $openSearchConfig,
    ) {}

    /** @return list<Ref<Command>> */
    public function configure(Dic $dic): array
    {
        $dic->apply(new AutoconfigureCommands());

        $httpClient = $dic->object(
            HttpClient::class,
            fn(): HttpClient => AmpHttpClientFactory::create($this->openSearchConfig->timeoutSeconds),
        );
        $clientFactory = $dic
            ->object(OpenSearchClientFactory::class)
            ->arg('httpClient', $httpClient);
        $client = $dic
            ->object(OpenSearchClient::class, [$clientFactory, 'create'])
            ->args([
                'baseUrl' => $this->openSearchConfig->url,
                'userAgent' => $this->consoleConfig->userAgent,
                'username' => $this->openSearchConfig->username,
                'password' => $this->openSearchConfig->password,
            ]);
        $source = $dic
            ->object(PostgresOpenSearchProjectionSource::class)
            ->args([
                'postgres' => $this->postgres,
                'hydrator' => $dic->object(PostgresAddressDocumentHydrator::class),
            ]);
        $rebuilder = $dic
            ->object(OpenSearchProjectionRebuilder::class)
            ->args([
                'source' => $source,
                'openSearch' => $client,
                'alias' => $this->openSearchConfig->index,
                'numberOfShards' => $this->openSearchConfig->numberOfShards,
                'numberOfReplicas' => $this->openSearchConfig->numberOfReplicas,
                'bulkNumberOfReplicas' => $this->openSearchConfig->bulkNumberOfReplicas,
                'refreshInterval' => $this->openSearchConfig->refreshInterval,
                'bulkRefreshInterval' => $this->openSearchConfig->bulkRefreshInterval,
            ]);
        $rebuildRunner = $dic
            ->object(RebuildOpenSearchProjectionRunner::class)
            ->arg('rebuilder', $rebuilder);
        $cleaner = $dic
            ->object(OpenSearchProjectionCleaner::class)
            ->args([
                'openSearch' => $client,
                'alias' => $this->openSearchConfig->index,
            ]);
        $cleanupRunner = $dic
            ->object(CleanupOpenSearchIndexRunner::class)
            ->arg('cleaner', $cleaner);

        return [
            $dic
                ->object(RebuildOpenSearchProjectionCommand::class)
                ->arg('runner', $rebuildRunner),
            $dic
                ->object(CleanupOpenSearchIndexCommand::class)
                ->arg('runner', $cleanupRunner),
        ];
    }
}

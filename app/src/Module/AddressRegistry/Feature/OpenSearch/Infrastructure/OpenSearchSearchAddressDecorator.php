<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure;

use Amp\Cancellation;
use Amp\CancelledException;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class OpenSearchSearchAddressDecorator implements SearchAddressPort
{
    public function __construct(
        private OpenSearchClient $client,
        private string $index,
        private SearchAddressPort $fallback,
        private OpenSearchAddressSearchQueryFactory $queries,
        private OpenSearchAddressSearchResultMapper $results,
        private LoggerInterface $logger,
    ) {}

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        try {
            $matches = $this->results->map(
                $this->client->search(
                    index: $this->index,
                    query: $this->queries->create($criteria),
                    size: $criteria->limit,
                    cancellation: $cancellation,
                    sort: $this->queries->sort(),
                ),
                $cancellation,
            );
        } catch (CancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->warning('OpenSearch address search fell back to PostgreSQL', [
                'event' => 'address_registry.search.opensearch.fallback',
                'exception_type' => $exception::class,
                'integration' => 'opensearch',
            ]);

            return $this->fallback->search($criteria, $cancellation);
        }

        return $matches === []
            ? $this->fallback->search($criteria, $cancellation)
            : $matches;
    }
}

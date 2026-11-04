<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\Rebuild;

use function Amp\async;

use Amp\DeferredCancellation;
use Amp\Future;

use function Amp\Future\awaitAll;

use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\OpenSearchProjectionFailed;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\RebuildOpenSearchProjection;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\RebuildOpenSearchProjectionPort;
use App\Module\AddressRegistry\Feature\OpenSearch\Capability\RebuildProjection\RebuildOpenSearchProjectionResult;
use App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure\OpenSearchAddressIndexDefinition;
use Throwable;

final readonly class OpenSearchProjectionRebuilder implements RebuildOpenSearchProjectionPort
{
    public function __construct(
        private PostgresOpenSearchProjectionSource $source,
        private OpenSearchClient $openSearch,
        private string $alias,
        private int $numberOfShards,
        private int $numberOfReplicas,
        private int $bulkNumberOfReplicas,
        private string $refreshInterval,
        private string $bulkRefreshInterval,
    ) {}

    public function rebuild(RebuildOpenSearchProjection $command): RebuildOpenSearchProjectionResult
    {
        $startedAt = hrtime(true);
        $targetIndex = OpenSearchVersionedIndexName::generate($this->alias);
        $snapshot = null;
        $pending = [];
        $cancellation = new DeferredCancellation();
        $targetCreated = false;
        $aliasSwitchStarted = false;

        try {
            if ($this->openSearch->concreteIndexExists($this->alias)) {
                throw new OpenSearchProjectionFailed(
                    "OpenSearch alias `{$this->alias}` conflicts with a concrete index; live data was not changed.",
                    $targetIndex,
                );
            }
            if ($this->openSearch->indexExists($targetIndex)) {
                throw new OpenSearchProjectionFailed(
                    "Generated OpenSearch target index `{$targetIndex}` already exists.",
                    $targetIndex,
                );
            }

            $previousIndexes = $this->openSearch->aliasTargets($this->alias);
            $this->openSearch->createIndex(
                $targetIndex,
                OpenSearchAddressIndexDefinition::create(
                    $this->numberOfShards,
                    $this->bulkNumberOfReplicas,
                    $this->bulkRefreshInterval,
                ),
            );
            $targetCreated = true;

            $snapshot = $this->source->openSnapshot();
            $manifest = $snapshot->manifest;
            if ($manifest->invalidRegionDocuments !== 0) {
                throw new OpenSearchProjectionFailed(sprintf(
                    'OpenSearch source contains %d document(s) without a valid region_code.',
                    $manifest->invalidRegionDocuments,
                ), $targetIndex);
            }

            $lastRegionCode = 0;
            $lastRowId = 0;
            $indexedDocuments = 0;
            $sequence = 0;

            while (($batch = $snapshot->nextBatch($lastRegionCode, $lastRowId, $command->batchSize)) !== null) {
                $lastRegionCode = $batch->lastRegionCode;
                $lastRowId = $batch->lastRowId;
                $payload = OpenSearchBulkPayload::fromDocuments($batch->documents);
                $pending[$sequence++] = async(fn(): int => $this->openSearch->bulkIndex(
                    $targetIndex,
                    $payload->ndjson,
                    $payload->documents,
                    $cancellation->getCancellation(),
                ));

                if (count($pending) >= $command->concurrency) {
                    $indexedDocuments += self::awaitOne($pending);
                }
            }
            while ($pending !== []) {
                $indexedDocuments += self::awaitOne($pending);
            }
            $snapshot->close();

            $this->openSearch->updateIndexSettings($targetIndex, [
                'refresh_interval' => $this->refreshInterval,
                'number_of_replicas' => $this->numberOfReplicas,
            ]);
            $this->openSearch->refreshIndex($targetIndex);
            $targetDocuments = $this->openSearch->countDocuments($targetIndex);
            if (
                $indexedDocuments !== $manifest->documents
                || $targetDocuments !== $manifest->documents
            ) {
                throw new OpenSearchProjectionFailed(sprintf(
                    'OpenSearch parity failed: source=%d indexed=%d target=%d.',
                    $manifest->documents,
                    $indexedDocuments,
                    $targetDocuments,
                ), $targetIndex);
            }

            if ($command->switchAlias) {
                $aliasSwitchStarted = true;
                $this->openSearch->switchAlias($this->alias, $targetIndex);
            }

            return new RebuildOpenSearchProjectionResult(
                targetIndex: $targetIndex,
                sourceDocuments: $manifest->documents,
                indexedDocuments: $indexedDocuments,
                targetDocuments: $targetDocuments,
                aliasSwitched: $command->switchAlias,
                previousIndexes: $previousIndexes,
                elapsedMilliseconds: max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000)),
            );
        } catch (Throwable $error) {
            $cancellation->cancel($error);
            awaitAll($pending);
            $snapshot?->abort();

            if (!$targetCreated && $error instanceof OpenSearchProjectionFailed) {
                throw $error;
            }

            $message = $aliasSwitchStarted
                ? "OpenSearch alias switch outcome is ambiguous; inspect alias `{$this->alias}` before retrying"
                : 'OpenSearch rebuild failed before alias switch; the active search was not changed';
            $targetState = $targetCreated
                ? "target `{$targetIndex}` was kept for inspection"
                : "target `{$targetIndex}` was not confirmed as created";

            throw new OpenSearchProjectionFailed(
                "{$message}; {$targetState}: {$error->getMessage()}",
                $targetIndex,
                $error,
            );
        }
    }

    /**
     * @param array<int, Future<int>> $pending
     */
    private static function awaitOne(array &$pending): int
    {
        foreach (Future::iterate($pending) as $key => $future) {
            unset($pending[$key]);

            return $future->await();
        }

        throw new OpenSearchProjectionFailed('OpenSearch in-flight queue became empty unexpectedly.');
    }
}

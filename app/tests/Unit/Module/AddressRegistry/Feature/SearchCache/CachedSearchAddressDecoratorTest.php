<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Feature\SearchCache;

use function Amp\async;

use Amp\Cache\Cache;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;

use Amp\NullCancellation;
use App\Module\AddressRegistry\Capability\AddressDocument;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressCriteria;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressPort;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use App\Module\AddressRegistry\Feature\SearchCache\Infrastructure\AddressSearchResultCache;
use App\Module\AddressRegistry\Feature\SearchCache\Infrastructure\CachedSearchAddressDecorator;
use RuntimeException;
use Testo\Assert;
use Testo\Test;

#[Test]
final class CachedSearchAddressDecoratorTest
{
    public function testItCachesTheCompleteCriteriaIdentity(): void
    {
        $reader = new RecordingSearchAddressReader();
        $cache = AddressSearchResultCache::local(10, 60, 5);
        $decorator = new CachedSearchAddressDecorator($reader, $cache, 'release-1');

        $first = $decorator->search($this->criteria(), new NullCancellation());
        $second = $decorator->search($this->criteria(), new NullCancellation());
        $regional = $decorator->search($this->criteria(regionCode: 50), new NullCancellation());
        $nextGeneration = (new CachedSearchAddressDecorator($reader, $cache, 'release-2'))
            ->search($this->criteria(), new NullCancellation());

        Assert::same($reader->calls, 3);
        Assert::same($first, $second);
        Assert::same($regional[0]->document->regionCode ?? null, 50);
        Assert::same($nextGeneration[0]->document->regionCode ?? null, 77);
    }

    public function testItUsesShorterTtlForAnEmptyResult(): void
    {
        $cache = new RecordingCache();
        $reader = new RecordingSearchAddressReader(emptyQueries: ['missing']);
        $decorator = new CachedSearchAddressDecorator(
            $reader,
            new AddressSearchResultCache($cache, 60, 5),
            'v1',
        );

        $decorator->search($this->criteria(query: 'present'), new NullCancellation());
        $decorator->search($this->criteria(query: 'missing'), new NullCancellation());
        $decorator->search($this->criteria(query: 'missing'), new NullCancellation());

        Assert::same($cache->ttls, [60, 5]);
        Assert::same($reader->calls, 2);
    }

    public function testItEvictsTheLeastRecentlyUsedEntryAtTheConfiguredBound(): void
    {
        $reader = new RecordingSearchAddressReader();
        $decorator = new CachedSearchAddressDecorator(
            $reader,
            AddressSearchResultCache::local(1, 60, 5),
            'v1',
        );

        $decorator->search($this->criteria(query: 'first'), new NullCancellation());
        $decorator->search($this->criteria(query: 'second'), new NullCancellation());
        $decorator->search($this->criteria(query: 'first'), new NullCancellation());

        Assert::same($reader->calls, 3);
    }

    public function testItCoalescesConcurrentMissesInsideOneWorker(): void
    {
        $reader = new RecordingSearchAddressReader(delaySeconds: 0.01);
        $decorator = new CachedSearchAddressDecorator(
            $reader,
            AddressSearchResultCache::local(10, 60, 5),
            'v1',
        );

        $first = async(fn(): array => $decorator->search($this->criteria(), new NullCancellation()));
        $second = async(fn(): array => $decorator->search($this->criteria(), new NullCancellation()));

        Assert::same($first->await(), $second->await());
        Assert::same($reader->calls, 1);
    }

    public function testAWaitingCancellationDoesNotCancelTheSharedLookup(): void
    {
        $reader = new RecordingSearchAddressReader(delaySeconds: 0.02);
        $decorator = new CachedSearchAddressDecorator(
            $reader,
            AddressSearchResultCache::local(10, 60, 5),
            'v1',
        );
        $creator = async(fn(): array => $decorator->search($this->criteria(), new NullCancellation()));
        delay(0.001);

        $deferred = new DeferredCancellation();
        $waiter = async(fn(): array => $decorator->search($this->criteria(), $deferred->getCancellation()));
        delay(0.001);
        $deferred->cancel();
        $error = null;
        try {
            $waiter->await();
        } catch (CancelledException $exception) {
            $error = $exception;
        }

        Assert::instanceOf($error, CancelledException::class);
        Assert::same(
            $creator->await(),
            $decorator->search($this->criteria(), new NullCancellation()),
        );
        Assert::same($reader->calls, 1);
    }

    public function testItDoesNotCacheFailuresOrCancelledRequests(): void
    {
        $reader = new RecordingSearchAddressReader(failures: 1);
        $decorator = new CachedSearchAddressDecorator(
            $reader,
            AddressSearchResultCache::local(10, 60, 5),
            'v1',
        );
        $error = null;
        try {
            $decorator->search($this->criteria(), new NullCancellation());
        } catch (RuntimeException $exception) {
            $error = $exception;
        }

        Assert::instanceOf($error, RuntimeException::class);
        Assert::same(count($decorator->search($this->criteria(), new NullCancellation())), 1);
        Assert::same($reader->calls, 2);

        $callsBeforeCancellation = $reader->calls;
        $cancelled = new DeferredCancellation();
        $cancelled->cancel();
        $cancelError = null;
        try {
            $decorator->search($this->criteria(query: 'cancelled'), $cancelled->getCancellation());
        } catch (CancelledException $exception) {
            $cancelError = $exception;
        }

        Assert::instanceOf($cancelError, CancelledException::class);
        Assert::same($reader->calls, $callsBeforeCancellation);
    }

    private function criteria(string $query = 'Москва Тверская 1', ?int $regionCode = 77): SearchAddressCriteria
    {
        return new SearchAddressCriteria(
            query: $query,
            hierarchy: AddressHierarchy::Administrative,
            profile: SearchProfile::Search,
            limit: 10,
            regionCode: $regionCode,
        );
    }
}

/** @implements Cache<list<SearchAddressMatch>> */
final class RecordingCache implements Cache
{
    /** @var array<string, list<SearchAddressMatch>> */
    private array $values = [];

    /** @var list<int|null> */
    public array $ttls = [];

    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Expected a match list.');
        }
        foreach ($value as $match) {
            if (!$match instanceof SearchAddressMatch) {
                throw new RuntimeException('Expected an address search match.');
            }
        }

        $this->values[$key] = $value;
        $this->ttls[] = $ttl;
    }

    public function delete(string $key): bool
    {
        $exists = isset($this->values[$key]);
        unset($this->values[$key]);

        return $exists;
    }
}

final class RecordingSearchAddressReader implements SearchAddressPort
{
    public int $calls = 0;

    /** @param list<string> $emptyQueries */
    public function __construct(
        private readonly array $emptyQueries = [],
        private readonly float $delaySeconds = 0,
        private int $failures = 0,
    ) {}

    public function search(SearchAddressCriteria $criteria, Cancellation $cancellation): array
    {
        ++$this->calls;
        $cancellation->throwIfRequested();
        if ($this->delaySeconds > 0) {
            delay($this->delaySeconds, cancellation: $cancellation);
        }
        if ($this->failures > 0) {
            --$this->failures;
            throw new RuntimeException('Search failed.');
        }
        if (in_array($criteria->query, $this->emptyQueries, true)) {
            return [];
        }

        return [new SearchAddressMatch(
            document: new AddressDocument(
                objectId: 770001,
                objectGuid: '00000000-0000-4000-8000-000000770001',
                address: $criteria->query,
                hierarchy: $criteria->hierarchy,
                level: 10,
                postalCode: null,
                okato: null,
                oktmo: null,
                latitude: null,
                longitude: null,
                source: 'gar',
                sourceId: null,
                dataMosGlobalId: null,
                dataMosUnom: null,
                dataMosNreg: null,
                dataMosAdministrativeArea: null,
                dataMosDistrict: null,
                cadastralNumbers: [],
                landCadastralNumbers: [],
                aliasCount: 0,
                regionCode: $criteria->regionCode,
            ),
            score: 1,
            aliases: [],
            matchedAlias: null,
        )];
    }
}

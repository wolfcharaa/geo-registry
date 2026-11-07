<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\SearchCache\Infrastructure;

use function Amp\async;

use Amp\Cache\Cache;
use Amp\Cache\LocalCache;
use Amp\Cancellation;
use Amp\Future;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressMatch;
use Closure;

final class AddressSearchResultCache
{
    /** @var array<string, Future<list<SearchAddressMatch>>> */
    private array $inFlight = [];

    /**
     * @param Cache<list<SearchAddressMatch>> $cache
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly int $ttlSeconds,
        private readonly int $emptyTtlSeconds,
    ) {}

    public static function local(int $maxEntries, int $ttlSeconds, int $emptyTtlSeconds): self
    {
        if ($maxEntries < 1) {
            throw new \ValueError('Address search cache max entries must be greater than zero.');
        }

        return new self(new LocalCache($maxEntries), $ttlSeconds, $emptyTtlSeconds);
    }

    /**
     * @param Closure(): list<SearchAddressMatch> $resolve
     * @return list<SearchAddressMatch>
     */
    public function remember(string $key, Cancellation $cancellation, Closure $resolve): array
    {
        $cancellation->throwIfRequested();

        $matches = $this->cache->get($key);
        if ($matches !== null) {
            return $matches;
        }

        $future = $this->inFlight[$key] ?? null;
        if ($future === null) {
            $future = $this->resolve($key, $resolve);
            $this->inFlight[$key] = $future;
        }

        return $future->await($cancellation);
    }

    /**
     * @param Closure(): list<SearchAddressMatch> $resolve
     * @return Future<list<SearchAddressMatch>>
     */
    private function resolve(string $key, Closure $resolve): Future
    {
        return async(function () use ($key, $resolve): array {
            try {
                $matches = $resolve();
                $this->cache->set(
                    $key,
                    $matches,
                    $matches === [] ? $this->emptyTtlSeconds : $this->ttlSeconds,
                );

                return $matches;
            } finally {
                unset($this->inFlight[$key]);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Observability;

use Closure;
use Fiber;
use WeakMap;

final class CorrelationContext
{
    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, CorrelationIds> */
    private WeakMap $fibers;

    private ?CorrelationIds $root = null;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    public function current(): ?CorrelationIds
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->root : ($this->fibers[$fiber] ?? null);
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function run(CorrelationIds $ids, Closure $callback): mixed
    {
        $fiber = Fiber::getCurrent();
        $previous = $this->current();
        $this->set($fiber, $ids);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                $this->remove($fiber);
            } else {
                $this->set($fiber, $previous);
            }
        }
    }

    /** @param ?Fiber<mixed, mixed, mixed, mixed> $fiber */
    private function set(?Fiber $fiber, CorrelationIds $ids): void
    {
        if ($fiber === null) {
            $this->root = $ids;

            return;
        }

        $this->fibers[$fiber] = $ids;
    }

    /** @param ?Fiber<mixed, mixed, mixed, mixed> $fiber */
    private function remove(?Fiber $fiber): void
    {
        if ($fiber === null) {
            $this->root = null;

            return;
        }

        unset($this->fibers[$fiber]);
    }
}

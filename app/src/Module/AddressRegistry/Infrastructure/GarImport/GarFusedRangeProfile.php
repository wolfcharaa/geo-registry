<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

/** Benchmark-only stage timing; absent from ordinary fused range results. */
final class GarFusedRangeProfile
{
    public int $prefetchNanoseconds = 0;

    public int $permitWaitNanoseconds = 0;

    public int $connectionNanoseconds = 0;

    public int $permitDecodeNanoseconds = 0;

    public int $beginNanoseconds = 0;

    public int $copyBeginNanoseconds = 0;

    public int $copySendNanoseconds = 0;

    public int $copyEndNanoseconds = 0;

    public int $ledgerNanoseconds = 0;

    public int $commitNanoseconds = 0;

    public int $writerNanoseconds = 0;

    public int $taskNanoseconds = 0;
}

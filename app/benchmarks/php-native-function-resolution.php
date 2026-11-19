#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace NativeFunctionBenchmark\Unqualified {
    /** @param non-empty-list<int> $values */
    function run(array $values, int $iterations): int
    {
        $checksum = 0;
        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $checksum += array_sum($values);
        }

        return $checksum;
    }
}

namespace NativeFunctionBenchmark\Imported {
    use function array_sum;

    /** @param non-empty-list<int> $values */
    function run(array $values, int $iterations): int
    {
        $checksum = 0;
        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $checksum += array_sum($values);
        }

        return $checksum;
    }
}

namespace NativeFunctionBenchmark\FullyQualified {
    /** @param non-empty-list<int> $values */
    function run(array $values, int $iterations): int
    {
        $checksum = 0;
        for ($iteration = 0; $iteration < $iterations; ++$iteration) {
            $checksum += \array_sum($values);
        }

        return $checksum;
    }
}

namespace {
    const ITERATIONS = 20_000_000;
    const WARMUP_ITERATIONS = 2_000_000;
    const REPETITIONS = 9;

    /** @return array{median_ns: int, mean_ns: int, p95_ns: int, min_ns: int} */
    function summarize(array $samples): array
    {
        \sort($samples, \SORT_NUMERIC);
        $count = \count($samples);

        return [
            'median_ns' => $samples[\intdiv($count, 2)],
            'mean_ns' => (int) \round(\array_sum($samples) / $count),
            'p95_ns' => $samples[(int) \ceil($count * 0.95) - 1],
            'min_ns' => $samples[0],
        ];
    }

    $values = [1, 2, 3, 4, 5, 6, 7, 8];
    $variants = [
        'unqualified' => NativeFunctionBenchmark\Unqualified\run(...),
        'imported' => NativeFunctionBenchmark\Imported\run(...),
        'fully_qualified' => NativeFunctionBenchmark\FullyQualified\run(...),
    ];
    $samples = \array_fill_keys(\array_keys($variants), []);

    foreach ($variants as $variant) {
        $variant($values, WARMUP_ITERATIONS);
    }

    \gc_disable();
    $names = \array_keys($variants);
    for ($repetition = 0; $repetition < REPETITIONS; ++$repetition) {
        $offset = $repetition % \count($names);
        $order = \array_merge(\array_slice($names, $offset), \array_slice($names, 0, $offset));
        foreach ($order as $name) {
            $startedAt = \hrtime(true);
            $checksum = $variants[$name]($values, ITERATIONS);
            $samples[$name][] = \hrtime(true) - $startedAt;
            if ($checksum !== \array_sum($values) * ITERATIONS) {
                throw new \RuntimeException("Checksum mismatch for {$name}.");
            }
        }
    }

    $summaries = [];
    foreach ($samples as $name => $variantSamples) {
        $summaries[$name] = summarize($variantSamples);
    }
    $baseline = $summaries['fully_qualified']['median_ns'];

    \printf(
        "php=%s opcache_cli=%s jit=%s iterations=%d repetitions=%d\n",
        \PHP_VERSION,
        \ini_get('opcache.enable_cli') ?: '0',
        \ini_get('opcache.jit') ?: 'off',
        ITERATIONS,
        REPETITIONS,
    );
    \printf("variant,median_ms,mean_ms,p95_ms,min_ms,ns_per_call,delta_vs_fqn_percent\n");
    foreach ($summaries as $name => $summary) {
        \printf(
            "%s,%.3f,%.3f,%.3f,%.3f,%.3f,%+.3f\n",
            $name,
            $summary['median_ns'] / 1_000_000,
            $summary['mean_ns'] / 1_000_000,
            $summary['p95_ns'] / 1_000_000,
            $summary['min_ns'] / 1_000_000,
            $summary['median_ns'] / ITERATIONS,
            ($summary['median_ns'] / $baseline - 1) * 100,
        );
    }
}

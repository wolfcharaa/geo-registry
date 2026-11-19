<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Generator;
use HashContext;
use IteratorAggregate;

/** @implements IteratorAggregate<int, string> */
final class GarTransportChunkSource implements IteratorAggregate
{
    private int $rowCount = 0;

    private int $encodedBytes = 0;

    private int $chunks = 0;

    private int $maxChunkBytes = 0;

    private float $encodeNanoseconds = 0.0;

    private readonly HashContext $hash;

    private ?string $sha256 = null;

    private bool $started = false;

    private bool $completed = false;

    /** @param iterable<array-key, list<?string>> $rowSource */
    public function __construct(
        private readonly iterable $rowSource,
        private readonly GarBenchmarkRowEncoder $encoder,
        private readonly int $chunkBytes,
    ) {
        if ($this->chunkBytes < 1) {
            throw new GarImportFailed('GAR transport benchmark chunk size must be positive.');
        }
        $this->hash = \hash_init('sha256');
    }

    /** @return Generator<int, string> */
    public function getIterator(): Generator
    {
        if ($this->started) {
            throw new GarImportFailed('GAR transport benchmark chunk source is one-shot.');
        }
        $this->started = true;
        $chunk = '';

        foreach ($this->rowSource as $values) {
            $startedAt = \hrtime(true);
            $encoded = $this->encoder->encode($values);
            if ($chunk !== '' && \strlen($chunk) + \strlen($encoded) > $this->chunkBytes) {
                $this->encodeNanoseconds += \hrtime(true) - $startedAt;
                $this->recordChunk($chunk);
                yield $chunk;
                $startedAt = \hrtime(true);
                $chunk = '';
            }

            $chunk .= $encoded;
            \hash_update($this->hash, $encoded);
            $this->encodedBytes += \strlen($encoded);
            ++$this->rowCount;
            $this->encodeNanoseconds += \hrtime(true) - $startedAt;
        }

        if ($chunk !== '') {
            $this->recordChunk($chunk);
            yield $chunk;
        }
        $this->sha256 = \hash_final($this->hash);
        $this->completed = true;
    }

    /**
     * @return array{
     *     rows: int,
     *     encoded_bytes: int,
     *     chunks: int,
     *     max_chunk_bytes: int,
     *     encode_seconds: float,
     *     transport_sha256: string
     * }
     */
    public function metrics(): array
    {
        if (!$this->completed) {
            throw new GarImportFailed('GAR transport benchmark chunk source has not completed.');
        }

        return [
            'rows' => $this->rowCount,
            'encoded_bytes' => $this->encodedBytes,
            'chunks' => $this->chunks,
            'max_chunk_bytes' => $this->maxChunkBytes,
            'encode_seconds' => $this->encodeNanoseconds / 1_000_000_000,
            'transport_sha256' => $this->sha256 ?? throw new GarImportFailed(
                'GAR transport benchmark digest is unavailable.',
            ),
        ];
    }

    private function recordChunk(string $chunk): void
    {
        ++$this->chunks;
        $this->maxChunkBytes = \max($this->maxChunkBytes, \strlen($chunk));
    }
}

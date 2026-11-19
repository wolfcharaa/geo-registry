<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use Generator;
use HashContext;
use IteratorAggregate;
use LibXMLError;
use XMLReader;

/**
 * One-shot fused XMLReader source for bounded PostgreSQL COPY chunks.
 *
 * @implements IteratorAggregate<int, string>
 */
final class GarXmlCopyChunkSource implements IteratorAggregate
{
    private int $rows = 0;

    private int $encodedBytes = 0;

    private int $chunks = 0;

    private float $encodeNanoseconds = 0.0;

    private readonly HashContext $hash;

    private ?string $sha256 = null;

    private bool $started = false;

    public function __construct(
        private readonly GarXmlSourceFile $file,
        private readonly int $chunkBytes,
    ) {
        if ($this->chunkBytes < 1) {
            throw new GarImportFailed('GAR XML COPY chunk size must be positive.');
        }
        $this->hash = \hash_init('sha256');
    }

    /** @return Generator<int, string> */
    public function getIterator(): Generator
    {
        if ($this->started) {
            throw new GarImportFailed('GAR XML COPY chunk source is one-shot.');
        }
        $this->started = true;

        $previousInternalErrors = \libxml_use_internal_errors(true);
        \libxml_clear_errors();
        $reader = XMLReader::open($this->file->path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        if (!$reader instanceof XMLReader) {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previousInternalErrors);
            throw new GarImportFailed("Unable to open GAR XML file: {$this->file->relativePath}");
        }
        $chunk = '';

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                    continue;
                }

                $startedAt = \hrtime(true);
                $encoded = $this->encode($reader);
                if ($chunk !== '' && \strlen($chunk) + \strlen($encoded) > $this->chunkBytes) {
                    $this->encodeNanoseconds += \hrtime(true) - $startedAt;
                    ++$this->chunks;
                    yield $chunk;
                    $startedAt = \hrtime(true);
                    $chunk = '';
                }

                $chunk .= $encoded;
                \hash_update($this->hash, $encoded);
                $this->encodedBytes += \strlen($encoded);
                ++$this->rows;
                $this->encodeNanoseconds += \hrtime(true) - $startedAt;
            }

            $error = self::firstParsingError(\libxml_get_errors());
            if ($error !== null) {
                throw new GarImportFailed(\sprintf(
                    'Invalid GAR XML in %s at line %d: %s',
                    $this->file->relativePath,
                    $error->line,
                    \trim($error->message),
                ));
            }
            if ($chunk !== '') {
                ++$this->chunks;
                yield $chunk;
            }
            $this->sha256 = \hash_final($this->hash);
        } finally {
            $reader->close();
            \libxml_clear_errors();
            \libxml_use_internal_errors($previousInternalErrors);
        }
    }

    /** @return array{rows: int, encoded_bytes: int, chunks: int, encode_seconds: float, sha256: string} */
    public function metrics(): array
    {
        if ($this->sha256 === null) {
            throw new GarImportFailed('GAR XML COPY chunk source has not completed.');
        }

        return [
            'rows' => $this->rows,
            'encoded_bytes' => $this->encodedBytes,
            'chunks' => $this->chunks,
            'encode_seconds' => $this->encodeNanoseconds / 1_000_000_000,
            'sha256' => $this->sha256,
        ];
    }

    private function encode(XMLReader $reader): string
    {
        $encoded = '';
        $identity = null;
        $index = 0;
        foreach ($this->file->descriptor->attributes as $column => $attribute) {
            assert(\is_string($column));
            $value = $this->file->descriptor->normalizeValue($column, $reader->getAttribute($attribute));
            if ($column === 'path_arr') {
                $value = self::pathArray($value);
            }
            if ($index === 0) {
                $identity = $value;
            } else {
                $encoded .= "\t";
            }
            $encoded .= self::encodeValue($value);
            ++$index;
        }
        if ($identity === null || $identity === '') {
            throw new GarImportFailed(\sprintf(
                'GAR XML row %d in %s has no identity attribute.',
                $this->rows + 1,
                $this->file->relativePath,
            ));
        }

        return $encoded . "\n";
    }

    private static function encodeValue(?string $value): string
    {
        if ($value === null) {
            return '\\N';
        }

        return \strtr($value, [
            '\\' => '\\\\',
            "\t" => '\\t',
            "\n" => '\\n',
            "\r" => '\\r',
        ]);
    }

    private static function pathArray(?string $path): string
    {
        if ($path === null) {
            return '{}';
        }

        $parts = \preg_split('/[^0-9]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return '{}';
        }

        return '{' . \implode(',', $parts) . '}';
    }

    /** @param list<LibXMLError> $errors */
    private static function firstParsingError(array $errors): ?LibXMLError
    {
        foreach ($errors as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                return $error;
            }
        }

        return null;
    }
}

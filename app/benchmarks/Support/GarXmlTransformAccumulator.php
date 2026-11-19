<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarCopyRowEncoder;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use DOMElement;
use HashContext;
use SimpleXMLElement;
use XMLReader;

/** Collects an implementation-independent digest of GAR XML transformation. */
final class GarXmlTransformAccumulator
{
    /** @var array<string, string> */
    private readonly array $attributes;

    private readonly GarCopyRowEncoder $encoder;

    private readonly HashContext $hash;

    private int $rows = 0;

    private int $encodedBytes = 0;

    public function __construct(private readonly GarXmlSourceFile $file)
    {
        $this->attributes = $this->file->descriptor->attributes;
        $this->encoder = new GarCopyRowEncoder();
        $this->hash = \hash_init('sha256');
    }

    public function acceptReader(XMLReader $reader): void
    {
        $values = [];
        foreach ($this->attributes as $column => $attribute) {
            assert(\is_string($column));
            $value = $this->file->descriptor->normalizeValue($column, $reader->getAttribute($attribute));
            $values[] = $column === 'path_arr' ? self::pathArray($value) : $value;
        }

        $this->acceptValues($values);
    }

    public function acceptReaderFused(XMLReader $reader): void
    {
        $encoded = '';
        $identity = null;
        $index = 0;
        foreach ($this->attributes as $column => $attribute) {
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
        $encoded .= "\n";

        $this->acceptEncoded($encoded, $identity);
    }

    /** @param array<string, string> $attributes */
    public function acceptAttributes(array $attributes): void
    {
        $values = [];
        foreach ($this->attributes as $column => $attribute) {
            assert(\is_string($column));
            $value = $this->file->descriptor->normalizeValue($column, $attributes[$attribute] ?? null);
            $values[] = $column === 'path_arr' ? self::pathArray($value) : $value;
        }

        $this->acceptValues($values);
    }

    public function acceptDomElement(DOMElement $element): void
    {
        $values = [];
        foreach ($this->attributes as $column => $attribute) {
            assert(\is_string($column));
            $value = $element->hasAttribute($attribute) ? $element->getAttribute($attribute) : null;
            $value = $this->file->descriptor->normalizeValue($column, $value);
            $values[] = $column === 'path_arr' ? self::pathArray($value) : $value;
        }

        $this->acceptValues($values);
    }

    public function acceptSimpleXml(SimpleXMLElement $element): void
    {
        $attributes = $element->attributes();
        if (!$attributes instanceof SimpleXMLElement) {
            throw new GarImportFailed('SimpleXML GAR row has no attributes.');
        }

        $values = [];
        foreach ($this->attributes as $column => $attribute) {
            assert(\is_string($column));
            $value = isset($attributes[$attribute]) ? (string) $attributes[$attribute] : null;
            $value = $this->file->descriptor->normalizeValue($column, $value);
            $values[] = $column === 'path_arr' ? self::pathArray($value) : $value;
        }

        $this->acceptValues($values);
    }

    /** @param list<?string> $values */
    public function acceptValues(array $values): void
    {
        $identity = $values[0] ?? null;
        $this->acceptEncoded($this->encoder->encode($values), $identity);
    }

    private function acceptEncoded(string $encoded, ?string $identity): void
    {
        if ($identity === null || $identity === '') {
            throw new GarImportFailed(\sprintf(
                'GAR XML row %d in %s has no identity attribute.',
                $this->rows + 1,
                $this->file->relativePath,
            ));
        }

        \hash_update($this->hash, $encoded);
        $this->encodedBytes += \strlen($encoded);
        ++$this->rows;
    }

    /** @return array{rows: int, encoded_bytes: int, sha256: string} */
    public function result(): array
    {
        return [
            'rows' => $this->rows,
            'encoded_bytes' => $this->encodedBytes,
            'sha256' => \hash_final($this->hash),
        ];
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

}

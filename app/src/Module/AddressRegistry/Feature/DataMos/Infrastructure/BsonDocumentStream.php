<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\Infrastructure;

use function array_values;
use function base64_encode;
use function bin2hex;
use function date;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function ord;

use RuntimeException;

use function sprintf;
use function str_ends_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function unpack;

final class BsonDocumentStream
{
    /**
     * @return iterable<array<string, mixed>>
     */
    public function read(string $path): iterable
    {
        $handle = $this->open($path);

        try {
            while (!feof($handle)) {
                $prefix = fread($handle, 4);
                if ($prefix === '' || $prefix === false) {
                    return;
                }

                if (strlen($prefix) !== 4) {
                    throw new RuntimeException(sprintf('Truncated BSON document length in %s.', $path));
                }

                $length = $this->int32($prefix, 0);
                if ($length < 5) {
                    throw new RuntimeException(sprintf('Invalid BSON document length %d in %s.', $length, $path));
                }

                $document = $prefix . $this->readBytes($handle, $length - 4, $path);
                $decoded = $this->decodeDocument($document, false);

                /** @var array<string, mixed> $decoded */
                yield $decoded;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open(string $path): mixed
    {
        $streamPath = str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://' . $path : $path;
        $handle = fopen($streamPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open Data.Mos BSON file: %s', $path));
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function readBytes(mixed $handle, int $length, string $path): string
    {
        if ($length < 1) {
            throw new RuntimeException(sprintf('Invalid BSON read length %d in %s.', $length, $path));
        }

        $buffer = '';
        while (strlen($buffer) < $length && !feof($handle)) {
            $remaining = $length - strlen($buffer);
            if ($remaining < 1) {
                break;
            }

            $chunk = fread($handle, $remaining);
            if ($chunk === false) {
                throw new RuntimeException(sprintf('Unable to read Data.Mos BSON file: %s', $path));
            }

            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        if (strlen($buffer) !== $length) {
            throw new RuntimeException(sprintf('Truncated BSON document body in %s.', $path));
        }

        return $buffer;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function decodeDocument(string $bytes, bool $list): array
    {
        $length = $this->int32($bytes, 0);
        if ($length !== strlen($bytes)) {
            throw new RuntimeException(sprintf('Invalid nested BSON document length: %d.', $length));
        }

        $offset = 4;
        $document = [];

        while ($offset < $length - 1) {
            $type = ord($bytes[$offset]);
            $offset++;

            $key = $this->cstring($bytes, $offset);
            $document[$key] = $this->decodeValue($type, $bytes, $offset, $key);
        }

        if ($offset !== $length - 1 || ord($bytes[$offset]) !== 0) {
            throw new RuntimeException('Invalid BSON document terminator.');
        }

        return $list ? array_values($document) : $document;
    }

    private function decodeValue(int $type, string $bytes, int &$offset, string $key): mixed
    {
        return match ($type) {
            0x01 => $this->double($bytes, $offset),
            0x02 => $this->string($bytes, $offset),
            0x03 => $this->documentValue($bytes, $offset, false),
            0x04 => $this->documentValue($bytes, $offset, true),
            0x05 => $this->binary($bytes, $offset),
            0x07 => $this->objectId($bytes, $offset),
            0x08 => $this->bool($bytes, $offset),
            0x09 => $this->dateTime($bytes, $offset),
            0x0A, 0x06, 0xFF, 0x7F => null,
            0x0B => $this->regex($bytes, $offset),
            0x0D, 0x0E => $this->string($bytes, $offset),
            0x10 => $this->int32Value($bytes, $offset),
            0x11 => $this->timestamp($bytes, $offset),
            0x12 => $this->int64Value($bytes, $offset),
            default => throw new RuntimeException(sprintf('Unsupported BSON type 0x%02x for key "%s".', $type, $key)),
        };
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    private function documentValue(string $bytes, int &$offset, bool $list): array
    {
        $length = $this->int32($bytes, $offset);
        $document = $this->decodeDocument(substr($bytes, $offset, $length), $list);
        $offset += $length;

        return $document;
    }

    private function string(string $bytes, int &$offset): string
    {
        $length = $this->int32($bytes, $offset);
        $offset += 4;
        $value = substr($bytes, $offset, $length - 1);
        $offset += $length;

        return $value;
    }

    private function cstring(string $bytes, int &$offset): string
    {
        $end = strpos($bytes, "\0", $offset);
        if ($end === false) {
            throw new RuntimeException('Invalid BSON cstring.');
        }

        $value = substr($bytes, $offset, $end - $offset);
        $offset = $end + 1;

        return $value;
    }

    private function double(string $bytes, int &$offset): float
    {
        $value = unpack('evalue', substr($bytes, $offset, 8));
        $offset += 8;
        if ($value === false || !is_float($value['value'] ?? null)) {
            throw new RuntimeException('Invalid BSON double value.');
        }

        return $value['value'];
    }

    private function int32Value(string $bytes, int &$offset): int
    {
        $value = $this->int32($bytes, $offset);
        $offset += 4;

        return $value;
    }

    private function int64Value(string $bytes, int &$offset): int
    {
        $parts = unpack('Vlow/Vhigh', substr($bytes, $offset, 8));
        $offset += 8;
        if ($parts === false || !is_int($parts['low'] ?? null) || !is_int($parts['high'] ?? null)) {
            throw new RuntimeException('Invalid BSON int64 value.');
        }

        return $parts['low'] + $parts['high'] * 4294967296;
    }

    private function bool(string $bytes, int &$offset): bool
    {
        $value = ord($bytes[$offset]) !== 0;
        $offset++;

        return $value;
    }

    /**
     * @return array{binary: string, subtype: int}
     */
    private function binary(string $bytes, int &$offset): array
    {
        $length = $this->int32($bytes, $offset);
        $offset += 4;
        $subtype = ord($bytes[$offset]);
        $offset++;
        $value = substr($bytes, $offset, $length);
        $offset += $length;

        return [
            'binary' => base64_encode($value),
            'subtype' => $subtype,
        ];
    }

    private function objectId(string $bytes, int &$offset): string
    {
        $value = bin2hex(substr($bytes, $offset, 12));
        $offset += 12;

        return $value;
    }

    private function dateTime(string $bytes, int &$offset): string
    {
        $milliseconds = $this->int64Value($bytes, $offset);

        return date('c', (int) ($milliseconds / 1000));
    }

    /**
     * @return array{pattern: string, options: string}
     */
    private function regex(string $bytes, int &$offset): array
    {
        return [
            'pattern' => $this->cstring($bytes, $offset),
            'options' => $this->cstring($bytes, $offset),
        ];
    }

    /**
     * @return array{increment: int, timestamp: int}
     */
    private function timestamp(string $bytes, int &$offset): array
    {
        $increment = $this->int32($bytes, $offset);
        $timestamp = $this->int32($bytes, $offset + 4);
        $offset += 8;

        return [
            'increment' => $increment,
            'timestamp' => $timestamp,
        ];
    }

    private function int32(string $bytes, int $offset): int
    {
        $value = unpack('Vvalue', substr($bytes, $offset, 4));
        if ($value === false || !is_int($value['value'] ?? null)) {
            throw new RuntimeException('Invalid BSON int32 value.');
        }

        return $value['value'];
    }
}

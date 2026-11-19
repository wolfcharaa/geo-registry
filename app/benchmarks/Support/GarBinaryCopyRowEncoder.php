<?php

declare(strict_types=1);

namespace App\Benchmark\Support;

use RuntimeException;

/** Binary COPY encoder for the nine-column GAR object_params benchmark row. */
final class GarBinaryCopyRowEncoder implements GarBenchmarkRowEncoder
{
    private const POSTGRES_EPOCH = 946_684_800;

    private const FIELD_TYPES = [
        'int8',
        'int8',
        'int8',
        'int8',
        'int4',
        'text',
        'date',
        'date',
        'date',
    ];

    /** @var array<string, int> */
    private array $dateDays = [];

    public static function header(): string
    {
        return "PGCOPY\n\xFF\r\n\0" . \pack('N2', 0, 0);
    }

    public static function trailer(): string
    {
        return \pack('n', 0xFFFF);
    }

    public function encode(array $values): string
    {
        if (\count($values) !== \count(self::FIELD_TYPES)) {
            throw new RuntimeException('Binary GAR benchmark row must contain exactly nine fields.');
        }

        $encoded = \pack('n', \count($values));
        foreach ($values as $index => $value) {
            if ($value === null) {
                $encoded .= \pack('N', 0xFFFFFFFF);
                continue;
            }

            $field = match (self::FIELD_TYPES[$index]) {
                'int8' => self::int8($value),
                'int4' => self::int4($value),
                'text' => $value,
                'date' => $this->date($value),
            };
            $encoded .= \pack('N', \strlen($field)) . $field;
        }

        return $encoded;
    }

    private static function int8(string $value): string
    {
        self::assertInteger($value);

        return \pack('J', (int) $value);
    }

    private static function int4(string $value): string
    {
        self::assertInteger($value);

        return \pack('N', (int) $value);
    }

    private function date(string $value): string
    {
        $days = $this->dateDays[$value] ?? null;
        if ($days === null) {
            $timestamp = \strtotime($value . ' 00:00:00 UTC');
            if ($timestamp === false) {
                throw new RuntimeException("Invalid GAR benchmark date: {$value}");
            }
            $days = \intdiv($timestamp - self::POSTGRES_EPOCH, 86_400);
            $this->dateDays[$value] = $days;
        }

        return \pack('N', $days);
    }

    private static function assertInteger(string $value): void
    {
        if (\preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            throw new RuntimeException("Invalid GAR benchmark integer: {$value}");
        }
    }
}

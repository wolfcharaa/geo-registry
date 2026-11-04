<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

final class GarCopyRowEncoder
{
    /** @param list<?string> $values */
    public function encode(array $values): string
    {
        return implode("\t", array_map(self::encodeValue(...), $values)) . "\n";
    }

    private static function encodeValue(?string $value): string
    {
        if ($value === null) {
            return '\\N';
        }

        return strtr($value, [
            '\\' => '\\\\',
            "\t" => '\\t',
            "\n" => '\\n',
            "\r" => '\\r',
        ]);
    }
}

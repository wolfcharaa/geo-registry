<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use XMLReader;

final class GarXmlRowDecoder
{
    /** @return list<?string> */
    public static function decode(XMLReader $reader, GarXmlSourceFile $file, int $rowNumber): array
    {
        self::assertIdentity($reader, $file, $rowNumber);

        $values = [];
        foreach ($file->descriptor->attributes as $column => $attribute) {
            if (!\is_string($column)) {
                throw new GarImportFailed('GAR XML descriptor contains a non-string stage column.');
            }
            $value = $file->descriptor->normalizeValue($column, $reader->getAttribute($attribute));
            $values[] = $column === 'path_arr' ? self::pathArray($value) : $value;
        }

        return $values;
    }

    public static function assertIdentity(XMLReader $reader, GarXmlSourceFile $file, int $rowNumber): void
    {
        $identity = $reader->getAttribute($file->descriptor->identityAttribute());
        if ($identity === null || $identity === '') {
            throw new GarImportFailed(\sprintf(
                'GAR XML row %d in %s has no %s identity attribute.',
                $rowNumber,
                $file->relativePath,
                $file->descriptor->identityAttribute(),
            ));
        }
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
}

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Generator;
use LibXMLError;
use XMLReader;

final class GarXmlRowReader
{
    /**
     * @return Generator<int, list<?string>>
     */
    public function rows(GarXmlSourceFile $file, int $skipRows): Generator
    {
        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = null;

        try {
            $reader = XMLReader::open($file->path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
            if (!$reader instanceof XMLReader) {
                throw new GarImportFailed("Unable to open GAR XML file: {$file->relativePath}");
            }

            $rowNumber = 0;
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                    continue;
                }

                ++$rowNumber;
                $identity = $reader->getAttribute($file->descriptor->identityAttribute());
                if ($identity === null || $identity === '') {
                    throw new GarImportFailed(sprintf(
                        'GAR XML row %d in %s has no %s identity attribute.',
                        $rowNumber,
                        $file->relativePath,
                        $file->descriptor->identityAttribute(),
                    ));
                }
                if ($rowNumber <= $skipRows) {
                    continue;
                }

                $values = [];
                foreach ($file->descriptor->attributes as $column => $attribute) {
                    $value = $reader->getAttribute($attribute);
                    if ($value === '') {
                        $value = null;
                    }
                    $values[] = $column === 'path_arr' ? self::pathArray($value) : $value;
                }

                yield $rowNumber => $values;
            }

            $error = self::firstParsingError(libxml_get_errors());
            if ($error !== null) {
                throw new GarImportFailed(sprintf(
                    'Invalid GAR XML in %s at line %d: %s',
                    $file->relativePath,
                    $error->line,
                    trim($error->message),
                ));
            }
        } finally {
            if ($reader instanceof XMLReader) {
                $reader->close();
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }
    }

    private static function pathArray(?string $path): string
    {
        if ($path === null) {
            return '{}';
        }

        $parts = preg_split('/[^0-9]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return '{}';
        }

        return '{' . implode(',', $parts) . '}';
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

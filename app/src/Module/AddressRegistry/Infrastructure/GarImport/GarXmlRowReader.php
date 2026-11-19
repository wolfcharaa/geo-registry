<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Generator;
use LibXMLError;
use XMLReader;

final class GarXmlRowReader
{
    /**
     * @return Generator<int, list<?string>>
     */
    public function rows(
        GarXmlSourceFile $file,
        int $skipRows,
        ?Cancellation $cancellation = null,
    ): Generator {
        $cancellation?->throwIfRequested();
        $previousInternalErrors = \libxml_use_internal_errors(true);
        \libxml_clear_errors();
        $reader = null;

        try {
            $reader = XMLReader::open($file->path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
            if (!$reader instanceof XMLReader) {
                throw new GarImportFailed("Unable to open GAR XML file: {$file->relativePath}");
            }

            $rowNumber = 0;
            while ($reader->read()) {
                $cancellation?->throwIfRequested();
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                    continue;
                }

                ++$rowNumber;
                if ($rowNumber <= $skipRows) {
                    GarXmlRowDecoder::assertIdentity($reader, $file, $rowNumber);
                    continue;
                }

                yield $rowNumber => GarXmlRowDecoder::decode($reader, $file, $rowNumber);
            }

            $error = self::firstParsingError(\libxml_get_errors());
            if ($error !== null) {
                throw new GarImportFailed(\sprintf(
                    'Invalid GAR XML in %s at line %d: %s',
                    $file->relativePath,
                    $error->line,
                    \trim($error->message),
                ));
            }
        } finally {
            if ($reader instanceof XMLReader) {
                $reader->close();
            }
            \libxml_clear_errors();
            \libxml_use_internal_errors($previousInternalErrors);
        }
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

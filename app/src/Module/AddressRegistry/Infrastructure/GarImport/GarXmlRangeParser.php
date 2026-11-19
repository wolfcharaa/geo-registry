<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use Amp\Cancellation;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use Generator;
use LibXMLError;
use XMLReader;

final readonly class GarXmlRangeParser
{
    public function __construct(
        private int $maxEncodedBytes = 8 * 1024 * 1024,
    ) {
        if ($this->maxEncodedBytes < 1) {
            throw new GarImportFailed('GAR XML range parser byte limit must be positive.');
        }
    }

    public function parse(
        GarXmlSourceFile $file,
        GarXmlByteRange $range,
        Cancellation $cancellation,
    ): GarParsedXmlRange {
        $rows = [];
        $encodedBytes = 0;
        foreach ($this->encodedRows($file, $range, $cancellation) as $encoded) {
            $rows[] = $encoded;
            $encodedBytes += \strlen($encoded);
        }
        if ($rows === []) {
            throw new GarImportFailed('GAR parsed XML range has no rows.');
        }

        return new GarParsedXmlRange($rows, $range->firstRow, $range->lastRow, $encodedBytes);
    }

    /** @return Generator<int, string> Row number => encoded COPY-text row. */
    public function encodedRows(
        GarXmlSourceFile $file,
        GarXmlByteRange $range,
        Cancellation $cancellation,
    ): Generator {
        $cancellation->throwIfRequested();
        $fragment = $this->fragment($file, $range);

        $previousInternalErrors = \libxml_use_internal_errors(true);
        \libxml_clear_errors();
        $reader = XMLReader::XML(
            '<GAR_ROWS>' . $fragment . '</GAR_ROWS>',
            null,
            LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE,
        );
        if (!$reader instanceof XMLReader) {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previousInternalErrors);
            throw new GarImportFailed("Unable to parse GAR XML range: {$file->relativePath}");
        }

        try {
            $encodedBytes = 0;
            $rowNumber = $range->firstRow - 1;
            $encoder = new GarCopyRowEncoder();
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== 1) {
                    continue;
                }
                ++$rowNumber;
                if (($rowNumber & 1023) === 0) {
                    $cancellation->throwIfRequested();
                }
                $encoded = $encoder->encode(GarXmlRowDecoder::decode($reader, $file, $rowNumber));
                $encodedBytes += \strlen($encoded);
                if ($encodedBytes > $this->maxEncodedBytes) {
                    throw new GarImportFailed('GAR parsed XML range exceeds the encoded batch byte limit.');
                }
                yield $rowNumber => $encoded;
            }

            $error = self::firstParsingError(\libxml_get_errors());
            if ($error !== null) {
                throw new GarImportFailed(\sprintf(
                    'Invalid GAR XML range in %s at line %d: %s',
                    $file->relativePath,
                    $error->line,
                    \trim($error->message),
                ));
            }
            if ($rowNumber !== $range->lastRow || $rowNumber - $range->firstRow + 1 !== $range->rows()) {
                throw new GarImportFailed('GAR parsed XML range does not match its planned row boundary.');
            }
            $cancellation->throwIfRequested();
        } finally {
            $reader->close();
            \libxml_clear_errors();
            \libxml_use_internal_errors($previousInternalErrors);
        }
    }

    private function fragment(GarXmlSourceFile $file, GarXmlByteRange $range): string
    {
        $handle = \fopen($file->path, 'rb');
        if ($handle === false || \fseek($handle, $range->firstByte) !== 0) {
            if (\is_resource($handle)) {
                \fclose($handle);
            }
            throw new GarImportFailed("Unable to open GAR XML range: {$file->relativePath}");
        }

        try {
            $fragment = '';
            $remaining = $range->bytes();
            while ($remaining > 0) {
                $chunk = \fread($handle, \min($remaining, 1024 * 1024));
                if ($chunk === false || $chunk === '') {
                    throw new GarImportFailed("Unable to read GAR XML range: {$file->relativePath}");
                }
                $fragment .= $chunk;
                $remaining -= \strlen($chunk);
            }

            return $fragment;
        } finally {
            \fclose($handle);
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

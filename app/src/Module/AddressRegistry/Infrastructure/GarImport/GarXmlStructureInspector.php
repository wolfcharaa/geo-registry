<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use XMLReader;

final class GarXmlStructureInspector
{
    public function inspect(GarXmlSourceFile $file): GarXmlStructure
    {
        $reader = XMLReader::open($file->path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        if (!$reader instanceof XMLReader) {
            throw new GarImportFailed("Unable to open GAR XML file: {$file->relativePath}");
        }

        try {
            $rootElement = null;
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->depth === 0) {
                    $rootElement ??= $reader->name;
                    continue;
                }
                if ($reader->depth === 1 && $rootElement !== null) {
                    return new GarXmlStructure($rootElement, $reader->name);
                }
            }
        } finally {
            $reader->close();
        }

        throw new GarImportFailed("GAR XML file has no depth-1 rows: {$file->relativePath}");
    }
}

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use InvalidArgumentException;

/** Immutable GAR source snapshot used as one import operation identity. */
final readonly class GarXmlManifest
{
    public string $hash;

    /** @var non-empty-list<GarXmlSourceFile> */
    public array $files;

    /** @var non-empty-array<array-key, GarXmlSourceFile> */
    private array $filesByRelativePath;

    /** @param list<GarXmlSourceFile> $files */
    public function __construct(
        public string $sourcePath,
        array $files,
    ) {
        if ($this->sourcePath === '') {
            throw new InvalidArgumentException('GAR manifest source path must not be empty.');
        }
        if ($files === []) {
            throw new InvalidArgumentException('GAR manifest must contain at least one file.');
        }

        /** @var array<array-key, GarXmlSourceFile> $filesByRelativePath */
        $filesByRelativePath = [];
        foreach ($files as $file) {
            $key = self::relativePathKey($file->relativePath);
            if (isset($filesByRelativePath[$key])) {
                throw new InvalidArgumentException(
                    "GAR manifest contains a duplicate relative path: {$file->relativePath}",
                );
            }

            $filesByRelativePath[$key] = $file;
        }

        $this->files = $files;
        $this->filesByRelativePath = $filesByRelativePath;
        $this->hash = \hash('sha256', \implode("\n", \array_map(
            static fn(GarXmlSourceFile $file): string => $file->key,
            $this->files,
        )));
    }

    public function file(string $relativePath): GarXmlSourceFile
    {
        return $this->filesByRelativePath[self::relativePathKey($relativePath)]
            ?? throw new GarImportFailed("GAR worker received a file outside its manifest: {$relativePath}");
    }

    private static function relativePathKey(string $relativePath): string
    {
        return 'relative-path:' . $relativePath;
    }
}

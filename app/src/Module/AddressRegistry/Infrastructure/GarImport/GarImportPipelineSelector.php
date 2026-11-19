<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

final readonly class GarImportPipelineSelector
{
    public const AUTO = 'auto';

    /**
     * @param list<GarXmlSourceFile> $files
     * @return list<GarXmlSourceFile>
     */
    public function select(?string $selection, array $files): array
    {
        if ($selection === null) {
            return [];
        }
        if ($selection !== self::AUTO) {
            foreach ($files as $file) {
                if ($file->relativePath !== $selection) {
                    continue;
                }
                if (!$this->supports($file)) {
                    throw new GarImportFailed(
                        'GAR batch pipeline requires an XML file whose stage and target columns are identical.',
                    );
                }

                return [$file];
            }

            throw new GarImportFailed('GAR pipeline file is not part of the selected import manifest.');
        }

        $automatic = $this->automatic($files);
        if ($automatic === []) {
            throw new GarImportFailed('GAR automatic batch pipeline found no compatible data-phase XML files.');
        }

        return $automatic;
    }

    /**
     * @param list<GarXmlSourceFile> $files
     * @return list<GarXmlSourceFile>
     */
    public function automatic(array $files): array
    {
        $selected = \array_values(\array_filter(
            $files,
            fn(GarXmlSourceFile $file): bool => $file->descriptor->priority > 10 && $this->supports($file),
        ));
        \usort(
            $selected,
            static fn(GarXmlSourceFile $left, GarXmlSourceFile $right): int => [
                $left->descriptor->priority,
                -$left->size,
                $left->relativePath,
            ] <=> [
                $right->descriptor->priority,
                -$right->size,
                $right->relativePath,
            ],
        );

        return $selected;
    }

    public function supports(GarXmlSourceFile $file): bool
    {
        return $file->descriptor->stageColumns() === $file->descriptor->targetColumns();
    }

    /** @param non-empty-list<GarXmlSourceFile> $files */
    public function identity(array $files): string
    {
        if (\count($files) === 1) {
            return $files[0]->key;
        }

        return \hash('sha256', "multi-file-pipeline\n" . \implode("\n", \array_map(
            static fn(GarXmlSourceFile $file): string => $file->key,
            $files,
        )));
    }
}

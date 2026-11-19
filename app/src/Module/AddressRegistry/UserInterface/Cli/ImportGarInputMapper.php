<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

/** Converts untyped Symfony Console values into the validated import request. */
final class ImportGarInputMapper
{
    public static function map(InputInterface $input): ImportGar
    {
        $source = $input->getArgument('source');
        if (!\is_string($source)) {
            throw new InvalidArgumentException('GAR import source must be a string path.');
        }

        $regions = self::optionalString($input, 'regions');

        return new ImportGar(
            sourcePath: $source,
            batchSize: self::integer($input, 'batch-size'),
            workers: self::integer($input, 'workers'),
            indexWorkers: self::integer($input, 'index-workers'),
            foreignKeyWorkers: self::integer($input, 'foreign-key-workers'),
            restart: self::enabled($input, 'restart'),
            regions: $regions === null ? [] : self::regions($regions),
            initialLoad: self::enabled($input, 'initial-load'),
            directCopy: self::enabled($input, 'direct-copy'),
            pipelineFile: self::optionalString($input, 'pipeline-file'),
            pipelineWriters: self::optionalInteger($input, 'pipeline-writers'),
            pipelineParsers: self::optionalInteger($input, 'pipeline-parsers'),
            pipelineFusedRanges: self::enabled($input, 'pipeline-fused-ranges'),
            pipelineFileConcurrency: self::integer($input, 'pipeline-file-concurrency'),
            pipelineBufferMiB: self::integer($input, 'pipeline-buffer-mib'),
            pipelineFragmentMiB: self::integer($input, 'pipeline-fragment-mib'),
            pipelineTableAwareFiles: self::enabled($input, 'pipeline-table-aware-files'),
            overlapTableIndexes: self::enabled($input, 'overlap-table-indexes'),
            overlapIndexWorkers: self::integer($input, 'overlap-index-workers'),
            overlapIndexStartPercent: self::integer($input, 'overlap-index-start-percent'),
            persistentIndexWorkers: self::enabled($input, 'persistent-index-workers'),
            deferPrimaryKeys: self::enabled($input, 'defer-primary-keys'),
            deferForeignKeys: self::enabled($input, 'defer-foreign-keys'),
            overlapIndexForeignKeys: self::enabled($input, 'overlap-index-foreign-keys'),
        );
    }

    private static function integer(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || \preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException("GAR option --{$name} must be an unsigned integer.");
        }

        return (int) $value;
    }

    private static function optionalInteger(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);

        return $value === null ? null : self::integer($input, $name);
    }

    private static function optionalString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value !== null && !\is_string($value)) {
            throw new InvalidArgumentException("GAR option --{$name} must be a string.");
        }

        return $value;
    }

    private static function enabled(InputInterface $input, string $name): bool
    {
        return $input->getOption($name) === true;
    }

    /** @return non-empty-list<int> */
    private static function regions(string $value): array
    {
        $regions = [];
        foreach (\explode(',', $value) as $part) {
            $part = \trim($part);
            if (\preg_match('/^[0-9]{1,2}$/D', $part) !== 1 || (int) $part < 1 || (int) $part > 99) {
                throw new InvalidArgumentException('Option --regions accepts unique codes from 1 to 99.');
            }
            $region = (int) $part;
            if (isset($regions[$region])) {
                throw new InvalidArgumentException('Option --regions accepts unique codes from 1 to 99.');
            }
            $regions[$region] = $region;
        }
        \ksort($regions);

        return \array_values($regions);
    }

    private function __construct() {}
}

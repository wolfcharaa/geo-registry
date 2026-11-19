<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRaw;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

/** Converts untyped raw-only CLI values into the validated capability request. */
final class ImportGarRawInputMapper
{
    public static function map(InputInterface $input): ImportGarRaw
    {
        $source = $input->getArgument('source');
        if (!\is_string($source)) {
            throw new InvalidArgumentException('GAR raw import source must be a string path.');
        }
        $pipelineFile = $input->getOption('pipeline-file');
        if (!\is_string($pipelineFile)) {
            throw new InvalidArgumentException('GAR raw option --pipeline-file must be a string.');
        }
        $regions = $input->getOption('regions');
        if ($regions !== null && !\is_string($regions)) {
            throw new InvalidArgumentException('GAR raw option --regions must be a string.');
        }

        return new ImportGarRaw(
            sourcePath: $source,
            batchSize: self::integer($input, 'batch-size'),
            primaryWorkers: self::integer($input, 'primary-workers'),
            childWorkersPerPrimary: self::integer($input, 'child-workers-per-primary'),
            regions: $regions === null ? [] : self::regions($regions),
            pipelineFile: $pipelineFile,
            fragmentMiB: self::integer($input, 'fragment-mib'),
            deferPrimaryKeys: $input->getOption('defer-primary-keys') === true,
            deferForeignKeys: $input->getOption('defer-foreign-keys') === true,
        );
    }

    private static function integer(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || \preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException("GAR raw option --{$name} must be an unsigned integer.");
        }

        return (int) $value;
    }

    /** @return non-empty-list<int> */
    private static function regions(string $value): array
    {
        $regions = [];
        foreach (\explode(',', $value) as $part) {
            $part = \trim($part);
            if (\preg_match('/^[0-9]{1,2}$/D', $part) !== 1 || (int) $part < 1 || (int) $part > 99) {
                throw new InvalidArgumentException(
                    'GAR raw option --regions accepts unique codes from 1 to 99.',
                );
            }
            $region = (int) $part;
            if (isset($regions[$region])) {
                throw new InvalidArgumentException(
                    'GAR raw option --regions accepts unique codes from 1 to 99.',
                );
            }
            $regions[$region] = $region;
        }
        \ksort($regions);

        return \array_values($regions);
    }

    private function __construct() {}
}

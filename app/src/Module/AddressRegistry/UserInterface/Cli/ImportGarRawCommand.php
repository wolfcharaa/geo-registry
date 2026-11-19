<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawFailed;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawPort;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Experimental raw-only entry used to compare work-orchestrator with production control. */
final class ImportGarRawCommand extends Command
{
    public function __construct(private readonly ImportGarRawPort $importer)
    {
        parent::__construct('gar:import:raw');
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Run the experimental raw-only GAR work-orchestrator path without index/FK finalization.',
            )
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Path to one supported GAR XML file or a directory containing a GAR release.',
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Rows used to plan bounded XML ranges (1..100000).',
                '50000',
            )
            ->addOption(
                'primary-workers',
                null,
                InputOption::VALUE_REQUIRED,
                'Reusable primary file workers; topology must fit the 16-process budget.',
                '1',
            )
            ->addOption(
                'child-workers-per-primary',
                null,
                InputOption::VALUE_REQUIRED,
                'Reusable fused-range children per primary (1..8).',
                '8',
            )
            ->addOption(
                'regions',
                null,
                InputOption::VALUE_REQUIRED,
                'Import shared dictionaries and only these regional directories (comma-separated codes 1..99).',
            )
            ->addOption(
                'pipeline-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Compatible manifest-relative XML or auto for every compatible data-phase file.',
                'auto',
            )
            ->addOption(
                'fragment-mib',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum raw and encoded bytes per managed range task (8..16 MiB).',
                '8',
            )
            ->addOption(
                'defer-primary-keys',
                null,
                InputOption::VALUE_NONE,
                'Drop and snapshot canonical primary keys before the raw stage.',
            )
            ->addOption(
                'defer-foreign-keys',
                null,
                InputOption::VALUE_NONE,
                'Drop and snapshot canonical foreign keys before the raw stage.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $command = ImportGarRawInputMapper::map($input);
            $result = $this->importer->import($command);
        } catch (InvalidArgumentException|ImportGarRawFailed $failure) {
            $output->writeln('<error>' . OutputFormatter::escape($failure->getMessage()) . '</error>');
            if ($failure instanceof ImportGarRawFailed && $failure->diagnostics !== []) {
                $output->writeln(OutputFormatter::escape(
                    ImportGarRawDiagnosticFormatter::format($failure->diagnostics),
                ));
            }

            return self::FAILURE;
        }

        $output->writeln(ImportGarRawResultFormatter::format($command, $result));
        if ($result->diagnostics !== []) {
            $output->writeln(OutputFormatter::escape(
                ImportGarRawDiagnosticFormatter::format($result->diagnostics),
            ));
        }

        return self::SUCCESS;
    }
}

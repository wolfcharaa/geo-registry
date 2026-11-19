<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarPort;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportGarCommand extends Command
{
    public function __construct(
        private readonly ImportGarPort $importer,
    ) {
        parent::__construct('gar:import');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Import supported GAR XML files through bounded PostgreSQL COPY batches.')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Path to one supported GAR XML file or a directory containing a GAR release.',
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Rows per atomic COPY/merge/checkpoint batch (1..100000).',
                '50000',
            )
            ->addOption(
                'workers',
                null,
                InputOption::VALUE_REQUIRED,
                'Parallel GAR file worker processes per dependency phase (1..16).',
                '2',
            )
            ->addOption(
                'index-workers',
                null,
                InputOption::VALUE_REQUIRED,
                'Parallel initial-load index build processes (1..4).',
                '2',
            )
            ->addOption(
                'foreign-key-workers',
                null,
                InputOption::VALUE_REQUIRED,
                'Parallel deferred foreign-key validation processes (1..4).',
                '2',
            )
            ->addOption(
                'regions',
                null,
                InputOption::VALUE_REQUIRED,
                'Import shared dictionaries and only these regional directories (comma-separated codes 1..99).',
            )
            ->addOption(
                'restart',
                null,
                InputOption::VALUE_NONE,
                'Abandon an unfinished checkpoint for this source and start a new operation.',
            )
            ->addOption(
                'initial-load',
                null,
                InputOption::VALUE_NONE,
                'For an empty dedicated database: rebuild secondary indexes after import.',
            )
            ->addOption(
                'direct-copy',
                null,
                InputOption::VALUE_NONE,
                'With --initial-load: COPY batches directly into empty canonical tables without staging/upsert.',
            )
            ->addOption(
                'pipeline-file',
                null,
                InputOption::VALUE_REQUIRED,
                'With direct initial-load: compatible manifest-relative XML or auto for every compatible data-phase file.',
            )
            ->addOption(
                'pipeline-writers',
                null,
                InputOption::VALUE_REQUIRED,
                'With --pipeline-file: maximum batch writers within the global --workers budget (1..4).',
            )
            ->addOption(
                'pipeline-parsers',
                null,
                InputOption::VALUE_REQUIRED,
                'With --pipeline-file: XML range parsers sharing the global --workers budget (1..4; 1..8 with --pipeline-fused-ranges).',
            )
            ->addOption(
                'pipeline-fused-ranges',
                null,
                InputOption::VALUE_NONE,
                'Keep each parsed XML range in its worker through COPY; permits up to 8 range parsers.',
            )
            ->addOption(
                'pipeline-file-concurrency',
                null,
                InputOption::VALUE_REQUIRED,
                'With fused ranges: files from the same dependency phase sharing one parser/COPY budget (1..2).',
                '1',
            )
            ->addOption(
                'pipeline-buffer-mib',
                null,
                InputOption::VALUE_REQUIRED,
                'With fused ranges: shared encoded in-flight COPY window in MiB (16..64).',
                '16',
            )
            ->addOption(
                'pipeline-fragment-mib',
                null,
                InputOption::VALUE_REQUIRED,
                'With fused ranges: maximum raw and encoded bytes per range fragment in MiB (8..16).',
                '8',
            )
            ->addOption(
                'pipeline-table-aware-files',
                null,
                InputOption::VALUE_NONE,
                'With concurrent fused files: finish canonical table groups sequentially while overlapping siblings.',
            )
            ->addOption(
                'overlap-table-indexes',
                null,
                InputOption::VALUE_NONE,
                'Restore indexes for completed tables while other tables are still importing.',
            )
            ->addOption(
                'overlap-index-workers',
                null,
                InputOption::VALUE_REQUIRED,
                'With --overlap-table-indexes: early index workers during raw import (1..4; default 1).',
                '1',
            )
            ->addOption(
                'overlap-index-start-percent',
                null,
                InputOption::VALUE_REQUIRED,
                'With --overlap-table-indexes: wait for this percentage of source bytes to complete (0..99).',
                '0',
            )
            ->addOption(
                'persistent-index-workers',
                null,
                InputOption::VALUE_NONE,
                'Reuse bounded index worker processes and PostgreSQL connections across index snapshots.',
            )
            ->addOption(
                'defer-primary-keys',
                null,
                InputOption::VALUE_NONE,
                'With direct initial-load: bulk-build canonical primary keys after their table data is loaded.',
            )
            ->addOption(
                'defer-foreign-keys',
                null,
                InputOption::VALUE_NONE,
                'With direct initial-load: restore and validate canonical foreign keys after bulk loading.',
            )
            ->addOption(
                'overlap-index-foreign-keys',
                null,
                InputOption::VALUE_NONE,
                'With deferred keys and early indexes: overlap post-raw index builds with foreign-key validation.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $command = ImportGarInputMapper::map($input);
            $result = $this->importer->import($command);
        } catch (InvalidArgumentException|GarImportFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(ImportGarResultFormatter::format($command, $result));

        return self::SUCCESS;
    }
}

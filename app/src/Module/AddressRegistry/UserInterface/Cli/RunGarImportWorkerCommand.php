<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use App\Module\AddressRegistry\Capability\ImportGar\GarImportWorkerFile;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarImportWorker;
use App\Module\AddressRegistry\Capability\ImportGar\RunGarImportWorkerRunner;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RunGarImportWorkerCommand extends Command
{
    public function __construct(
        private readonly RunGarImportWorkerRunner $runner,
    ) {
        parent::__construct('gar:import:worker');
        $this->setHidden(true);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run one internal GAR XML import worker assignment.')
            ->addArgument('operation', InputArgument::REQUIRED, 'GAR import operation id.')
            ->addArgument('source', InputArgument::REQUIRED, 'Canonical GAR source path.')
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Rows per atomic COPY/merge/checkpoint batch.',
                '50000',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $operation = $input->getArgument('operation');
            $source = $input->getArgument('source');
            $batchSize = $input->getOption('batch-size');
            if (
                !is_string($operation)
                || !is_string($source)
                || !is_string($batchSize)
                || preg_match('/^[0-9]+$/D', $operation) !== 1
                || preg_match('/^[0-9]+$/D', $batchSize) !== 1
            ) {
                throw new InvalidArgumentException('GAR import worker arguments must have valid scalar values.');
            }

            $payload = stream_get_contents(STDIN);
            if (!is_string($payload)) {
                throw new InvalidArgumentException('Unable to read the GAR import worker assignment.');
            }
            $this->runner->run(new RunGarImportWorker(
                operationId: (int) $operation,
                sourcePath: $source,
                batchSize: (int) $batchSize,
                files: $this->files($payload),
            ));
        } catch (InvalidArgumentException|JsonException|GarImportFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return non-empty-list<GarImportWorkerFile>
     * @throws JsonException
     */
    private function files(string $payload): array
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || $decoded === []) {
            throw new InvalidArgumentException('GAR import worker assignment must be a non-empty JSON list.');
        }

        $files = [];
        foreach ($decoded as $item) {
            if (
                !is_array($item)
                || !is_string($item['relative_path'] ?? null)
                || !is_string($item['file_key'] ?? null)
            ) {
                throw new InvalidArgumentException('GAR import worker assignment contains an invalid file.');
            }
            $files[] = new GarImportWorkerFile(
                relativePath: $item['relative_path'],
                fileKey: $item['file_key'],
            );
        }

        return $files;
    }
}

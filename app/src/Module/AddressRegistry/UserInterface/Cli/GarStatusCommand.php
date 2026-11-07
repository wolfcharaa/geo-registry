<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\Status\GarRegionStatus;
use App\Module\AddressRegistry\Capability\Status\GarStatusReader;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'gar:status',
    description: 'Показывает manifest, checkpoints и региональное покрытие GAR.',
)]
final class GarStatusCommand extends Command
{
    public function __construct(private readonly GarStatusReader $reader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('regions', null, InputOption::VALUE_REQUIRED, 'Коды регионов через запятую.', '50,77')
            ->addOption(
                'exact',
                null,
                InputOption::VALUE_NONE,
                'Посчитать точные projection/Data.Mos/alias/geocode coverage (может быть дорого).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $regions = $input->getOption('regions');
        if (!is_string($regions)) {
            $output->writeln('<error>Option --regions must be a comma-separated list.</error>');

            return self::INVALID;
        }

        try {
            $regions = self::regions($regions);
        } catch (InvalidArgumentException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::INVALID;
        }

        try {
            $snapshot = $this->reader->read($regions, $input->getOption('exact') === true);
        } catch (RuntimeException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'gar_version=%s gar_version_date=%s exact=%s',
            $snapshot->garVersion ?? 'unknown',
            $snapshot->garVersionDate ?? 'unknown',
            $snapshot->exact ? 'yes' : 'no',
        ));
        $import = $snapshot->import;
        $output->writeln($import === null ? 'import=missing' : sprintf(
            'import=%s operation=%d source=%s manifest=%s files=%d/%d file_states=%s rows=%d merged=%d updated_at=%s',
            $import->status,
            $import->operationId,
            $import->sourcePath,
            $import->manifestHash,
            $import->filesCompleted,
            $import->filesTotal,
            sprintf(
                'pending:%d,running:%d,completed:%d,failed:%d',
                $import->pendingFiles,
                $import->runningFiles,
                $import->completedFiles,
                $import->failedFiles,
            ),
            $import->rowsProcessed,
            $import->rowsMerged,
            $import->updatedAt,
        ));
        $rebuild = $snapshot->rebuild;
        $output->writeln($rebuild === null ? 'rebuild=missing' : sprintf(
            'rebuild=%s phase=%s operation=%d tasks=%d/%d scanned=%d written=%d rows=%d updated_at=%s',
            $rebuild->status,
            $rebuild->phase,
            $rebuild->operationId,
            $rebuild->tasksCompleted,
            $rebuild->tasksTotal,
            $rebuild->rowsScanned,
            $rebuild->rowsWritten,
            $rebuild->projectionRows,
            $rebuild->updatedAt,
        ));
        foreach ($snapshot->regions as $region) {
            $output->writeln(self::regionLine($region));
        }

        return self::SUCCESS;
    }

    /** @return non-empty-list<int> */
    private static function regions(string $value): array
    {
        $regions = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if (preg_match('/^[0-9]{1,2}$/D', $part) !== 1 || (int) $part < 1 || (int) $part > 99) {
                throw new InvalidArgumentException('Option --regions accepts unique codes from 1 to 99.');
            }
            $regions[(int) $part] = (int) $part;
        }
        ksort($regions);

        return array_values($regions);
    }

    private static function regionLine(GarRegionStatus $region): string
    {
        return sprintf(
            'region=%d hierarchy=%s checkpoint=%s checkpoint_rows=%d projection_rows=%s geocoded=%s datamos=%s aliases=%s',
            $region->regionCode,
            $region->hierarchy,
            $region->checkpointStatus,
            $region->checkpointRows,
            self::optionalInteger($region->projectionRows),
            self::optionalInteger($region->geocodedRows),
            self::optionalInteger($region->dataMosRows),
            self::optionalInteger($region->aliasRows),
        );
    }

    private static function optionalInteger(?int $value): string
    {
        return $value === null ? 'not-scanned' : (string) $value;
    }
}

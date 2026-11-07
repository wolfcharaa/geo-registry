<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\UserInterface\Cli;

use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\DataMosSnapshotDownloader;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'datamos:address-registry:download',
    description: 'Скачивает воспроизводимый JSONL snapshot адресного реестра из официального API Data.Mos.',
)]
final class DownloadDataMosAddressRegistryCommand extends Command
{
    public function __construct(
        private readonly DataMosSnapshotDownloader $downloader,
        private readonly string $defaultDestination,
        private readonly ?string $defaultApiKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('destination', InputArgument::OPTIONAL, 'Выходной .jsonl или .jsonl.gz файл.', $this->defaultDestination)
            ->addOption('dataset-id', null, InputOption::VALUE_REQUIRED, 'ID официального набора Data.Mos.', (string) DataMosSnapshotDownloader::DEFAULT_DATASET_ID)
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Строк в HTTP-странице; 1..1000.', '500')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Ограничить snapshot для smoke-проверки.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout одного API-запроса; 1..120 секунд.', '30')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Атомарно заменить существующие snapshot и manifest.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Проверить API key, version и count без записи файла.')
            ->setHelp(<<<'HELP'
API key читается только из DATAMOS_API_KEY и не записывается в manifest или output.
Полный snapshot сопровождается <destination>.manifest.json с version, release,
датой выгрузки, числом строк, размером и SHA-256.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $destination = $input->getArgument('destination');
            if (!is_string($destination) || trim($destination) === '') {
                throw new InvalidArgumentException('Argument destination must be a non-empty path.');
            }
            $apiKey = $this->defaultApiKey;
            if ($apiKey === null) {
                throw new InvalidArgumentException('DATAMOS_API_KEY is required for the official Data.Mos API.');
            }
            $datasetId = self::integerOption($input, 'dataset-id', 1, PHP_INT_MAX);
            $timeout = self::integerOption($input, 'timeout', 1, 120);

            if ($input->getOption('dry-run') === true) {
                $metadata = $this->downloader->inspect($apiKey, $datasetId, $timeout);
                $output->writeln(sprintf(
                    'status=available dataset=%d version=%s release=%s rows=%d dry_run=yes',
                    $metadata->datasetId,
                    $metadata->versionNumber ?? 'unknown',
                    $metadata->releaseNumber ?? 'unknown',
                    $metadata->rows,
                ));

                return self::SUCCESS;
            }

            $lastProgressAt = microtime(true);
            $result = $this->downloader->download(
                destination: $destination,
                apiKey: $apiKey,
                datasetId: $datasetId,
                pageSize: self::integerOption($input, 'page-size', 1, 1000),
                limit: self::nullableIntegerOption($input, 'limit'),
                timeoutSeconds: $timeout,
                force: $input->getOption('force') === true,
                progress: static function (int $downloadedRows, int $advertisedRows) use ($output, &$lastProgressAt): void {
                    if (microtime(true) - $lastProgressAt < 5.0) {
                        return;
                    }
                    $output->writeln(sprintf(
                        'phase=download rows=%d advertised_rows=%d',
                        $downloadedRows,
                        $advertisedRows,
                    ));
                    $lastProgressAt = microtime(true);
                },
            );
            $output->writeln(sprintf(
                'status=downloaded dataset=%d version=%s release=%s rows=%d bytes=%d sha256=%s path=%s manifest=%s',
                $result->dataset->datasetId,
                $result->dataset->versionNumber ?? 'unknown',
                $result->dataset->releaseNumber ?? 'unknown',
                $result->rows,
                $result->bytes,
                $result->sha256,
                $result->path,
                $result->manifestPath,
            ));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }
    }

    private static function integerOption(InputInterface $input, string $name, int $minimum, int $maximum): int
    {
        $value = self::nullableIntegerOption($input, $name);
        if ($value === null || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Option --{$name} must be between {$minimum} and {$maximum}.");
        }

        return $value;
    }

    private static function nullableIntegerOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException("Option --{$name} must be a positive integer.");
        }

        return (int) $value;
    }
}

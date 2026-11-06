<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\DataMos\UserInterface\Cli;

use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\DataMosAddressRegistryEnricher;
use App\Module\AddressRegistry\Feature\DataMos\Infrastructure\DataMosAddressRegistryImporter;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'datamos:address-registry:import',
    description: 'Загружает адресный реестр Москвы Data.Mos и опционально обогащает address_search.',
)]
final class ImportDataMosAddressRegistryCommand extends Command
{
    public function __construct(
        private readonly DataMosAddressRegistryImporter $importer,
        private readonly DataMosAddressRegistryEnricher $enricher,
        private readonly string $defaultSource,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'source',
                InputArgument::OPTIONAL,
                'BSON.GZ/BSON/JSON/JSONL файл или HTTP URL.',
                $this->defaultSource,
            )
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Строк в одной транзакции; 1..50000.', '1000')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Размер HTTP-страницы; 1..10000.', '1000')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум исходных строк для smoke-проверки.')
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Очистить staging-таблицу перед загрузкой.')
            ->addOption('enrich', null, InputOption::VALUE_NONE, 'После загрузки применить Data.Mos к address_search.')
            ->addOption(
                'no-insert-missing',
                null,
                InputOption::VALUE_NONE,
                'При --enrich не создавать datamos-only строки.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Проверить чтение и нормализацию без записи.')
            ->setHelp(<<<'HELP'
Команда потоково читает выгрузку Data.Mos и делает идемпотентный upsert в
datamos_address_registry. Передайте --enrich, чтобы связать записи по N_FIAS
с московской partition address_search и добавить отсутствующие datamos-only адреса.

После изменения данных пересоберите OpenSearch projection командой:
  bin/app opensearch:reindex --batch-size=10000 --concurrency=8
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $source = $input->getArgument('source');
            if (!is_string($source) || trim($source) === '') {
                throw new InvalidArgumentException('Argument source must be a non-empty path or URL.');
            }

            $lastProgressAt = microtime(true);
            $result = $this->importer->import(
                source: $source,
                batchSize: self::integerOption($input, 'batch-size', 1, 50000),
                pageSize: self::integerOption($input, 'page-size', 1, 10000),
                limit: self::nullableIntegerOption($input, 'limit'),
                truncate: $input->getOption('truncate') === true,
                dryRun: $input->getOption('dry-run') === true,
                progress: static function (
                    int $readRows,
                    int $writtenRows,
                    int $skippedRows,
                ) use ($output, &$lastProgressAt): void {
                    if (microtime(true) - $lastProgressAt < 5.0) {
                        return;
                    }

                    $output->writeln(sprintf(
                        'phase=import read=%d written=%d skipped=%d',
                        $readRows,
                        $writtenRows,
                        $skippedRows,
                    ));
                    $lastProgressAt = microtime(true);
                },
            );

            $output->writeln(sprintf(
                'status=imported source=%s read=%d written=%d skipped=%d elapsed_ms=%d dry_run=%s',
                $result->source,
                $result->readRows,
                $result->writtenRows,
                $result->skippedRows,
                (int) round($result->duration * 1000),
                $result->dryRun ? 'yes' : 'no',
            ));

            if ($input->getOption('enrich') !== true || $result->dryRun) {
                return self::SUCCESS;
            }

            $enrichment = $this->enricher->enrich($input->getOption('no-insert-missing') !== true);
            $output->writeln(sprintf(
                'status=enriched updated=%d inserted=%d elapsed_ms=%d',
                $enrichment->updatedSearchRows,
                $enrichment->insertedSearchRows,
                (int) round($enrichment->duration * 1000),
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
        if (!is_string($value) || !ctype_digit($value)) {
            throw new InvalidArgumentException("Option --{$name} must be a positive integer.");
        }

        $integer = (int) $value;
        if ($integer < 1) {
            throw new InvalidArgumentException("Option --{$name} must be a positive integer.");
        }

        return $integer;
    }
}

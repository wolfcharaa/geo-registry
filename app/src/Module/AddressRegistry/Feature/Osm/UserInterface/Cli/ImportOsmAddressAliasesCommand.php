<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\Osm\UserInterface\Cli;

use App\Module\AddressRegistry\Feature\Osm\Infrastructure\OsmAddressAliasImporter;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'osm:address-aliases:import',
    description: 'Привязывает OSM addr2:* алиасы к московским адресам.',
)]
final class ImportOsmAddressAliasesCommand extends Command
{
    public function __construct(
        private readonly OsmAddressAliasImporter $importer,
        private readonly string $defaultSource,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'JSONL/JSONL.GZ файл OSM aliases.', $this->defaultSource)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Alias-строк в транзакции; 1..50000.', '1000')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимум исходных OSM-строк.')
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Очистить address_aliases перед загрузкой.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Проверить парсинг и matching без записи.')
            ->setHelp(<<<'HELP'
Команда не создаёт новые адреса: основной addr:* сопоставляется с домом региона
Москвы в address_search, после чего варианты addr2:* записываются как aliases.
После импорта требуется повторный opensearch:reindex.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $source = $input->getArgument('source');
            if (!is_string($source) || trim($source) === '') {
                throw new InvalidArgumentException('Argument source must be a non-empty path.');
            }

            $lastProgressAt = microtime(true);
            $result = $this->importer->import(
                source: $source,
                batchSize: self::integerOption($input, 'batch-size', 1000, 50000),
                limit: self::nullableIntegerOption($input, 'limit'),
                truncate: $input->getOption('truncate') === true,
                dryRun: $input->getOption('dry-run') === true,
                progress: static function (
                    int $readRows,
                    int $matchedRows,
                    int $writtenRows,
                    int $skippedRows,
                ) use ($output, &$lastProgressAt): void {
                    if (microtime(true) - $lastProgressAt < 5.0) {
                        return;
                    }

                    $output->writeln(sprintf(
                        'phase=import read=%d matched=%d written=%d skipped=%d',
                        $readRows,
                        $matchedRows,
                        $writtenRows,
                        $skippedRows,
                    ));
                    $lastProgressAt = microtime(true);
                },
            );
        } catch (Throwable $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'status=imported source=%s read=%d matched=%d written=%d skipped=%d elapsed_ms=%d dry_run=%s',
            $result->source,
            $result->readRows,
            $result->matchedRows,
            $result->writtenRows,
            $result->skippedRows,
            (int) round($result->duration * 1000),
            $result->dryRun ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }

    private static function integerOption(InputInterface $input, string $name, int $default, int $maximum): int
    {
        $value = self::nullableIntegerOption($input, $name) ?? $default;
        if ($value > $maximum) {
            throw new InvalidArgumentException("Option --{$name} must be between 1 and {$maximum}.");
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

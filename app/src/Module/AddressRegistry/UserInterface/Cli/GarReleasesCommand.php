<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\Releases\GarReleaseMetadata;
use App\Module\AddressRegistry\Capability\Releases\GarReleaseMetadataReader;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'gar:releases',
    description: 'Показывает optional download metadata официальных GAR releases.',
)]
final class GarReleasesCommand extends Command
{
    public function __construct(private readonly GarReleaseMetadataReader $reader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Запросить список releases вместо последнего.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Показать 1..50 releases.', '5')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Переопределить metadata endpoint URL.')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout 1..60 секунд.', '30')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = $input->getOption('all') === true;
        try {
            $limit = self::integer($input, 'limit', 1, 50);
            $timeout = self::integer($input, 'timeout', 1, 60);
            $url = self::optionalString($input, 'url');
            $releases = $all
                ? $this->reader->all($url, $timeout)
                : [$this->reader->latest($url, $timeout)];
        } catch (RuntimeException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        $releases = array_slice($releases, 0, $limit);
        if ($input->getOption('json') === true) {
            return self::writeJson($output, $releases);
        }

        $output->writeln('endpoint=' . ($url ?? $this->reader->endpoint($all)));
        $output->writeln('releases=' . count($releases));
        foreach ($releases as $release) {
            $output->writeln(sprintf(
                'release version=%d date=%s text="%s" full=%s delta=%s',
                $release->versionId,
                $release->date ?? 'unknown',
                self::quoted($release->textVersion ?? 'unknown'),
                $release->fullXmlUrl ?? 'missing',
                $release->deltaXmlUrl ?? 'missing',
            ));
        }

        return self::SUCCESS;
    }

    /** @param list<GarReleaseMetadata> $releases */
    private static function writeJson(OutputInterface $output, array $releases): int
    {
        try {
            $output->writeln(json_encode(
                array_map(
                    static fn(GarReleaseMetadata $release): array => $release->toArray(),
                    $releases,
                ),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        } catch (JsonException $error) {
            $output->writeln('<error>Unable to encode GAR release metadata: ' . $error->getMessage() . '</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private static function integer(InputInterface $input, string $name, int $minimum, int $maximum): int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !ctype_digit($value) || (int) $value < $minimum || (int) $value > $maximum) {
            throw new RuntimeException("Option --{$name} must be an integer from {$minimum} to {$maximum}.");
        }

        return (int) $value;
    }

    private static function optionalString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("Option --{$name} must not be empty.");
        }

        return trim($value);
    }

    private static function quoted(string $value): string
    {
        return str_replace(
            ["\\", '"', "\r", "\n"],
            ["\\\\", '\\"', '\\r', '\\n'],
            $value,
        );
    }
}

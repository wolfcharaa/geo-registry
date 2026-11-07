<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarPort;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarResult;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarRunner;
use App\Module\AddressRegistry\UserInterface\Cli\ImportGarCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ImportGarCommandTest
{
    public function testItNormalizesAndPassesAnExplicitRegionSelection(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run($importer, '77,50');

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::same($importer->command->regions, [50, 77]);
        Assert::true(str_contains($output, 'regions=50,77'));
    }

    public function testItRejectsDuplicateRegionsBeforeCallingTheImporter(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run($importer, '77,77');

        Assert::same($exitCode, 1);
        Assert::null($importer->command);
        Assert::true(str_contains($output, 'unique codes from 1 to 99'));
    }

    /** @return array{int, string} */
    private static function run(RecordingGarImporter $importer, string $regions): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new ImportGarCommand(new ImportGarRunner($importer)));
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput([
            'command' => 'gar:import',
            'source' => '/data/gar',
            '--regions' => $regions,
            '--no-ansi' => true,
        ]), $output);

        return [$exitCode, $output->fetch()];
    }
}

final class RecordingGarImporter implements ImportGarPort
{
    public ?ImportGar $command = null;

    public function import(ImportGar $command): ImportGarResult
    {
        $this->command = $command;

        return new ImportGarResult(
            operationId: 7,
            filesTotal: 3,
            filesCompleted: 3,
            rowsProcessed: 10,
            rowsMerged: 10,
            resumed: false,
            alreadyCompleted: false,
        );
    }
}

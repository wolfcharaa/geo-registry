<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRaw;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawDiagnostic;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawFailed;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawPort;
use App\Module\AddressRegistry\Capability\ImportGarRaw\ImportGarRawResult;
use App\Module\AddressRegistry\UserInterface\Cli\ImportGarRawCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ImportGarRawCommandTest
{
    public function testItMapsTheRawTopologyAndPrintsAnExplicitBoundary(): void
    {
        $importer = new RecordingGarRawImporter();
        [$exitCode, $output] = self::run($importer, [
            '--regions' => '77,50',
            '--defer-primary-keys' => true,
            '--defer-foreign-keys' => true,
        ]);

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::same($importer->command->regions, [50, 77]);
        Assert::same($importer->command->primaryWorkers, 1);
        Assert::same($importer->command->childWorkersPerPrimary, 8);
        Assert::true($importer->command->deferPrimaryKeys);
        Assert::true($importer->command->deferForeignKeys);
        Assert::true(\str_contains($output, 'status=raw-completed boundary=raw'));
        Assert::true(\str_contains($output, 'process_budget=9'));
        Assert::true(\str_contains($output, 'regions=50,77'));
    }

    public function testItRejectsAnUnsafeTopologyBeforeCallingThePort(): void
    {
        $importer = new RecordingGarRawImporter();
        [$exitCode, $output] = self::run($importer, [
            '--primary-workers' => '6',
            '--child-workers-per-primary' => '2',
        ]);

        Assert::same($exitCode, 1);
        Assert::null($importer->command);
        Assert::true(\str_contains($output, '16-process topology budget'));
    }

    public function testItPrintsBoundedStructuredFailureEvidence(): void
    {
        [$exitCode, $output] = self::run(new FailingGarRawImporter(), []);

        Assert::same($exitCode, 1);
        Assert::true(\str_contains($output, 'managed range failed'));
        Assert::true(\str_contains($output, 'diagnostic[0]={'));
        Assert::true(\str_contains($output, '"kind":"application"'));
        Assert::true(\str_contains($output, '"role":"child"'));
        Assert::true(\str_contains($output, '"work_id":"/data/gar/AS_REESTR_OBJECTS.XML"'));
        Assert::true(\str_contains($output, '"omitted_frames":2'));
    }

    /**
     * @param array<string, bool|string> $options
     * @return array{int, string}
     */
    private static function run(ImportGarRawPort $importer, array $options): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new ImportGarRawCommand($importer));
        $output = new BufferedOutput();
        $input = new ArrayInput([
            'command' => 'gar:import:raw',
            'source' => '/data/gar',
            '--no-ansi' => true,
            ...$options,
        ]);

        return [$application->run($input, $output), $output->fetch()];
    }
}

final class FailingGarRawImporter implements ImportGarRawPort
{
    public function import(ImportGarRaw $command): ImportGarRawResult
    {
        throw new ImportGarRawFailed('managed range failed', [
            new ImportGarRawDiagnostic(
                kind: 'application',
                role: 'child',
                executor: 'parallel',
                processId: 321,
                workId: '/data/gar/AS_REESTR_OBJECTS.XML',
                taskId: 'range-7',
                attempt: 2,
                type: \RuntimeException::class,
                message: 'malformed XML',
                code: 0,
                file: '/app/src/GarRawRangeTaskHandler.php',
                line: 40,
                frames: [],
                causes: [],
                omittedFrames: 2,
                omittedCauses: 0,
                messageTruncated: false,
            ),
        ]);
    }
}

final class RecordingGarRawImporter implements ImportGarRawPort
{
    public ?ImportGarRaw $command = null;

    public function import(ImportGarRaw $command): ImportGarRawResult
    {
        $this->command = $command;

        return new ImportGarRawResult(
            operationId: 7,
            filesTotal: 3,
            filesCompleted: 3,
            rowsProcessed: 10,
            rowsMerged: 10,
            resumed: false,
            alreadyCompleted: false,
            durablePhase: 'import',
            pipelineFile: 'auto:2',
            pipelineBatches: 4,
            pipelineBytes: 1024,
            rangeTasks: 4,
            managedTaskRequestBytes: 400,
            managedTaskResultBytes: 200,
            indexesDeferred: 10,
            foreignKeysDeferred: 5,
            deferPrimaryKeys: $command->deferPrimaryKeys,
            deferForeignKeys: $command->deferForeignKeys,
            rawSeconds: 1.25,
        );
    }
}

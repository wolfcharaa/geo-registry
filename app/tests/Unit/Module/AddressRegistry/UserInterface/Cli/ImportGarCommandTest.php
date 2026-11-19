<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarPort;
use App\Module\AddressRegistry\Capability\ImportGar\ImportGarResult;
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
        Assert::true(\str_contains($output, 'regions=50,77'));
    }

    public function testItRejectsDuplicateRegionsBeforeCallingTheImporter(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run($importer, '77,77');

        Assert::same($exitCode, 1);
        Assert::null($importer->command);
        Assert::true(\str_contains($output, 'unique codes from 1 to 99'));
    }

    public function testItPassesTheInitialLoadModeToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode] = self::run($importer, '50', true, true);

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::same($importer->command->initialLoad, true);
        Assert::same($importer->command->directCopy, true);
    }

    public function testItPassesTheIndexWorkerCountToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run($importer, '50', true, indexWorkers: 3);

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::same($importer->command->indexWorkers, 3);
        Assert::true(\str_contains($output, 'index_workers=3'));
    }

    public function testItPassesThePipelineSelectionToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            true,
            '50/AS_HOUSES_PARAMS.XML',
            2,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::same($importer->command->pipelineFile, '50/AS_HOUSES_PARAMS.XML');
        Assert::same($importer->command->pipelineWriters, 2);
        Assert::true(\str_contains($output, 'pipeline=50/AS_HOUSES_PARAMS.XML pipeline_writers=2'));
    }

    public function testItPassesDeferredForeignKeysToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            true,
            deferForeignKeys: true,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::true($importer->command->deferForeignKeys);
        Assert::true(\str_contains($output, 'foreign_keys=deferred'));
    }

    public function testItPassesDeferredPrimaryKeysToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            true,
            deferPrimaryKeys: true,
            deferForeignKeys: true,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::true($importer->command->deferPrimaryKeys);
        Assert::true(\str_contains($output, 'primary_keys=deferred'));
    }

    public function testItPassesForeignKeyWorkerCountToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            true,
            deferForeignKeys: true,
            foreignKeyWorkers: 4,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::same($importer->command->foreignKeyWorkers, 4);
        Assert::true(\str_contains($output, 'foreign_key_workers=4'));
    }

    public function testItPassesParallelRangeParsersToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            true,
            '50/AS_HOUSES_PARAMS.XML',
            4,
            pipelineParsers: 4,
            workers: 8,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::same($importer->command->pipelineParsers, 4);
        Assert::true(\str_contains($output, 'pipeline_parsers=4'));
    }

    public function testItPassesFusedRangeWorkersToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            true,
            '50/AS_HOUSES_PARAMS.XML',
            4,
            pipelineParsers: 8,
            pipelineFusedRanges: true,
            pipelineFileConcurrency: 2,
            pipelineBufferMiB: 32,
            pipelineFragmentMiB: 16,
            pipelineTableAwareFiles: true,
            workers: 12,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::same($importer->command->pipelineFusedRanges, true);
        Assert::same($importer->command->pipelineFileConcurrency, 2);
        Assert::same($importer->command->pipelineBufferMiB, 32);
        Assert::same($importer->command->pipelineFragmentMiB, 16);
        Assert::same($importer->command->pipelineTableAwareFiles, true);
        Assert::true(\str_contains($output, 'pipeline_fused_ranges=yes'));
        Assert::true(\str_contains($output, 'pipeline_file_concurrency=2'));
        Assert::true(\str_contains($output, 'pipeline_buffer_mib=32'));
        Assert::true(\str_contains($output, 'pipeline_fragment_mib=16'));
        Assert::true(\str_contains($output, 'pipeline_table_aware_files=yes'));
    }

    public function testItPassesOverlappingTableIndexesToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            true,
            overlapTableIndexes: true,
            overlapIndexWorkers: 2,
            overlapIndexStartPercent: 50,
            indexWorkers: 4,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::true($importer->command->overlapTableIndexes);
        Assert::same($importer->command->overlapIndexWorkers, 2);
        Assert::same($importer->command->overlapIndexStartPercent, 50);
        Assert::true(\str_contains($output, 'overlap_table_indexes=yes'));
        Assert::true(\str_contains($output, 'overlap_index_workers=2'));
        Assert::true(\str_contains($output, 'overlap_index_start_percent=50'));
    }

    public function testItPassesPersistentIndexWorkersToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            persistentIndexWorkers: true,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::true($importer->command->persistentIndexWorkers);
        Assert::true(\str_contains($output, 'persistent_index_workers=yes'));
    }

    public function testItPassesPostRawIndexForeignKeyOverlapToTheCapability(): void
    {
        $importer = new RecordingGarImporter();
        [$exitCode, $output] = self::run(
            $importer,
            '50',
            true,
            true,
            deferForeignKeys: true,
            overlapTableIndexes: true,
            overlapIndexForeignKeys: true,
        );

        Assert::same($exitCode, 0);
        Assert::notNull($importer->command);
        Assert::true($importer->command->overlapIndexForeignKeys);
        Assert::true(\str_contains($output, 'overlap_index_foreign_keys=yes'));
    }

    /** @return array{int, string} */
    private static function run(
        RecordingGarImporter $importer,
        string $regions,
        bool $initialLoad = false,
        bool $directCopy = false,
        ?string $pipelineFile = null,
        ?int $pipelineWriters = null,
        ?int $pipelineParsers = null,
        bool $pipelineFusedRanges = false,
        int $pipelineFileConcurrency = 1,
        int $pipelineBufferMiB = 16,
        int $pipelineFragmentMiB = 8,
        bool $pipelineTableAwareFiles = false,
        bool $deferForeignKeys = false,
        bool $overlapTableIndexes = false,
        int $overlapIndexWorkers = 1,
        int $overlapIndexStartPercent = 0,
        int $indexWorkers = 2,
        int $workers = 2,
        int $foreignKeyWorkers = 2,
        bool $persistentIndexWorkers = false,
        bool $deferPrimaryKeys = false,
        bool $overlapIndexForeignKeys = false,
    ): array {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new ImportGarCommand($importer));
        $output = new BufferedOutput();
        $input = [
            'command' => 'gar:import',
            'source' => '/data/gar',
            '--regions' => $regions,
            '--index-workers' => (string) $indexWorkers,
            '--overlap-index-workers' => (string) $overlapIndexWorkers,
            '--overlap-index-start-percent' => (string) $overlapIndexStartPercent,
            '--workers' => (string) $workers,
            '--foreign-key-workers' => (string) $foreignKeyWorkers,
            '--no-ansi' => true,
        ];
        if ($initialLoad) {
            $input['--initial-load'] = true;
        }
        if ($directCopy) {
            $input['--direct-copy'] = true;
        }
        if ($pipelineFile !== null) {
            $input['--pipeline-file'] = $pipelineFile;
        }
        if ($pipelineWriters !== null) {
            $input['--pipeline-writers'] = (string) $pipelineWriters;
        }
        if ($pipelineParsers !== null) {
            $input['--pipeline-parsers'] = (string) $pipelineParsers;
        }
        if ($pipelineFusedRanges) {
            $input['--pipeline-fused-ranges'] = true;
        }
        if ($pipelineFileConcurrency !== 1) {
            $input['--pipeline-file-concurrency'] = (string) $pipelineFileConcurrency;
        }
        if ($pipelineBufferMiB !== 16) {
            $input['--pipeline-buffer-mib'] = (string) $pipelineBufferMiB;
        }
        if ($pipelineFragmentMiB !== 8) {
            $input['--pipeline-fragment-mib'] = (string) $pipelineFragmentMiB;
        }
        if ($pipelineTableAwareFiles) {
            $input['--pipeline-table-aware-files'] = true;
        }
        if ($overlapTableIndexes) {
            $input['--overlap-table-indexes'] = true;
        }
        if ($deferForeignKeys) {
            $input['--defer-foreign-keys'] = true;
        }
        if ($persistentIndexWorkers) {
            $input['--persistent-index-workers'] = true;
        }
        if ($deferPrimaryKeys) {
            $input['--defer-primary-keys'] = true;
        }
        if ($overlapIndexForeignKeys) {
            $input['--overlap-index-foreign-keys'] = true;
        }
        $exitCode = $application->run(new ArrayInput($input), $output);

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
            pipelineFile: $command->pipelineFile,
            pipelineWriters: $command->pipelineWriters,
            deferPrimaryKeys: $command->deferPrimaryKeys,
            deferForeignKeys: $command->deferForeignKeys,
        );
    }
}

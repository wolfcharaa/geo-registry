<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Capability\ImportGar;

use App\Module\AddressRegistry\Capability\ImportGar\ImportGar;
use InvalidArgumentException;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class ImportGarTest
{
    public function testItUsesBoundedParallelCopyDefaults(): void
    {
        $command = new ImportGar('/data/gar');

        Assert::same($command->batchSize, 50_000);
        Assert::same($command->workers, 2);
        Assert::same($command->indexWorkers, 2);
        Assert::same($command->foreignKeyWorkers, 2);
        Assert::same($command->initialLoad, false);
        Assert::same($command->directCopy, false);
        Assert::null($command->pipelineFile);
        Assert::null($command->pipelineWriters);
        Assert::null($command->pipelineParsers);
        Assert::same($command->pipelineFusedRanges, false);
        Assert::same($command->pipelineFileConcurrency, 1);
        Assert::same($command->pipelineBufferMiB, 16);
        Assert::same($command->pipelineFragmentMiB, 8);
        Assert::same($command->pipelineTableAwareFiles, false);
        Assert::same($command->overlapTableIndexes, false);
        Assert::same($command->overlapIndexWorkers, 1);
        Assert::same($command->overlapIndexStartPercent, 0);
        Assert::same($command->persistentIndexWorkers, false);
        Assert::same($command->deferPrimaryKeys, false);
        Assert::same($command->deferForeignKeys, false);
        Assert::same($command->overlapIndexForeignKeys, false);
    }

    public function testItRejectsAnUnsafeWorkerCount(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', workers: 17);
    }

    public function testItAcceptsABoundedInitialLoadIndexWorkerCount(): void
    {
        $command = new ImportGar('/data/gar', indexWorkers: 4, initialLoad: true);

        Assert::same($command->indexWorkers, 4);
    }

    public function testItRejectsAnUnsafeInitialLoadIndexWorkerCount(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', indexWorkers: 5);
    }

    public function testItAcceptsABoundedDeferredForeignKeyWorkerCount(): void
    {
        $command = new ImportGar(
            '/data/gar',
            foreignKeyWorkers: 4,
            initialLoad: true,
            directCopy: true,
            deferForeignKeys: true,
        );

        Assert::same($command->foreignKeyWorkers, 4);
    }

    public function testItRejectsAnUnsafeDeferredForeignKeyWorkerCount(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', foreignKeyWorkers: 5);
    }

    public function testItRejectsForeignKeyWorkersWithoutDeferredForeignKeys(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', foreignKeyWorkers: 3);
    }

    public function testItAcceptsAnExplicitRegionSelection(): void
    {
        $command = new ImportGar('/data/gar', regions: [50, 77]);

        Assert::same($command->regions, [50, 77]);
    }

    public function testItRejectsDuplicateRegions(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', regions: [77, 77]);
    }

    public function testItRejectsDiscardingAnInitialLoadRecoveryState(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', restart: true, initialLoad: true);
    }

    public function testItRejectsDirectCopyOutsideAnInitialLoad(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', directCopy: true);
    }

    public function testItAcceptsABoundedDirectCopyPipeline(): void
    {
        $command = new ImportGar(
            '/data/gar',
            workers: 4,
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
            pipelineWriters: 3,
        );

        Assert::same($command->pipelineFile, '50/AS_HOUSES_PARAMS.XML');
        Assert::same($command->pipelineWriters, 3);
    }

    public function testItRejectsAnIncompletePipelineSelection(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
        );
    }

    public function testItAcceptsDeferredForeignKeysForDirectInitialLoad(): void
    {
        $command = new ImportGar(
            '/data/gar',
            initialLoad: true,
            directCopy: true,
            deferForeignKeys: true,
        );

        Assert::true($command->deferForeignKeys);
    }

    public function testItAcceptsDeferredPrimaryKeysForDirectInitialLoad(): void
    {
        $command = new ImportGar(
            '/data/gar',
            initialLoad: true,
            directCopy: true,
            deferPrimaryKeys: true,
            deferForeignKeys: true,
        );

        Assert::true($command->deferPrimaryKeys);
    }

    public function testItRejectsDeferredPrimaryKeysOutsideDirectInitialLoad(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', initialLoad: true, deferPrimaryKeys: true);
    }

    public function testItRejectsDeferredPrimaryKeysWithActiveForeignKeys(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            initialLoad: true,
            directCopy: true,
            deferPrimaryKeys: true,
        );
    }

    public function testItRejectsDeferredForeignKeysOutsideDirectInitialLoad(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', initialLoad: true, deferForeignKeys: true);
    }

    public function testItAcceptsPostRawIndexForeignKeyOverlap(): void
    {
        $command = new ImportGar(
            '/data/gar',
            initialLoad: true,
            directCopy: true,
            overlapTableIndexes: true,
            deferForeignKeys: true,
            overlapIndexForeignKeys: true,
        );

        Assert::true($command->overlapIndexForeignKeys);
    }

    public function testItRejectsPostRawOverlapWithoutDeferredForeignKeys(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            initialLoad: true,
            directCopy: true,
            overlapTableIndexes: true,
            overlapIndexForeignKeys: true,
        );
    }

    public function testItRejectsPostRawOverlapWithoutEarlyIndexes(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            initialLoad: true,
            directCopy: true,
            deferForeignKeys: true,
            overlapIndexForeignKeys: true,
        );
    }

    public function testItRejectsMorePipelineWritersThanTheGlobalLimit(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 2,
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
            pipelineWriters: 3,
        );
    }

    public function testItAcceptsBoundedRangeParsersInsideTheGlobalLimit(): void
    {
        $command = new ImportGar(
            '/data/gar',
            workers: 8,
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
            pipelineWriters: 4,
            pipelineParsers: 4,
        );

        Assert::same($command->pipelineParsers, 4);
    }

    public function testItRejectsWritersAboveTheMeasuredLimit(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 13,
            initialLoad: true,
            directCopy: true,
            pipelineFile: 'auto',
            pipelineWriters: 5,
            pipelineParsers: 8,
            pipelineFusedRanges: true,
        );
    }

    public function testItRejectsParsersAndWritersAboveTheGlobalLimit(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 7,
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
            pipelineWriters: 4,
            pipelineParsers: 4,
        );
    }

    public function testItRejectsAnUnsafeRangeParserCount(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 12,
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
            pipelineWriters: 4,
            pipelineParsers: 5,
        );
    }

    public function testItAcceptsFusedRangeWorkersAtTheExtendedParserLimit(): void
    {
        $command = new ImportGar(
            '/data/gar',
            workers: 16,
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
            pipelineWriters: 4,
            pipelineParsers: 8,
            pipelineFusedRanges: true,
        );

        Assert::same($command->pipelineParsers, 8);
        Assert::same($command->pipelineFusedRanges, true);
    }

    public function testItAcceptsBoundedFusedFileConcurrency(): void
    {
        $command = new ImportGar(
            '/data/gar',
            workers: 12,
            initialLoad: true,
            directCopy: true,
            pipelineFile: 'auto',
            pipelineWriters: 4,
            pipelineParsers: 8,
            pipelineFusedRanges: true,
            pipelineFileConcurrency: 2,
            pipelineBufferMiB: 32,
            pipelineFragmentMiB: 16,
            pipelineTableAwareFiles: true,
        );

        Assert::same($command->pipelineFileConcurrency, 2);
        Assert::same($command->pipelineBufferMiB, 32);
        Assert::same($command->pipelineFragmentMiB, 16);
        Assert::same($command->pipelineTableAwareFiles, true);
    }

    public function testItRejectsFileConcurrencyWithoutFusedRanges(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 8,
            initialLoad: true,
            directCopy: true,
            pipelineFile: 'auto',
            pipelineWriters: 4,
            pipelineParsers: 4,
            pipelineFileConcurrency: 2,
        );
    }

    public function testItRejectsUnsafeFusedFileConcurrency(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 12,
            initialLoad: true,
            directCopy: true,
            pipelineFile: 'auto',
            pipelineWriters: 4,
            pipelineParsers: 8,
            pipelineFusedRanges: true,
            pipelineFileConcurrency: 3,
        );
    }

    public function testItRejectsAnExpandedBufferWithoutFusedRanges(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', pipelineBufferMiB: 32);
    }

    public function testItRejectsAnExpandedFragmentWithoutFusedRanges(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', pipelineFragmentMiB: 16);
    }

    public function testItRejectsTableAwareFilesWithoutFusedRanges(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', pipelineTableAwareFiles: true);
    }

    public function testItRejectsTableAwareFilesWithoutConcurrentFiles(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 12,
            initialLoad: true,
            directCopy: true,
            pipelineFile: 'auto',
            pipelineWriters: 4,
            pipelineParsers: 8,
            pipelineFusedRanges: true,
            pipelineTableAwareFiles: true,
        );
    }

    public function testItRejectsAnUnsafeFusedFragment(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 12,
            initialLoad: true,
            directCopy: true,
            pipelineFile: 'auto',
            pipelineWriters: 4,
            pipelineParsers: 8,
            pipelineFusedRanges: true,
            pipelineBufferMiB: 64,
            pipelineFragmentMiB: 33,
        );
    }

    public function testItRejectsAnUnsafeFusedBuffer(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 12,
            initialLoad: true,
            directCopy: true,
            pipelineFile: 'auto',
            pipelineWriters: 4,
            pipelineParsers: 8,
            pipelineFusedRanges: true,
            pipelineBufferMiB: 65,
        );
    }

    public function testItRejectsFusedRangesWithoutRangeParsers(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 4,
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
            pipelineWriters: 4,
            pipelineFusedRanges: true,
        );
    }

    public function testItRejectsFusedRangeWorkersAboveTheExtendedParserLimit(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            workers: 16,
            initialLoad: true,
            directCopy: true,
            pipelineFile: '50/AS_HOUSES_PARAMS.XML',
            pipelineWriters: 4,
            pipelineParsers: 9,
            pipelineFusedRanges: true,
        );
    }

    public function testItAcceptsOverlappingIndexesForDirectInitialLoad(): void
    {
        $command = new ImportGar(
            '/data/gar',
            indexWorkers: 4,
            initialLoad: true,
            directCopy: true,
            overlapTableIndexes: true,
            overlapIndexWorkers: 2,
            overlapIndexStartPercent: 50,
        );

        Assert::true($command->overlapTableIndexes);
        Assert::same($command->overlapIndexWorkers, 2);
        Assert::same($command->overlapIndexStartPercent, 50);
    }

    public function testItRejectsOverlappingIndexesOutsideDirectInitialLoad(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', initialLoad: true, overlapTableIndexes: true);
    }

    public function testItRejectsMoreOverlapWorkersThanFinalIndexWorkers(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            indexWorkers: 2,
            initialLoad: true,
            directCopy: true,
            overlapTableIndexes: true,
            overlapIndexWorkers: 3,
        );
    }

    public function testItRejectsOverlapWorkersWithoutTableOverlap(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', overlapIndexWorkers: 2);
    }

    public function testItRejectsOverlapIndexStartPercentWithoutTableOverlap(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', overlapIndexStartPercent: 50);
    }

    public function testItRejectsUnsafeOverlapIndexStartPercent(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar(
            '/data/gar',
            initialLoad: true,
            directCopy: true,
            overlapTableIndexes: true,
            overlapIndexStartPercent: 100,
        );
    }

    public function testItAcceptsPersistentWorkersForAnInitialLoad(): void
    {
        $command = new ImportGar(
            '/data/gar',
            initialLoad: true,
            persistentIndexWorkers: true,
        );

        Assert::true($command->persistentIndexWorkers);
    }

    public function testItRejectsPersistentWorkersOutsideAnInitialLoad(): void
    {
        Expect::exception(InvalidArgumentException::class);

        new ImportGar('/data/gar', persistentIndexWorkers: true);
    }
}

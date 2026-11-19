<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarFileAdmissionQueue;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportStrategy;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlSourceFile;
use Fedor\WorkOrchestrator\Result\TaskOutcome;
use Fedor\WorkOrchestrator\Scheduling\Granted;
use InvalidArgumentException;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;

#[Test]
final class GarImportStrategyTest
{
    public function testPolicyStaysSerializableAndAdmissionOrdersManifestLargestFirst(): void
    {
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/regions', [50])->files;
        $strategy = new GarImportStrategy('host=postgres', 42, 5_000, true);
        Assert::true(\unserialize(\serialize($strategy)) instanceof GarImportStrategy);

        $queue = GarFileAdmissionQueue::create(\array_reverse($files));
        $sizes = [];
        while (($decision = $queue->acquire()) instanceof Granted) {
            $file = $decision->lease->work->payload;
            if (!$file instanceof GarXmlSourceFile) {
                throw new \LogicException('GAR queue returned an invalid file payload.');
            }
            Assert::same($decision->lease->work->id->value, $file->path);
            $sizes[] = $file->size;
            $queue->complete($decision->lease, TaskOutcome::succeeded(null));
        }
        $expected = $sizes;
        \rsort($expected);

        Assert::same($sizes, $expected);
    }

    #[ExpectException(InvalidArgumentException::class)]
    public function testItRejectsDuplicateManifestPaths(): void
    {
        $files = new GarXmlCatalog()->manifest(\dirname(__DIR__, 5) . '/Fixture/Gar/regions', [50])->files;
        $file = $files[0];

        GarFileAdmissionQueue::create([$file, $file]);
    }
}

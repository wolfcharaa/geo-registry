<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Infrastructure\GarImport\GarImportPipelineSelector;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlCatalog;
use App\Module\AddressRegistry\Infrastructure\GarImport\GarXmlImportPlanner;
use App\Module\AddressRegistry\UserInterface\Cli\PlanGarImportCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class PlanGarImportCommandTest
{
    public function testItPrintsEligiblePipelineFilesByDescendingSizeWithoutImporting(): void
    {
        $source = \dirname(__DIR__, 5) . '/Fixture/Gar/pipeline';
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new PlanGarImportCommand(new GarXmlImportPlanner(
            new GarXmlCatalog(),
            new GarImportPipelineSelector(),
        )));
        $output = new BufferedOutput();

        $exitCode = $application->run(new ArrayInput([
            'command' => 'gar:import:plan',
            'source' => $source,
        ]), $output);
        $lines = \explode("\n", \trim($output->fetch()));

        Assert::same($exitCode, 0);
        Assert::true(\str_contains($lines[0], 'files=5 bytes=1447 regions=all pipeline_candidates=5'));
        Assert::true(\str_contains(
            $lines[1] ?? '',
            'path="AS_HOUSES_PARAMS_20261104.XML" bytes=562 priority=50 auto=yes',
        ));
        Assert::true(\str_contains(
            $lines[2] ?? '',
            'path="AS_REESTR_OBJECTS_20261104.XML" bytes=238 priority=20 auto=yes',
        ));
        Assert::true(\str_contains(
            $lines[3] ?? '',
            'path="AS_PARAM_TYPES_20261104.XML" bytes=226 priority=10 auto=no',
        ));
        Assert::true(\str_contains(
            $lines[4] ?? '',
            'path="AS_ADDR_OBJ_PARAMS_20261104.XML" bytes=223 priority=50 auto=yes',
        ));
    }
}

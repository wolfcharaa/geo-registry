<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Cli;

use App\Module\AddressRegistry\Capability\Integrity\GarIntegrityChecker;
use App\Module\AddressRegistry\Capability\Integrity\GarIntegrityCheckResult;
use App\Module\AddressRegistry\Capability\Integrity\GarIntegrityReport;
use App\Module\AddressRegistry\UserInterface\Cli\GarIntegrityCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class GarIntegrityCommandTest
{
    public function testItFailsClosedForIssuesAndUnavailableChecks(): void
    {
        $checker = new RecordingGarIntegrityChecker([
            new GarIntegrityCheckResult('valid check', 0),
            new GarIntegrityCheckResult('broken check', 2),
            new GarIntegrityCheckResult('unavailable check', null),
        ]);
        [$exitCode, $output] = self::run($checker, [
            'command' => 'gar:integrity',
            '--regions' => '77,50,77',
            '--deep' => true,
            '--only-failed' => true,
        ]);

        Assert::same($exitCode, 1);
        Assert::same($checker->regions, [50, 77]);
        Assert::true($checker->deep);
        Assert::false(str_contains($output, 'valid check'));
        Assert::true(str_contains($output, 'FAIL check="broken check" issues=2'));
        Assert::true(str_contains($output, 'ERROR check="unavailable check" issues=unavailable'));
        Assert::true(str_contains($output, 'failed=1 unavailable=1 status=failed'));
    }

    public function testItReturnsSuccessOnlyWhenEveryCheckPasses(): void
    {
        $checker = new RecordingGarIntegrityChecker([
            new GarIntegrityCheckResult('valid check', 0),
        ]);
        [$exitCode, $output] = self::run($checker, ['command' => 'gar:integrity']);

        Assert::same($exitCode, 0);
        Assert::same($checker->regions, [50, 77]);
        Assert::false($checker->deep);
        Assert::true(str_contains($output, 'summary checks=1 failed=0 unavailable=0 status=ok'));
    }

    /**
     * @param array<string, bool|string> $arguments
     * @return array{int, string}
     */
    private static function run(GarIntegrityChecker $checker, array $arguments): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new GarIntegrityCommand($checker));
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($arguments), $output);

        return [$exitCode, $output->fetch()];
    }
}

final class RecordingGarIntegrityChecker implements GarIntegrityChecker
{
    /** @var list<int> */
    public array $regions = [];

    public bool $deep = false;

    /** @param list<GarIntegrityCheckResult> $checks */
    public function __construct(private readonly array $checks) {}

    public function check(array $regions, bool $deep): GarIntegrityReport
    {
        $this->regions = $regions;
        $this->deep = $deep;

        return new GarIntegrityReport($regions, $deep, $this->checks);
    }
}

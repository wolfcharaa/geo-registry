<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Worker;

use App\Application\Console\Command\SafeWorkerControlActionCommand;
use App\Application\Console\Command\SafeWorkerControlCommand;
use Fedor\MessageBus\Worker\Cli\BootstrapResolver;
use Fedor\MessageBus\Worker\Control\WorkerControlCommandType;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Test;

#[Test]
final class SafeWorkerControlCommandTest
{
    public function testActionAliasRejectsABroadOneShotTargetBeforeBootstrapping(): void
    {
        [$exitCode, $output] = self::run(new SafeWorkerControlActionCommand(
            WorkerControlCommandType::Drain,
            'worker:drain',
            new BootstrapResolver([]),
        ), [
            'command' => 'worker:drain',
            '--worker-name' => 'geo-registry-address',
        ]);

        Assert::same($exitCode, 2);
        Assert::true(str_contains($output, 'require an exact --worker-instance-id'));
    }

    public function testGenericCommandRejectsABroadOneShotTargetBeforeBootstrapping(): void
    {
        [$exitCode, $output] = self::run(
            new SafeWorkerControlCommand(new BootstrapResolver([])),
            [
                'command' => 'worker:control',
                '--command' => 'restart',
                '--all' => true,
            ],
        );

        Assert::same($exitCode, 2);
        Assert::true(str_contains($output, 'require an exact --worker-instance-id'));
    }

    /**
     * @param array<string, bool|string> $arguments
     * @return array{int, string}
     */
    private static function run(Command $command, array $arguments): array
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand($command);
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput($arguments), $output);

        return [$exitCode, $output->fetch()];
    }
}

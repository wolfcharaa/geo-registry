<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

use Fedor\MessageBus\Worker\Cli\BootstrapResolver;
use Fedor\MessageBus\Worker\Cli\Command\AbstractWorkerControlCommand;
use Fedor\MessageBus\Worker\Cli\InputOptionValue;
use Fedor\MessageBus\Worker\Control\WorkerControlCommandType;
use Fedor\MessageBus\Worker\Control\WorkerControlRuntime;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SafeWorkerControlCommand extends AbstractWorkerControlCommand
{
    public function __construct(BootstrapResolver $bootstrapResolver)
    {
        parent::__construct('worker:control', $bootstrapResolver);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Send a worker control command.')
            ->setHelp(
                'One-shot drain/stop/kill/restart commands require an exact --worker-instance-id so a replacement process cannot replay them.',
            )
            ->addOption('command', null, InputOption::VALUE_REQUIRED);
        $this->addBootstrapOption();
        $this->addTargetOptions();
        $this->addAuditOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = WorkerControlCommandType::from(InputOptionValue::string($input, 'command'));
        if ($type->isOneShot() && InputOptionValue::nullableString($input, 'worker-instance-id') === null) {
            $output->writeln(
                '<error>One-shot worker lifecycle commands require an exact --worker-instance-id.</error>',
            );

            return self::INVALID;
        }

        $target = $this->target($input);
        $request = $this->request($input);

        return $this->withControlRuntime(
            $input,
            fn(WorkerControlRuntime $runtime): int => $this->writeReceipt(
                $output,
                $this->dispatchControl($runtime, $type, $target, $request),
            ),
        );
    }
}

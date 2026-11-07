<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

use Fedor\MessageBus\Worker\Cli\BootstrapResolver;
use Fedor\MessageBus\Worker\Cli\Command\AbstractWorkerControlCommand;
use Fedor\MessageBus\Worker\Cli\InputOptionValue;
use Fedor\MessageBus\Worker\Control\WorkerControlCommandType;
use Fedor\MessageBus\Worker\Control\WorkerControlRuntime;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SafeWorkerControlActionCommand extends AbstractWorkerControlCommand
{
    public function __construct(
        private readonly WorkerControlCommandType $type,
        string $name,
        BootstrapResolver $bootstrapResolver,
    ) {
        parent::__construct($name, $bootstrapResolver);
    }

    protected function configure(): void
    {
        $this->setDescription('Send worker ' . $this->type->value . ' command.');
        $this->addBootstrapOption();
        $this->addTargetOptions();
        $this->addAuditOptions();
        if ($this->type->isOneShot()) {
            $this->setHelp(
                'One-shot lifecycle commands require an exact --worker-instance-id so a replacement process cannot replay them.',
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->type->isOneShot() && InputOptionValue::nullableString($input, 'worker-instance-id') === null) {
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
                $this->dispatchControl($runtime, $this->type, $target, $request),
            ),
        );
    }
}

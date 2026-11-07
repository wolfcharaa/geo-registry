<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use function Amp\async;

use Amp\ByteStream;
use Amp\Cancellation;
use Amp\CompositeCancellation;

use function Amp\delay;
use function Amp\now;

use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Ipc\IpcHub;
use Psr\Log\LoggerInterface;

final readonly class PipedProcessContextFactory implements ContextFactory
{
    private ProcessContextFactory $contextFactory;

    public function __construct(
        IpcHub $ipcHub,
        private ProcessRestartBackoff $restartBackoff,
        private LoggerInterface $logger,
        private Cancellation $lifecycleCancellation,
    ) {
        $this->contextFactory = new ProcessContextFactory(ipcHub: $ipcHub);
    }

    /**
     * @param string|non-empty-list<string> $script
     *
     * @return ProcessContext<mixed, mixed, mixed>
     */
    public function start(string|array $script, ?Cancellation $cancellation = null): Context
    {
        $startCancellation = $cancellation === null
            ? $this->lifecycleCancellation
            : new CompositeCancellation($this->lifecycleCancellation, $cancellation);
        $decision = $this->restartBackoff->beforeStart(now());
        if ($decision->delaySeconds > 0) {
            $this->logger->warning('Delaying HTTP worker restart after a rapid restart burst', [
                'event' => 'http.cluster.worker.restart.delayed',
                'delay_seconds' => $decision->delaySeconds,
                'restarts_in_window' => $decision->restartsInWindow,
            ]);
            delay($decision->delaySeconds, cancellation: $startCancellation);
        }

        $context = $this->contextFactory->start($script, $startCancellation);
        $stdout = $context->getStdout();
        $stdout->unreference();
        $stderr = $context->getStderr();
        $stderr->unreference();

        async(ByteStream\pipe(...), $stdout, ByteStream\getStdout())->ignore();
        async(ByteStream\pipe(...), $stderr, ByteStream\getStderr())->ignore();

        return $context;
    }
}

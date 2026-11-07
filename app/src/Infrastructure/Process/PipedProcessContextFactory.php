<?php

declare(strict_types=1);

namespace App\Infrastructure\Process;

use function Amp\async;

use Amp\ByteStream;
use Amp\Cancellation;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Parallel\Ipc\IpcHub;

final readonly class PipedProcessContextFactory implements ContextFactory
{
    private ProcessContextFactory $contextFactory;

    public function __construct(IpcHub $ipcHub)
    {
        $this->contextFactory = new ProcessContextFactory(ipcHub: $ipcHub);
    }

    /**
     * @param string|non-empty-list<string> $script
     *
     * @return ProcessContext<mixed, mixed, mixed>
     */
    public function start(string|array $script, ?Cancellation $cancellation = null): Context
    {
        $context = $this->contextFactory->start($script, $cancellation);
        $stdout = $context->getStdout();
        $stdout->unreference();
        $stderr = $context->getStderr();
        $stderr->unreference();

        async(ByteStream\pipe(...), $stdout, ByteStream\getStdout())->ignore();
        async(ByteStream\pipe(...), $stderr, ByteStream\getStderr())->ignore();

        return $context;
    }
}

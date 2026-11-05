<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;

final class GarImportWorkerProcess
{
    private const MAX_OUTPUT_BYTES = 16_384;

    /** @var resource */
    private $process;

    /** @var resource */
    private $standardOutput;

    /** @var resource */
    private $standardError;

    private string $output = '';
    private string $error = '';
    private bool $closed = false;

    /**
     * @param resource $process
     * @param resource $standardOutput
     * @param resource $standardError
     */
    public function __construct(
        $process,
        $standardOutput,
        $standardError,
        private readonly string $description,
    ) {
        $this->process = $process;
        $this->standardOutput = $standardOutput;
        $this->standardError = $standardError;
        stream_set_blocking($this->standardOutput, false);
        stream_set_blocking($this->standardError, false);
    }

    public function poll(): ?GarImportWorkerProcessResult
    {
        if ($this->closed) {
            throw new GarImportFailed('GAR import worker process was polled after it closed.');
        }

        $this->drain();
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return null;
        }

        $this->drain();
        fclose($this->standardOutput);
        fclose($this->standardError);
        $closeCode = proc_close($this->process);
        $this->closed = true;
        $exitCode = $status['exitcode'];
        if ($exitCode < 0 && $closeCode >= 0) {
            $exitCode = $closeCode;
        }

        return new GarImportWorkerProcessResult(
            exitCode: $exitCode,
            description: $this->description,
            standardOutput: trim($this->output),
            standardError: trim($this->error),
        );
    }

    public function terminate(): void
    {
        if ($this->closed) {
            return;
        }

        proc_terminate($this->process);
        $this->drain();
        fclose($this->standardOutput);
        fclose($this->standardError);
        proc_close($this->process);
        $this->closed = true;
    }

    private function drain(): void
    {
        $this->output = $this->append($this->output, stream_get_contents($this->standardOutput));
        $this->error = $this->append($this->error, stream_get_contents($this->standardError));
    }

    private function append(string $buffer, string|false $chunk): string
    {
        if (!is_string($chunk) || $chunk === '' || strlen($buffer) >= self::MAX_OUTPUT_BYTES) {
            return $buffer;
        }

        return $buffer . substr($chunk, 0, self::MAX_OUTPUT_BYTES - strlen($buffer));
    }
}

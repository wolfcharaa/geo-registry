<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class HttpLoadCheckCommand extends Command
{
    public function __construct(private readonly HttpLoadCheckRunner $runner)
    {
        parent::__construct('server:load-check');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run a bounded concurrent HTTP load gate without retries.')
            ->addArgument('url', InputArgument::REQUIRED, 'Absolute HTTP(S) endpoint URL.')
            ->addOption('requests', null, InputOption::VALUE_REQUIRED, 'Measured request count.', '1000')
            ->addOption('concurrency', null, InputOption::VALUE_REQUIRED, 'Maximum in-flight requests.', '32')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Timeout per request in seconds.', '5')
            ->addOption('expected-status', null, InputOption::VALUE_REQUIRED, 'Successful HTTP status.', '200')
            ->addOption('max-failures', null, InputOption::VALUE_REQUIRED, 'Allowed failed requests.', '0')
            ->addOption('max-p95-ms', null, InputOption::VALUE_REQUIRED, 'Allowed p95 in ms; 0 disables.', '0')
            ->addOption(
                'max-body-bytes',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum buffered response body per request.',
                '1048576',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print a machine-readable JSON report.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $url = $input->getArgument('url');
            if (!is_string($url)) {
                throw new InvalidArgumentException('Argument url must be a string.');
            }

            $options = new HttpLoadCheckOptions(
                url: $url,
                requests: self::integerOption($input, 'requests'),
                concurrency: self::integerOption($input, 'concurrency'),
                timeoutSeconds: self::floatOption($input, 'timeout'),
                expectedStatus: self::integerOption($input, 'expected-status'),
                maxBodyBytes: self::integerOption($input, 'max-body-bytes'),
            );
            $maxFailures = self::integerOption($input, 'max-failures');
            $maxP95Milliseconds = self::floatOption($input, 'max-p95-ms');
            if ($maxFailures < 0 || $maxFailures > $options->requests) {
                throw new InvalidArgumentException('Option --max-failures must be between 0 and --requests.');
            }
            if (!is_finite($maxP95Milliseconds) || $maxP95Milliseconds < 0) {
                throw new InvalidArgumentException('Option --max-p95-ms must be non-negative.');
            }
        } catch (InvalidArgumentException $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return self::INVALID;
        }

        $report = $this->runner->run($options);
        $passed = $report->passes($maxFailures, $maxP95Milliseconds);

        if ($input->getOption('json') === true) {
            try {
                $output->writeln(json_encode([
                    'status' => $passed ? 'ok' : 'failed',
                    ...$report->toArray(),
                    'thresholds' => [
                        'max_failures' => $maxFailures,
                        'max_p95_ms' => $maxP95Milliseconds,
                    ],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } catch (JsonException $error) {
                $output->writeln('<error>Unable to encode the load report: ' . $error->getMessage() . '</error>');

                return self::FAILURE;
            }
        } else {
            self::writeTextReport($output, $report, $passed);
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private static function integerOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException(sprintf('Option --%s must be an integer.', $name));
        }

        return (int) $value;
    }

    private static function floatOption(InputInterface $input, string $name): float
    {
        $value = $input->getOption($name);
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_FLOAT) === false) {
            throw new InvalidArgumentException(sprintf('Option --%s must be a number.', $name));
        }

        return (float) $value;
    }

    private static function writeTextReport(
        OutputInterface $output,
        HttpLoadCheckReport $report,
        bool $passed,
    ): void {
        $output->writeln(sprintf(
            'status=%s attempts=%d successes=%d failures=%d duration_ms=%.3f requests_per_second=%.3f',
            $passed ? 'ok' : 'failed',
            $report->attempts,
            $report->successes,
            $report->failures,
            $report->durationMilliseconds,
            $report->requestsPerSecond,
        ));
        $output->writeln(sprintf(
            'latency_ms p50=%.3f p95=%.3f p99=%.3f response_bytes=%d',
            $report->p50Milliseconds,
            $report->p95Milliseconds,
            $report->p99Milliseconds,
            $report->responseBytes,
        ));
        $output->writeln('http_statuses=' . json_encode($report->statusCounts, JSON_THROW_ON_ERROR));
        $output->writeln('errors=' . json_encode($report->errorCounts, JSON_THROW_ON_ERROR));
    }
}

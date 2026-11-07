<?php

declare(strict_types=1);

namespace App\Application\Console\Command;

use function Amp\async;
use function Amp\ByteStream\buffer;

use Amp\Future;

use function Amp\Future\await;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use Throwable;

final readonly class HttpLoadCheckRunner
{
    public function __construct(
        private DelegateHttpClient $httpClient,
        private string $userAgent,
    ) {}

    public function run(HttpLoadCheckOptions $options): HttpLoadCheckReport
    {
        $nextRequest = 0;
        $successes = 0;
        $responseBytes = 0;
        $durations = [];
        $statusCounts = [];
        $errorCounts = [];
        $startedAt = hrtime(true);
        $workers = [];

        for ($worker = 0; $worker < $options->concurrency; ++$worker) {
            $workers[] = async(function () use (
                $options,
                &$nextRequest,
                &$successes,
                &$responseBytes,
                &$durations,
                &$statusCounts,
                &$errorCounts,
            ): void {
                while (true) {
                    $requestIndex = $nextRequest++;
                    if ($requestIndex >= $options->requests) {
                        return;
                    }

                    $requestStartedAt = hrtime(true);
                    try {
                        $request = new Request($options->url);
                        $request->setHeader('User-Agent', $this->userAgent);
                        $request->setTcpConnectTimeout($options->timeoutSeconds);
                        $request->setTlsHandshakeTimeout($options->timeoutSeconds);
                        $request->setTransferTimeout($options->timeoutSeconds);
                        $request->setInactivityTimeout($options->timeoutSeconds);
                        $request->setBodySizeLimit($options->maxBodyBytes);
                        $cancellation = new TimeoutCancellation($options->timeoutSeconds);
                        $response = $this->httpClient->request($request, $cancellation);
                        $status = $response->getStatus();
                        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                        $body = buffer($response->getBody(), $cancellation, $options->maxBodyBytes);
                        $responseBytes += strlen($body);

                        if ($status === $options->expectedStatus) {
                            ++$successes;
                        }
                    } catch (Throwable $error) {
                        $errorType = $error::class;
                        $errorCounts[$errorType] = ($errorCounts[$errorType] ?? 0) + 1;
                    } finally {
                        $durations[$requestIndex] = (float) ((hrtime(true) - $requestStartedAt) / 1_000_000);
                    }
                }
            });
        }

        /** @var list<Future<void>> $workers */
        await($workers);

        $durationMilliseconds = max(0.001, (hrtime(true) - $startedAt) / 1_000_000);
        sort($durations, SORT_NUMERIC);
        ksort($statusCounts);
        ksort($errorCounts);

        return new HttpLoadCheckReport(
            attempts: $options->requests,
            successes: $successes,
            failures: $options->requests - $successes,
            durationMilliseconds: $durationMilliseconds,
            requestsPerSecond: $options->requests / ($durationMilliseconds / 1000),
            p50Milliseconds: self::percentile($durations, 0.50),
            p95Milliseconds: self::percentile($durations, 0.95),
            p99Milliseconds: self::percentile($durations, 0.99),
            responseBytes: $responseBytes,
            statusCounts: $statusCounts,
            errorCounts: $errorCounts,
        );
    }

    /** @param list<float|int> $sortedValues */
    private static function percentile(array $sortedValues, float $percentile): float
    {
        $index = max(0, (int) ceil(count($sortedValues) * $percentile) - 1);

        return (float) ($sortedValues[$index] ?? throw new \LogicException('No request latencies were recorded.'));
    }
}

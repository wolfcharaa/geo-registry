<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Console\Command;

use Amp\Cancellation;

use function Amp\delay;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Application\Console\Command\HttpLoadCheckOptions;
use App\Application\Console\Command\HttpLoadCheckRunner;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpLoadCheckRunnerTest
{
    public function testItBoundsConcurrencyAndReportsUnexpectedStatuses(): void
    {
        $client = new ConcurrentLoadCheckClient();
        $report = (new HttpLoadCheckRunner($client, 'geo-registry-test'))->run(new HttpLoadCheckOptions(
            url: 'http://localhost/health/live',
            requests: 6,
            concurrency: 2,
            timeoutSeconds: 1,
            expectedStatus: 200,
            maxBodyBytes: 1024,
        ));

        Assert::same($report->attempts, 6);
        Assert::same($report->successes, 5);
        Assert::same($report->failures, 1);
        Assert::same($report->statusCounts, [200 => 5, 503 => 1]);
        Assert::same($report->errorCounts, []);
        Assert::same($report->responseBytes, 12);
        Assert::same($client->maxConcurrency, 2);
        Assert::true($report->durationMilliseconds > 0);
        Assert::true($report->requestsPerSecond > 0);
        Assert::true($report->p95Milliseconds >= $report->p50Milliseconds);
        Assert::same($report->passes(1, 0), true);
        Assert::same($report->passes(0, 0), false);
    }
}

final class ConcurrentLoadCheckClient implements DelegateHttpClient
{
    public int $maxConcurrency = 0;

    private int $activeRequests = 0;

    private int $requestNumber = 0;

    public function request(Request $request, Cancellation $cancellation): Response
    {
        $requestNumber = $this->requestNumber++;
        ++$this->activeRequests;
        $this->maxConcurrency = max($this->maxConcurrency, $this->activeRequests);

        try {
            delay(0.001, cancellation: $cancellation);
        } finally {
            --$this->activeRequests;
        }

        return new Response(
            '1.1',
            $requestNumber === 4 ? 503 : 200,
            null,
            ['content-type' => 'text/plain'],
            'ok',
            $request,
        );
    }
}

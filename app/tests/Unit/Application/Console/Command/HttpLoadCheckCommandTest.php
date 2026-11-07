<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Console\Command;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use App\Application\Console\Command\HttpLoadCheckCommand;
use App\Application\Console\Command\HttpLoadCheckRunner;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpLoadCheckCommandTest
{
    public function testItReturnsFailureWhenTheErrorBudgetIsExceeded(): void
    {
        $tester = new CommandTester(new HttpLoadCheckCommand(
            new HttpLoadCheckRunner(new FixedStatusLoadCheckClient(503), 'geo-registry-test'),
        ));

        $exitCode = $tester->execute([
            'url' => 'http://localhost/health/live',
            '--requests' => '2',
            '--concurrency' => '2',
            '--json' => true,
        ]);

        Assert::same($exitCode, Command::FAILURE);
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($report)) {
            throw new LogicException('Expected the command to return a JSON object.');
        }
        Assert::same($report['status'] ?? null, 'failed');
        Assert::same($report['failures'] ?? null, 2);
        Assert::same($report['http_statuses'] ?? null, ['503' => 2]);
    }

    public function testItRejectsCredentialsEmbeddedInTheTargetUrl(): void
    {
        $tester = new CommandTester(new HttpLoadCheckCommand(
            new HttpLoadCheckRunner(new FixedStatusLoadCheckClient(200), 'geo-registry-test'),
        ));

        $exitCode = $tester->execute([
            'url' => 'http://user:secret@localhost/health/live',
            '--requests' => '1',
            '--concurrency' => '1',
        ]);

        Assert::same($exitCode, Command::INVALID);
        Assert::true(str_contains($tester->getDisplay(), 'without embedded credentials or a fragment'));
    }
}

final readonly class FixedStatusLoadCheckClient implements DelegateHttpClient
{
    public function __construct(private int $status) {}

    public function request(Request $request, Cancellation $cancellation): Response
    {
        return new Response('1.1', $this->status, null, [], '{}', $request);
    }
}

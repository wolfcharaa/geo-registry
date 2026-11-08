<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserInterface\Http;

use function Amp\async;
use function Amp\ByteStream\buffer;

use Amp\DeferredFuture;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use App\Tests\Support\Http\TestClient;
use App\UserInterface\Http\HttpAdmissionMiddleware;
use League\Uri\Http;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Test;

#[Test]
final class HttpAdmissionMiddlewareTest
{
    public function testItRejectsExcessRequestsWithoutQueueingAndReleasesCapacity(): void
    {
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $middleware = new HttpAdmissionMiddleware(1, new NullLogger());
        $blockingHandler = new BlockingAdmissionHandler($entered, $release);
        $first = async(fn(): Response => $middleware->handleRequest(
            self::request('/first'),
            $blockingHandler,
        ));
        $entered->getFuture()->await();

        $rejectedHandler = new CountingAdmissionHandler();
        $rejected = $middleware->handleRequest(self::request('/second'), $rejectedHandler);
        /** @var array<string, mixed> $payload */
        $payload = json_decode(buffer($rejected->getBody()), true, flags: JSON_THROW_ON_ERROR);

        Assert::same($rejected->getStatus(), HttpStatus::SERVICE_UNAVAILABLE);
        Assert::same($rejected->getHeader('Retry-After'), '1');
        Assert::same($payload['error_code'] ?? null, 'http_capacity_exhausted');
        Assert::same($rejectedHandler->calls, 0);
        Assert::same($middleware->inFlightRequests(), 1);
        Assert::same($middleware->rejectedRequests(), 1);

        $release->complete();
        $firstResponse = $first->await();
        Assert::instanceOf($firstResponse, Response::class);
        Assert::same($firstResponse->getStatus(), HttpStatus::OK);
        Assert::same($middleware->inFlightRequests(), 0);

        $acceptedHandler = new CountingAdmissionHandler();
        Assert::same(
            $middleware->handleRequest(self::request('/third'), $acceptedHandler)->getStatus(),
            HttpStatus::OK,
        );
        Assert::same($acceptedHandler->calls, 1);
    }

    private static function request(string $path): Request
    {
        return new Request(new TestClient(), 'GET', Http::new('http://localhost' . $path));
    }
}

final readonly class BlockingAdmissionHandler implements RequestHandler
{
    /**
     * @param DeferredFuture<void> $entered
     * @param DeferredFuture<void> $release
     */
    public function __construct(
        private DeferredFuture $entered,
        private DeferredFuture $release,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if (!$this->entered->isComplete()) {
            $this->entered->complete();
        }
        $this->release->getFuture()->await();

        return new Response();
    }
}

final class CountingAdmissionHandler implements RequestHandler
{
    public int $calls = 0;

    public function handleRequest(Request $request): Response
    {
        ++$this->calls;

        return new Response();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserInterface\Http;

use function Amp\ByteStream\buffer;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use App\Application\Observability\CorrelationContext;
use App\Tests\Support\Http\TestClient;
use App\UserInterface\Http\CorrelationMiddleware;
use League\Uri\Http;
use LogicException;
use Psr\Log\NullLogger;
use Testo\Assert;
use Testo\Test;

#[Test]
final class CorrelationMiddlewareTest
{
    public function testItPropagatesSafeHeadersAndClearsTheRequestContext(): void
    {
        $context = new CorrelationContext();
        $middleware = new CorrelationMiddleware($context, new NullLogger());
        $request = new Request(
            new TestClient(),
            'GET',
            Http::new('http://localhost/health/live'),
            [
                'X-Request-ID' => 'request-test',
                'X-Correlation-ID' => 'correlation-test',
            ],
        );

        $response = $middleware->handleRequest($request, new CorrelationEchoHandler($context));

        Assert::same($response->getHeader('X-Request-ID'), 'request-test');
        Assert::same($response->getHeader('X-Correlation-ID'), 'correlation-test');
        Assert::same(buffer($response->getBody()), 'request-test:correlation-test');
        Assert::same($context->current(), null);
    }
}

final readonly class CorrelationEchoHandler implements RequestHandler
{
    public function __construct(private CorrelationContext $context) {}

    public function handleRequest(Request $request): Response
    {
        $ids = $this->context->current()
            ?? throw new LogicException('Correlation context is missing inside the request handler.');

        return new Response(body: sprintf('%s:%s', $ids->requestId, $ids->correlationId));
    }
}

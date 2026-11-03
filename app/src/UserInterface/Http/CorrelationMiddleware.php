<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use App\Application\Observability\CorrelationContext;
use App\Application\Observability\CorrelationIds;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class CorrelationMiddleware implements Middleware
{
    public function __construct(
        private CorrelationContext $context,
        private LoggerInterface $logger,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $requestId = self::headerId($request, 'X-Request-ID') ?? self::generateId();
        $correlationId = self::headerId($request, 'X-Correlation-ID') ?? $requestId;
        $startedAt = hrtime(true);

        return $this->context->run(
            new CorrelationIds(requestId: $requestId, correlationId: $correlationId),
            function () use ($request, $requestHandler, $requestId, $correlationId, $startedAt): Response {
                $status = null;

                try {
                    $response = $requestHandler->handleRequest($request);
                    $status = $response->getStatus();
                    $response->setHeader('X-Request-ID', $requestId);
                    $response->setHeader('X-Correlation-ID', $correlationId);

                    return $response;
                } catch (Throwable $exception) {
                    $this->logger->error('HTTP request failed', [
                        'event' => 'http.request.failed',
                        'exception_type' => $exception::class,
                        'method' => $request->getMethod(),
                        'path' => $request->getUri()->getPath(),
                    ]);

                    throw $exception;
                } finally {
                    $this->logger->info('HTTP request completed', [
                        'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
                        'event' => 'http.request.completed',
                        'method' => $request->getMethod(),
                        'path' => $request->getUri()->getPath(),
                        'status' => $status,
                    ]);
                }
            },
        );
    }

    private static function headerId(Request $request, string $name): ?string
    {
        $value = $request->getHeader($name);

        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) === 1
            ? $value
            : null;
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}

<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class HttpAdmissionMiddleware implements Middleware
{
    private int $inFlightRequests = 0;

    private int $rejectedRequests = 0;

    public function __construct(
        private readonly int $concurrencyLimit,
        private readonly LoggerInterface $logger,
    ) {
        if ($this->concurrencyLimit < 1) {
            throw new InvalidArgumentException('HTTP admission concurrency limit must be positive.');
        }
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if ($this->inFlightRequests >= $this->concurrencyLimit) {
            ++$this->rejectedRequests;
            $this->logger->warning('HTTP request rejected because admission capacity is exhausted', [
                'event' => 'http.request.admission.rejected',
                'concurrency_limit' => $this->concurrencyLimit,
                'in_flight_requests' => $this->inFlightRequests,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'rejected_requests' => $this->rejectedRequests,
            ]);

            $response = JsonResponse::create([
                'success' => false,
                'error' => HttpStatus::getReason(HttpStatus::SERVICE_UNAVAILABLE),
                'error_code' => 'http_capacity_exhausted',
                'retryable' => true,
            ], HttpStatus::SERVICE_UNAVAILABLE);
            $response->setHeader('Retry-After', '1');

            return $response;
        }

        ++$this->inFlightRequests;

        try {
            return $requestHandler->handleRequest($request);
        } finally {
            --$this->inFlightRequests;
        }
    }

    public function inFlightRequests(): int
    {
        return $this->inFlightRequests;
    }

    public function rejectedRequests(): int
    {
        return $this->rejectedRequests;
    }
}

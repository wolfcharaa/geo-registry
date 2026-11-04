<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use App\Application\Health\HealthRegistry;
use App\Application\Health\HealthReport;
use App\Application\Health\HealthStatus;
use App\Infrastructure\Nominatim\NominatimClient;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\FindAddressByIdAction;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class RouterFactory
{
    public function __construct(
        private LoggerInterface $logger,
        private HealthRegistry $health,
        private NominatimClient $nominatim,
        private OpenSearchClient $openSearch,
        private string $openSearchIndex,
        private CorrelationMiddleware $correlationMiddleware,
        private FindAddressByIdAction $findAddressById,
    ) {}

    public function create(HttpServer $server, ErrorHandler $errorHandler): Router
    {
        $router = new Router($server, $this->logger, $errorHandler);
        $router->addMiddleware($this->correlationMiddleware);

        $router->addRoute('GET', '/health/live', new ClosureRequestHandler(
            fn(Request $_request): Response => $this->healthResponse($this->health->liveness()),
        ));

        $router->addRoute('GET', '/health/ready', new ClosureRequestHandler(
            fn(Request $_request): Response => $this->healthResponse($this->health->readiness()),
        ));

        $router->addRoute('GET', '/health/startup', new ClosureRequestHandler(
            fn(Request $_request): Response => $this->healthResponse($this->health->startup()),
        ));

        $router->addRoute('POST', '/api/addresses/find-by-id', new ClosureRequestHandler(
            fn(Request $request): Response => $this->findAddressById->handle($request),
        ));

        $router->addRoute('GET', '/geocode', new ClosureRequestHandler(
            fn(Request $request): Response => $this->geocode($request),
        ));

        $router->addRoute('GET', '/search', new ClosureRequestHandler(
            fn(Request $request): Response => $this->search($request),
        ));

        return $router;
    }

    private function healthResponse(HealthReport $report): Response
    {
        return JsonResponse::create(
            $report->toArray(),
            $report->status === HealthStatus::Unavailable ? HttpStatus::SERVICE_UNAVAILABLE : HttpStatus::OK,
        );
    }

    private function geocode(Request $request): Response
    {
        $query = self::queryParameter($request, 'q');
        if ($query === null) {
            return JsonResponse::create(['error' => 'The q query parameter is required.'], HttpStatus::BAD_REQUEST);
        }

        try {
            return JsonResponse::create($this->nominatim->search($query, self::limit($request)));
        } catch (Throwable $exception) {
            $this->logger->error('Nominatim search failed', [
                'event' => 'integration.nominatim.search.failed',
                'exception_type' => $exception::class,
                'integration' => 'nominatim',
            ]);

            return JsonResponse::create(['error' => 'Nominatim is unavailable.'], HttpStatus::BAD_GATEWAY);
        }
    }

    private function search(Request $request): Response
    {
        $query = self::queryParameter($request, 'q');
        if ($query === null) {
            return JsonResponse::create(['error' => 'The q query parameter is required.'], HttpStatus::BAD_REQUEST);
        }

        try {
            return JsonResponse::create($this->openSearch->search(
                $this->openSearchIndex,
                ['simple_query_string' => ['query' => $query]],
                self::limit($request),
            ));
        } catch (Throwable $exception) {
            $this->logger->error('OpenSearch query failed', [
                'event' => 'integration.opensearch.search.failed',
                'exception_type' => $exception::class,
                'integration' => 'opensearch',
            ]);

            return JsonResponse::create(['error' => 'OpenSearch is unavailable.'], HttpStatus::BAD_GATEWAY);
        }
    }

    private static function queryParameter(Request $request, string $name): ?string
    {
        parse_str($request->getUri()->getQuery(), $parameters);
        $value = $parameters[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function limit(Request $request): int
    {
        $value = self::queryParameter($request, 'limit');

        return $value !== null && ctype_digit($value) ? max(1, min(50, (int) $value)) : 5;
    }
}

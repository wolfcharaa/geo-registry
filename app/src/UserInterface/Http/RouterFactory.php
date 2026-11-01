<?php

declare(strict_types=1);

namespace App\UserInterface\Http;

use function Amp\async;

use Amp\Http\HttpStatus;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Postgres\PostgresConnectionPool;
use App\Infrastructure\Nominatim\NominatimClient;
use App\Infrastructure\OpenSearch\OpenSearchClient;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final readonly class RouterFactory
{
    public function __construct(
        private LoggerInterface $logger,
        private PostgresConnectionPool $postgres,
        private NominatimClient $nominatim,
        private OpenSearchClient $openSearch,
        private string $openSearchIndex,
    ) {}

    public function create(HttpServer $server, ErrorHandler $errorHandler): Router
    {
        $router = new Router($server, $this->logger, $errorHandler);

        $router->addRoute('GET', '/health/live', new ClosureRequestHandler(
            static fn(Request $_request): Response => JsonResponse::create(['status' => 'ok']),
        ));

        $router->addRoute('GET', '/health/ready', new ClosureRequestHandler(
            fn(Request $_request): Response => $this->readiness(),
        ));

        $router->addRoute('GET', '/geocode', new ClosureRequestHandler(
            fn(Request $request): Response => $this->geocode($request),
        ));

        $router->addRoute('GET', '/search', new ClosureRequestHandler(
            fn(Request $request): Response => $this->search($request),
        ));

        return $router;
    }

    private function readiness(): Response
    {
        $checks = [
            'nominatim' => async(fn(): array => $this->nominatim->status()),
            'opensearch' => async(fn(): array => $this->openSearch->health()),
            'postgres' => async(fn() => $this->checkPostgres()),
        ];
        $ready = true;
        $details = [];

        foreach ($checks as $name => $future) {
            try {
                $future->await();
                $details[$name] = 'ok';
            } catch (Throwable $exception) {
                $ready = false;
                $details[$name] = 'unavailable';
                $this->logger->warning('Readiness check failed', [
                    'exception' => $exception,
                    'integration' => $name,
                ]);
            }
        }

        return JsonResponse::create(
            ['status' => $ready ? 'ok' : 'unavailable', 'checks' => $details],
            $ready ? HttpStatus::OK : HttpStatus::SERVICE_UNAVAILABLE,
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
            $this->logger->error('Nominatim search failed', ['exception' => $exception]);

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
            $this->logger->error('OpenSearch query failed', ['exception' => $exception]);

            return JsonResponse::create(['error' => 'OpenSearch is unavailable.'], HttpStatus::BAD_GATEWAY);
        }
    }

    private function checkPostgres(): void
    {
        foreach ($this->postgres->query('SELECT 1 AS ready') as $_row) {
            return;
        }

        throw new RuntimeException('PostgreSQL readiness query returned no rows.');
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

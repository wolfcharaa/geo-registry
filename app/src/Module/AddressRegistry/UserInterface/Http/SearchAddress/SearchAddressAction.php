<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SearchAddress;

use Amp\CancelledException;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddress;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressUnavailable;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\QueryBusInterface;

final readonly class SearchAddressAction
{
    public function __construct(
        private SearchAddressRequestMapper $requestMapper,
        private SearchAddressPresenter $presenter,
        private QueryBusInterface $messageBus,
        private float $timeoutSeconds,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $input = $this->requestMapper->map($request);
        } catch (InvalidSearchAddressRequest $exception) {
            return self::json(
                $this->presenter->error(
                    $exception->getMessage(),
                    $exception->errorCode,
                    $exception->query,
                ),
                HttpStatus::BAD_REQUEST,
            );
        }

        $query = $input->queryContext();
        try {
            $result = $this->messageBus->ask(
                new SearchAddress(
                    query: $input->normalizedQuery(),
                    hierarchy: $input->normalizedHierarchy(),
                    profile: $input->normalizedProfile(),
                    limit: $input->normalizedLimit(),
                    regionCode: $input->normalizedRegionCode(),
                ),
                new ExecutionOptions(cancellation: new TimeoutCancellation($this->timeoutSeconds)),
            );
        } catch (CancelledException|SearchAddressUnavailable) {
            return self::json(
                $this->presenter->error(
                    'Search service unavailable',
                    'search_unavailable',
                    $query,
                ),
                HttpStatus::SERVICE_UNAVAILABLE,
            );
        }

        return self::json($this->presenter->success($result, $query));
    }

    private static function json(mixed $payload, int $status = HttpStatus::OK): Response
    {
        return new Response(
            status: $status,
            headers: ['content-type' => 'application/json; charset=utf-8'],
            body: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}

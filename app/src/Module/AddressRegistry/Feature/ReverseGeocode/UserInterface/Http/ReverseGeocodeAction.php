<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\ReverseGeocode\UserInterface\Http;

use Amp\CancelledException;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressUnavailable;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocode;
use App\Module\AddressRegistry\Feature\ReverseGeocode\Capability\ReverseGeocodeUnavailable;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\QueryBusInterface;

final readonly class ReverseGeocodeAction
{
    public function __construct(
        private ReverseGeocodeRequestMapper $requestMapper,
        private ReverseGeocodePresenter $presenter,
        private QueryBusInterface $messageBus,
        private float $timeoutSeconds,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $input = $this->requestMapper->map($request);
        } catch (InvalidReverseGeocodeRequest $exception) {
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
                new ReverseGeocode(
                    latitude: $input->normalizedLatitude(),
                    longitude: $input->normalizedLongitude(),
                    hierarchy: $input->normalizedHierarchy(),
                    limit: $input->normalizedLimit(),
                ),
                new ExecutionOptions(cancellation: new TimeoutCancellation($this->timeoutSeconds)),
            );
        } catch (CancelledException|ReverseGeocodeUnavailable|SearchAddressUnavailable) {
            return self::json(
                $this->presenter->error(
                    'Geocoding service unavailable',
                    'geocoding_unavailable',
                    $query,
                ),
                HttpStatus::SERVICE_UNAVAILABLE,
            );
        }

        return self::json($this->presenter->success($result, $query, $input->normalizedHierarchy()));
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

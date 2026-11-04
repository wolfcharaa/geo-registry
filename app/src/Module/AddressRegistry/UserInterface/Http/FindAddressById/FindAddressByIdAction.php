<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\FindAddressById;

use Amp\CancelledException;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressById;
use App\Module\AddressRegistry\Capability\FindAddressById\FindAddressByIdUnavailable;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\QueryBusInterface;

final readonly class FindAddressByIdAction
{
    public function __construct(
        private FindAddressByIdRequestMapper $requestMapper,
        private FindAddressByIdPresenter $presenter,
        private QueryBusInterface $messageBus,
        private float $timeoutSeconds,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $input = $this->requestMapper->map($request);
        } catch (InvalidFindAddressByIdRequest $exception) {
            return self::json(
                $this->presenter->error($exception->getMessage(), $exception->errorCode()),
                HttpStatus::BAD_REQUEST,
            );
        }

        try {
            $result = $this->messageBus->ask(
                new FindAddressById($input->id(), $input->normalizedLimit()),
                new ExecutionOptions(cancellation: new TimeoutCancellation($this->timeoutSeconds)),
            );
        } catch (CancelledException|FindAddressByIdUnavailable) {
            return self::json(
                $this->presenter->error(
                    'Search service unavailable',
                    'search_unavailable',
                    ['query' => $input->id(), 'limit' => $input->normalizedLimit()],
                ),
                HttpStatus::SERVICE_UNAVAILABLE,
            );
        }

        return self::json($this->presenter->success(
            $result,
            $input->id(),
            $input->normalizedLimit(),
        ));
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

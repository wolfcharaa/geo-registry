<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SuggestAddress;

use Amp\CancelledException;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchAddressUnavailable;
use App\Module\AddressRegistry\Capability\SuggestAddress\SuggestAddress;
use App\Module\AddressRegistry\Capability\SuggestAddressUnavailable;
use App\Module\AddressRegistry\Capability\SuggestionContextNotFound;
use Fedor\MessageBus\Execution\ExecutionOptions;
use Fedor\MessageBus\QueryBusInterface;

final readonly class SuggestAddressAction
{
    public function __construct(
        private SuggestAddressRequestMapper $requestMapper,
        private SuggestAddressPresenter $presenter,
        private QueryBusInterface $messageBus,
        private float $timeoutSeconds,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $input = $this->requestMapper->map($request);
        } catch (InvalidSuggestAddressRequest $exception) {
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
                new SuggestAddress(
                    suggest: $input->normalizedSuggest(),
                    stage: $input->normalizedStage(),
                    hierarchy: $input->normalizedHierarchy(),
                    exact: $input->normalizedExact(),
                    count: $input->normalizedCount(),
                    parentId: $input->normalizedParentId(),
                ),
                new ExecutionOptions(cancellation: new TimeoutCancellation($this->timeoutSeconds)),
            );
        } catch (SuggestionContextNotFound) {
            return self::json(
                $this->presenter->error(
                    'Suggestion context was not found.',
                    'suggestion_context_not_found',
                    $query,
                ),
                HttpStatus::UNPROCESSABLE_ENTITY,
            );
        } catch (CancelledException|SuggestAddressUnavailable|SearchAddressUnavailable) {
            return self::json(
                $this->presenter->error(
                    'Suggestion service unavailable',
                    'suggest_unavailable',
                    $query,
                ),
                HttpStatus::SERVICE_UNAVAILABLE,
            );
        }

        return self::json($this->presenter->success($result, $query, $input->normalizedStage()));
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

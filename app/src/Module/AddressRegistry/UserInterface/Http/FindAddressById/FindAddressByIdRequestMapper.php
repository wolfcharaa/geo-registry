<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\FindAddressById;

use Amp\Http\Server\Request;
use JsonException;
use stdClass;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class FindAddressByIdRequestMapper
{
    public function __construct(private ValidatorInterface $validator) {}

    public function map(Request $request): FindAddressByIdRequest
    {
        $rawBody = trim($request->getBody()->buffer());
        $payload = $rawBody === '' ? [] : $this->decode($rawBody);
        $input = new FindAddressByIdRequest(
            query: $payload['query'] ?? '',
            count: $payload['count'] ?? 20,
            limit: $payload['limit'] ?? 20,
        );
        $violations = $this->validator->validate($input);
        if ($violations->count() === 0) {
            return $input;
        }

        $violation = $violations->get(0);
        $field = $violation->getPropertyPath();

        throw new InvalidFindAddressByIdRequest(
            $field !== '' ? $field : 'body',
            (string) $violation->getMessage(),
        );
    }

    /** @return array<array-key, mixed> */
    private function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidFindAddressByIdRequest('body', 'Request body must contain valid JSON.', $exception);
        }

        if (!$decoded instanceof stdClass) {
            throw new InvalidFindAddressByIdRequest('body', 'Request body must be a JSON object.');
        }

        return get_object_vars($decoded);
    }
}

<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SuggestAddress;

use Amp\Http\Server\Request;
use JsonException;
use stdClass;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class SuggestAddressRequestMapper
{
    private const FIELDS = ['suggest', 'stage', 'hierarchy', 'exact', 'count', 'context'];

    public function __construct(private ValidatorInterface $validator) {}

    public function map(Request $request): SuggestAddressRequest
    {
        $rawBody = trim($request->getBody()->buffer());
        $payload = $rawBody === '' ? [] : $this->decode($rawBody);
        $this->assertKnownFields($payload, self::FIELDS, 'Request body contains unsupported fields.');

        $context = $payload['context'] ?? null;
        if ($context instanceof stdClass) {
            $contextPayload = get_object_vars($context);
            $this->assertKnownFields($contextPayload, ['parent_id'], 'context contains unsupported fields.');
            $context = new SuggestAddressContextRequest($contextPayload['parent_id'] ?? null);
        }

        $input = new SuggestAddressRequest(
            suggest: $payload['suggest'] ?? '',
            stage: $payload['stage'] ?? '',
            hierarchy: $payload['hierarchy'] ?? '',
            exact: $payload['exact'] ?? '',
            count: $payload['count'] ?? 5,
            context: $context,
        );
        $violations = $this->validator->validate($input);
        if ($violations->count() === 0) {
            return $input;
        }

        $violation = $violations->get(0);
        $field = $violation->getPropertyPath();

        throw new InvalidSuggestAddressRequest(
            self::errorCode($field),
            (string) $violation->getMessage(),
            self::safeQuery($payload),
        );
    }

    /** @return array<array-key, mixed> */
    private function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidSuggestAddressRequest(
                'invalid_body',
                'Request body must contain valid JSON.',
                previous: $exception,
            );
        }

        if (!$decoded instanceof stdClass) {
            throw new InvalidSuggestAddressRequest('invalid_body', 'Request body must be a JSON object.');
        }

        return get_object_vars($decoded);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param list<string> $allowedFields
     */
    private function assertKnownFields(array $payload, array $allowedFields, string $message): void
    {
        foreach (array_keys($payload) as $field) {
            if (!is_string($field) || !in_array($field, $allowedFields, true)) {
                throw new InvalidSuggestAddressRequest('invalid_body', $message, self::safeQuery($payload));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, int|string|null>
     */
    private static function safeQuery(array $payload): array
    {
        $context = $payload['context'] ?? null;
        $parentId = $context instanceof stdClass ? ($context->parent_id ?? null) : null;

        return [
            'exact' => is_string($payload['exact'] ?? null) ? trim($payload['exact']) : '',
            'suggest' => is_string($payload['suggest'] ?? null) ? trim($payload['suggest']) : '',
            'stage' => is_string($payload['stage'] ?? null) ? $payload['stage'] : '',
            'hierarchy' => is_string($payload['hierarchy'] ?? null) ? $payload['hierarchy'] : '',
            'parent_id' => is_int($parentId) ? $parentId : null,
            'count' => is_int($payload['count'] ?? null) ? $payload['count'] : 5,
        ];
    }

    private static function errorCode(string $field): string
    {
        return match (true) {
            $field === 'stage' => 'invalid_stage',
            $field === 'suggest' => 'invalid_suggest',
            $field === 'hierarchy' => 'invalid_hierarchy',
            $field === 'exact' => 'invalid_exact',
            $field === 'count' => 'invalid_count',
            str_starts_with($field, 'context') => 'invalid_context',
            default => 'invalid_body',
        };
    }
}

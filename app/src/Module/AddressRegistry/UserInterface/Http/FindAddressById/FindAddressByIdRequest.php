<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\FindAddressById;

use LogicException;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class FindAddressByIdRequest
{
    public function __construct(
        #[Assert\Type(type: 'string', message: 'query must be a string.')]
        public mixed $query = '',
        #[Assert\NotNull(message: 'count must be an integer.')]
        #[Assert\Type(type: ['integer', 'digit'], message: 'count must be an integer.')]
        public mixed $count = 20,
        #[Assert\NotNull(message: 'limit must be an integer.')]
        #[Assert\Type(type: ['integer', 'digit'], message: 'limit must be an integer.')]
        public mixed $limit = 20,
    ) {}

    public function id(): string
    {
        return is_string($this->query) ? trim($this->query) : '';
    }

    public function normalizedLimit(): int
    {
        $count = self::integer($this->count);
        $limit = self::integer($this->limit);

        return max(1, min(20, $this->count !== 20 ? $count : $limit));
    }

    private static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new LogicException('FindAddressByIdRequest must be validated before normalization.');
    }
}

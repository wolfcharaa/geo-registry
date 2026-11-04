<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SuggestAddress;

use LogicException;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SuggestAddressContextRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'context.parent_id must be an integer.')]
        #[Assert\Type(type: ['integer', 'digit'], message: 'context.parent_id must be an integer.')]
        #[Assert\Positive(message: 'context.parent_id must be positive.')]
        public mixed $parentId,
    ) {}

    public function normalizedParentId(): int
    {
        if (is_int($this->parentId)) {
            return $this->parentId;
        }
        if (is_string($this->parentId) && ctype_digit($this->parentId)) {
            return (int) $this->parentId;
        }

        throw new LogicException('SuggestAddressContextRequest must be validated before normalization.');
    }
}

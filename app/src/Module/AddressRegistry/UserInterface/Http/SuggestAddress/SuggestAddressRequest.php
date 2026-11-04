<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SuggestAddress;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SuggestionStage;
use LogicException;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SuggestAddressRequest
{
    public function __construct(
        #[Assert\Type(type: 'string', message: 'suggest must be a string.')]
        #[Assert\NotBlank(message: 'suggest must not be empty.')]
        public mixed $suggest,
        #[Assert\Type(type: 'string', message: 'stage must be a string.')]
        #[Assert\Choice(
            choices: ['administrative_area', 'thoroughfare', 'search'],
            message: 'Unsupported suggestion stage.',
        )]
        public mixed $stage,
        #[Assert\Type(type: 'string', message: 'hierarchy must be a string.')]
        #[Assert\Choice(choices: ['adm', 'mun'], message: 'hierarchy must be adm or mun.')]
        public mixed $hierarchy,
        #[Assert\Type(type: 'string', message: 'exact must be a string.')]
        public mixed $exact = '',
        #[Assert\NotNull(message: 'count must be an integer.')]
        #[Assert\Type(type: ['integer', 'digit'], message: 'count must be an integer.')]
        #[Assert\Range(min: 1, max: 7, notInRangeMessage: 'count must be between 1 and 7.')]
        public mixed $count = 5,
        #[Assert\Type(type: SuggestAddressContextRequest::class, message: 'context must be an object.')]
        #[Assert\Valid]
        public mixed $context = null,
    ) {}

    public function normalizedSuggest(): string
    {
        return self::normalize($this->string($this->suggest));
    }

    public function normalizedStage(): SuggestionStage
    {
        return SuggestionStage::from($this->string($this->stage));
    }

    public function normalizedHierarchy(): AddressHierarchy
    {
        return AddressHierarchy::from($this->string($this->hierarchy));
    }

    public function normalizedExact(): string
    {
        return self::normalize($this->string($this->exact));
    }

    public function normalizedCount(): int
    {
        if (is_int($this->count)) {
            return $this->count;
        }
        if (is_string($this->count) && ctype_digit($this->count)) {
            return (int) $this->count;
        }

        throw new LogicException('SuggestAddressRequest must be validated before normalization.');
    }

    public function normalizedParentId(): ?int
    {
        if ($this->context === null) {
            return null;
        }
        if (!$this->context instanceof SuggestAddressContextRequest) {
            throw new LogicException('SuggestAddressRequest must be validated before normalization.');
        }

        return $this->context->normalizedParentId();
    }

    /** @return array<string, int|string|null> */
    public function queryContext(): array
    {
        return [
            'exact' => $this->normalizedExact(),
            'suggest' => $this->normalizedSuggest(),
            'stage' => $this->normalizedStage()->value,
            'hierarchy' => $this->normalizedHierarchy()->value,
            'parent_id' => $this->normalizedParentId(),
            'count' => $this->normalizedCount(),
        ];
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new LogicException('SuggestAddressRequest must be validated before normalization.');
        }

        return $value;
    }

    private static function normalize(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return $normalized ?? trim($value);
    }
}

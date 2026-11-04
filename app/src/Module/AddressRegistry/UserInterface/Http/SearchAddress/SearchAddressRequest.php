<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\UserInterface\Http\SearchAddress;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use LogicException;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SearchAddressRequest
{
    public function __construct(
        #[Assert\Type(type: 'string', message: 'q must be a string.')]
        public mixed $query,
        #[Assert\Type(type: 'string', message: 'hier must be a string.')]
        #[Assert\Choice(choices: ['adm', 'mun'], message: 'hier must be adm or mun.')]
        public mixed $hierarchy = 'adm',
        #[Assert\Type(type: 'string', message: 'profile must be a string.')]
        #[Assert\Choice(choices: ['search', 'remediation-debug'], message: 'profile is not supported.')]
        public mixed $profile = 'search',
        #[Assert\NotNull(message: 'limit must be an integer.')]
        #[Assert\Type(type: ['integer', 'digit'], message: 'limit must be an integer.')]
        #[Assert\Range(min: 1, max: 50, notInRangeMessage: 'limit must be between 1 and 50.')]
        public mixed $limit = 10,
        #[Assert\Type(type: ['integer', 'digit'], message: 'region_code must be an integer.')]
        #[Assert\Range(min: 1, max: 99, notInRangeMessage: 'region_code must be between 1 and 99.')]
        public mixed $regionCode = null,
    ) {}

    public function normalizedQuery(): string
    {
        if (!is_string($this->query)) {
            throw new LogicException('SearchAddressRequest must be validated before normalization.');
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($this->query));

        return $normalized ?? trim($this->query);
    }

    public function normalizedHierarchy(): AddressHierarchy
    {
        return AddressHierarchy::from($this->string($this->hierarchy));
    }

    public function normalizedProfile(): SearchProfile
    {
        return SearchProfile::from($this->string($this->profile));
    }

    public function normalizedLimit(): int
    {
        return $this->integer($this->limit);
    }

    public function normalizedRegionCode(): ?int
    {
        return $this->regionCode === null ? null : $this->integer($this->regionCode);
    }

    /** @return array<string, int|string> */
    public function queryContext(): array
    {
        $query = [
            'q' => $this->normalizedQuery(),
            'hierarchy' => $this->normalizedHierarchy()->value,
            'limit' => $this->normalizedLimit(),
            'profile' => $this->normalizedProfile()->value,
        ];
        $regionCode = $this->normalizedRegionCode();
        if ($regionCode !== null) {
            $query['region_code'] = $regionCode;
        }

        return $query;
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new LogicException('SearchAddressRequest must be validated before normalization.');
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new LogicException('SearchAddressRequest must be validated before normalization.');
    }
}

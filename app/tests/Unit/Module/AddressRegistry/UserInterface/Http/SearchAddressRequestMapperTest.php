<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Http;

use Amp\Http\Server\Request;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SearchAddress\SearchProfile;
use App\Module\AddressRegistry\UserInterface\Http\SearchAddress\InvalidSearchAddressRequest;
use App\Module\AddressRegistry\UserInterface\Http\SearchAddress\SearchAddressRequestMapper;
use App\Tests\Support\Http\TestClient;
use League\Uri\Http;
use Symfony\Component\Validator\Validation;
use Testo\Assert;
use Testo\Test;

#[Test]
final class SearchAddressRequestMapperTest
{
    public function testItNormalizesTheAcceptedQueryParameters(): void
    {
        $input = $this->mapper()->map($this->request([
            'q' => '  Тестоград   Общая  ',
            'hier' => 'mun',
            'profile' => 'remediation-debug',
            'limit' => '25',
            'region_code' => '77',
        ]));

        Assert::same($input->normalizedQuery(), 'Тестоград Общая');
        Assert::same($input->normalizedHierarchy(), AddressHierarchy::Municipal);
        Assert::same($input->normalizedProfile(), SearchProfile::RemediationDebug);
        Assert::same($input->normalizedLimit(), 25);
        Assert::same($input->normalizedRegionCode(), 77);
        Assert::same($input->queryContext(), [
            'q' => 'Тестоград Общая',
            'hierarchy' => 'mun',
            'limit' => 25,
            'profile' => 'remediation-debug',
            'region_code' => 77,
        ]);
    }

    public function testItReturnsTheStableMissingQueryError(): void
    {
        $error = $this->error([]);

        Assert::same($error->errorCode, 'missing_query');
        Assert::same($error->getMessage(), 'Missing required parameter: q');
        Assert::same($error->query, [
            'q' => '',
            'hierarchy' => 'adm',
            'limit' => 10,
            'profile' => 'search',
        ]);
    }

    public function testItRejectsUnsupportedFiltersAndRanges(): void
    {
        foreach ([
            [['q' => ['Тестоград']], 'invalid_query'],
            [['q' => 'Тестоград', 'hier' => 'other'], 'invalid_hierarchy'],
            [['q' => 'Тестоград', 'profile' => 'other'], 'invalid_profile'],
            [['q' => 'Тестоград', 'limit' => '51'], 'invalid_limit'],
            [['q' => 'Тестоград', 'region_code' => '100'], 'invalid_region_code'],
        ] as [$parameters, $code]) {
            Assert::same($this->error($parameters)->errorCode, $code);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function error(array $parameters): InvalidSearchAddressRequest
    {
        try {
            $this->mapper()->map($this->request($parameters));
        } catch (InvalidSearchAddressRequest $exception) {
            return $exception;
        }

        throw new \LogicException('Expected invalid search request.');
    }

    private function mapper(): SearchAddressRequestMapper
    {
        return new SearchAddressRequestMapper(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }

    /** @param array<string, mixed> $parameters */
    private function request(array $parameters): Request
    {
        return new Request(
            new TestClient(),
            'GET',
            Http::new('http://localhost/api/addresses/search?' . http_build_query($parameters)),
        );
    }
}

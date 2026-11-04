<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Http;

use Amp\Http\Server\Request;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\FindAddressByIdRequestMapper;
use App\Module\AddressRegistry\UserInterface\Http\FindAddressById\InvalidFindAddressByIdRequest;
use App\Tests\Support\Http\TestClient;
use League\Uri\Http;
use Symfony\Component\Validator\Validation;
use Testo\Assert;
use Testo\Test;

#[Test]
final class FindAddressByIdRequestMapperTest
{
    public function testItNormalizesLegacyCountAndLimitInputs(): void
    {
        $countInput = $this->mapper()->map($this->request(
            '{"query":"  770005  ","count":"99","limit":3}',
        ));
        $limitInput = $this->mapper()->map($this->request(
            '{"query":"00000000-0000-4000-8000-000000770005","limit":"5"}',
        ));

        Assert::same($countInput->id(), '770005');
        Assert::same($countInput->normalizedLimit(), 20);
        Assert::same($limitInput->normalizedLimit(), 5);
    }

    public function testItRejectsInvalidFieldTypesWithStableCodes(): void
    {
        $error = null;
        try {
            $this->mapper()->map($this->request('{"query":["770005"]}'));
        } catch (InvalidFindAddressByIdRequest $exception) {
            $error = $exception;
        }

        Assert::notNull($error);
        Assert::same($error->errorCode(), 'invalid_query');
        Assert::same($error->getMessage(), 'query must be a string.');
    }

    public function testItRejectsMalformedJsonAndJsonLists(): void
    {
        foreach (['{', '[]'] as $body) {
            $error = null;
            try {
                $this->mapper()->map($this->request($body));
            } catch (InvalidFindAddressByIdRequest $exception) {
                $error = $exception;
            }

            Assert::notNull($error);
            Assert::same($error->errorCode(), 'invalid_body');
        }
    }

    private function mapper(): FindAddressByIdRequestMapper
    {
        return new FindAddressByIdRequestMapper(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }

    private function request(string $body): Request
    {
        return new Request(
            new TestClient(),
            'POST',
            Http::new('http://localhost/api/addresses/find-by-id'),
            ['content-type' => 'application/json'],
            $body,
        );
    }
}

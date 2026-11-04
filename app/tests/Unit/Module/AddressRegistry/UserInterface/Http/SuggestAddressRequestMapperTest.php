<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\UserInterface\Http;

use Amp\Http\Server\Request;
use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\SuggestionStage;
use App\Module\AddressRegistry\UserInterface\Http\SuggestAddress\InvalidSuggestAddressRequest;
use App\Module\AddressRegistry\UserInterface\Http\SuggestAddress\SuggestAddressRequestMapper;
use App\Tests\Support\Http\TestClient;
use League\Uri\Http;
use Symfony\Component\Validator\Validation;
use Testo\Assert;
use Testo\Test;

#[Test]
final class SuggestAddressRequestMapperTest
{
    public function testItMapsARegionalStageAndNormalizesWhitespace(): void
    {
        $input = $this->mapper()->map($this->request(<<<'JSON'
{
  "suggest": "  Общая   ",
  "stage": "thoroughfare",
  "hierarchy": "mun",
  "exact": " Тестоград ",
  "count": "7",
  "context": {"parent_id": "770002"}
}
JSON));

        Assert::same($input->normalizedSuggest(), 'Общая');
        Assert::same($input->normalizedExact(), 'Тестоград');
        Assert::same($input->normalizedStage(), SuggestionStage::Thoroughfare);
        Assert::same($input->normalizedHierarchy(), AddressHierarchy::Municipal);
        Assert::same($input->normalizedCount(), 7);
        Assert::same($input->normalizedParentId(), 770002);
    }

    public function testItRejectsInvalidStageAndContextWithStableCodes(): void
    {
        $cases = [
            ['{"suggest":"Общая","stage":"unknown","hierarchy":"mun"}', 'invalid_stage'],
            [
                '{"suggest":"Общая","stage":"thoroughfare","hierarchy":"mun","context":{"parent_id":0}}',
                'invalid_context',
            ],
        ];

        foreach ($cases as [$body, $errorCode]) {
            $error = null;
            try {
                $this->mapper()->map($this->request($body));
            } catch (InvalidSuggestAddressRequest $exception) {
                $error = $exception;
            }

            Assert::notNull($error);
            Assert::same($error->errorCode, $errorCode);
        }
    }

    public function testItRejectsMalformedBodiesAndUnknownFields(): void
    {
        foreach (['{', '[]', '{"suggest":"Альфа","stage":"administrative_area","hierarchy":"adm","extra":1}'] as $body) {
            $error = null;
            try {
                $this->mapper()->map($this->request($body));
            } catch (InvalidSuggestAddressRequest $exception) {
                $error = $exception;
            }

            Assert::notNull($error);
            Assert::same($error->errorCode, 'invalid_body');
        }
    }

    private function mapper(): SuggestAddressRequestMapper
    {
        return new SuggestAddressRequestMapper(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }

    private function request(string $body): Request
    {
        return new Request(
            new TestClient(),
            'POST',
            Http::new('http://localhost/api/addresses/suggest'),
            ['content-type' => 'application/json'],
            $body,
        );
    }
}

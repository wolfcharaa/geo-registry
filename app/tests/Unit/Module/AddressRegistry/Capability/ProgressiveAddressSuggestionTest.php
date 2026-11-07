<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\AddressRegistry\Capability;

use App\Module\AddressRegistry\Capability\AddressHierarchy;
use App\Module\AddressRegistry\Capability\ProgressiveAddressSuggestion;
use App\Module\AddressRegistry\Capability\SuggestionStage;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Test;
use UnexpectedValueException;

#[Test]
final class ProgressiveAddressSuggestionTest
{
    public function testFederalCitiesProceedDirectlyToThoroughfares(): void
    {
        foreach (['Москва', 'Санкт-Петербург', 'Севастополь'] as $name) {
            $suggestion = $this->suggestion($name, 'г.', 1);

            Assert::same($suggestion->nextStage, SuggestionStage::Thoroughfare);
            Assert::false($suggestion->readyForSearch);
        }
    }

    public function testOrdinaryFirstLevelRegionKeepsAdministrativeAreaTransition(): void
    {
        $suggestion = $this->suggestion('Московская область', 'обл.', 1);

        Assert::same($suggestion->nextStage, SuggestionStage::AdministrativeArea);
        Assert::false($suggestion->readyForSearch);
    }

    public function testAdministrativeAndPlanningLevelsProceedToThoroughfares(): void
    {
        foreach ([2, 5, 7] as $level) {
            $suggestion = $this->suggestion('Одинцово', 'г.', $level);

            Assert::same($suggestion->nextStage, SuggestionStage::Thoroughfare);
            Assert::false($suggestion->readyForSearch);
        }
    }

    public function testRoadNetworkLevelIsReadyForSearch(): void
    {
        $suggestion = $this->suggestion('Можайское', 'ш.', 8);

        Assert::same($suggestion->nextStage, SuggestionStage::Search);
        Assert::true($suggestion->readyForSearch);
    }

    #[ExpectException(UnexpectedValueException::class)]
    public function testItRejectsAddressObjectLevels(): void
    {
        $this->suggestion('Дом', 'д.', 9);
    }

    private function suggestion(string $name, string $typeName, int $level): ProgressiveAddressSuggestion
    {
        return new ProgressiveAddressSuggestion(
            objectId: 1405113,
            objectGuid: '0c5b2444-70a0-4932-980c-b4dc0d3f02b5',
            name: $name,
            typeName: $typeName,
            hierarchy: AddressHierarchy::Municipal,
            level: $level,
            regionCode: 77,
            district: null,
        );
    }
}

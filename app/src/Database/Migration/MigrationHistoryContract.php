<?php

declare(strict_types=1);

namespace App\Database\Migration;

use Yiisoft\Db\Migration\MigrationInterface;

final class MigrationHistoryContract
{
    /** @var list<class-string<MigrationInterface>> */
    public const CLASSES = [
        M260902040000CreateGarSchema::class,
        M260902050000EnableGeoExtensions::class,
        M260902060000AllowNullMunicipalOktmo::class,
        M260902070000AllowNullNormativeDocName::class,
        M260902080000RelaxAddressObjectDivisionLinks::class,
        M260902090000AllowPartialHouseNumbers::class,
        M260902100000RelaxGarVersionLinks::class,
        M260902110000UseTextForGarUnitNumbers::class,
        M260902120000RelaxHierarchyCrossLinks::class,
        M260902130000CreateAddressSearch::class,
        M260902140000AddAddressSearchBuildIndexes::class,
        M260902150000CreateAddressSearchBuildProgress::class,
        M260903040000AddAddressSearchGeocodeMetadata::class,
        M260903050000IndexParserLogs::class,
        M260903090000RelaxChangeHistoryDocumentLink::class,
        M260903100000CreateGarImportIndexSnapshots::class,
        M260903110000AllowNullGarUnitTypeIds::class,
        M260903120000AddAddressSearchTaxCodes::class,
        M260903130000RebuildObjectParamsDenormIndex::class,
        M260903140000AddDataMosAddressRegistry::class,
        M260904010000CreateAddressAliases::class,
    ];

    private function __construct() {}
}

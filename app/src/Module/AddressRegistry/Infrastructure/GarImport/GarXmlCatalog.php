<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Infrastructure\GarImport;

use App\Module\AddressRegistry\Capability\ImportGar\GarImportFailed;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class GarXmlCatalog
{
    /** @var list<GarXmlFileDescriptor> */
    private array $descriptors;

    public function __construct()
    {
        $this->descriptors = self::descriptors();
    }

    /** @return list<GarXmlSourceFile> */
    public function files(string $sourcePath): array
    {
        $source = realpath($sourcePath);
        if ($source === false) {
            throw new GarImportFailed("GAR import source does not exist: {$sourcePath}");
        }

        if (is_file($source)) {
            $descriptor = $this->descriptorFor(basename($source));
            if ($descriptor === null) {
                throw new GarImportFailed('The specified XML file is not supported by the GAR catalog.');
            }

            return [$this->sourceFile($source, basename($source), $descriptor)];
        }
        if (!is_dir($source)) {
            throw new GarImportFailed('GAR import source must be an XML file or a directory.');
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $descriptor = $this->descriptorFor($item->getFilename());
            if ($descriptor === null) {
                continue;
            }
            $path = $item->getRealPath();
            if ($path === false) {
                throw new GarImportFailed("Unable to resolve GAR XML file: {$item->getPathname()}");
            }
            $relativePath = str_replace('\\', '/', substr($path, strlen($source) + 1));
            $files[] = $this->sourceFile($path, $relativePath, $descriptor);
        }

        if ($files === []) {
            throw new GarImportFailed('GAR import source does not contain supported XML files.');
        }

        usort(
            $files,
            static fn(GarXmlSourceFile $left, GarXmlSourceFile $right): int => [
                $left->descriptor->priority,
                $left->relativePath,
            ] <=> [
                $right->descriptor->priority,
                $right->relativePath,
            ],
        );

        return $files;
    }

    public function canonicalSourcePath(string $sourcePath): string
    {
        $source = realpath($sourcePath);
        if ($source === false) {
            throw new GarImportFailed("GAR import source does not exist: {$sourcePath}");
        }

        return $source;
    }

    public function file(string $sourcePath, string $relativePath): GarXmlSourceFile
    {
        $source = $this->canonicalSourcePath($sourcePath);
        $normalizedRelativePath = str_replace('\\', '/', $relativePath);
        if (
            $normalizedRelativePath === ''
            || str_starts_with($normalizedRelativePath, '/')
            || str_contains($normalizedRelativePath, "\0")
        ) {
            throw new GarImportFailed('GAR import worker received an invalid relative XML path.');
        }

        if (is_file($source)) {
            if ($normalizedRelativePath !== basename($source)) {
                throw new GarImportFailed("GAR XML file is outside the import source: {$relativePath}");
            }
            $path = $source;
        } else {
            $candidate = realpath($source . DIRECTORY_SEPARATOR . $normalizedRelativePath);
            if (
                $candidate === false
                || !is_file($candidate)
                || !str_starts_with($candidate, $source . DIRECTORY_SEPARATOR)
            ) {
                throw new GarImportFailed("GAR XML file is outside the import source: {$relativePath}");
            }
            $path = $candidate;
            $resolvedRelativePath = str_replace('\\', '/', substr($path, strlen($source) + 1));
            if ($resolvedRelativePath !== $normalizedRelativePath) {
                throw new GarImportFailed("GAR XML relative path is not canonical: {$relativePath}");
            }
        }

        $descriptor = $this->descriptorFor(basename($path));
        if ($descriptor === null) {
            throw new GarImportFailed("GAR XML file is not supported by the catalog: {$relativePath}");
        }

        return $this->sourceFile($path, $normalizedRelativePath, $descriptor);
    }

    /** @param list<GarXmlSourceFile> $files */
    public function manifestHash(array $files): string
    {
        return hash('sha256', implode("\n", array_map(
            static fn(GarXmlSourceFile $file): string => $file->key,
            $files,
        )));
    }

    private function descriptorFor(string $filename): ?GarXmlFileDescriptor
    {
        foreach ($this->descriptors as $descriptor) {
            if ($descriptor->matches($filename)) {
                return $descriptor;
            }
        }

        return null;
    }

    private function sourceFile(string $path, string $relativePath, GarXmlFileDescriptor $descriptor): GarXmlSourceFile
    {
        $size = filesize($path);
        $modifiedAt = filemtime($path);
        if (!is_int($size) || !is_int($modifiedAt)) {
            throw new GarImportFailed("Unable to read GAR XML metadata: {$relativePath}");
        }

        return new GarXmlSourceFile(
            path: $path,
            relativePath: $relativePath,
            size: $size,
            modifiedAt: $modifiedAt,
            key: hash('sha256', implode('|', [
                $descriptor->key,
                $relativePath,
                (string) $size,
                (string) $modifiedAt,
            ])),
            descriptor: $descriptor,
        );
    }

    /** @return list<GarXmlFileDescriptor> */
    private static function descriptors(): array
    {
        $standardTypeColumns = [
            'id' => 'ID',
            'name' => 'NAME',
            'short_name' => 'SHORTNAME',
            'descr' => 'DESC',
            'is_active' => 'ISACTIVE',
            'update_date' => 'UPDATEDATE',
            'start_date' => 'STARTDATE',
            'end_date' => 'ENDDATE',
        ];
        $unitColumns = [
            'id' => 'ID',
            'reestr_object_id' => 'OBJECTID',
            'reestr_object_guid' => 'OBJECTGUID',
            'change_id' => 'CHANGEID',
            'number' => 'NUMBER',
            'oper_type_id' => 'OPERTYPEID',
            'prev_id' => 'PREVID',
            'next_id' => 'NEXTID',
            'update_date' => 'UPDATEDATE',
            'start_date' => 'STARTDATE',
            'end_date' => 'ENDDATE',
            'is_actual' => 'ISACTUAL',
            'is_active' => 'ISACTIVE',
        ];
        $unitZeroAsNullColumns = ['change_id', 'prev_id', 'next_id'];

        return [
            self::descriptor('house-types', '~^AS_HOUSE_TYPES(?:_.*)?\\.XML$~i', 'house_types', 10, $standardTypeColumns),
            self::descriptor('house-address-types', '~^AS_ADDHOUSE_TYPES(?:_.*)?\\.XML$~i', 'house_addr_types', 10, $standardTypeColumns),
            self::descriptor('address-object-types', '~^AS_ADDR_OBJ_TYPES(?:_.*)?\\.XML$~i', 'address_object_types', 10, [
                'id' => 'ID', 'level' => 'LEVEL', 'name' => 'NAME', 'short_name' => 'SHORTNAME',
                'descr' => 'DESC', 'is_active' => 'ISACTIVE', 'update_date' => 'UPDATEDATE',
                'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE',
            ]),
            self::descriptor('apartment-types', '~^AS_APARTMENT_TYPES(?:_.*)?\\.XML$~i', 'apartment_types', 10, $standardTypeColumns),
            self::descriptor('normative-document-kinds', '~^AS_NORMATIVE_DOCS_KINDS(?:_.*)?\\.XML$~i', 'normative_doc_kinds', 10, [
                'id' => 'ID', 'name' => 'NAME',
            ]),
            self::descriptor('normative-document-types', '~^AS_NORMATIVE_DOCS_TYPES(?:_.*)?\\.XML$~i', 'normative_doc_types', 10, [
                'id' => 'ID', 'name' => 'NAME', 'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE',
            ]),
            self::descriptor('object-levels', '~^AS_OBJECT_LEVELS(?:_.*)?\\.XML$~i', 'object_levels', 10, [
                'id' => 'LEVEL', 'name' => 'NAME', 'is_active' => 'ISACTIVE', 'start_date' => 'STARTDATE',
                'end_date' => 'ENDDATE', 'update_date' => 'UPDATEDATE',
            ]),
            self::descriptor('operation-types', '~^AS_OPERATION_TYPES(?:_.*)?\\.XML$~i', 'operation_types', 10, [
                'id' => 'ID', 'name' => 'NAME', 'is_active' => 'ISACTIVE', 'update_date' => 'UPDATEDATE',
                'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE',
            ]),
            self::descriptor('parameter-types', '~^AS_PARAM_TYPES(?:_.*)?\\.XML$~i', 'param_types', 10, [
                'id' => 'ID', 'name' => 'NAME', 'descr' => 'DESC', 'code' => 'CODE', 'is_active' => 'ISACTIVE',
                'update_date' => 'UPDATEDATE', 'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE',
            ]),
            self::descriptor('room-types', '~^AS_ROOM_TYPES(?:_.*)?\\.XML$~i', 'room_types', 10, $standardTypeColumns),
            self::descriptor('registry-objects', '~^AS_REESTR_OBJECTS(?:_.*)?\\.XML$~i', 'reestr_objects', 20, [
                'id' => 'OBJECTID', 'object_guid' => 'OBJECTGUID', 'change_id' => 'CHANGEID',
                'is_active' => 'ISACTIVE', 'level_id' => 'LEVELID', 'create_date' => 'CREATEDATE',
                'update_date' => 'UPDATEDATE',
            ]),
            self::descriptor(
                'normative-documents',
                '~^AS_NORMATIVE_DOCS(?!_(?:KINDS|TYPES))(?:_.*)?\\.XML$~i',
                'normative_docs',
                30,
                [
                    'id' => 'ID', 'name' => 'NAME', 'date' => 'DATE', 'number' => 'NUMBER', 'type_id' => 'TYPE',
                    'kind_id' => 'KIND', 'update_date' => 'UPDATEDATE', 'orgname' => 'ORGNAME', 'accdate' => 'ACCDATE',
                ],
                defaults: ['orgname' => ''],
            ),
            self::descriptor('change-history', '~^AS_CHANGE_HISTORY(?:_.*)?\\.XML$~i', 'change_history', 40, [
                'id' => 'CHANGEID', 'reestr_object_id' => 'OBJECTID', 'addr_object_guid' => 'ADROBJECTID',
                'oper_type_id' => 'OPERTYPEID', 'ndoc_id' => 'NDOCID', 'change_date' => 'CHANGEDATE',
            ], zeroAsNullColumns: ['ndoc_id']),
            self::descriptor('object-parameters', '~^AS_(?:ADDR_OBJ|APARTMENTS|CARPLACES|HOUSES|ROOMS|STEADS)_PARAMS(?:_.*)?\\.XML$~i', 'object_params', 50, [
                'id' => 'ID', 'object_id' => 'OBJECTID', 'change_id' => 'CHANGEID', 'change_id_end' => 'CHANGEIDEND',
                'type_id' => 'TYPEID', 'value' => 'VALUE', 'update_date' => 'UPDATEDATE',
                'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE',
            ], zeroAsNullColumns: ['change_id', 'change_id_end']),
            self::addressObjectsDescriptor(),
            self::descriptor('address-object-divisions', '~^AS_ADDR_OBJ_DIVISION(?:_.*)?\\.XML$~i', 'address_object_divisions', 70, [
                'id' => 'ID', 'parent_id' => 'PARENTID', 'child_id' => 'CHILDID', 'change_id' => 'CHANGEID',
            ], zeroAsNullColumns: ['change_id']),
            self::descriptor('apartments', '~^AS_APARTMENTS(?!_PARAMS)(?:_.*)?\\.XML$~i', 'apartments', 70, [
                ...$unitColumns,
                'apart_type_id' => 'APARTTYPE',
            ], zeroAsNullColumns: [...$unitZeroAsNullColumns, 'apart_type_id']),
            self::descriptor(
                'car-places',
                '~^AS_CARPLACES(?!_PARAMS)(?:_.*)?\\.XML$~i',
                'car_places',
                70,
                $unitColumns,
                zeroAsNullColumns: $unitZeroAsNullColumns,
            ),
            self::descriptor('houses', '~^AS_HOUSES(?!_PARAMS)(?:_.*)?\\.XML$~i', 'houses', 70, [
                'id' => 'ID', 'reestr_object_id' => 'OBJECTID', 'reestr_object_guid' => 'OBJECTGUID',
                'change_id' => 'CHANGEID', 'house_num' => 'HOUSENUM', 'add_num1' => 'ADDNUM1', 'add_num2' => 'ADDNUM2',
                'house_type_id' => 'HOUSETYPE', 'add_type1_id' => 'ADDTYPE1', 'add_type2_id' => 'ADDTYPE2',
                'oper_type_id' => 'OPERTYPEID', 'prev_id' => 'PREVID', 'next_id' => 'NEXTID',
                'update_date' => 'UPDATEDATE', 'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE',
                'is_actual' => 'ISACTUAL', 'is_active' => 'ISACTIVE',
            ], zeroAsNullColumns: ['change_id', 'house_type_id', 'add_type1_id', 'add_type2_id', 'prev_id', 'next_id']),
            self::descriptor('rooms', '~^AS_ROOMS(?!_PARAMS)(?:_.*)?\\.XML$~i', 'rooms', 70, [
                ...$unitColumns,
                'room_type_id' => 'ROOMTYPE',
            ], zeroAsNullColumns: [...$unitZeroAsNullColumns, 'room_type_id']),
            self::descriptor('steads', '~^AS_STEADS(?!_PARAMS)(?:_.*)?\\.XML$~i', 'steads', 70, [
                'id' => 'ID', 'object_id' => 'OBJECTID', 'object_guid' => 'OBJECTGUID', 'change_id' => 'CHANGEID',
                'number' => 'NUMBER', 'oper_type_id' => 'OPERTYPEID', 'prev_id' => 'PREVID', 'next_id' => 'NEXTID',
                'update_date' => 'UPDATEDATE', 'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE',
                'is_actual' => 'ISACTUAL', 'is_active' => 'ISACTIVE',
            ], zeroAsNullColumns: ['change_id', 'prev_id', 'next_id']),
            self::descriptor('administrative-hierarchy', '~^AS_ADM_HIERARCHY(?:_.*)?\\.XML$~i', 'administrative_reestr_hierarchy', 80, [
                'id' => 'ID', 'object_id' => 'OBJECTID', 'parent_obj_id' => 'PARENTOBJID', 'change_id' => 'CHANGEID',
                'region_code' => 'REGIONCODE', 'area_code' => 'AREACODE', 'city_code' => 'CITYCODE',
                'place_code' => 'PLACECODE', 'plan_code' => 'PLANCODE', 'street_code' => 'STREETCODE',
                'prev_id' => 'PREVID', 'next_id' => 'NEXTID', 'update_date' => 'UPDATEDATE',
                'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE', 'is_active' => 'ISACTIVE',
                'path' => 'PATH', 'path_arr' => 'PATH',
            ], zeroAsNullColumns: ['parent_obj_id', 'change_id', 'prev_id', 'next_id']),
            self::descriptor('municipal-hierarchy', '~^AS_MUN_HIERARCHY(?:_.*)?\\.XML$~i', 'municipal_reestr_hierarchy', 80, [
                'id' => 'ID', 'object_id' => 'OBJECTID', 'parent_obj_id' => 'PARENTOBJID', 'change_id' => 'CHANGEID',
                'oktmo' => 'OKTMO', 'prev_id' => 'PREVID', 'next_id' => 'NEXTID', 'update_date' => 'UPDATEDATE',
                'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE', 'is_active' => 'ISACTIVE',
                'path' => 'PATH', 'path_arr' => 'PATH',
            ], zeroAsNullColumns: ['parent_obj_id', 'change_id', 'prev_id', 'next_id']),
        ];
    }

    /**
     * @param array<string, string> $attributes
     * @param array<string, string> $defaults
     * @param list<string> $zeroAsNullColumns
     */
    private static function descriptor(
        string $key,
        string $pattern,
        string $table,
        int $priority,
        array $attributes,
        array $defaults = [],
        array $zeroAsNullColumns = [],
    ): GarXmlFileDescriptor {
        return new GarXmlFileDescriptor(
            key: $key,
            filenamePattern: $pattern,
            table: $table,
            priority: $priority,
            attributes: $attributes,
            defaults: $defaults,
            zeroAsNullColumns: $zeroAsNullColumns,
        );
    }

    private static function addressObjectsDescriptor(): GarXmlFileDescriptor
    {
        return new GarXmlFileDescriptor(
            key: 'address-objects',
            filenamePattern: '~^AS_ADDR_OBJ(?!_(?:TYPES|DIVISION|PARAMS))(?:_.*)?\\.XML$~i',
            table: 'address_objects',
            priority: 60,
            attributes: [
                'id' => 'ID', 'reestr_object_id' => 'OBJECTID', 'reestr_object_guid' => 'OBJECTGUID',
                'change_id' => 'CHANGEID', 'name' => 'NAME', 'type_name' => 'TYPENAME', 'level_id' => 'LEVEL',
                'oper_type_id' => 'OPERTYPEID', 'prev_id' => 'PREVID', 'next_id' => 'NEXTID',
                'update_date' => 'UPDATEDATE', 'start_date' => 'STARTDATE', 'end_date' => 'ENDDATE',
                'is_actual' => 'ISACTUAL', 'is_active' => 'ISACTIVE',
            ],
            targetColumns: [
                'id', 'reestr_object_id', 'reestr_object_guid', 'change_id', 'name', 'type_name', 'addr_type_id',
                'level_id', 'oper_type_id', 'prev_id', 'next_id', 'update_date', 'start_date', 'end_date',
                'is_actual', 'is_active',
            ],
            selectExpressions: [
                's.id', 's.reestr_object_id', 's.reestr_object_guid', 's.change_id', 's.name', 's.type_name',
                '(SELECT type.id FROM address_object_types type WHERE type.level = s.level_id AND type.short_name = s.type_name ORDER BY type.id LIMIT 1)',
                's.level_id', 's.oper_type_id', 's.prev_id', 's.next_id', 's.update_date', 's.start_date',
                's.end_date', 's.is_actual', 's.is_active',
            ],
            zeroAsNullColumns: ['change_id', 'prev_id', 'next_id'],
        );
    }
}

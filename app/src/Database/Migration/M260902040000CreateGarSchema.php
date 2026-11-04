<?php

declare(strict_types=1);

namespace App\Database\Migration;

use LogicException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M260902040000CreateGarSchema implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach ($this->upSql() as $sql) {
            $b->execute($sql);
        }

        foreach ($this->columnComments() as $table => $columns) {
            foreach ($columns as $column => $comment) {
                if (!is_string($table) || !is_string($column)) {
                    throw new LogicException('Migration comment identifiers must be strings.');
                }

                $b->execute($this->commentColumnSql($table, $column, $comment));
            }
        }
    }

    public function down(MigrationBuilder $b): void
    {
        foreach ($this->downSql() as $sql) {
            $b->execute($sql);
        }
    }

    /**
     * @return list<string>
     */
    private function upSql(): array
    {
        return [
            <<<'SQL'
CREATE TABLE house_types (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL,
    short_name varchar(150) NOT NULL,
    descr text NOT NULL,
    is_active bool NOT NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_house_types_name ON house_types USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_house_types_short_name ON house_types USING GIN (short_name gin_trgm_ops)',
            'CREATE INDEX idx_house_types_end_date ON house_types USING BTREE (end_date)',
            "COMMENT ON TABLE house_types IS 'Справочник типов домов'",

            <<<'SQL'
CREATE TABLE house_addr_types (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL,
    short_name varchar(150) NOT NULL,
    descr text NOT NULL,
    is_active bool NOT NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_house_addr_types_name ON house_addr_types USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_house_addr_types_short_name ON house_addr_types USING GIN (short_name gin_trgm_ops)',
            'CREATE INDEX idx_house_addr_types_end_date ON house_addr_types USING BTREE (end_date)',
            "COMMENT ON TABLE house_addr_types IS 'Справочник дополнительных типов домов'",

            <<<'SQL'
CREATE TABLE address_object_types (
    id bigint NOT NULL PRIMARY KEY,
    level integer NOT NULL,
    name varchar(150) NOT NULL,
    short_name varchar(150) NOT NULL,
    descr text NOT NULL,
    is_active bool NOT NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_address_object_types_name ON address_object_types USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_address_object_types_short_name ON address_object_types USING GIN (short_name gin_trgm_ops)',
            'CREATE INDEX idx_address_object_types_level ON address_object_types USING BTREE (level)',
            'CREATE INDEX idx_address_object_types_end_date ON address_object_types USING BTREE (end_date)',
            'CREATE UNIQUE INDEX uq_address_object_types_short_name_level_id ON address_object_types (short_name, level, id)',
            "COMMENT ON TABLE address_object_types IS 'Справочник типов объектов адресации'",

            <<<'SQL'
CREATE TABLE apartment_types (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL,
    short_name varchar(150) NOT NULL,
    descr text NOT NULL,
    is_active bool NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    update_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_apartment_types_name ON apartment_types USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_apartment_types_short_name ON apartment_types USING GIN (short_name gin_trgm_ops)',
            'CREATE INDEX idx_apartment_types_start_date ON apartment_types USING BTREE (start_date)',
            'CREATE INDEX idx_apartment_types_end_date ON apartment_types USING BTREE (end_date)',
            "COMMENT ON TABLE apartment_types IS 'Справочник типов помещений внутри дома'",

            <<<'SQL'
CREATE TABLE normative_doc_kinds (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL
)
SQL,
            'CREATE INDEX idx_normative_doc_kinds_name ON normative_doc_kinds USING GIN (name gin_trgm_ops)',
            "COMMENT ON TABLE normative_doc_kinds IS 'Справочник видов нормативных документов'",

            <<<'SQL'
CREATE TABLE normative_doc_types (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_normative_doc_types_name ON normative_doc_types USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_normative_doc_types_start_date ON normative_doc_types USING BTREE (start_date)',
            'CREATE INDEX idx_normative_doc_types_end_date ON normative_doc_types USING BTREE (end_date)',
            "COMMENT ON TABLE normative_doc_types IS 'Справочник типов нормативных документов'",

            <<<'SQL'
CREATE TABLE object_levels (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL,
    is_active bool NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    update_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_object_levels_name ON object_levels USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_object_levels_is_active ON object_levels USING BTREE (is_active)',
            'CREATE INDEX idx_object_levels_start_date ON object_levels USING BTREE (start_date)',
            'CREATE INDEX idx_object_levels_end_date ON object_levels USING BTREE (end_date)',
            "COMMENT ON TABLE object_levels IS 'Справочник уровней объектов адресации'",

            <<<'SQL'
CREATE TABLE operation_types (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL,
    is_active bool NOT NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_operation_types_name ON operation_types USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_operation_types_is_active ON operation_types USING BTREE (is_active)',
            'CREATE INDEX idx_operation_types_start_date ON operation_types USING BTREE (start_date)',
            'CREATE INDEX idx_operation_types_end_date ON operation_types USING BTREE (end_date)',
            "COMMENT ON TABLE operation_types IS 'Справочник типов операций'",

            <<<'SQL'
CREATE TABLE param_types (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL,
    descr text NOT NULL,
    code varchar(100) NOT NULL,
    is_active bool NOT NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_param_types_name ON param_types USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_param_types_descr ON param_types USING GIN (descr gin_trgm_ops)',
            'CREATE INDEX idx_param_types_code ON param_types USING BTREE (code)',
            'CREATE INDEX idx_param_types_is_active ON param_types USING BTREE (is_active)',
            'CREATE INDEX idx_param_types_start_date ON param_types USING BTREE (start_date)',
            'CREATE INDEX idx_param_types_end_date ON param_types USING BTREE (end_date)',
            "COMMENT ON TABLE param_types IS 'Справочник типов параметров объектов адресации'",

            <<<'SQL'
CREATE TABLE room_types (
    id bigint NOT NULL PRIMARY KEY,
    name varchar(150) NOT NULL,
    short_name varchar(150),
    descr text NOT NULL,
    is_active bool NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    update_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_room_types_name ON room_types USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_room_types_short_name ON room_types USING GIN (short_name gin_trgm_ops)',
            'CREATE INDEX idx_room_types_is_active ON room_types USING BTREE (is_active)',
            'CREATE INDEX idx_room_types_start_date ON room_types USING BTREE (start_date)',
            'CREATE INDEX idx_room_types_end_date ON room_types USING BTREE (end_date)',
            "COMMENT ON TABLE room_types IS 'Справочник типов комнат'",

            <<<'SQL'
CREATE TABLE gar_versions (
    id int PRIMARY KEY GENERATED BY DEFAULT AS IDENTITY,
    ver_date date NOT NULL,
    version text NOT NULL
)
SQL,
            'CREATE INDEX idx_gar_versions_ver_date ON gar_versions USING BTREE (ver_date)',
            'CREATE INDEX idx_gar_versions_version ON gar_versions USING GIN (version gin_trgm_ops)',
            "COMMENT ON TABLE gar_versions IS 'Загруженные версии ГАР'",

            <<<'SQL'
CREATE TABLE parser_logs (
    id bigint PRIMARY KEY GENERATED BY DEFAULT AS IDENTITY,
    level varchar(15) NOT NULL DEFAULT 'INFO',
    message text NOT NULL,
    details jsonb NOT NULL DEFAULT '{}'::jsonb,
    logged_at timestamptz NOT NULL DEFAULT now()
)
SQL,
            "COMMENT ON TABLE parser_logs IS 'Технический журнал загрузки ГАР'",

            <<<'SQL'
CREATE TABLE reestr_objects (
    id bigint NOT NULL PRIMARY KEY,
    object_guid uuid NOT NULL,
    change_id bigint NOT NULL,
    is_active bool NOT NULL,
    level_id bigint NOT NULL REFERENCES object_levels(id) ON DELETE RESTRICT,
    create_date date NOT NULL,
    update_date date NOT NULL
)
SQL,
            'CREATE UNIQUE INDEX uq_reestr_objects_object_guid ON reestr_objects (object_guid)',
            'CREATE INDEX idx_reestr_objects_level_id ON reestr_objects USING BTREE (level_id)',
            'CREATE INDEX idx_reestr_objects_change_id ON reestr_objects USING BTREE (change_id)',
            'CREATE INDEX idx_reestr_objects_is_active ON reestr_objects USING BTREE (is_active)',
            'CREATE INDEX idx_reestr_objects_create_date ON reestr_objects USING BTREE (create_date)',
            'CREATE INDEX idx_reestr_objects_update_date ON reestr_objects USING BTREE (update_date)',
            "COMMENT ON TABLE reestr_objects IS 'Реестр объектов адресации ГАР'",

            <<<'SQL'
CREATE TABLE normative_docs (
    id bigint NOT NULL PRIMARY KEY,
    name text NOT NULL,
    date date NOT NULL,
    number text NOT NULL,
    type_id bigint NOT NULL REFERENCES normative_doc_types(id) ON DELETE RESTRICT,
    kind_id bigint NOT NULL REFERENCES normative_doc_kinds(id) ON DELETE RESTRICT,
    update_date date NOT NULL,
    orgname text NOT NULL DEFAULT '',
    accdate date
)
SQL,
            'CREATE INDEX idx_normative_docs_type_id ON normative_docs USING BTREE (type_id)',
            'CREATE INDEX idx_normative_docs_kind_id ON normative_docs USING BTREE (kind_id)',
            'CREATE INDEX idx_normative_docs_date ON normative_docs USING BTREE (date)',
            'CREATE INDEX idx_normative_docs_update_date ON normative_docs USING BTREE (update_date)',
            'CREATE INDEX idx_normative_docs_accdate ON normative_docs USING BTREE (accdate)',
            'CREATE INDEX idx_normative_docs_number ON normative_docs USING GIN (number gin_trgm_ops)',
            'CREATE INDEX idx_normative_docs_name ON normative_docs USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_normative_docs_orgname ON normative_docs USING GIN (orgname gin_trgm_ops)',
            "COMMENT ON TABLE normative_docs IS 'Нормативные документы'",

            <<<'SQL'
CREATE TABLE change_history (
    id bigint NOT NULL PRIMARY KEY,
    reestr_object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    addr_object_guid uuid NOT NULL,
    oper_type_id bigint NOT NULL REFERENCES operation_types(id) ON DELETE RESTRICT,
    ndoc_id bigint REFERENCES normative_docs(id) ON DELETE SET NULL,
    change_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_change_history_reestr_object_id ON change_history USING BTREE (reestr_object_id)',
            'CREATE INDEX idx_change_history_oper_type_id ON change_history USING BTREE (oper_type_id)',
            'CREATE INDEX idx_change_history_ndoc_id ON change_history USING BTREE (ndoc_id)',
            'CREATE INDEX idx_change_history_addr_object_guid ON change_history USING BTREE (addr_object_guid)',
            'CREATE INDEX idx_change_history_change_date ON change_history USING BTREE (change_date)',
            "COMMENT ON TABLE change_history IS 'История изменений объектов адресации'",

            <<<'SQL'
CREATE TABLE object_params (
    id bigint NOT NULL PRIMARY KEY,
    object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    change_id_end bigint,
    type_id bigint NOT NULL REFERENCES param_types(id) ON DELETE RESTRICT,
    value text NOT NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL
)
SQL,
            'CREATE INDEX idx_object_params_object_id ON object_params USING BTREE (object_id)',
            'CREATE INDEX idx_object_params_type_id ON object_params USING BTREE (type_id)',
            'CREATE INDEX idx_object_params_change_id ON object_params USING BTREE (change_id)',
            'CREATE INDEX idx_object_params_change_id_end ON object_params USING BTREE (change_id_end)',
            'CREATE INDEX idx_object_params_start_date ON object_params USING BTREE (start_date)',
            'CREATE INDEX idx_object_params_end_date ON object_params USING BTREE (end_date)',
            'CREATE INDEX idx_object_params_update_date ON object_params USING BTREE (update_date)',
            'CREATE INDEX idx_object_params_value ON object_params USING GIN (value gin_trgm_ops)',
            "COMMENT ON TABLE object_params IS 'Параметры объектов адресации'",

            <<<'SQL'
CREATE TABLE address_objects (
    id bigint NOT NULL PRIMARY KEY,
    reestr_object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    reestr_object_guid uuid NOT NULL,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    name varchar(250) NOT NULL,
    type_name varchar(50) NOT NULL,
    addr_type_id bigint NOT NULL REFERENCES address_object_types(id) ON DELETE RESTRICT,
    level_id bigint NOT NULL REFERENCES object_levels(id) ON DELETE RESTRICT,
    oper_type_id bigint NOT NULL REFERENCES operation_types(id) ON DELETE RESTRICT,
    prev_id bigint REFERENCES address_objects(id) ON DELETE SET NULL,
    next_id bigint REFERENCES address_objects(id) ON DELETE SET NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_actual bool NOT NULL,
    is_active bool NOT NULL
)
SQL,
            'CREATE INDEX idx_address_objects_reestr_object_id ON address_objects USING BTREE (reestr_object_id)',
            'CREATE INDEX idx_address_objects_level_id ON address_objects USING BTREE (level_id)',
            'CREATE INDEX idx_address_objects_oper_type_id ON address_objects USING BTREE (oper_type_id)',
            'CREATE INDEX idx_address_objects_reestr_object_guid ON address_objects USING BTREE (reestr_object_guid)',
            'CREATE INDEX idx_address_objects_change_id ON address_objects USING BTREE (change_id)',
            'CREATE INDEX idx_address_objects_prev_id ON address_objects USING BTREE (prev_id)',
            'CREATE INDEX idx_address_objects_next_id ON address_objects USING BTREE (next_id)',
            'CREATE INDEX idx_address_objects_start_date ON address_objects USING BTREE (start_date)',
            'CREATE INDEX idx_address_objects_end_date ON address_objects USING BTREE (end_date)',
            'CREATE INDEX idx_address_objects_update_date ON address_objects USING BTREE (update_date)',
            'CREATE INDEX idx_address_objects_is_actual ON address_objects USING BTREE (is_actual)',
            'CREATE INDEX idx_address_objects_is_active ON address_objects USING BTREE (is_active)',
            'CREATE INDEX idx_address_objects_name ON address_objects USING GIN (name gin_trgm_ops)',
            'CREATE INDEX idx_address_objects_type_name ON address_objects USING GIN (type_name gin_trgm_ops)',
            "COMMENT ON TABLE address_objects IS 'Адресные объекты'",

            <<<'SQL'
CREATE TABLE address_object_divisions (
    id bigint NOT NULL PRIMARY KEY,
    parent_id bigint NOT NULL REFERENCES address_objects(id) ON DELETE CASCADE,
    child_id bigint NOT NULL REFERENCES address_objects(id) ON DELETE CASCADE,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL
)
SQL,
            'CREATE INDEX idx_address_object_divisions_parent_id ON address_object_divisions USING BTREE (parent_id)',
            'CREATE INDEX idx_address_object_divisions_child_id ON address_object_divisions USING BTREE (child_id)',
            'CREATE INDEX idx_address_object_divisions_change_id ON address_object_divisions USING BTREE (change_id)',
            "COMMENT ON TABLE address_object_divisions IS 'Связи деления адресных объектов'",

            <<<'SQL'
CREATE TABLE apartments (
    id bigint NOT NULL PRIMARY KEY,
    reestr_object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    reestr_object_guid uuid NOT NULL,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    number varchar(50) NOT NULL,
    apart_type_id bigint REFERENCES apartment_types(id) ON DELETE RESTRICT,
    oper_type_id bigint NOT NULL REFERENCES operation_types(id) ON DELETE RESTRICT,
    prev_id bigint REFERENCES apartments(id) ON DELETE SET NULL,
    next_id bigint REFERENCES apartments(id) ON DELETE SET NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_actual bool NOT NULL,
    is_active bool NOT NULL
)
SQL,
            'CREATE INDEX idx_apartments_reestr_object_id ON apartments USING BTREE (reestr_object_id)',
            'CREATE INDEX idx_apartments_change_id ON apartments USING BTREE (change_id)',
            'CREATE INDEX idx_apartments_apart_type_id ON apartments USING BTREE (apart_type_id)',
            'CREATE INDEX idx_apartments_oper_type_id ON apartments USING BTREE (oper_type_id)',
            'CREATE INDEX idx_apartments_prev_id ON apartments USING BTREE (prev_id)',
            'CREATE INDEX idx_apartments_next_id ON apartments USING BTREE (next_id)',
            'CREATE INDEX idx_apartments_reestr_object_guid ON apartments USING BTREE (reestr_object_guid)',
            'CREATE INDEX idx_apartments_start_date ON apartments USING BTREE (start_date)',
            'CREATE INDEX idx_apartments_end_date ON apartments USING BTREE (end_date)',
            'CREATE INDEX idx_apartments_update_date ON apartments USING BTREE (update_date)',
            'CREATE INDEX idx_apartments_is_actual ON apartments USING BTREE (is_actual)',
            'CREATE INDEX idx_apartments_is_active ON apartments USING BTREE (is_active)',
            'CREATE INDEX idx_apartments_number ON apartments USING GIN (number gin_trgm_ops)',
            "COMMENT ON TABLE apartments IS 'Квартиры и помещения'",

            <<<'SQL'
CREATE TABLE car_places (
    id bigint NOT NULL PRIMARY KEY,
    reestr_object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    reestr_object_guid uuid NOT NULL,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    number varchar(50) NOT NULL,
    oper_type_id bigint NOT NULL REFERENCES operation_types(id) ON DELETE RESTRICT,
    prev_id bigint REFERENCES car_places(id) ON DELETE SET NULL,
    next_id bigint REFERENCES car_places(id) ON DELETE SET NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_actual bool NOT NULL,
    is_active bool NOT NULL
)
SQL,
            'CREATE INDEX idx_car_places_reestr_object_id ON car_places USING BTREE (reestr_object_id)',
            'CREATE INDEX idx_car_places_change_id ON car_places USING BTREE (change_id)',
            'CREATE INDEX idx_car_places_oper_type_id ON car_places USING BTREE (oper_type_id)',
            'CREATE INDEX idx_car_places_prev_id ON car_places USING BTREE (prev_id)',
            'CREATE INDEX idx_car_places_next_id ON car_places USING BTREE (next_id)',
            'CREATE INDEX idx_car_places_reestr_object_guid ON car_places USING BTREE (reestr_object_guid)',
            'CREATE INDEX idx_car_places_start_date ON car_places USING BTREE (start_date)',
            'CREATE INDEX idx_car_places_end_date ON car_places USING BTREE (end_date)',
            'CREATE INDEX idx_car_places_update_date ON car_places USING BTREE (update_date)',
            'CREATE INDEX idx_car_places_is_actual ON car_places USING BTREE (is_actual)',
            'CREATE INDEX idx_car_places_is_active ON car_places USING BTREE (is_active)',
            'CREATE INDEX idx_car_places_number ON car_places USING GIN (number gin_trgm_ops)',
            "COMMENT ON TABLE car_places IS 'Машино-места'",

            <<<'SQL'
CREATE TABLE houses (
    id bigint NOT NULL PRIMARY KEY,
    reestr_object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    reestr_object_guid uuid NOT NULL,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    house_num varchar(50) NOT NULL,
    add_num1 varchar(50),
    add_num2 varchar(50),
    house_type_id bigint NOT NULL REFERENCES house_types(id) ON DELETE RESTRICT,
    add_type1_id bigint REFERENCES house_addr_types(id) ON DELETE RESTRICT,
    add_type2_id bigint REFERENCES house_addr_types(id) ON DELETE RESTRICT,
    oper_type_id bigint NOT NULL REFERENCES operation_types(id) ON DELETE RESTRICT,
    prev_id bigint REFERENCES houses(id) ON DELETE SET NULL,
    next_id bigint REFERENCES houses(id) ON DELETE SET NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_actual bool NOT NULL,
    is_active bool NOT NULL
)
SQL,
            'CREATE INDEX idx_houses_reestr_object_id ON houses USING BTREE (reestr_object_id)',
            'CREATE INDEX idx_houses_change_id ON houses USING BTREE (change_id)',
            'CREATE INDEX idx_houses_house_type_id ON houses USING BTREE (house_type_id)',
            'CREATE INDEX idx_houses_add_type1_id ON houses USING BTREE (add_type1_id)',
            'CREATE INDEX idx_houses_add_type2_id ON houses USING BTREE (add_type2_id)',
            'CREATE INDEX idx_houses_oper_type_id ON houses USING BTREE (oper_type_id)',
            'CREATE INDEX idx_houses_prev_id ON houses USING BTREE (prev_id)',
            'CREATE INDEX idx_houses_next_id ON houses USING BTREE (next_id)',
            'CREATE INDEX idx_houses_reestr_object_guid ON houses USING BTREE (reestr_object_guid)',
            'CREATE INDEX idx_houses_start_date ON houses USING BTREE (start_date)',
            'CREATE INDEX idx_houses_end_date ON houses USING BTREE (end_date)',
            'CREATE INDEX idx_houses_update_date ON houses USING BTREE (update_date)',
            'CREATE INDEX idx_houses_is_actual ON houses USING BTREE (is_actual)',
            'CREATE INDEX idx_houses_is_active ON houses USING BTREE (is_active)',
            'CREATE INDEX idx_houses_house_num ON houses USING GIN (house_num gin_trgm_ops)',
            'CREATE INDEX idx_houses_add_num1 ON houses USING GIN (add_num1 gin_trgm_ops)',
            'CREATE INDEX idx_houses_add_num2 ON houses USING GIN (add_num2 gin_trgm_ops)',
            "COMMENT ON TABLE houses IS 'Дома и строения'",

            <<<'SQL'
CREATE TABLE rooms (
    id bigint NOT NULL PRIMARY KEY,
    reestr_object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    reestr_object_guid uuid NOT NULL,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    number varchar(50) NOT NULL,
    room_type_id bigint REFERENCES room_types(id) ON DELETE RESTRICT,
    oper_type_id bigint NOT NULL REFERENCES operation_types(id) ON DELETE RESTRICT,
    prev_id bigint REFERENCES rooms(id) ON DELETE SET NULL,
    next_id bigint REFERENCES rooms(id) ON DELETE SET NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_actual bool NOT NULL,
    is_active bool NOT NULL
)
SQL,
            'CREATE INDEX idx_rooms_reestr_object_id ON rooms USING BTREE (reestr_object_id)',
            'CREATE INDEX idx_rooms_change_id ON rooms USING BTREE (change_id)',
            'CREATE INDEX idx_rooms_room_type_id ON rooms USING BTREE (room_type_id)',
            'CREATE INDEX idx_rooms_oper_type_id ON rooms USING BTREE (oper_type_id)',
            'CREATE INDEX idx_rooms_prev_id ON rooms USING BTREE (prev_id)',
            'CREATE INDEX idx_rooms_next_id ON rooms USING BTREE (next_id)',
            'CREATE INDEX idx_rooms_reestr_object_guid ON rooms USING BTREE (reestr_object_guid)',
            'CREATE INDEX idx_rooms_start_date ON rooms USING BTREE (start_date)',
            'CREATE INDEX idx_rooms_end_date ON rooms USING BTREE (end_date)',
            'CREATE INDEX idx_rooms_update_date ON rooms USING BTREE (update_date)',
            'CREATE INDEX idx_rooms_is_actual ON rooms USING BTREE (is_actual)',
            'CREATE INDEX idx_rooms_is_active ON rooms USING BTREE (is_active)',
            'CREATE INDEX idx_rooms_number ON rooms USING GIN (number gin_trgm_ops)',
            "COMMENT ON TABLE rooms IS 'Комнаты'",

            <<<'SQL'
CREATE TABLE steads (
    id bigint NOT NULL PRIMARY KEY,
    object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    object_guid uuid NOT NULL,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    number varchar(50) NOT NULL,
    oper_type_id bigint NOT NULL REFERENCES operation_types(id) ON DELETE RESTRICT,
    prev_id bigint REFERENCES steads(id) ON DELETE SET NULL,
    next_id bigint REFERENCES steads(id) ON DELETE SET NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_actual bool NOT NULL,
    is_active bool NOT NULL
)
SQL,
            'CREATE INDEX idx_steads_object_id ON steads USING BTREE (object_id)',
            'CREATE INDEX idx_steads_change_id ON steads USING BTREE (change_id)',
            'CREATE INDEX idx_steads_oper_type_id ON steads USING BTREE (oper_type_id)',
            'CREATE INDEX idx_steads_prev_id ON steads USING BTREE (prev_id)',
            'CREATE INDEX idx_steads_next_id ON steads USING BTREE (next_id)',
            'CREATE INDEX idx_steads_object_guid ON steads USING BTREE (object_guid)',
            'CREATE INDEX idx_steads_start_date ON steads USING BTREE (start_date)',
            'CREATE INDEX idx_steads_end_date ON steads USING BTREE (end_date)',
            'CREATE INDEX idx_steads_update_date ON steads USING BTREE (update_date)',
            'CREATE INDEX idx_steads_is_actual ON steads USING BTREE (is_actual)',
            'CREATE INDEX idx_steads_is_active ON steads USING BTREE (is_active)',
            'CREATE INDEX idx_steads_number ON steads USING GIN (number gin_trgm_ops)',
            "COMMENT ON TABLE steads IS 'Земельные участки'",

            <<<'SQL'
CREATE TABLE administrative_reestr_hierarchy (
    id bigint NOT NULL PRIMARY KEY,
    object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    parent_obj_id bigint REFERENCES reestr_objects(id) ON DELETE SET NULL,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    region_code integer NOT NULL,
    area_code varchar(255),
    city_code varchar(255),
    place_code varchar(255),
    plan_code varchar(255),
    street_code varchar(255),
    prev_id bigint REFERENCES administrative_reestr_hierarchy(id) ON DELETE SET NULL,
    next_id bigint REFERENCES administrative_reestr_hierarchy(id) ON DELETE SET NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_active bool NOT NULL,
    path text NOT NULL,
    path_arr int[] NOT NULL DEFAULT '{}'
)
SQL,
            'CREATE INDEX idx_administrative_reestr_hierarchy_object_id ON administrative_reestr_hierarchy USING BTREE (object_id)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_parent_obj_id ON administrative_reestr_hierarchy USING BTREE (parent_obj_id)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_change_id ON administrative_reestr_hierarchy USING BTREE (change_id)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_region_code ON administrative_reestr_hierarchy USING BTREE (region_code)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_area_code ON administrative_reestr_hierarchy USING BTREE (area_code)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_city_code ON administrative_reestr_hierarchy USING BTREE (city_code)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_place_code ON administrative_reestr_hierarchy USING BTREE (place_code)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_plan_code ON administrative_reestr_hierarchy USING BTREE (plan_code)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_street_code ON administrative_reestr_hierarchy USING BTREE (street_code)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_prev_id ON administrative_reestr_hierarchy USING BTREE (prev_id)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_next_id ON administrative_reestr_hierarchy USING BTREE (next_id)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_start_date ON administrative_reestr_hierarchy USING BTREE (start_date)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_end_date ON administrative_reestr_hierarchy USING BTREE (end_date)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_update_date ON administrative_reestr_hierarchy USING BTREE (update_date)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_path ON administrative_reestr_hierarchy USING GIN (path gin_trgm_ops)',
            'CREATE INDEX idx_administrative_reestr_hierarchy_path_arr ON administrative_reestr_hierarchy USING GIN (path_arr)',
            "COMMENT ON TABLE administrative_reestr_hierarchy IS 'Административная иерархия объектов адресации'",

            <<<'SQL'
CREATE TABLE municipal_reestr_hierarchy (
    id bigint NOT NULL PRIMARY KEY,
    object_id bigint NOT NULL REFERENCES reestr_objects(id) ON DELETE CASCADE,
    parent_obj_id bigint REFERENCES reestr_objects(id) ON DELETE SET NULL,
    change_id bigint REFERENCES change_history(id) ON DELETE SET NULL,
    oktmo varchar(11) NOT NULL,
    prev_id bigint REFERENCES municipal_reestr_hierarchy(id) ON DELETE SET NULL,
    next_id bigint REFERENCES municipal_reestr_hierarchy(id) ON DELETE SET NULL,
    update_date date NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_active bool NOT NULL,
    path text NOT NULL,
    path_arr int[] NOT NULL DEFAULT '{}'
)
SQL,
            'CREATE INDEX idx_municipal_reestr_hierarchy_object_id ON municipal_reestr_hierarchy USING BTREE (object_id)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_parent_obj_id ON municipal_reestr_hierarchy USING BTREE (parent_obj_id)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_change_id ON municipal_reestr_hierarchy USING BTREE (change_id)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_oktmo ON municipal_reestr_hierarchy USING BTREE (oktmo)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_prev_id ON municipal_reestr_hierarchy USING BTREE (prev_id)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_next_id ON municipal_reestr_hierarchy USING BTREE (next_id)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_start_date ON municipal_reestr_hierarchy USING BTREE (start_date)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_end_date ON municipal_reestr_hierarchy USING BTREE (end_date)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_update_date ON municipal_reestr_hierarchy USING BTREE (update_date)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_path ON municipal_reestr_hierarchy USING GIN (path gin_trgm_ops)',
            'CREATE INDEX idx_municipal_reestr_hierarchy_path_arr ON municipal_reestr_hierarchy USING GIN (path_arr)',
            "COMMENT ON TABLE municipal_reestr_hierarchy IS 'Муниципальная иерархия объектов адресации'",
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function columnComments(): array
    {
        return [
            'house_types' => [
                'id' => 'Идентификатор типа дома из AS_HOUSE_TYPES.ID.',
                'name' => 'Полное наименование типа дома из AS_HOUSE_TYPES.NAME.',
                'short_name' => 'Краткое наименование типа дома из AS_HOUSE_TYPES.SHORTNAME.',
                'descr' => 'Описание типа дома из AS_HOUSE_TYPES.DESC.',
                'is_active' => 'Признак актуальности записи справочника из AS_HOUSE_TYPES.ISACTIVE.',
                'update_date' => 'Дата обновления записи справочника из AS_HOUSE_TYPES.UPDATEDATE.',
                'start_date' => 'Дата начала действия записи справочника из AS_HOUSE_TYPES.STARTDATE.',
                'end_date' => 'Дата окончания действия записи справочника из AS_HOUSE_TYPES.ENDDATE.',
            ],
            'house_addr_types' => [
                'id' => 'Идентификатор дополнительного типа дома из AS_ADDHOUSE_TYPES.ID.',
                'name' => 'Полное наименование дополнительного типа дома из AS_ADDHOUSE_TYPES.NAME.',
                'short_name' => 'Краткое наименование дополнительного типа дома из AS_ADDHOUSE_TYPES.SHORTNAME.',
                'descr' => 'Описание дополнительного типа дома из AS_ADDHOUSE_TYPES.DESC.',
                'is_active' => 'Признак актуальности записи справочника из AS_ADDHOUSE_TYPES.ISACTIVE.',
                'update_date' => 'Дата обновления записи справочника из AS_ADDHOUSE_TYPES.UPDATEDATE.',
                'start_date' => 'Дата начала действия записи справочника из AS_ADDHOUSE_TYPES.STARTDATE.',
                'end_date' => 'Дата окончания действия записи справочника из AS_ADDHOUSE_TYPES.ENDDATE.',
            ],
            'address_object_types' => [
                'id' => 'Идентификатор типа адресного объекта из AS_ADDR_OBJ_TYPES.ID.',
                'level' => 'Уровень адресного объекта из AS_ADDR_OBJ_TYPES.LEVEL.',
                'name' => 'Полное наименование типа адресного объекта из AS_ADDR_OBJ_TYPES.NAME.',
                'short_name' => 'Краткое наименование типа адресного объекта из AS_ADDR_OBJ_TYPES.SHORTNAME.',
                'descr' => 'Описание типа адресного объекта из AS_ADDR_OBJ_TYPES.DESC.',
                'is_active' => 'Признак актуальности записи справочника из AS_ADDR_OBJ_TYPES.ISACTIVE.',
                'update_date' => 'Дата обновления записи справочника из AS_ADDR_OBJ_TYPES.UPDATEDATE.',
                'start_date' => 'Дата начала действия записи справочника из AS_ADDR_OBJ_TYPES.STARTDATE.',
                'end_date' => 'Дата окончания действия записи справочника из AS_ADDR_OBJ_TYPES.ENDDATE.',
            ],
            'apartment_types' => [
                'id' => 'Идентификатор типа помещения из AS_APARTMENT_TYPES.ID.',
                'name' => 'Полное наименование типа помещения из AS_APARTMENT_TYPES.NAME.',
                'short_name' => 'Краткое наименование типа помещения из AS_APARTMENT_TYPES.SHORTNAME.',
                'descr' => 'Описание типа помещения из AS_APARTMENT_TYPES.DESC.',
                'is_active' => 'Признак актуальности записи справочника из AS_APARTMENT_TYPES.ISACTIVE.',
                'start_date' => 'Дата начала действия записи справочника из AS_APARTMENT_TYPES.STARTDATE.',
                'end_date' => 'Дата окончания действия записи справочника из AS_APARTMENT_TYPES.ENDDATE.',
                'update_date' => 'Дата обновления записи справочника из AS_APARTMENT_TYPES.UPDATEDATE.',
            ],
            'normative_doc_kinds' => [
                'id' => 'Идентификатор вида нормативного документа из AS_NORMATIVE_DOCS_KINDS.ID.',
                'name' => 'Наименование вида нормативного документа из AS_NORMATIVE_DOCS_KINDS.NAME.',
            ],
            'normative_doc_types' => [
                'id' => 'Идентификатор типа нормативного документа из AS_NORMATIVE_DOCS_TYPES.ID.',
                'name' => 'Наименование типа нормативного документа из AS_NORMATIVE_DOCS_TYPES.NAME.',
                'start_date' => 'Дата начала действия типа нормативного документа из AS_NORMATIVE_DOCS_TYPES.STARTDATE.',
                'end_date' => 'Дата окончания действия типа нормативного документа из AS_NORMATIVE_DOCS_TYPES.ENDDATE.',
            ],
            'object_levels' => [
                'id' => 'Уровень объекта адресации из AS_OBJECT_LEVELS.LEVEL.',
                'name' => 'Наименование уровня объекта адресации из AS_OBJECT_LEVELS.NAME.',
                'is_active' => 'Признак актуальности уровня из AS_OBJECT_LEVELS.ISACTIVE.',
                'start_date' => 'Дата начала действия уровня из AS_OBJECT_LEVELS.STARTDATE.',
                'end_date' => 'Дата окончания действия уровня из AS_OBJECT_LEVELS.ENDDATE.',
                'update_date' => 'Дата обновления уровня из AS_OBJECT_LEVELS.UPDATEDATE.',
            ],
            'operation_types' => [
                'id' => 'Идентификатор типа операции из AS_OPERATION_TYPES.ID.',
                'name' => 'Наименование типа операции из AS_OPERATION_TYPES.NAME.',
                'is_active' => 'Признак актуальности типа операции из AS_OPERATION_TYPES.ISACTIVE.',
                'update_date' => 'Дата обновления типа операции из AS_OPERATION_TYPES.UPDATEDATE.',
                'start_date' => 'Дата начала действия типа операции из AS_OPERATION_TYPES.STARTDATE.',
                'end_date' => 'Дата окончания действия типа операции из AS_OPERATION_TYPES.ENDDATE.',
            ],
            'param_types' => [
                'id' => 'Идентификатор типа параметра из AS_PARAM_TYPES.ID.',
                'name' => 'Наименование типа параметра из AS_PARAM_TYPES.NAME.',
                'descr' => 'Описание типа параметра из AS_PARAM_TYPES.DESC.',
                'code' => 'Код типа параметра из AS_PARAM_TYPES.CODE.',
                'is_active' => 'Признак актуальности типа параметра из AS_PARAM_TYPES.ISACTIVE.',
                'update_date' => 'Дата обновления типа параметра из AS_PARAM_TYPES.UPDATEDATE.',
                'start_date' => 'Дата начала действия типа параметра из AS_PARAM_TYPES.STARTDATE.',
                'end_date' => 'Дата окончания действия типа параметра из AS_PARAM_TYPES.ENDDATE.',
            ],
            'room_types' => [
                'id' => 'Идентификатор типа комнаты из AS_ROOM_TYPES.ID.',
                'name' => 'Наименование типа комнаты из AS_ROOM_TYPES.NAME.',
                'short_name' => 'Краткое наименование типа комнаты из AS_ROOM_TYPES.SHORTNAME; в GAR может отсутствовать.',
                'descr' => 'Описание типа комнаты из AS_ROOM_TYPES.DESC.',
                'is_active' => 'Признак актуальности типа комнаты из AS_ROOM_TYPES.ISACTIVE.',
                'start_date' => 'Дата начала действия типа комнаты из AS_ROOM_TYPES.STARTDATE.',
                'end_date' => 'Дата окончания действия типа комнаты из AS_ROOM_TYPES.ENDDATE.',
                'update_date' => 'Дата обновления типа комнаты из AS_ROOM_TYPES.UPDATEDATE.',
            ],
            'gar_versions' => [
                'id' => 'Локальный идентификатор записи о загруженной версии ГАР.',
                'ver_date' => 'Дата версии ГАР из version.txt.',
                'version' => 'Номер версии ГАР из version.txt.',
            ],
            'parser_logs' => [
                'id' => 'Локальный идентификатор записи журнала загрузки.',
                'level' => 'Уровень сообщения загрузчика.',
                'message' => 'Текст сообщения загрузчика.',
                'details' => 'Структурированные детали сообщения загрузчика.',
                'logged_at' => 'Время записи сообщения загрузчика.',
            ],
            'reestr_objects' => [
                'id' => 'Внутренний идентификатор объекта адресации из AS_REESTR_OBJECTS.OBJECTID.',
                'object_guid' => 'Стабильный UUID объекта адресации из AS_REESTR_OBJECTS.OBJECTGUID.',
                'change_id' => 'Идентификатор последнего изменения объекта из AS_REESTR_OBJECTS.CHANGEID.',
                'is_active' => 'Признак активного объекта из AS_REESTR_OBJECTS.ISACTIVE.',
                'level_id' => 'Уровень объекта адресации из AS_REESTR_OBJECTS.LEVELID.',
                'create_date' => 'Дата создания объекта из AS_REESTR_OBJECTS.CREATEDATE.',
                'update_date' => 'Дата обновления объекта из AS_REESTR_OBJECTS.UPDATEDATE.',
            ],
            'normative_docs' => [
                'id' => 'Идентификатор нормативного документа из AS_NORMATIVE_DOCS.ID.',
                'name' => 'Наименование нормативного документа из AS_NORMATIVE_DOCS.NAME.',
                'date' => 'Дата нормативного документа из AS_NORMATIVE_DOCS.DATE.',
                'number' => 'Номер нормативного документа из AS_NORMATIVE_DOCS.NUMBER.',
                'type_id' => 'Тип нормативного документа из AS_NORMATIVE_DOCS.TYPE.',
                'kind_id' => 'Вид нормативного документа из AS_NORMATIVE_DOCS.KIND.',
                'update_date' => 'Дата обновления нормативного документа из AS_NORMATIVE_DOCS.UPDATEDATE.',
                'orgname' => 'Организация, выпустившая документ, из AS_NORMATIVE_DOCS.ORGNAME.',
                'accdate' => 'Дата вступления документа в силу из AS_NORMATIVE_DOCS.ACCDATE; в GAR может отсутствовать.',
            ],
            'change_history' => [
                'id' => 'Идентификатор изменения из AS_CHANGE_HISTORY.CHANGEID.',
                'reestr_object_id' => 'Идентификатор объекта адресации из AS_CHANGE_HISTORY.OBJECTID.',
                'addr_object_guid' => 'UUID адресного объекта из AS_CHANGE_HISTORY.ADROBJECTID.',
                'oper_type_id' => 'Тип операции из AS_CHANGE_HISTORY.OPERTYPEID.',
                'ndoc_id' => 'Нормативный документ из AS_CHANGE_HISTORY.NDOCID; в GAR может отсутствовать.',
                'change_date' => 'Дата изменения из AS_CHANGE_HISTORY.CHANGEDATE.',
            ],
            'object_params' => [
                'id' => 'Идентификатор параметра из региональных AS_*_PARAMS.ID.',
                'object_id' => 'Идентификатор объекта адресации из региональных AS_*_PARAMS.OBJECTID.',
                'change_id' => 'Идентификатор начального изменения параметра из региональных AS_*_PARAMS.CHANGEID.',
                'change_id_end' => 'Идентификатор завершающего изменения параметра из региональных AS_*_PARAMS.CHANGEIDEND; может ссылаться на историю вне загружаемого среза.',
                'type_id' => 'Тип параметра из региональных AS_*_PARAMS.TYPEID.',
                'value' => 'Значение параметра из региональных AS_*_PARAMS.VALUE.',
                'update_date' => 'Дата обновления параметра из региональных AS_*_PARAMS.UPDATEDATE.',
                'start_date' => 'Дата начала действия параметра из региональных AS_*_PARAMS.STARTDATE.',
                'end_date' => 'Дата окончания действия параметра из региональных AS_*_PARAMS.ENDDATE.',
            ],
            'address_objects' => [
                'id' => 'Идентификатор версии адресного объекта из AS_ADDR_OBJ.ID.',
                'reestr_object_id' => 'Идентификатор объекта адресации из AS_ADDR_OBJ.OBJECTID.',
                'reestr_object_guid' => 'UUID объекта адресации из AS_ADDR_OBJ.OBJECTGUID.',
                'change_id' => 'Идентификатор изменения версии адресного объекта из AS_ADDR_OBJ.CHANGEID.',
                'name' => 'Имя адресного объекта из AS_ADDR_OBJ.NAME.',
                'type_name' => 'Краткий тип адресного объекта из AS_ADDR_OBJ.TYPENAME.',
                'addr_type_id' => 'Нормализованный тип адресного объекта из AS_ADDR_OBJ_TYPES.',
                'level_id' => 'Уровень адресного объекта из AS_ADDR_OBJ.LEVEL.',
                'oper_type_id' => 'Тип операции из AS_ADDR_OBJ.OPERTYPEID.',
                'prev_id' => 'Предыдущая версия адресного объекта из AS_ADDR_OBJ.PREVID.',
                'next_id' => 'Следующая версия адресного объекта из AS_ADDR_OBJ.NEXTID.',
                'update_date' => 'Дата обновления версии из AS_ADDR_OBJ.UPDATEDATE.',
                'start_date' => 'Дата начала действия версии из AS_ADDR_OBJ.STARTDATE.',
                'end_date' => 'Дата окончания действия версии из AS_ADDR_OBJ.ENDDATE.',
                'is_actual' => 'Признак актуальной версии из AS_ADDR_OBJ.ISACTUAL.',
                'is_active' => 'Признак активной версии из AS_ADDR_OBJ.ISACTIVE.',
            ],
            'address_object_divisions' => [
                'id' => 'Идентификатор связи деления из AS_ADDR_OBJ_DIVISION.ID.',
                'parent_id' => 'Родительский адресный объект из AS_ADDR_OBJ_DIVISION.PARENTID.',
                'child_id' => 'Дочерний адресный объект из AS_ADDR_OBJ_DIVISION.CHILDID.',
                'change_id' => 'Идентификатор изменения связи из AS_ADDR_OBJ_DIVISION.CHANGEID.',
            ],
            'apartments' => [
                'id' => 'Идентификатор версии помещения из AS_APARTMENTS.ID.',
                'reestr_object_id' => 'Идентификатор объекта адресации из AS_APARTMENTS.OBJECTID.',
                'reestr_object_guid' => 'UUID объекта адресации из AS_APARTMENTS.OBJECTGUID.',
                'change_id' => 'Идентификатор изменения версии помещения из AS_APARTMENTS.CHANGEID.',
                'number' => 'Номер помещения из AS_APARTMENTS.NUMBER.',
                'apart_type_id' => 'Тип помещения из AS_APARTMENTS.APARTTYPE; в реальных GAR-выгрузках может быть 0 или отсутствовать.',
                'oper_type_id' => 'Тип операции из AS_APARTMENTS.OPERTYPEID.',
                'prev_id' => 'Предыдущая версия помещения из AS_APARTMENTS.PREVID.',
                'next_id' => 'Следующая версия помещения из AS_APARTMENTS.NEXTID.',
                'update_date' => 'Дата обновления версии из AS_APARTMENTS.UPDATEDATE.',
                'start_date' => 'Дата начала действия версии из AS_APARTMENTS.STARTDATE.',
                'end_date' => 'Дата окончания действия версии из AS_APARTMENTS.ENDDATE.',
                'is_actual' => 'Признак актуальной версии из AS_APARTMENTS.ISACTUAL.',
                'is_active' => 'Признак активной версии из AS_APARTMENTS.ISACTIVE.',
            ],
            'car_places' => [
                'id' => 'Идентификатор версии машино-места из AS_CARPLACES.ID.',
                'reestr_object_id' => 'Идентификатор объекта адресации из AS_CARPLACES.OBJECTID.',
                'reestr_object_guid' => 'UUID объекта адресации из AS_CARPLACES.OBJECTGUID.',
                'change_id' => 'Идентификатор изменения версии машино-места из AS_CARPLACES.CHANGEID.',
                'number' => 'Номер машино-места из AS_CARPLACES.NUMBER.',
                'oper_type_id' => 'Тип операции из AS_CARPLACES.OPERTYPEID.',
                'prev_id' => 'Предыдущая версия машино-места из AS_CARPLACES.PREVID.',
                'next_id' => 'Следующая версия машино-места из AS_CARPLACES.NEXTID.',
                'update_date' => 'Дата обновления версии из AS_CARPLACES.UPDATEDATE.',
                'start_date' => 'Дата начала действия версии из AS_CARPLACES.STARTDATE.',
                'end_date' => 'Дата окончания действия версии из AS_CARPLACES.ENDDATE.',
                'is_actual' => 'Признак актуальной версии из AS_CARPLACES.ISACTUAL.',
                'is_active' => 'Признак активной версии из AS_CARPLACES.ISACTIVE.',
            ],
            'houses' => [
                'id' => 'Идентификатор версии дома из AS_HOUSES.ID.',
                'reestr_object_id' => 'Идентификатор объекта адресации из AS_HOUSES.OBJECTID.',
                'reestr_object_guid' => 'UUID объекта адресации из AS_HOUSES.OBJECTGUID.',
                'change_id' => 'Идентификатор изменения версии дома из AS_HOUSES.CHANGEID.',
                'house_num' => 'Основной номер дома из AS_HOUSES.HOUSENUM.',
                'add_num1' => 'Первый дополнительный номер дома из AS_HOUSES.ADDNUM1. Это отдельная величина со своим add_type1_id, не дубль house_num и не замена add_num2.',
                'add_num2' => 'Второй дополнительный номер дома из AS_HOUSES.ADDNUM2. Это отдельная величина со своим add_type2_id, не дубль house_num и не замена add_num1.',
                'house_type_id' => 'Основной тип дома из AS_HOUSES.HOUSETYPE.',
                'add_type1_id' => 'Тип первого дополнительного номера из AS_HOUSES.ADDTYPE1 и справочника AS_ADDHOUSE_TYPES.',
                'add_type2_id' => 'Тип второго дополнительного номера из AS_HOUSES.ADDTYPE2 и справочника AS_ADDHOUSE_TYPES.',
                'oper_type_id' => 'Тип операции из AS_HOUSES.OPERTYPEID.',
                'prev_id' => 'Предыдущая версия дома из AS_HOUSES.PREVID.',
                'next_id' => 'Следующая версия дома из AS_HOUSES.NEXTID.',
                'update_date' => 'Дата обновления версии из AS_HOUSES.UPDATEDATE.',
                'start_date' => 'Дата начала действия версии из AS_HOUSES.STARTDATE.',
                'end_date' => 'Дата окончания действия версии из AS_HOUSES.ENDDATE.',
                'is_actual' => 'Признак актуальной версии из AS_HOUSES.ISACTUAL.',
                'is_active' => 'Признак активной версии из AS_HOUSES.ISACTIVE.',
            ],
            'rooms' => [
                'id' => 'Идентификатор версии комнаты из AS_ROOMS.ID.',
                'reestr_object_id' => 'Идентификатор объекта адресации из AS_ROOMS.OBJECTID.',
                'reestr_object_guid' => 'UUID объекта адресации из AS_ROOMS.OBJECTGUID.',
                'change_id' => 'Идентификатор изменения версии комнаты из AS_ROOMS.CHANGEID.',
                'number' => 'Номер комнаты из AS_ROOMS.NUMBER.',
                'room_type_id' => 'Тип комнаты из AS_ROOMS.ROOMTYPE; в реальных GAR-выгрузках может быть 0 или отсутствовать.',
                'oper_type_id' => 'Тип операции из AS_ROOMS.OPERTYPEID.',
                'prev_id' => 'Предыдущая версия комнаты из AS_ROOMS.PREVID.',
                'next_id' => 'Следующая версия комнаты из AS_ROOMS.NEXTID.',
                'update_date' => 'Дата обновления версии из AS_ROOMS.UPDATEDATE.',
                'start_date' => 'Дата начала действия версии из AS_ROOMS.STARTDATE.',
                'end_date' => 'Дата окончания действия версии из AS_ROOMS.ENDDATE.',
                'is_actual' => 'Признак актуальной версии из AS_ROOMS.ISACTUAL.',
                'is_active' => 'Признак активной версии из AS_ROOMS.ISACTIVE.',
            ],
            'steads' => [
                'id' => 'Идентификатор версии земельного участка из AS_STEADS.ID.',
                'object_id' => 'Идентификатор объекта адресации из AS_STEADS.OBJECTID.',
                'object_guid' => 'UUID объекта адресации из AS_STEADS.OBJECTGUID.',
                'change_id' => 'Идентификатор изменения версии участка из AS_STEADS.CHANGEID.',
                'number' => 'Номер земельного участка из AS_STEADS.NUMBER.',
                'oper_type_id' => 'Тип операции из AS_STEADS.OPERTYPEID.',
                'prev_id' => 'Предыдущая версия участка из AS_STEADS.PREVID.',
                'next_id' => 'Следующая версия участка из AS_STEADS.NEXTID.',
                'update_date' => 'Дата обновления версии из AS_STEADS.UPDATEDATE.',
                'start_date' => 'Дата начала действия версии из AS_STEADS.STARTDATE.',
                'end_date' => 'Дата окончания действия версии из AS_STEADS.ENDDATE.',
                'is_actual' => 'Признак актуальной версии из AS_STEADS.ISACTUAL.',
                'is_active' => 'Признак активной версии из AS_STEADS.ISACTIVE.',
            ],
            'administrative_reestr_hierarchy' => [
                'id' => 'Идентификатор записи административной иерархии из AS_ADM_HIERARCHY.ID.',
                'object_id' => 'Идентификатор объекта адресации из AS_ADM_HIERARCHY.OBJECTID.',
                'parent_obj_id' => 'Идентификатор родительского объекта из AS_ADM_HIERARCHY.PARENTOBJID.',
                'change_id' => 'Идентификатор изменения записи иерархии из AS_ADM_HIERARCHY.CHANGEID.',
                'region_code' => 'Код региона из AS_ADM_HIERARCHY.REGIONCODE.',
                'area_code' => 'Код района из AS_ADM_HIERARCHY.AREACODE.',
                'city_code' => 'Код города из AS_ADM_HIERARCHY.CITYCODE.',
                'place_code' => 'Код населенного пункта из AS_ADM_HIERARCHY.PLACECODE.',
                'plan_code' => 'Код планировочной структуры из AS_ADM_HIERARCHY.PLANCODE.',
                'street_code' => 'Код улицы из AS_ADM_HIERARCHY.STREETCODE.',
                'prev_id' => 'Предыдущая версия записи иерархии из AS_ADM_HIERARCHY.PREVID.',
                'next_id' => 'Следующая версия записи иерархии из AS_ADM_HIERARCHY.NEXTID.',
                'update_date' => 'Дата обновления записи из AS_ADM_HIERARCHY.UPDATEDATE.',
                'start_date' => 'Дата начала действия записи из AS_ADM_HIERARCHY.STARTDATE.',
                'end_date' => 'Дата окончания действия записи из AS_ADM_HIERARCHY.ENDDATE.',
                'is_active' => 'Признак активной записи из AS_ADM_HIERARCHY.ISACTIVE.',
                'path' => 'Материализованный путь административной иерархии из AS_ADM_HIERARCHY.PATH.',
                'path_arr' => 'PATH, разобранный в массив идентификаторов для индексируемых PostgreSQL-запросов.',
            ],
            'municipal_reestr_hierarchy' => [
                'id' => 'Идентификатор записи муниципальной иерархии из AS_MUN_HIERARCHY.ID.',
                'object_id' => 'Идентификатор объекта адресации из AS_MUN_HIERARCHY.OBJECTID.',
                'parent_obj_id' => 'Идентификатор родительского объекта из AS_MUN_HIERARCHY.PARENTOBJID.',
                'change_id' => 'Идентификатор изменения записи иерархии из AS_MUN_HIERARCHY.CHANGEID.',
                'oktmo' => 'ОКТМО из AS_MUN_HIERARCHY.OKTMO.',
                'prev_id' => 'Предыдущая версия записи иерархии из AS_MUN_HIERARCHY.PREVID.',
                'next_id' => 'Следующая версия записи иерархии из AS_MUN_HIERARCHY.NEXTID.',
                'update_date' => 'Дата обновления записи из AS_MUN_HIERARCHY.UPDATEDATE.',
                'start_date' => 'Дата начала действия записи из AS_MUN_HIERARCHY.STARTDATE.',
                'end_date' => 'Дата окончания действия записи из AS_MUN_HIERARCHY.ENDDATE.',
                'is_active' => 'Признак активной записи из AS_MUN_HIERARCHY.ISACTIVE.',
                'path' => 'Материализованный путь муниципальной иерархии из AS_MUN_HIERARCHY.PATH.',
                'path_arr' => 'PATH, разобранный в массив идентификаторов для индексируемых PostgreSQL-запросов.',
            ],
        ];
    }

    private function commentColumnSql(string $table, string $column, string $comment): string
    {
        return sprintf(
            "COMMENT ON COLUMN %s.%s IS '%s'",
            $table,
            $column,
            str_replace("'", "''", $comment),
        );
    }

    /**
     * @return list<string>
     */
    private function downSql(): array
    {
        return [
            'DROP TABLE IF EXISTS municipal_reestr_hierarchy CASCADE',
            'DROP TABLE IF EXISTS administrative_reestr_hierarchy CASCADE',
            'DROP TABLE IF EXISTS steads CASCADE',
            'DROP TABLE IF EXISTS rooms CASCADE',
            'DROP TABLE IF EXISTS houses CASCADE',
            'DROP TABLE IF EXISTS car_places CASCADE',
            'DROP TABLE IF EXISTS apartments CASCADE',
            'DROP TABLE IF EXISTS address_object_divisions CASCADE',
            'DROP TABLE IF EXISTS address_objects CASCADE',
            'DROP TABLE IF EXISTS object_params CASCADE',
            'DROP TABLE IF EXISTS change_history CASCADE',
            'DROP TABLE IF EXISTS normative_docs CASCADE',
            'DROP TABLE IF EXISTS reestr_objects CASCADE',
            'DROP TABLE IF EXISTS parser_logs CASCADE',
            'DROP TABLE IF EXISTS gar_versions CASCADE',
            'DROP TABLE IF EXISTS room_types CASCADE',
            'DROP TABLE IF EXISTS param_types CASCADE',
            'DROP TABLE IF EXISTS operation_types CASCADE',
            'DROP TABLE IF EXISTS object_levels CASCADE',
            'DROP TABLE IF EXISTS normative_doc_types CASCADE',
            'DROP TABLE IF EXISTS normative_doc_kinds CASCADE',
            'DROP TABLE IF EXISTS apartment_types CASCADE',
            'DROP TABLE IF EXISTS address_object_types CASCADE',
            'DROP TABLE IF EXISTS house_addr_types CASCADE',
            'DROP TABLE IF EXISTS house_types CASCADE',
            'DROP EXTENSION IF EXISTS pg_trgm',
        ];
    }
}

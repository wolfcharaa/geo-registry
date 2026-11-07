# Data.Mos И OSM Address Sources

- Дата full-data evidence: `2026-11-06`
- Scope: GAR регионы `50` и `77`; Data.Mos/OSM mutation разрешена только для
  региона `77`
- Source data не входит в Git и монтируется read-only в CLI container

## Runtime Boundary

Импорт доступен только через единый `bin/app`:

```shell
bin/app datamos:address-registry:import --batch-size=5000 --truncate
bin/app datamos:address-registry:enrich
bin/app osm:address-aliases:import --batch-size=1000 --truncate
bin/app opensearch:reindex --batch-size=10000 --concurrency=8
```

Команды лениво собираются в отдельном CLI graph. HTTP и worker runtime не
создают blocking BSON/JSONL readers или PDO connection. PDO создаётся из того
же `DATABASE_DSN` и живёт не дольше одного CLI invocation; Yii DB остаётся
только migration tooling.

Настройки источников:

| Переменная | Default внутри container | Назначение |
| --- | --- | --- |
| `ADDRESS_SOURCE_DATA_PATH` | `../../../docker/data` | Host directory, read-only mount в `/data` |
| `DATAMOS_ADDRESS_REGISTRY_SOURCE` | `/data/datamos/addressreestr.bson.gz` | BSON.GZ/BSON/JSON/JSONL или HTTP source |
| `OSM_ADDRESS_ALIASES_SOURCE` | `/data/geodata/moscow-address-aliases.jsonl` | JSONL/JSONL.GZ с подготовленными `addr2:*` |
| `MOSCOW_REGION_CODE` | `77` | Partition, доступная enrichment/matching |

## Data.Mos Contract

Импорт потоково читает BSON.GZ, нормализует source fields и выполняет
идемпотентный upsert по `source_key` в `datamos_address_registry`. `--dry-run`
проверяет parser без записи; `--limit` ограничивает число исходных документов.

Enrichment выполняет три операции:

1. обновляет строки региона 77 по `N_FIAS = object_guid`;
2. наследует metadata/coordinates от московского родителя к помещениям и
   комнатам той же hierarchy;
3. добавляет отсутствующие `datamos-only` строки в partition 77 с conflict key
   `(region_code, object_id, hierarchy_type)`.

Регион 50 не входит в `UPDATE`, hierarchy lookup или insert target. Это явное
отличие от legacy SQL, который определял Москву эвристиками по ОКАТО/ОКТМО и
тексту адреса.

Full-data результат:

| Метрика | Значение |
| --- | ---: |
| Staging rows / unique source keys | `440 399 / 440 399` |
| Пустые нормализованные адреса | `0` |
| Import duration | `130 595 ms` |
| Обновлено `gar+datamos` | `11 572 796` |
| Вставлено `datamos-only` | `64 676` |
| Enrichment duration | `1 717 732 ms` (`28m 38s`) |

Числа enrichment полностью совпали с legacy. После операции region 50 остался
`14 607 719` строк без Data.Mos, region 77 стал `14 041 519` строк.

Enrichment является WAL-heavy maintenance operation: первый полный запуск
обновляет около 11,6 млн индексированных строк одним set-based `UPDATE`,
использует одно CPU core PostgreSQL и создаёт большой объём WAL/index writes.
Его нужно выполнять в maintenance window. Повторный запуск идемпотентен:
дорогое наследование выбирает только незаполненные поля, а прямые совпадения
по `N_FIAS` обновляются повторно.

## OSM Alias Contract

Importer не создаёт адреса. Основной `addr:*` сопоставляется только с домом
`MOSCOW_REGION_CODE`, после чего альтернативные `addr2:*` варианты записываются
идемпотентно в `address_aliases`. После изменения aliases OpenSearch projection
обязательно перестраивается.

Full-data результат: `139` source objects прочитано, `117` сопоставлено,
`794` aliases записано, `22` пропущено. Все записанные aliases принадлежат
region 77.

Legacy содержал `838` aliases, но региональная сверка выявила ошибочные matches:
`44` были связаны с Московской областью, `6` — с другим регионом, `12` — со
строками без регионального кода. Поэтому меньшее target count является
осознанным исправлением, а не потерей parity.

## Operator Checks

До enrichment:

```sql
SELECT count(*), count(DISTINCT source_key)
FROM datamos_address_registry;
```

После enrichment:

```sql
SELECT
    region_code,
    count(*),
    count(*) FILTER (WHERE address_source = 'datamos') AS datamos_only,
    count(*) FILTER (WHERE address_source = 'gar+datamos') AS enriched
FROM address_search
WHERE region_code IN (50, 77)
GROUP BY region_code
ORDER BY region_code;
```

После OSM import:

```sql
SELECT s.region_code, count(*)
FROM address_aliases a
JOIN address_search s
  ON s.object_id = a.object_id
 AND s.hierarchy_type = a.hierarchy_type
GROUP BY s.region_code;
```

OpenSearch alias переключается только после source/index parity нового
versioned index. Для `OPENSEARCH_SEARCH_ENABLED=1` нужны green parity/status и
принятый relevance contract. Для подготовленной release projection оба условия
выполнены; после смены dataset или ranking gate запускается повторно.

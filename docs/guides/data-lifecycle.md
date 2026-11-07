# Жизненный Цикл Данных

Geo Registry разделяет исходные данные, canonical storage и перестраиваемые
проекции. Git release не содержит ни одного из этих datasets.

## Что Откуда Берётся

| Слой | Источник | Назначение | Входит в backup |
| --- | --- | --- | --- |
| Canonical GAR | полная XML-выгрузка ФНС | адресные объекты, дома, помещения и иерархии | да, application PostgreSQL |
| `address_search` | rebuild из canonical GAR | региональный runtime search | да, application PostgreSQL |
| Data.Mos | отдельная выгрузка реестра адресов Москвы | московские metadata, координаты и datamos-only строки | да, после импорта в PostgreSQL |
| OSM aliases | подготовленный JSONL `addr2:*` | альтернативные московские написания | да, после импорта в PostgreSQL |
| Nominatim | утверждённый OSM PBF | reverse geocode | отдельный Nominatim backup |
| OpenSearch | rebuild из `address_search` и aliases | optional быстрый/fuzzy read model | нет, перестраивается |

Application PostgreSQL является source of truth приложения. Nominatim —
отдельная PostgreSQL lifecycle. OpenSearch никогда не заменяет их backup.

## Выбрать GAR Release

После запуска PostgreSQL и применения migrations запросите официальный metadata
endpoint ФНС:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:releases --json
```

Команда показывает URL, но намеренно не скачивает архив. Скачайте полную XML
выгрузку отдельно, сохраните URL, дату и SHA-256, затем распакуйте её вне Git.

Importer рекурсивно читает все поддерживаемые XML под переданным каталогом. Для
dataset только регионов `50` и `77` подготовьте отдельное дерево, содержащее
общие справочники release и только региональные каталоги `50`/`77`. Не
передавайте корень полной распаковки и не ожидайте, что `--regions` отфильтрует
его: у `gar:import` такой опции нет.

## Загрузить Canonical GAR

Пусть `GAR_SOURCE` указывает на XML-файл или подготовленный каталог:

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume "${GAR_SOURCE}:/data/gar:ro" \
  app bin/app gar:import /data/gar \
  --batch-size=50000 \
  --workers=4
```

Импорт использует bounded XMLReader + PostgreSQL `COPY`, фиксирует checkpoint
каждого batch и продолжает совместимую незавершённую операцию. `--restart`
нужен только для сознательного отказа от checkpoint; изменившийся source без
него отклоняется.

Проверить состояние:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77
```

## Построить Runtime Проекцию

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:address-search:rebuild --batch-size=50000

docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:integrity --regions=50,77 --deep --only-failed

docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77 --exact
```

Rebuild создаёт LIST-partitioned shadow table, проверяет parity и только затем
атомарно переключает active `address_search`. Для ручного review до switch
используйте `--build-only`; следующий обычный запуск продолжит готовую операцию.

## Optional Data.Mos И OSM

Development Compose монтирует host-каталог `ADDRESS_SOURCE_DATA_PATH` read-only
как `/data`. После размещения источников по путям из `config/.env`:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app datamos:address-registry:import \
  /data/datamos/addressreestr.bson.gz \
  --batch-size=5000 --truncate --enrich

docker/compose/dev.sh run --rm --no-deps app \
  bin/app osm:address-aliases:import \
  /data/geodata/moscow-address-aliases.jsonl \
  --batch-size=1000 --truncate
```

Обе операции применяются только к Москве (`MOSCOW_REGION_CODE=77`). Data.Mos
поддерживает `--dry-run`; OSM importer не создаёт новые адреса, а привязывает
aliases к существующим домам. Форматы и проверенные full-data counters описаны
в [справочнике источников](../migration/address-source-enrichment.md).

## Nominatim И OpenSearch

Для российских данных замените Monaco defaults в ignored `config/.env` на
утверждённый локальный PBF и отключите online updates, если snapshot должен быть
неизменяемым:

```dotenv
NOMINATIM_PBF_URL=
NOMINATIM_DATA_PATH=/absolute/host/path/to/pbf-directory
NOMINATIM_PBF_PATH=/data/nominatim/approved-snapshot.osm.pbf
NOMINATIM_REPLICATION_URL=
NOMINATIM_UPDATE_MODE=none
```

После любых изменений `address_search` или aliases полностью пересоберите
OpenSearch:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app opensearch:reindex --batch-size=10000 --concurrency=8
docker/compose/dev.sh run --rm --no-deps app bin/app opensearch:status
```

Включать `OPENSEARCH_SEARCH_ENABLED=1` разрешено только после green exact
source/index parity и принятого relevance gate. PostgreSQL остаётся fallback.

## Экспорт Готовых Данных

Готовый переносимый набор — это два независимых logical backup:

1. application PostgreSQL со schema, GAR, `address_search`, enrichment и
   durable queues;
2. Nominatim PostgreSQL с именем и SHA-256 исходного PBF.

OpenSearch не экспортируется как canonical data. Перед backup остановите новые
imports/rebuilds, поставьте workers на pause/drain и выберите защищённый
`BACKUP_ROOT`:

```shell
make backup-data-preflight \
  BACKUP_COMPONENT=application-postgres \
  BACKUP_ROOT=/srv/backups/geo-registry

make backup-data \
  BACKUP_COMPONENT=application-postgres \
  BACKUP_ROOT=/srv/backups/geo-registry

make backup-data \
  BACKUP_COMPONENT=nominatim \
  BACKUP_ROOT=/srv/backups/geo-registry \
  BACKUP_PROVENANCE_NAME=approved-snapshot.osm.pbf \
  BACKUP_PROVENANCE_SHA256=<64-hex-characters>
```

Каждый каталог содержит custom `pg_dump` и manifest с checksum/provenance.
Скопируйте каталоги и внешний manifest в защищённое off-host хранилище.

## Импорт Готовых Данных

Restore никогда не выполняется поверх активной БД. На целевом host сначала
проверьте backup, затем восстановите его в отсутствующую shadow database:

```shell
make verify-data-backup \
  BACKUP_DIR=/srv/backups/geo-registry/application-postgres-<UTC> \
  VERIFY_CONTAINER=<compatible-postgres-container>

make restore-data-preflight \
  BACKUP_DIR=/srv/backups/geo-registry/application-postgres-<UTC> \
  RESTORE_CONTAINER=<target-postgres-container> \
  RESTORE_DATABASE=geo_registry_restore_v1 \
  RESTORE_DATABASE_OWNER=geo_registry \
  RESTORE_ADMIN_USER=geo_registry

make restore-data-shadow \
  BACKUP_DIR=/srv/backups/geo-registry/application-postgres-<UTC> \
  RESTORE_CONTAINER=<target-postgres-container> \
  RESTORE_DATABASE=geo_registry_restore_v1 \
  RESTORE_DATABASE_OWNER=geo_registry \
  RESTORE_ADMIN_USER=geo_registry \
  RESTORE_CONFIRM=create:<target-postgres-container>:geo_registry_restore_v1
```

Nominatim восстанавливается отдельно в совместимый PostgreSQL/Nominatim target.
После application restore выполните migrations/integrity/worker status,
пересоберите OpenSearch и только затем меняйте production DSN.

Полный fail-closed порядок, rollback boundary и обязательные проверки описаны в
[Backup и restore данных](../deployment/data-backup-restore.md). Для первого
release дополнительно выполните [release parity runbook](../migration/release-parity.md).

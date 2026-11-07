# Получение, Импорт И Нормализация Данных

Результат этого руководства — наполненная application PostgreSQL с canonical
GAR и partitioned `address_search` для регионов `50` и `77`. Data.Mos,
OSM aliases, Nominatim и OpenSearch подключаются после базовой проверки GAR.

Git release не содержит datasets. Исходные архивы, распаковки и backups храните
вне repository.

## Какие Данные Нужны

| Слой | Источник | Для чего |
| --- | --- | --- |
| Canonical GAR | полная XML-выгрузка ФНС | адресные объекты, дома, помещения, hierarchies |
| `address_search` | rebuild из canonical GAR | быстрый региональный поиск PostgreSQL |
| Data.Mos | официальный реестр адресов Москвы | координаты и московские metadata |
| OSM aliases | подготовленный JSONL с `addr2:*` | альтернативные написания адресов |
| Nominatim | OSM PBF | reverse geocoding |
| OpenSearch | rebuild из `address_search` | optional fuzzy/concurrent read model |

Source of truth приложения — application PostgreSQL. OpenSearch является
перестраиваемой проекцией. Nominatim использует отдельную PostgreSQL database.

## 1. Подготовить Runtime

Сначала выполните [первый запуск](getting-started.md), включая migrations:

```shell
make up
make migrate
```

Для большого import заранее проверьте свободное место и профиль PostgreSQL:

```shell
make postgres-doctor
```

## 2. Выбрать Официальный GAR Release

Приложение читает официальный metadata endpoint ФНС и показывает прямой URL
полной XML-выгрузки:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:releases --json
```

Команда ничего не скачивает. Выберите `full_xml_url`, а не delta archive.
Исходный каталог releases также доступен на
[портале ФИАС ФНС](https://fias.nalog.ru/Updates).

Зафиксируйте для provenance:

- `version_id`, дату и URL из команды;
- имя скачанного файла;
- SHA-256 архива;
- дату скачивания.

## 3. Скачать И Проверить Архив

Ниже `<full_xml_url>` — URL из `gar:releases`, а каталог можно заменить на
свой:

```shell
mkdir -p /srv/geo-registry-data/gar
cd /srv/geo-registry-data/gar

curl --fail --location --output gar-full.zip '<full_xml_url>'
sha256sum gar-full.zip
unzip -t gar-full.zip
unzip -q gar-full.zip -d full
```

Полная распаковка значительно больше ZIP. Проверяйте свободное место до
`unzip`; не распаковывайте архив внутри Git workspace.

## 4. Оставить Только Регионы 50 И 77

`gar:import` рекурсивно читает все поддерживаемые XML и не имеет опции
`--regions`. Поэтому подготовьте отдельный source tree:

```text
regions-50-77/
├── AS_*_TYPES_*.XML и другие общие справочники release
├── version.txt
├── 50/
│   └── региональные AS_*.XML
└── 77/
    └── региональные AS_*.XML
```

Пример для распаковки, где файлы лежат непосредственно в `full/`:

```shell
mkdir -p /srv/geo-registry-data/gar/regions-50-77
cp -a /srv/geo-registry-data/gar/full/50 \
  /srv/geo-registry-data/gar/regions-50-77/50
cp -a /srv/geo-registry-data/gar/full/77 \
  /srv/geo-registry-data/gar/regions-50-77/77

find /srv/geo-registry-data/gar/full -maxdepth 1 -type f \
  \( -name 'AS_*.XML' -o -name 'version.txt' \) \
  -exec cp -a -- {} /srv/geo-registry-data/gar/regions-50-77/ \;
```

Проверьте, что на верхнем уровне нет других региональных каталогов:

```shell
find /srv/geo-registry-data/gar/regions-50-77 \
  -mindepth 1 -maxdepth 1 -type d -printf '%f\n'
du -sh /srv/geo-registry-data/gar/regions-50-77
```

Ожидаются только `50` и `77`. Общие root XML нужны для справочников типов и
операций; не копируйте только два региональных каталога.

## 5. Импортировать Canonical GAR

Importer использует streaming XMLReader, нормализует XML attributes в
типизированные canonical tables, передаёт rows через PostgreSQL `COPY` во
временные staging tables и запускает несколько worker processes. Каждый batch
фиксирует checkpoint.

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume /srv/geo-registry-data/gar/regions-50-77:/data/gar:ro \
  app bin/app gar:import /data/gar \
  --batch-size=50000 \
  --workers=4
```

Повтор команды продолжает совместимую незавершённую operation. `--restart`
используйте только если нужно сознательно отказаться от checkpoint. Source,
изменившийся после начала operation, отклоняется.

Посмотреть progress:

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:activity
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77
```

## 6. Построить Поисковую Проекцию

Canonical tables повторяют структуру GAR. Для runtime search приложение
денормализует активные administrative/municipal hierarchies в
`address_search`, разделённую LIST partitions по `region_code`.

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:address-search:rebuild --batch-size=50000

docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:integrity --regions=50,77 --deep --only-failed

docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77 --exact
```

Rebuild строит shadow table, создаёт indexes, проверяет parity и атомарно
переключает active table. `--build-only` оставляет проверенную shadow table в
состоянии `ready`; следующий обычный запуск выполнит cutover.

## 7. Добавить Data.Mos

Официальный источник —
[«Адресный реестр объектов недвижимости города Москвы»](https://data.mos.ru/opendata/60562).
Importer принимает BSON.GZ, BSON, JSON, JSONL или совместимый paginated HTTP
source, но не разбирает XML. Получите snapshot на официальной странице и, если
портал отдаёт другой формат, преобразуйте его вне приложения с сохранением
version/date/checksum. В repository готового converter или официального mirror
нет; старый сторонний BSON snapshot нельзя выдавать за актуальную выгрузку.
Готовый input сохраните вне Git, например в
`<ADDRESS_SOURCE_DATA_PATH>/datamos/addressreestr.bson.gz`.

Сначала проверьте parser на ограниченном наборе без записи:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app datamos:address-registry:import \
  /data/datamos/addressreestr.bson.gz \
  --limit=1000 --dry-run
```

Затем загрузите staging и обогатите только регион `77`:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app datamos:address-registry:import \
  /data/datamos/addressreestr.bson.gz \
  --batch-size=5000 --truncate --enrich
```

Enrichment сопоставляет `N_FIAS` с GAR UUID, добавляет metadata/coordinates и
создаёт отсутствующие `datamos-only` адреса. Это WAL-heavy maintenance
operation; выполняйте её до OpenSearch rebuild.

## 8. Добавить OSM Aliases

Importer aliases не разбирает произвольный PBF. Ему нужен заранее
подготовленный JSONL/JSONL.GZ с основным `addr:*` и альтернативными
`addr2:*`. Этот artifact относится к legacy enrichment pipeline, а не к
официальному GAR download.

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app osm:address-aliases:import \
  /data/geodata/moscow-address-aliases.jsonl \
  --batch-size=1000 --truncate
```

Importer не создаёт адреса и изменяет только московскую partition. Формат и
контрольные SQL описаны в
[справочнике enrichment](../migration/address-source-enrichment.md).

## 9. Настроить Nominatim

Для Москвы и Московской области подходит OSM PBF Центрального федерального
округа с [Geofabrik](https://download.geofabrik.de/russia/central-fed-district.html).
Зафиксируйте имя и checksum выбранного snapshot и укажите локальный файл:

```dotenv
NOMINATIM_PBF_URL=
NOMINATIM_DATA_PATH=/absolute/host/path/to/pbf-directory
NOMINATIM_PBF_PATH=/data/nominatim/central-fed-district.osm.pbf
NOMINATIM_REPLICATION_URL=
NOMINATIM_UPDATE_MODE=none
```

Nominatim database не заполняет GAR tables и переносится отдельным backup.

## 10. Построить OpenSearch

После финального `address_search`, Data.Mos и aliases:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app opensearch:reindex --batch-size=10000 --concurrency=8
docker/compose/dev.sh run --rm --no-deps app bin/app opensearch:status
```

`OPENSEARCH_SEARCH_ENABLED=1` включайте только после green parity/status и
relevance check. PostgreSQL остаётся fallback и source of truth.

## 11. Проверить И Сохранить Результат

```shell
make smoke
make smoke-release \
  POSTMAN_ENVIRONMENT=config/release.postman_environment.json
```

Готовый переносимый набор состоит из независимых backups application
PostgreSQL и Nominatim. OpenSearch экспортировать как canonical data не нужно —
он перестраивается. Полные команды preflight, backup, verification и shadow
restore находятся в [runbook backup/restore](../deployment/data-backup-restore.md).

# Geo Registry

Geo Registry — сервис поиска российских адресов по данным ГАР/ФИАС на PHP 8.4
и AMPHP. PostgreSQL хранит исходную модель ГАР и готовую поисковую проекцию,
OpenSearch опционально ускоряет fuzzy-поиск, а Nominatim добавляет reverse
geocoding.

Текущий проверенный набор ограничен регионами `50` (Московская область) и
`77` (Москва). Данные, database dumps, OpenSearch indexes и Nominatim volumes
не входят в Git release: оператор выбирает и загружает их отдельно.

## С Чего Начать

Выберите свою задачу:

1. [Запустить приложение](docs/guides/getting-started.md) — получить три
   совместимых repository, настроить окружение и проверить пустой runtime.
2. [Скачать и загрузить данные](docs/guides/data-lifecycle.md) — получить
   официальный GAR XML, выделить регионы 50/77, импортировать canonical tables
   и построить `address_search`.
3. [Подключить HTTP-клиент](docs/api/openapi.yaml) — использовать search,
   suggest, find-by-id и reverse-geocode.
4. [Перенести готовую базу](docs/deployment/data-backup-restore.md) — создать
   проверяемый backup и восстановить его в shadow database.
5. [Перейти с legacy](docs/migration/from-legacy.md) — сопоставить старые и
   новые команды, данные и ограничения.

## Быстрый Запуск

Приложение `v0.1.x` использует две соседние библиотеки из Composer path
repositories. Workspace должна выглядеть так:

```text
workspace/
├── geo-registry/
└── library/
    ├── MessageBusAsync/
    └── MessageBusAsyncWorker/
```

Клонирование exact release и все требования описаны в
[руководстве первого запуска](docs/guides/getting-started.md). После получения
workspace:

```shell
make config
make doctor
make build
make install
make up
make migrate
make smoke
```

После migrations база содержит только schema. Пустой результат поиска на этом
этапе корректен; адреса появляются только после отдельного import/rebuild.

## Как Появляются Данные

```text
официальный GAR XML
  -> региональный source 50/77
  -> canonical GAR tables
  -> partitioned address_search
  -> Data.Mos / OSM aliases (опционально)
  -> OpenSearch projection (опционально)
```

Основные команды:

```shell
bin/app gar:releases --json
bin/app gar:import /data/gar --batch-size=50000 --workers=4
bin/app gar:address-search:rebuild --batch-size=50000
bin/app gar:integrity --regions=50,77 --deep --only-failed
bin/app gar:status --regions=50,77 --exact
```

Не запускайте `gar:import` на корне всероссийской распаковки, если нужен только
московский набор: у команды нет `--regions`. Подготовка каталога, скачивание,
распаковка, Data.Mos, Nominatim и OpenSearch описаны в
[жизненном цикле данных](docs/guides/data-lifecycle.md).

## HTTP API

Основные endpoints:

- `GET /health/live` — жив ли AMPHP process;
- `GET /health/startup` — завершена ли сборка runtime;
- `GET /health/ready` — готовы ли required/optional dependencies;
- `GET /api/addresses/search` — полнотекстовый поиск;
- `POST /api/addresses/suggest` — поэтапные подсказки;
- `POST /api/addresses/find-by-id` — поиск по GAR/FIAS/Data.Mos ID;
- `GET /api/addresses/reverse-geocode` — поиск по координатам.

Точный request/response contract находится в
[OpenAPI](docs/api/openapi.yaml), готовые запросы — в
[Postman collection](docs/api/postman/geo-registry.postman_collection.json).

## Опциональные Сервисы

Если Nominatim или OpenSearch не нужны, отключите их в ignored
`config/.env`:

```dotenv
NOMINATIM_ENABLED=0
OPENSEARCH_ENABLED=0
OPENSEARCH_SEARCH_ENABLED=0
OPENSEARCH_COORDINATE_UPDATES_ENABLED=0
```

Отключённые integrations не попадают в DIC graph и не задерживают readiness.
Application PostgreSQL остаётся обязательным. PostgreSQL Nominatim — отдельная
база со своими data lifecycle и backup.

## Консоль И Workers

`app/bin/app` — единый executable для HTTP, migrations, imports, projections
и worker control:

```shell
app/bin/app list
app/bin/app server:run
app/bin/app gar:import --help
app/bin/app worker:status --all
```

Development workers запускаются отдельно:

```shell
make worker-up
make worker-status
make worker-logs
```

## Документация

- [Первый запуск](docs/guides/getting-started.md)
- [Получение, импорт и нормализация данных](docs/guides/data-lifecycle.md)
- [Архитектурные границы](docs/architecture.md)
- [Thesis DIC и scopes](docs/development/thesis-dic.md)
- [PostgreSQL tuning](docs/postgresql-tuning.md)
- [Production Compose](docs/deployment/production-compose.md)
- [Source release](docs/deployment/source-release.md)
- [Offline release](docs/deployment/offline-release.md)

Исторические сравнения, benchmark и migration evidence находятся в
`docs/migration/`; для первого запуска они не нужны.

## Разработка

```shell
make check
make contract
make test-integration
make architecture-check
```

## Лицензия

[MIT](LICENSE)

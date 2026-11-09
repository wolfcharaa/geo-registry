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

1. [Запустить приложение](docs/guides/getting-started.md) — получить
   приложение, установить зафиксированные Composer packages, настроить
   окружение и проверить пустой runtime.
2. [Скачать и загрузить данные](docs/guides/data-lifecycle.md) — получить
   официальный GAR XML, выделить регионы 50/77, импортировать canonical tables
   и построить `address_search`.
3. [Подключить HTTP-клиент](docs/api/openapi.yaml) — использовать search,
   suggest, find-by-id и reverse-geocode.
4. [Перенести готовую базу](docs/deployment/data-backup-restore.md) — создать
   проверяемый backup и восстановить его в shadow database.
5. [Поднять development из готового backup](docs/guides/development-data-bootstrap.md)
   — восстановить PostgreSQL и заново построить OpenSearch.
6. [Выпустить и развернуть релиз](docs/deployment/release-deploy.md) —
   подготовить tag/image, перенести PostgreSQL и запустить новый сервер.
7. [Перейти с legacy](docs/migration/from-legacy.md) — сопоставить старые и
   новые команды, данные и ограничения.
8. [Разобрать проблему развёртывания](docs/deployment/troubleshooting.md) —
   понять, работает ли restore/import, безопасно пережить потерю SSH и выбрать
   следующий шаг без ручных SQL-команд.
9. [Развернуть готовый PostgreSQL backup](docs/deployment/deploy-with-backup.md)
   или [наполнить новый сервер из источников](docs/deployment/deploy-from-sources.md)
   — использовать отдельный линейный runbook без смешивания сценариев.
10. [Открыть доступ другому сервису](docs/deployment/network-access.md) —
    выбрать loopback, общую Docker network, private/LAN interface или
    защищённый reverse proxy согласно trust boundary.

## Production Быстрый Запуск

Новый сервер с готовым backup запускается только из exact release checkout.
До первой mutating-команды зафиксируйте утверждённую label цели и уже выбранный
Docker context; третья переменная должна буквально повторять target:

```shell
export DEPLOY_TARGET=moscow-primary
export DEPLOY_EXPECTED_DOCKER_CONTEXT=production
export DEPLOY_CONFIRM_TARGET="${DEPLOY_TARGET}"

make deploy-init
make deploy-doctor
make deploy-image DOCKER_PLATFORM=linux/amd64
make deploy-port-preflight
make data-restore
make deploy-start DOCKER_PLATFORM=linux/amd64
```

Во время длительного restore команда печатает heartbeat. Из второй сессии
текущее состояние и безопасный следующий шаг показывает
`make data-restore-status`. Блок предполагает, что проверенный backup уже
помещён в `docker/data/backups/`; выбор одного из нескольких каталогов и
проверка exact release описаны в
[линейном runbook](docs/deployment/deploy-with-backup.md).

`compose.yaml` содержит production defaults, `compose.override.yaml` —
необязательные host-specific несекретные изменения, а
`config/prod.secrets.env` содержит только пароли и API keys. Подробный линейный
сценарий, отдельный путь обновления существующего deployment и импорт из
официальных источников находятся в
[руководстве развёртывания](docs/deployment/release-deploy.md).

## Development Быстрый Запуск

Приложение получает обе MessageBus-библиотеки из Packagist. Достаточно
клонировать один repository:

```shell
git clone https://github.com/wolfcharaa/geo-registry.git
cd geo-registry
```

`app/composer.lock` фиксирует опубликованные версии; `make install` загружает
их как обычные Composer dist packages. Все требования описаны в
[руководстве первого запуска](docs/guides/getting-started.md):

```shell
make config
make doctor
make build
make install
make up
make migrate
make smoke
make load-check
```

После migrations база содержит только schema. Пустой результат поиска на этом
этапе корректен; адреса появляются только после отдельного import/rebuild.

## Как Появляются Данные

```text
официальный GAR XML
  -> --regions=50,77 без копирования всероссийской распаковки
  -> canonical GAR tables
  -> partitioned address_search
  -> Data.Mos / OSM aliases (опционально)
  -> OpenSearch projection (опционально)
```

Основные команды:

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:releases --json
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:import /data/gar --regions=50,77 --batch-size=50000 --workers=4
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:address-search:rebuild --batch-size=50000
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:integrity --regions=50,77 --deep --only-failed
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77 --exact
docker/compose/dev.sh run --rm --no-deps app \
  bin/app datamos:address-registry:download --dry-run
```

`gar:import --regions=50,77` оставляет общие root-справочники release и читает
региональные XML только из каталогов `50/` и `77/`. Скачивание, распаковка,
официальный Data.Mos snapshot, Nominatim и OpenSearch описаны в
[жизненном цикле данных](docs/guides/data-lifecycle.md).

После наполнения projection mixed-load gate регионального поиска запускается
через `make load-search`; сценарий и сравнение PostgreSQL/OpenSearch описаны в
[руководстве нагрузочной проверки](docs/deployment/address-search-load-test.md).

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
- [Development из готового PostgreSQL backup](docs/guides/development-data-bootstrap.md)
- [Архитектурные границы](docs/architecture.md)
- [Thesis DIC и scopes](docs/development/thesis-dic.md)
- [PostgreSQL tuning](docs/postgresql-tuning.md)
- [Production-like HTTP runtime](docs/deployment/http-runtime.md)
- [Production Compose](docs/deployment/production-compose.md)
- [Source release](docs/deployment/source-release.md)
- [Offline release](docs/deployment/offline-release.md)
- [Развёртывание релиза с готовыми данными](docs/deployment/release-deploy.md)
- [Новый сервер с готовым PostgreSQL backup](docs/deployment/deploy-with-backup.md)
- [Новый сервер с наполнением из источников](docs/deployment/deploy-from-sources.md)
- [Сетевой доступ для других приложений](docs/deployment/network-access.md)
- [Диагностика развёртывания](docs/deployment/troubleshooting.md)
- [Roadmap усиления release и deployment](docs/migration/deployment-hardening-roadmap.md)

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

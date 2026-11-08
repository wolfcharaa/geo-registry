# Новый Сервер С Наполнением Из Источников

Этот runbook предназначен для production-развёртывания без готового PostgreSQL
backup. Приложение само скачивает официальный полный GAR XML, импортирует только
регионы 50/77, строит `address_search` и при необходимости загружает Data.Mos и
OpenSearch.

## Где Хранятся Данные

По умолчанию maintenance container монтирует tracked-out каталог
`docker/data/` в `/data`:

```text
docker/data/
├── gar/
│   ├── gar-full.zip
│   └── full/
├── datamos/
│   ├── addressreestr.jsonl.gz
│   └── addressreestr.jsonl.gz.manifest.json
└── backups/
```

Каталог ignored и не входит в Git release. Для большого отдельного диска
создайте `compose.override.yaml` до скачивания:

```yaml
services:
  maintenance:
    volumes:
      - type: bind
        source: /srv/geo-registry-data
        target: /data
```

После этого host paths будут `/srv/geo-registry-data/gar`, `datamos` и
`backups`. Не меняйте mount после начала import: checkpoint связан с source
manifest и путём.

## 1. Получить Release И Проверить Host

```shell
cd /var/www/geo-registry
git fetch --tags origin
git switch --detach <release-tag>
test "$(git rev-parse HEAD)" = "$(git rev-parse 'refs/tags/<release-tag>^{commit}')"
git status --short

make deploy-init
make deploy-image DOCKER_PLATFORM=linux/amd64
make deploy-port-preflight
```

Port проверяется до многочасовой подготовки данных. Если default
`127.0.0.1:8080` занят, измените `services.app.ports` через `!override`, как
описано в [runbook с готовым backup](deploy-with-backup.md).

Публикация для consumer на отдельном физическом сервере настраивается через
точный private/LAN IP и firewall allow-list по
[сетевому runbook](network-access.md); `0.0.0.0` не является безопасным
production default.

До скачивания проверьте место на filesystem выбранного `/data`: ZIP, полная
распаковка, canonical PostgreSQL, WAL и будущий backup существуют одновременно.

```shell
df -h docker/data
```

При внешнем bind проверяйте его host path вместо `docker/data`.

## 2. Создать Пустую Application Database

```shell
make data-init
```

Команда сама запускает managed PostgreSQL, создаёт роль/БД, применяет migrations
и снимает временные elevated privileges. Она отказывается работать поверх уже
существующей `geo_registry`.

## 3. Выбрать И Скачать Официальный GAR

Получите список releases и скопируйте `full_xml_url` нужной полной выгрузки:

```shell
make data-gar-releases
```

Скачайте, проверьте ZIP и атомарно распакуйте его:

```shell
make data-gar-download \
  GAR_URL='<full_xml_url>'
```

Если checksum уже получен по доверенному каналу, добавьте к вызову
`GAR_SHA256='<expected-sha256>'`. В любом случае downloader проверяет структуру
ZIP и печатает фактический SHA-256, который нужно сохранить для последующего
переноса. Результат находится в `/data/gar/gar-full.zip` и `/data/gar/full`, то
есть по умолчанию в `docker/data/gar/` на host. Partial download и partial
extraction не публикуются как готовые данные.

## 4. Импортировать Регионы 50 И 77

```shell
make data-gar-import
```

Importer читает общие root dictionaries и региональные XML только из `50/` и
`77/`, использует streaming XMLReader, COPY batches по 50 000 строк и четыре
workers. Из второй terminal-сессии progress читается без остановки import:

```shell
make data-status
```

Повторный запуск продолжает совместимую checkpointed operation. Не используйте
`--restart` без осознанного отказа от текущего checkpoint.

## 5. Построить PostgreSQL Search Projection

```shell
make data-address-rebuild
```

Команда строит partitioned shadow `address_search`, выполняет deep integrity и
точную parity-проверку регионов 50/77. Progress также виден через
`make data-status`.

## 6. Опционально Добавить Data.Mos

Запишите `GEO_REGISTRY_DATAMOS_API_KEY` только в
`config/prod.secrets.env`, затем:

```shell
make data-datamos-download
make data-datamos-import
make data-address-rebuild
```

Snapshot и manifest появятся в `/data/datamos/`. Повторный address rebuild
обязателен после enrichment и выполняется до OpenSearch.

## 7. Опционально Построить OpenSearch

```shell
make data-opensearch
```

Команда запускает pinned OpenSearch, строит новый versioned index из
PostgreSQL, проверяет source/target parity и read alias. OpenSearch volume не
является backup canonical data.

## 8. Создать Переносимый Backup

После завершения нормализации полезно сразу создать artifact, чтобы следующий
сервер разворачивался по короткому runbook:

```shell
make data-backup-preflight
make data-backup
```

Готовый каталог появится в `docker/data/backups/` и будет опубликован только
после TOC/checksum verification. Для внешнего backup filesystem передайте
абсолютный host path обеим командам:

```shell
make data-backup-preflight BACKUP_ROOT=/srv/geo-registry-data/backups
make data-backup BACKUP_ROOT=/srv/geo-registry-data/backups
```

## 9. Активировать Сервис

```shell
make deploy-start DOCKER_PLATFORM=linux/amd64
```

Успешная команда повторяет port/data/release gates, запускает application и
workers и выполняет HTTP smoke по фактическому Compose binding.

```shell
docker compose --env-file config/prod.secrets.env ps
make deploy-smoke
```

Если команда остановилась, не повторяйте предыдущие data stages вслепую.
Используйте `make data-status`, `make data-restore-status` либо
`make deploy-port-preflight` в зависимости от напечатанного phase.

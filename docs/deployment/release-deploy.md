# Развёртывание И Обновление Релиза

Production-контракт использует обычный Docker Compose. Несекретные настройки
находятся в tracked YAML, host-specific изменения — в ignored
`compose.override.yaml`, а `config/prod.secrets.env` содержит только пароли и
API keys.

Требуются Linux, Git, Make, Docker Engine и Docker Compose `2.24.4+`. Команды
выполняются non-root deployment user с доступом к Docker.

## Выберите Сценарий

| Сценарий | Путь |
| --- | --- |
| Новый сервер с готовым PostgreSQL backup | `deploy-init` → `deploy-image` → `data-restore` → `deploy-start` |
| Новый сервер с официальными источниками | `deploy-init` → `deploy-image` → `data-init` → import/rebuild → `deploy-start` |
| Обновление уже managed Compose deployment | `deploy-image` → `deploy-update-preflight` → `deploy-update` |
| Переход с `v0.2.1` или вручную восстановленной БД | сохранить старый контур → создать/проверить backup → восстановить его в новый managed volume → `deploy-start` |

Не смешивайте эти пути. `data-restore` создаёт отсутствующую БД и отказывается
перезаписывать существующую. `deploy-update` требует уже существующую managed
БД `geo_registry` и никогда не используется для первого запуска.

## Получить Exact Tag

Ниже `v0.2.2` — первый release с native managed Compose-контрактом:

```shell
cd /var/www/geo-registry
git fetch --tags origin
git switch --detach v0.2.2
test "$(git cat-file -t refs/tags/v0.2.2)" = tag
test "$(git rev-parse HEAD)" = "$(git rev-parse 'refs/tags/v0.2.2^{commit}')"
git status --short
```

Последняя команда не должна печатать изменений. Не копируйте отдельные scripts
из `main` в старый tag: runbook, Compose и release tooling являются одним
versioned contract.

## Подготовить Secrets И Application Image

Для нового managed deployment создайте target-local secrets:

```shell
make deploy-init
```

Команда идемпотентна и не перезаписывает существующий
`config/prod.secrets.env`. Не переносите этот файл между серверами.

Одна команда выбирает image source:

```shell
make deploy-image DOCKER_PLATFORM=linux/amd64
```

Если в `docker/data/release/<tag>/` лежат оба offline-файла
`source-linux-amd64.tar` и `source-manifest.txt`, команда проверяет checksum и
загружает archive. Если их нет, она делает explicit registry pull exact frozen
image. Частичный offline payload является ошибкой, а не причиной fallback в
registry.

Для private registry сначала выполните соответствующий `docker login`.

## Новый Сервер: Восстановить Готовые Данные

Положите проверенный backup-каталог в `docker/data/backups/`. Он содержит:

```text
application-postgres-<identity>/
├── application-postgres.dump
└── manifest.txt
```

Если найден ровно один backup или full-data release:

```shell
make data-restore
```

Команда сама запускает закреплённый PostgreSQL 17/PostGIS, проверяет bytes,
SHA-256, TOC и PostgreSQL major, показывает target и просит одно подтверждение.
Она создаёт только отсутствующую `geo_registry`, временно повышает owner role и
обязательно снимает повышение после restore.

При нескольких backups выберите один без session exports:

```shell
make data-restore \
  DEPLOY_RESTORE_FLAGS='--backup-dir=/srv/backups/application-postgres-v0.2.0'
```

Не запускайте PostgreSQL отдельной командой перед `data-restore`: lifecycle уже
принадлежит restore orchestration.

## Новый Сервер: Наполнить БД Из Источников

Вместо restore выберите fresh path:

```shell
make data-init
make data-gar-releases
make data-gar-download GAR_URL='<full_xml_url>'
make data-gar-import
make data-address-rebuild
```

`data-gar-download` принимает только HTTPS, проверяет ZIP, печатает SHA-256 и
атомарно публикует extraction. Import читает регионы `50,77`, использует
streaming XML, COPY и четыре worker processes.

Опциональное обогащение Москвы:

```shell
# GEO_REGISTRY_DATAMOS_API_KEY задаётся в config/prod.secrets.env.
make data-datamos-download
make data-datamos-import
make data-address-rebuild
```

## OpenSearch

OpenSearch — перестраиваемая проекция, не backup source. После готового
`address_search`:

```shell
make data-opensearch
```

Команда строит новый physical index и проверяет alias/source-target parity.
Только после успешного status включите поиск в `compose.override.yaml`:

```yaml
services:
  app:
    environment:
      OPENSEARCH_ENABLED: "1"
      OPENSEARCH_SEARCH_ENABLED: "1"
  worker-runtime:
    environment:
      OPENSEARCH_ENABLED: "1"
```

## Активировать Новый Сервер

После restore/import и optional OpenSearch:

```shell
make deploy-start
```

Команда повторно проверяет frozen image, migration history, GAR integrity и
exact status регионов `50,77`, активирует только application processes через
`change-release` и завершает путь HTTP smoke. Она не восстанавливает и не
перезаписывает данные.

Проверка состояния:

```shell
docker compose --env-file config/prod.secrets.env ps
docker compose --env-file config/prod.secrets.env logs --tail=100 app
```

## Обновить Существующий Managed Deployment

Этот путь поддерживается для deployments, уже созданных native Compose
контрактом `v0.2.2+`. Сначала на текущем tag проверьте место и создайте verified
backup managed БД. Команды сами находят production container, используют БД
`geo_registry` и сохраняют результат вне PostgreSQL volume:

```shell
make data-backup-preflight
make data-backup
```

По умолчанию backup появится в `docker/data/backups/`. Другой абсолютный путь
задаётся параметром `BACKUP_ROOT=/srv/backups`, без session exports.

Получите новый annotated tag, затем подготовьте exact image:

```shell
git fetch --tags origin
git switch --detach <new-tag>
make deploy-image DOCKER_PLATFORM=linux/amd64
```

Read-only preflight не останавливает процессы и не меняет БД:

```shell
make deploy-update-preflight DOCKER_PLATFORM=linux/amd64
```

После review выполните update:

```shell
make deploy-update DOCKER_PLATFORM=linux/amd64
```

Команда показывает точные release/container/database, просит подтверждение,
останавливает HTTP producer, ставит workers на pause, завершает их через
graceful stop, применяет migrations, выполняет региональные gates, активирует
exact image, снимает pause и запускает smoke. При non-interactive automation
используйте `DEPLOY_UPDATE_FLAGS=--yes` только после успешного preflight.

Если update прервался после quiesce, не запускайте workers вручную. Сохраните
вывод, проверьте `docker compose ... ps/logs` и состояние migrations; затем
примите явное решение завершить update либо восстановить backup. Code rollback
не гарантирует обратную совместимость уже применённых migrations.

## Переход С v0.2.1 Или Ручного PostgreSQL

Старый release использовал внешний PostgreSQL и произвольные container, volume,
database name и пароль. Новый Compose не пытается угадать или незаметно
переиспользовать их.

Поддерживаемый переход:

1. не удалять и не переименовывать старый PostgreSQL container/volume;
2. использовать уже проверенный logical backup либо создать новый;
3. checkout `v0.2.2`, выполнить `deploy-init` и `deploy-image`;
4. восстановить backup через `data-restore` в новый managed volume и БД
   `geo_registry`;
5. выполнить optional OpenSearch rebuild и `deploy-start`;
6. сохранять старый contour до окончания rollback window.

Если backup ещё нет, новый Make target умеет работать с явно выбранным старым
container и database, не меняя их:

```shell
mkdir -p "$PWD/docker/data/backups"
make backup-data \
  BACKUP_COMPONENT=application-postgres \
  BACKUP_ROOT="$PWD/docker/data/backups" \
  BACKUP_CONTAINER=<old-postgres-container> \
  BACKUP_DATABASE_NAME=<old-database> \
  BACKUP_DATABASE_USER=<old-owner>
```

Сначала выполните тот же вызов через `make backup-data-preflight`. После backup
обязательно запустите `make verify-data-backup` с его точным каталогом. Не
подключайте новый Compose к старому raw volume: logical restore сохраняет
PostgreSQL/PostGIS compatibility boundary и оставляет исходник rollback-копией.

## Host-specific Конфигурация

Tracked defaults находятся в `compose.yaml` и
`docker/compose/prod/compose.yaml`. Для порта, CPU/RAM, `/data` bind или feature
flags:

```shell
cp compose.override.yaml.example compose.override.yaml
```

Файл ignored. Не переопределяйте в нём application image или `APP_VERSION` и
не записывайте secrets. Они остаются frozen release identity и target-local
secret contract соответственно.

## Recovery И Диагностика

- `make deploy-image` — повторно подготовить exact image без запуска services;
- `make deploy-update-preflight` — проверить существующий managed target;
- `docker compose --env-file config/prod.secrets.env logs -f postgres` — PostgreSQL;
- `docker compose --env-file config/prod.secrets.env logs -f app` — HTTP;
- `make verify-data-backup ...` — повторная read-only проверка backup;
- не используйте `docker compose down -v` и не копируйте dump внутрь volume;
- cleanup старого container/volume выполняется отдельно только после rollback
  window.

Низкоуровневый backup/restore contract находится в
[data-backup-restore.md](data-backup-restore.md), source/import lifecycle — в
[data-lifecycle.md](../guides/data-lifecycle.md), offline packaging — в
[offline-release.md](offline-release.md).

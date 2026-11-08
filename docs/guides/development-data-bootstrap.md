# Development Из Готового PostgreSQL Backup

Результат этого руководства — development runtime, который использует
проверенную копию application PostgreSQL, а OpenSearch заново построен из
`address_search`. Повторный импорт GAR XML для этого пути не нужен.

Если готового backup нет, используйте полный путь
[получения и импорта данных](data-lifecycle.md). Production/offline deployment
с exact tag и image выполняется по
[release deploy](../deployment/release-deploy.md).

## 1. Определить Тип Artifact

Поддерживаются только два входа:

1. распакованный full-data release directory с
   `source-manifest.txt` и `application-postgres-backup.tar`;
2. готовый backup directory с двумя файлами:

```text
application-postgres-<version-or-UTC>/
├── application-postgres.dump
└── manifest.txt
```

Не копируйте `application-postgres.dump` в PostgreSQL volume и не вызывайте
`pg_restore` вручную поверх development database. Один `.dump` без
`manifest.txt`, raw Docker volume, архив всего volume или OpenSearch snapshot
не являются поддержанным input: для них нет provenance, checksum и безопасного
restore gate.

Если передан внешний `release-geo-registry-*-offline.tar`, сначала проверьте
его `.sha256` и распакуйте по разделу «Проверить Transport Archive» из
[release deploy](../deployment/release-deploy.md). Один внутренний
`application-postgres-backup.tar` без внешнего `source-manifest.txt` также не
считается достаточным input.

Все artifacts хранятся вне Git workspace, например:

```shell
export DEV_DATA_ROOT=/srv/geo-registry-data
export BACKUP_ROOT=/srv/geo-registry-data/backups
mkdir -p "${BACKUP_ROOT}"
```

## 2. Извлечь Backup Из Full-data Release

Если уже передан готовый backup directory, пропустите extraction и сразу
задайте его абсолютный путь в `BACKUP_DIR`.

Для распакованного full-data release:

```shell
export RELEASE_VERSION=v1.0.0
export RELEASE_EXTRACT_DIR=/srv/geo-registry-data/releases/v1.0.0

make extract-release-data \
  RELEASE_EXTRACT_DIR="${RELEASE_EXTRACT_DIR}" \
  BACKUP_ROOT="${BACKUP_ROOT}" \
  RELEASE_DATA_DRY_RUN=1

make extract-release-data \
  RELEASE_EXTRACT_DIR="${RELEASE_EXTRACT_DIR}" \
  BACKUP_ROOT="${BACKUP_ROOT}"

export BACKUP_DIR="${BACKUP_ROOT}/application-postgres-${RELEASE_VERSION}"
```

Dry-run проверяет внешний manifest, SHA-256 data archive, точный список файлов,
внутренний backup manifest и dump checksum. Второй вызов атомарно публикует
backup directory и отказывается перезаписывать существующий путь.

Для уже готового backup directory достаточно:

```shell
export BACKUP_DIR=/srv/geo-registry-data/backups/application-postgres-20261108T120000Z
```

## 3. Запустить Development PostgreSQL И OpenSearch

```shell
make config
make doctor
make build
make install
docker/compose/dev.sh up --detach --no-build postgres opensearch
export DEV_POSTGRES_CONTAINER="$(docker/compose/dev.sh ps --quiet postgres)"
test -n "${DEV_POSTGRES_CONTAINER}"
```

Development PostgreSQL использует PostgreSQL/PostGIS 17. Default role `app`
создаётся как cluster admin и может создать отдельную shadow-БД. OpenSearch на
этом шаге пустой либо содержит старую disposable projection.

## 4. Проверить Backup На Development Host

```shell
make verify-data-backup \
  BACKUP_DIR="${BACKUP_DIR}" \
  VERIFY_CONTAINER="${DEV_POSTGRES_CONTAINER}"
```

Успешная команда доказывает размер и SHA-256 dump, читаемость custom archive и
совместимость major version `pg_restore`. Она ещё не изменяет database state.

Проверить совместимость application revision:

```shell
export BACKUP_REVISION="$(awk -F= '$1 == "application_revision" {print $2}' \
  "${BACKUP_DIR}/manifest.txt")"
printf '%s\n' "${BACKUP_REVISION}" | grep -Eq '^[0-9a-f]{40}$'
git cat-file -e "${BACKUP_REVISION}^{commit}"
test "$(git rev-parse HEAD)" = "${BACKUP_REVISION}"
```

Exact revision — безопасный default для development из готового backup. Если
последний `test` не прошёл, checkout должен быть переключён на соответствующий
tag/commit либо заранее подготовлен отдельный migration/rollback plan. Не
продолжайте с произвольным current `main`.

## 5. Восстановить В Новую Shadow-БД

Выберите новое имя. Оно должно быть простым PostgreSQL identifier и не должно
существовать:

```shell
export DEV_RESTORE_DATABASE=app_restore_v1

make restore-data-preflight \
  BACKUP_DIR="${BACKUP_DIR}" \
  RESTORE_CONTAINER="${DEV_POSTGRES_CONTAINER}" \
  RESTORE_DATABASE="${DEV_RESTORE_DATABASE}" \
  RESTORE_DATABASE_OWNER=app \
  RESTORE_ADMIN_USER=app
```

После read-only preflight выполните подтверждённый restore:

```shell
make restore-data-shadow \
  BACKUP_DIR="${BACKUP_DIR}" \
  RESTORE_CONTAINER="${DEV_POSTGRES_CONTAINER}" \
  RESTORE_DATABASE="${DEV_RESTORE_DATABASE}" \
  RESTORE_DATABASE_OWNER=app \
  RESTORE_ADMIN_USER=app \
  RESTORE_CONFIRM="create:${DEV_POSTGRES_CONTAINER}:${DEV_RESTORE_DATABASE}"
```

Restore не удаляет и не изменяет исходную database `app`. При ошибке новая
shadow-БД сохраняется для диагностики; скрипт не удаляет её автоматически.
Для повторной попытки используйте другое новое имя либо удалите неудачную БД
отдельной осознанной операцией после диагностики.

## 6. Переключить Development Runtime На Restore

В ignored `config/.env` оставьте development credentials и измените только имя
database в `DATABASE_DSN`:

```dotenv
POSTGRES_DB=app
POSTGRES_USER=app
POSTGRES_PASSWORD=local-app-only
DATABASE_DSN=host=postgres port=5432 user=app password=local-app-only db=app_restore_v1 application_name=geo-registry
NOMINATIM_ENABLED=0
```

Если development credentials были изменены, `DATABASE_DSN` должен содержать те
же `POSTGRES_USER` и `POSTGRES_PASSWORD`. Nominatim остаётся выключенным, пока
не подготовлен его отдельный PBF/database lifecycle. После сохранения config
сначала проверьте восстановленные данные одноразовым container:

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app migrate:history
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:integrity --regions=50,77 --deep --only-failed
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77 --exact
```

Backup из full-data release связан с exact application revision, поэтому
pending migrations не ожидаются. Для backup от более старой revision сначала
нужен отдельный migration/rollback plan; не запускайте `migrate:up` вслепую.

## 7. Построить OpenSearch Projection

До rebuild в `config/.env` должно быть:

```dotenv
OPENSEARCH_ENABLED=1
OPENSEARCH_URL=http://opensearch:9200
OPENSEARCH_INDEX=places
OPENSEARCH_SEARCH_ENABLED=0
OPENSEARCH_COORDINATE_UPDATES_ENABLED=0
```

Search остаётся выключенным, пока новый physical index не построен и alias не
прошёл parity:

```shell
docker/compose/dev.sh up --detach --no-build opensearch
docker/compose/dev.sh run --rm --no-deps app \
  bin/app opensearch:reindex --batch-size=10000 --concurrency=8
docker/compose/dev.sh run --rm --no-deps app bin/app opensearch:status
```

Успешный gate означает healthy cluster, один read alias, равные source/target
counts и `invalid_regions=0`. Reindex создаёт versioned physical index и
переключает alias только после parity; PostgreSQL остаётся source of truth.
OpenSearch volume распаковывать или переносить не требуется.

После green status включите поиск:

```dotenv
OPENSEARCH_SEARCH_ENABLED=1
```

Пересоздайте runtime, чтобы процессы получили новый config:

```shell
docker/compose/dev.sh up --detach --no-build --force-recreate app
docker/compose/dev.sh --profile worker up --detach --no-build \
  --force-recreate worker-address worker-runtime
```

Если workers не нужны для текущей разработки, вторую команду можно пропустить.

## 8. Проверить Готовый Development Runtime

```shell
make ps
make smoke
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:integrity --regions=50,77 --deep --only-failed
docker/compose/dev.sh run --rm --no-deps app bin/app opensearch:status
make load-search
```

`make load-search` является нагрузочной проверкой, а не обязательным условием
обычной разработки; его можно пропустить на малом host. Минимальный success —
green GAR integrity, green OpenSearch status и успешный `/health/ready`.

## Повторный Запуск И Recovery

- Повторное `extract-release-data` не перезаписывает опубликованный backup.
- Restore всегда требует отсутствующую database и точное `RESTORE_CONFIRM`.
- Ошибка OpenSearch rebuild не меняет active alias; после устранения причины
  команду можно повторить.
- Не используйте `make down -v`, `docker compose down --volumes` или ручное
  копирование файлов в PostgreSQL/OpenSearch volumes как shortcut recovery.
- Старую development database и предыдущий OpenSearch index удаляйте только
  после проверок и отдельного осознанного cleanup.

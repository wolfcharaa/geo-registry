# Развёртывание Релиза С Готовыми Данными

Это полное руководство для нового сервера: получить exact application tag,
загрузить immutable image, проверить и восстановить application PostgreSQL,
при необходимости перестроить OpenSearch, запустить HTTP и workers и закрыть
smoke gate. Этот файл копируется в offline transport archive как `DEPLOY.md` и
поэтому не зависит от других документов.

## Что Переносится

Один release имеет четыре независимые части:

1. annotated Git tag — identity исходников и tracked release-конфигурации;
2. exact Docker image — runtime приложения, HTTP и workers;
3. application PostgreSQL backup — canonical GAR и поисковая модель;
4. runtime configuration — endpoints и secrets конкретного сервера.

Full-data offline archive переносит первые три части. Runtime configuration и
secrets никогда не входят в Git tag или transport archive. Nominatim имеет
отдельный backup lifecycle, а OpenSearch перестраивается из восстановленного
application PostgreSQL.

## Требования К Новому Серверу

- Linux, Docker Engine с Compose plugin, Git, Make и `sha256sum`;
- non-root deployment user с доступом к Docker;
- versioned release, application и backup directories, доступные этому user;
- уже подготовленный target PostgreSQL/PostGIS container;
- уже созданные PostgreSQL owner role и admin role с правом `CREATEDB`;
- внешний OpenSearch и Nominatim либо их явное отключение в `config/prod.env`.

Production Compose управляет только `app`, `worker-address` и
`worker-runtime`. Он намеренно не создаёт PostgreSQL, OpenSearch и Nominatim и
никогда не удаляет их volumes. Для restore PostgreSQL должен быть доступен как
локальный Docker container, потому что проверка и `pg_restore` выполняются
через `docker exec`. Target PostgreSQL major обязан быть не старее source major,
а image должен содержать нужные dump-у extensions, включая PostGIS.

Дальше используются такие значения; замените их один раз до выполнения команд:

```shell
export RELEASE_VERSION=v1.0.0
export RELEASE_PLATFORM=linux/amd64
export RELEASE_PLATFORM_SLUG=linux-amd64
export RELEASE_MEDIA=/srv/releases/geo-registry/v1.0.0
export APPLICATION_ROOT=/srv/apps/geo-registry
export BACKUP_ROOT=/srv/backups/geo-registry
export BACKUP_DIR=/srv/backups/geo-registry/application-postgres-v1.0.0
export RESTORE_CONTAINER=geo-registry-postgres-target
export RESTORE_DATABASE=geo_registry_v1_0_0
export RESTORE_OWNER=geo_registry
export RESTORE_ADMIN_USER=postgres
```

`BACKUP_ROOT` и родительские каталоги должны существовать заранее и не должны
принадлежать root, если release-команды выполняет deployment user.

## 1. Проверить Transport Archive

Для offline/full-data delivery перенесите рядом `.tar` и `.tar.sha256`, затем:

```shell
cd "$(dirname "${RELEASE_MEDIA}")"
sha256sum --check "release-geo-registry-${RELEASE_VERSION}-offline.tar.sha256"
mkdir -p "${RELEASE_MEDIA}"
tar -xf "release-geo-registry-${RELEASE_VERSION}-offline.tar" \
  -C "${RELEASE_MEDIA}"
```

Archive должен содержать только Git bundle, Docker image archive,
`source-manifest.txt`, `RELEASE.md`, `DEPLOY.md` и, для full-data release,
`application-postgres-backup.tar`.

## 2. Получить Exact Git Tag

### Из Git Remote

```shell
git clone --no-checkout https://github.com/wolfcharaa/geo-registry.git \
  "${APPLICATION_ROOT}"
cd "${APPLICATION_ROOT}"
git fetch --tags origin
git switch --detach "${RELEASE_VERSION}"
```

### Из Offline Bundle

```shell
mkdir -p "${APPLICATION_ROOT}"
cd "${APPLICATION_ROOT}"
git init
git fetch "${RELEASE_MEDIA}/release-geo-registry-${RELEASE_VERSION}.bundle" \
  refs/heads/main:refs/remotes/release/main \
  "refs/tags/${RELEASE_VERSION}:refs/tags/${RELEASE_VERSION}"
git switch --detach "${RELEASE_VERSION}"
```

Для обоих вариантов обязательна одна проверка identity:

```shell
test "$(git cat-file -t "refs/tags/${RELEASE_VERSION}")" = tag
test "$(git rev-parse HEAD)" = \
  "$(git rev-parse "refs/tags/${RELEASE_VERSION}^{commit}")"
git status --short
```

Последняя команда не должна печатать изменений. Detached HEAD здесь ожидаем:
развёртывание привязано к immutable tag, а не к движущейся branch.

## 3. Настроить Runtime

```shell
cp config/prod.env.example config/prod.env
```

В `config/prod.env` замените все placeholder endpoints и credentials. Полный
registry namespace должен совпадать с release-host:

```dotenv
DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry
DATABASE_DSN=host=database.example port=5432 user=geo_registry password=replace-me db=geo_registry_v1_0_0 application_name=geo-registry
```

Если OpenSearch или Nominatim ещё не подготовлены, первый запуск делается без
них:

```dotenv
NOMINATIM_ENABLED=0
OPENSEARCH_ENABLED=0
OPENSEARCH_SEARCH_ENABLED=0
OPENSEARCH_COORDINATE_UPDATES_ENABLED=0
```

`APP_IMAGE_TAG`, `APP_IMAGE_DIGEST` и `RELEASE_VERSION` нельзя добавлять в
`config/prod.env`: они уже зафиксированы tag-ом в
`docker/compose/prod/release.env`.

## 4. Загрузить И Проверить Image

Для connected deployment image заранее публикуется release-host-ом. После
`docker login` target загружает его только явной командой:

```shell
make release-pull DOCKER_PLATFORM="${RELEASE_PLATFORM}"
make verify-release DOCKER_PLATFORM="${RELEASE_PLATFORM}"
```

Для offline deployment скопируйте payload в ignored release directory:

```shell
mkdir -p "docker/data/release/${RELEASE_VERSION}"
cp "${RELEASE_MEDIA}/source-${RELEASE_PLATFORM_SLUG}.tar" \
  "docker/data/release/${RELEASE_VERSION}/"
cp "${RELEASE_MEDIA}/source-manifest.txt" \
  "docker/data/release/${RELEASE_VERSION}/"
make verify-release DOCKER_PLATFORM="${RELEASE_PLATFORM}"
```

`verify-release` проверяет tag/digest/platform/runtime и при отсутствии exact
image проверяет checksum payload до `docker load`. Containers не меняются.

## 5. Извлечь И Проверить PostgreSQL Backup

Для full-data offline archive этот шаг нужен, только если
`source-manifest.txt` содержит `data_bundle=application-postgres`:

```shell
make extract-release-data \
  RELEASE_EXTRACT_DIR="${RELEASE_MEDIA}" \
  BACKUP_ROOT="${BACKUP_ROOT}" \
  RELEASE_DATA_DRY_RUN=1

make extract-release-data \
  RELEASE_EXTRACT_DIR="${RELEASE_MEDIA}" \
  BACKUP_ROOT="${BACKUP_ROOT}"
```

После extraction `BACKUP_DIR` должен указывать на
`${BACKUP_ROOT}/application-postgres-${RELEASE_VERSION}`. Для connected
delivery без full-data archive перенесите защищённым каналом весь готовый
backup directory — `application-postgres.dump` и `manifest.txt` — и укажите
его абсолютный путь в `BACKUP_DIR`.

Повторно проверьте backup на target host с target PostgreSQL tools:

```shell
make verify-data-backup \
  BACKUP_DIR="${BACKUP_DIR}" \
  VERIFY_CONTAINER="${RESTORE_CONTAINER}"
```

## 6. Восстановить Только В Новую БД

Restore никогда не выполняется поверх существующей БД. Target container должен
работать, `RESTORE_OWNER` должен существовать, `RESTORE_ADMIN_USER` должен
иметь право создать БД с этим owner, а `RESTORE_DATABASE` — отсутствовать.

```shell
make restore-data-preflight \
  BACKUP_DIR="${BACKUP_DIR}" \
  RESTORE_CONTAINER="${RESTORE_CONTAINER}" \
  RESTORE_DATABASE="${RESTORE_DATABASE}" \
  RESTORE_DATABASE_OWNER="${RESTORE_OWNER}" \
  RESTORE_ADMIN_USER="${RESTORE_ADMIN_USER}"
```

После проверки точного container и имени новой БД:

```shell
make restore-data-shadow \
  BACKUP_DIR="${BACKUP_DIR}" \
  RESTORE_CONTAINER="${RESTORE_CONTAINER}" \
  RESTORE_DATABASE="${RESTORE_DATABASE}" \
  RESTORE_DATABASE_OWNER="${RESTORE_OWNER}" \
  RESTORE_ADMIN_USER="${RESTORE_ADMIN_USER}" \
  RESTORE_CONFIRM="create:${RESTORE_CONTAINER}:${RESTORE_DATABASE}"
```

При ошибке созданная shadow-БД сохраняется для диагностики и не удаляется
скриптом. Исправьте `DATABASE_DSN` в `config/prod.env`, чтобы он указывал именно
на восстановленную БД, но пока не запускайте постоянные application processes.

## 7. Проверить Восстановленные Данные

Все команды выполняются одноразовым container того же immutable image:

```shell
docker/compose/prod.sh run --rm --no-deps app bin/app migrate:history
docker/compose/prod.sh run --rm --no-deps app \
  bin/app gar:integrity --regions=50,77 --deep --only-failed
docker/compose/prod.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77 --exact
docker/compose/prod.sh run --rm --no-deps app \
  bin/app worker:status --group=geo-registry --children
```

Backup, включённый в full-data release, связан с exact release commit, поэтому
pending migrations не ожидаются. Если используется более старый отдельно
созданный backup, сначала утвердите migration/rollback plan и только затем
выполните:

```shell
docker/compose/prod.sh run --rm --no-deps app \
  bin/app migrate:up --force-yes
```

Повторите `migrate:history` и GAR gates после migrations.

## 8. Перестроить OpenSearch

OpenSearch является projection, а не source of truth. Если он используется,
укажите новый пустой target endpoint в `config/prod.env`, включите integration,
но пока оставьте search выключенным:

```dotenv
OPENSEARCH_ENABLED=1
OPENSEARCH_SEARCH_ENABLED=0
OPENSEARCH_COORDINATE_UPDATES_ENABLED=0
```

Затем:

```shell
docker/compose/prod.sh run --rm --no-deps app \
  bin/app opensearch:reindex --batch-size=10000 --concurrency=8
docker/compose/prod.sh run --rm --no-deps app bin/app opensearch:status
```

Только после green cluster, одного ожидаемого alias, source/target parity и
`invalid_regions=0` установите `OPENSEARCH_SEARCH_ENABLED=1`. Если OpenSearch
не нужен, оставьте все его флаги выключенными и пропустите этот шаг.

Nominatim поднимается и проверяется отдельно. До готовности `/status` и
representative reverse queries оставьте `NOMINATIM_ENABLED=0`.

## 9. Запустить Релиз

```shell
make prod-config
make change-release DOCKER_PLATFORM="${RELEASE_PLATFORM}"
make prod-ps
```

`change-release` повторно проверяет exact image и MessageBus registry, затем
пересоздаёт только application HTTP и два worker processes с `--no-build` и
`--pull never`. PostgreSQL, OpenSearch, Nominatim и их volumes не изменяются.

## 10. Закрыть Smoke Gate

```shell
APP_BASE_URL=http://127.0.0.1:8080 make smoke
curl --fail http://127.0.0.1:8080/health/live
curl --fail http://127.0.0.1:8080/health/startup
curl --fail http://127.0.0.1:8080/health/ready
docker/compose/prod.sh run --rm --no-deps app \
  bin/app worker:status --group=geo-registry --children
```

Для representative HTTP contract подготовьте ignored environment и сохраните
JUnit/JSON evidence вне repository:

```shell
cp docs/api/postman/geo-registry.local.postman_environment.json \
  config/release.postman_environment.json
# Заполнить representative IDs, адрес и координаты без credentials.
make smoke-release-prod \
  POSTMAN_ENVIRONMENT=config/release.postman_environment.json \
  POSTMAN_BASE_URL=http://host.docker.internal:8080 \
  RELEASE_ARTIFACT_DIR=/srv/releases/geo-registry/evidence \
  RELEASE_RUN_NAME="${RELEASE_VERSION}"
```

Release считается активным только после успешных health, worker, GAR,
OpenSearch — если он включён — и representative HTTP gates.

## Rollback

Code rollback использует предыдущий annotated tag и его exact image:

1. проверить backward compatibility schema/data;
2. при необходимости восстановить согласованный backup в новую shadow-БД;
3. checkout предыдущего tag;
4. загрузить image через `make release-pull` либо предыдущий offline payload;
5. выполнить `make verify-release`, затем `make change-release`;
6. повторить все smoke gates.

Ни одна release-команда не удаляет действующую БД или data volume. Исходный
PostgreSQL target, предыдущий OpenSearch index и старые runtime artifacts
сохраняются до завершения утверждённого rollback window.

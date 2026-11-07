# Production Compose

Production-контур запускает только immutable application processes: HTTP и два
PostgreSQL workers. Application PostgreSQL, OpenSearch и Nominatim живут
отдельно и не входят в release switch приложения. Поэтому обычное обновление
кода не пересоздаёт их containers, volumes или данные.

## Image Contract

Production image собирается из согласованной трёхрепозиторной workspace:

```text
workspace/
├── geo-registry/
└── library/
    ├── MessageBusAsync/
    └── MessageBusAsyncWorker/
```

`docker/php/production.Dockerfile` встраивает минимальные runtime sources обеих
path-библиотек в `/library`, поэтому Composer symlinks разрешаются внутри самого
image. Итоговый image не зависит от исходной workspace, host bind mounts или
Composer network во время запуска. PHP и Composer build images закреплены
multi-architecture digest-ами.

Префикс image задаётся только целиком через `DOCKER_IMAGE_PREFIX`. Он уже должен
содержать registry host и полный namespace проекта; Compose не добавляет к нему
владельца или организацию:

```dotenv
DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry
```

Префикс хранится в ignored `config/prod.env`. Tracked
`docker/compose/prod/release.env` содержит только frozen identity релиза:

```dotenv
APP_IMAGE_TAG=v0.1.0-linux-amd64
APP_IMAGE_DIGEST=sha256:<64-hex-characters>
RELEASE_VERSION=v0.1.0
COMPOSE_PROJECT_NAME=geo-registry
```

Один tracked release-файл указывает на один точный tag и его OCI manifest
digest. Compose запускает image только по digest и использует
`pull_policy: never`: отсутствующий локальный image является ошибкой, а не
причиной неявно скачать другой build.
Checksum manifest и offline archive создаются отдельным release workflow,
описанным в [offline-release.md](offline-release.md).

Сборка требует clean revisions всех трёх repositories:

```shell
make build-production \
  DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry \
  RELEASE_VERSION=v0.1.0 \
  DOCKER_PLATFORM=linux/amd64
```

Поддерживаются `linux/amd64` и `linux/arm64`. Tag получает platform suffix,
например `v0.1.0-linux-amd64`.

## Runtime Configuration

Runtime endpoints, credentials, feature flags и resource overrides находятся в
отдельном ignored `config/prod.env`:

```shell
cp config/prod.env.example config/prod.env
```

Placeholder credentials и endpoints нужно заменить до запуска. Runtime env
намеренно не может содержать `APP_IMAGE_TAG`, `APP_IMAGE_DIGEST` или
`RELEASE_VERSION`: secrets/configuration не выбирают revision приложения.
`DOCKER_IMAGE_PREFIX` является только полным deployment namespace и сам по себе
не выбирает release.

Если Nominatim или OpenSearch отсутствуют на конкретном deployment, нужно явно
задать `NOMINATIM_ENABLED=0` или `OPENSEARCH_ENABLED=0`. Тогда HTTP graph и
readiness не выполняют сетевые запросы к отключённому service.

## Проверка И Запуск

```shell
make prod-config
make prod-up
make prod-ps
make prod-down
```

Wrapper требует non-root deployment user, автоматически добавляет `--no-build`
и `--pull never`, отклоняет `--build`, а также запрещает `down -v` и `rm -v`.
Прямой Compose остаётся доступен:

```shell
APP_ENV_FILE="$(pwd)/config/prod.env" docker compose \
  --env-file config/prod.env \
  --env-file docker/compose/prod/release.env \
  -f docker/compose/prod/compose.yaml \
  config
```

HTTP публикуется на loopback по умолчанию и считается healthy только после
успешного `/health/ready`. Для HTTP и workers заданы отдельные CPU, memory,
process и shutdown budgets. Filesystem image read-only; writable временные
каталоги ограничены tmpfs. Все capabilities удалены, включён
`no-new-privileges`, JSON logs имеют rotation limits.

Application commands выполняются из той же revision:

```shell
docker/compose/prod.sh run --rm --no-deps app bin/app migrate:history
docker/compose/prod.sh run --rm --no-deps app bin/app gar:integrity --regions=50,77
```

Миграции, import/rebuild и worker cutover выполняются только по соответствующим
runbooks. Production Compose не является backup PostgreSQL, Nominatim или
OpenSearch и не удаляет их данные.

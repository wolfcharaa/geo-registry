# Production Compose

Production runtime запускается через защитную Compose-обёртку:

```shell
make prod-up
```

Корневой `compose.yaml` подключает production model. Оператор передаёт ignored
secret-файл, а обёртка связывает tracked source identity с exact artifact
identity из `docker/data/release/<tag>/release-artifact.env`.

## Состав

По умолчанию Compose управляет:

- PostgreSQL 17/PostGIS с persistent `geo-registry-postgres-data`;
- immutable HTTP application;
- двумя PostgreSQL workers.

Профиль `opensearch` добавляет OpenSearch с отдельным persistent volume, а
профиль `maintenance` предоставляет одноразовый CLI container с read/write
bind на `docker/data`. Maintenance не является long-running service.

```shell
docker compose --env-file config/prod.secrets.env \
  --profile opensearch up -d opensearch
docker compose --env-file config/prod.secrets.env \
  run --rm maintenance bin/app list
```

## Где Находятся Настройки

`docker/compose/prod/compose.yaml` хранит non-secret defaults: endpoints,
feature flags, resource budgets, PostgreSQL tuning, ports, healthchecks и
volumes. Для новых releases `docker/compose/prod/release.env` содержит tracked
source identity: image prefix/tag, version и platform без post-build digest.
Exact digest, image ID, peeled source OID и config revision находятся в ignored
`release-artifact.env`. Legacy `v0.2.x` сохраняет digest в tracked
`release.env` и проверяется своими versioned scripts.

Секреты создаются один раз:

```shell
make deploy-init
```

Файл `config/prod.secrets.env` содержит только:

- application PostgreSQL password;
- PostgreSQL admin password;
- optional Data.Mos/OpenSearch credentials.

Host-specific несекретные изменения выполняются YAML override:

```shell
cp compose.override.yaml.example compose.override.yaml
```

Файл ignored и автоматически подхватывается Docker Compose. Например, в нём
можно изменить bind address, число HTTP workers, `/data` path или OpenSearch
heap. Secrets в override не записываются; `image` и `APP_VERSION` также нельзя
переопределять, потому что это frozen release identity.

## Image Contract

Application reference и `APP_VERSION` зафиксированы непосредственно в tracked
Compose YAML и сверяются с source/artifact identity; secret-файл и shell
environment не могут выбрать другой tag. Artifact identity дополнительно
связывает tag с exact digest. Image имеет `pull_policy: never`.

Оператор использует одну команду:

```shell
make deploy-image
```

При полном offline payload она проверяет archive checksum до `docker load`, а
при его отсутствии выполняет explicit registry pull. Обычный
`docker compose up` не заменяет application image непроверенным pull.

PostgreSQL и OpenSearch images закреплены digest-ами. Persistent volumes не
удаляются командами release switch. `docker/compose/prod.sh` запрещает
`--build`, удаление volumes и production mutation без exact artifact identity.
Read-only `config` разрешён до build, чтобы проверить prepared source model.

## Data Lifecycle

Long-running runtime не восстанавливает и не импортирует данные автоматически.
На новом сервере оператор выбирает один явный путь:

```shell
make data-restore
```

или:

```shell
make data-init
make data-gar-releases
make data-gar-download GAR_URL='<full_xml_url>'
make data-gar-import
make data-address-rebuild
make data-opensearch
```

`data-restore` и `data-init` сами запускают managed PostgreSQL. Для уже
инициализированного deployment используется отдельный `make deploy-update`,
который отказывается работать без существующей `geo_registry`.

Так повторный `docker compose up -d` остаётся идемпотентным и никогда не
перезаписывает БД или индекс.

Перед первой активацией `make deploy-port-preflight` читает фактический HTTP
binding из объединённого Compose YAML, включая ignored override. Production
smoke использует тот же binding, поэтому изменение host port не требует
session env.

Выбор между loopback, общей Docker network, точным private/LAN IP для другого
физического сервера и reverse proxy описан в
[сетевом контракте](network-access.md). HTTP API не имеет встроенной
аутентификации и не публикуется напрямую в недоверенную сеть.

Отдельные линейные runbooks:

- [сервер с готовым PostgreSQL backup](deploy-with-backup.md);
- [сервер с наполнением из официальных источников](deploy-from-sources.md);
- [сетевой доступ для других приложений](network-access.md);
- [обновление и общий release contract](release-deploy.md).

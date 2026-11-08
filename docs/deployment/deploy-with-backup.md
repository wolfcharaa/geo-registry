# Новый Сервер С Готовым PostgreSQL Backup

Этот runbook предназначен для production-развёртывания, когда canonical GAR и
`address_search` уже подготовлены в проверенном application PostgreSQL backup.
Повторно скачивать и импортировать GAR не требуется. OpenSearch не переносится:
при необходимости он заново строится из восстановленной PostgreSQL.

## Что Должно Быть На Сервере

Требуются Linux, Git, Make, Docker Engine, Docker Compose `2.24.4+`, `ss` из
пакета `iproute2` и deployment user с доступом к Docker. Команды выполняются
без `sudo`.

Backup переносится целым каталогом:

```text
application-postgres-<version-or-UTC>/
├── application-postgres.dump
└── manifest.txt
```

Один `.dump` без manifest, raw PostgreSQL volume и OpenSearch volume не являются
поддерживаемым входом.

## 1. Получить Exact Release

```shell
cd /var/www/geo-registry
git fetch --tags origin
git switch --detach <release-tag>
test "$(git rev-parse HEAD)" = "$(git rev-parse 'refs/tags/<release-tag>^{commit}')"
git status --short
```

Последняя команда не должна выводить изменений. Runbook, Compose и scripts
используются только из одного tag.

## 2. Положить Backup В Ожидаемый Каталог

Скопируйте весь каталог в `docker/data/backups/`. Например, на source host:

```shell
rsync --archive --info=progress2 \
  /srv/backups/application-postgres-<identity>/ \
  <deployment-user>@<target-host>:/var/www/geo-registry/docker/data/backups/application-postgres-<identity>/
```

На target host должны присутствовать ровно два файла:

```shell
cd /var/www/geo-registry
find docker/data/backups/application-postgres-<identity> \
  -maxdepth 1 -type f -printf '%f\n' | sort
```

Ожидаемый вывод — `application-postgres.dump` и `manifest.txt`. Если в
`docker/data/backups/` находится несколько backups, позже передайте точный
каталог через `DEPLOY_RESTORE_FLAGS=--backup-dir=...`.

## 3. Подготовить Secrets, Image И Host Binding

```shell
make deploy-init
make deploy-image DOCKER_PLATFORM=linux/amd64
make deploy-port-preflight
```

Port preflight выполняется до restore и дорогих GAR gates. Default binding —
`127.0.0.1:8080`. Если порт занят и его владелец нельзя остановить, создайте
ignored `compose.override.yaml`:

```yaml
services:
  app:
    ports: !override
      - 127.0.0.1:18080:8080
```

Повторите `make deploy-port-preflight`. Production smoke автоматически читает
тот же YAML binding; session `APP_PORT` или ручной URL не нужен.

## 4. Восстановить PostgreSQL

Если найден ровно один backup:

```shell
make data-restore
```

При нескольких каталогах:

```shell
make data-restore \
  DEPLOY_RESTORE_FLAGS='--backup-dir=docker/data/backups/application-postgres-<identity>'
```

Команда сама запускает managed PostgreSQL, проверяет размер, SHA-256,
`pg_restore` TOC и major version, создаёт только отсутствующую `geo_registry` и
печатает progress heartbeat. PostgreSQL отдельно запускать не нужно.

Если terminal оборвался, restore не повторяют:

```shell
make data-restore-status
```

Для `restore-active` нужно ждать. Для
`present-needs-application-validation` переходите к следующему шагу.

## 5. При Необходимости Построить OpenSearch

PostgreSQL уже содержит `address_search`, поэтому GAR import и address rebuild
не нужны. Optional OpenSearch строится одной командой:

```shell
make data-opensearch
```

После green parity включите OpenSearch flags в `compose.override.yaml`, если
этот сервер должен использовать OpenSearch в runtime. PostgreSQL остаётся
source of truth.

## 6. Активировать И Проверить Сервис

```shell
make deploy-start DOCKER_PLATFORM=linux/amd64
```

Команда в начале ещё раз проверяет host port, затем physical state PostgreSQL,
frozen image, migration history и GAR регионов 50/77. Непосредственно перед
`compose up` port проверяется повторно для защиты от позднего конфликта. В конце
HTTP smoke использует binding из Compose YAML.

Успешный результат:

```shell
docker compose --env-file config/prod.secrets.env ps
make deploy-smoke
```

После первого запуска обычный идемпотентный restart возможен нативно:

```shell
docker compose --env-file config/prod.secrets.env up -d
```

Первую активацию эта команда не заменяет, потому что не выполняет data и
release gates.

## Если Что-то Пошло Не Так

- port conflict разбирается через `make deploy-port-preflight`;
- потерянная restore-сессия — через `make data-restore-status`;
- существующая БД не удаляется и restore поверх неё не повторяется;
- orphan containers не удаляются автоматически до закрытия rollback window;
- `docker compose down -v` запрещён для recovery.

Подробная карта симптомов находится в
[диагностике развёртывания](troubleshooting.md).

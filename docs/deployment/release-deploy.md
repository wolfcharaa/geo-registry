# Развёртывание И Обновление Релиза

Production-контракт использует обычный Docker Compose. Несекретные настройки
находятся в tracked YAML, host-specific изменения — в ignored
`compose.override.yaml`, а `config/prod.secrets.env` содержит только пароли и
API keys.

Требуются Linux, Git, Make, Docker Engine, Docker Compose `2.24.4+` и `ss` из
пакета `iproute2`. Команды выполняются non-root deployment user с доступом к
Docker.

## Проверить Цель До Любого Изменения

Назначьте стабильную operator label цели и отдельно укажите уже выбранный
Docker context. Команды не переключают context и не исправляют окружение:

```shell
make deploy-doctor \
  DEPLOY_TARGET=moscow-primary \
  DEPLOY_EXPECTED_DOCKER_CONTEXT=production

make deploy-plan \
  DEPLOY_TARGET=moscow-primary \
  DEPLOY_EXPECTED_DOCKER_CONTEXT=production \
  DEPLOY_DATA_MODE=inspect \
  DEPLOY_SERVICE_SCOPE=core
```

`deploy-doctor` показывает target, текущий context и endpoint без userinfo,
Compose project, host platform, версии Docker/Compose и наличие обязательных
локальных файлов. `deploy-plan` добавляет current/desired image identity,
checksum service hashes итоговой Compose-модели, scope, data mode, backup gate
и последний deployment evidence state. При наличии `partial.env` plan получает
blocker `partial-state-requires-handover` и показывает recovery branch. Обе
команды read-only: они не выполняют `pull`, `load`, `up`, migrations, restore
или запись deployment evidence.

Поддерживаемые data modes: `inspect`, `fresh`, `restore`, `update`. Для restore
и update передайте каталог с `manifest.txt` и dump:

```shell
make deploy-plan \
  DEPLOY_TARGET=moscow-primary \
  DEPLOY_EXPECTED_DOCKER_CONTEXT=production \
  DEPLOY_DATA_MODE=update \
  DEPLOY_SERVICE_SCOPE=application \
  DEPLOY_BACKUP_DIR=/srv/backups/application-postgres-20261109T120000Z \
  DEPLOY_SCHEMA_COMPATIBILITY=backward-compatible
```

Plan полностью проверяет bytes и SHA-256 backup. Для update его
`application_revision` обязан совпасть с source OID текущего application
container. `status=ready` означает только согласованный read-only план;
mutation по-прежнему запускается отдельной подтверждаемой командой. При
`status=blocked` ориентируйтесь на поле `blockers`; последняя безопасная точка
остаётся `before-artifact-acquire`.

Для update обязательна одна из четырёх классификаций схемы:

- `none` — migration не меняет схему, разрешён binary rollback;
- `backward-compatible` — старая версия сохраняет совместимость, но rollback
  всё равно требует проверки;
- `expand-contract` — rollback допустим только до contract-фазы;
- `forward-only` — после migration разрешены roll-forward либо отдельно
  подтверждённый restore.

Перед первой mutating-командой в deployment-сессии задайте target contract.
Третья переменная должна буквально повторять label цели:

```shell
export DEPLOY_TARGET=moscow-primary
export DEPLOY_EXPECTED_DOCKER_CONTEXT=production
export DEPLOY_CONFIRM_TARGET=moscow-primary
```

Production wrapper перед каждым `run`, `up`, `stop`, `pull`, `exec` и другим
изменяющим Compose-вызовом повторно сравнивает active context с ожидаемым и
печатает target, endpoint без userinfo, project, scope и data mode. Read-only
`config`, `ps`, `logs` и status-команды не требуют подтверждения. Пустой target,
другая confirmation label или context mismatch останавливают операцию до
Docker mutation. Эти переменные не являются secrets; не записывайте их в
tracked release files и очищайте terminal session перед работой с другой
целью.

После target gate mutating entrypoint захватывает kernel `flock` на
`docker/data/deployment/<target>/deployment.lock`. Файл ignored, имеет mode,
ограниченный deployment user, и содержит только operation, target, context,
project, scope, data mode, PID, actor и UTC start — без secrets. Дочерние
вызовы production wrapper наследуют тот же descriptor, поэтому один update,
restore или init не конфликтует сам с собой. Параллельный operator получает
`Active deployment target lock` и metadata текущего владельца до мутации;
lock-файл вручную не удаляйте. Kernel lock освобождается при завершении
процесса, а оставшийся файл сохраняет последнюю диагностику.

`deploy-start` и `deploy-update` удерживают один descriptor через preflight,
data gates, activation, финальный smoke и запись результата; их Make targets
являются тонким facade над testable scripts. Каждая операция сохраняется в
ignored `docker/data/deployment/<target>/operations/`. Последний успешный
результат атомарно публикуется как `current.env`, предшествующий — как
`previous.env`. Файлы содержат identity, actor, UTC timing и статусы gates, но
не secret values.

Ошибка до изменения application/data фиксируется как `safe-abort` и не
блокирует повтор. Ошибка после начала изменения создаёт `partial.env`; пока он
существует, новые mutating operations fail-closed. Не удаляйте этот файл
вручную: recovery handover описан ниже.

## Выберите Сценарий

| Сценарий | Путь |
| --- | --- |
| Новый сервер с готовым PostgreSQL backup | [Отдельный линейный runbook](deploy-with-backup.md): `deploy-init` → `deploy-image` → `data-restore` → `deploy-start` |
| Новый сервер с официальными источниками | [Отдельный линейный runbook](deploy-from-sources.md): `deploy-init` → `deploy-image` → `data-init` → import/rebuild → `deploy-start` |
| Обновление уже managed Compose deployment | `deploy-image` → `deploy-update-preflight` → `deploy-update` |
| Production-like recovery rehearsal | `recovery-rehearsal-plan` → отдельное разрешение restore/migrations/projection/smoke |
| Переход с `v0.2.1` или вручную восстановленной БД | сохранить старый контур → создать/проверить backup → восстановить его в новый managed volume → `deploy-start` |

Не смешивайте эти пути. `data-restore` создаёт отсутствующую БД и отказывается
перезаписывать существующую. `deploy-update` требует уже существующую managed
БД `geo_registry` и никогда не используется для первого запуска.

Перед длительным restore/import можно отдельно выполнить
`make deploy-port-preflight`: команда проверяет фактический
`services.app.ports` из итогового Compose YAML. `deploy-start` и
`deploy-update-preflight` также выполняют эту проверку сами, поэтому при
активации и обновлении отдельный вызов не нужен.

## Получить Exact Tag

Подставьте требуемый annotated release tag:

```shell
cd /var/www/geo-registry
git fetch --tags origin
git switch --detach <release-tag>
test "$(git cat-file -t refs/tags/<release-tag>)" = tag
test "$(git rev-parse HEAD)" = "$(git rev-parse 'refs/tags/<release-tag>^{commit}')"
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

Offline manifest имеет scope `app-update` либо `new-host-core` (legacy v3 без
поля трактуется как `app-update`). Первый переносит только application image.
Второй переносит также exact PostgreSQL image из rendered production Compose;
`deploy-image` проверяет count/reference/digest/ID/platform обоих images, а
`data-init`/`data-restore` повторно проверяют PostgreSQL и пропускают registry
pull. OpenSearch и Nominatim в core scope не входят.

Для release identity format 2 в том же каталоге обязателен
`release-artifact.env`, полученный вместе с offline transport или из
авторизованного connected publication workflow. Он связывает checked-out tag с
source/config OID и exact image digest/ID. Без него разрешён только read-only
`make prod-config`; pull, load и production mutation завершаются fail-closed.

Для private registry сначала выполните соответствующий `docker login`.

## Новый Сервер: Восстановить Готовые Данные

Полный самостоятельный сценарий с передачей каталога, host paths, port
preflight, restore recovery и OpenSearch находится в
[runbook развёртывания с готовым backup](deploy-with-backup.md). Ниже оставлен
краткий контракт команд.

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
обязательно снимает повышение после restore. Во время `pg_restore` каждые 30
секунд печатаются elapsed time, текущий размер БД, число relations и видимая
PostgreSQL activity.

При нескольких backups выберите один без session exports:

```shell
make data-restore \
  DEPLOY_RESTORE_FLAGS='--backup-dir=/srv/backups/application-postgres-v0.2.0'
```

Не запускайте PostgreSQL отдельной командой перед `data-restore`: lifecycle уже
принадлежит restore orchestration.

Если SSH-сессия пропала или команда больше не видна, restore не повторяют.
Состояние и безопасный следующий шаг показывает read-only команда:

```shell
make data-restore-status
```

## Новый Сервер: Наполнить БД Из Источников

Полный самостоятельный сценарий с расположением GAR/Data.Mos, внешним `/data`
bind, checkpoints, backup результата и optional OpenSearch находится в
[runbook наполнения из официальных источников](deploy-from-sources.md). Ниже
оставлен краткий контракт команд.

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

Во время долгого import или rebuild durable checkpoints и активную работу можно
посмотреть из второй сессии:

```shell
make data-status
```

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
make deploy-start DOCKER_PLATFORM=linux/amd64
```

`deploy-start` проверяет port до дорогих gates и повторно перед activation,
затем проверяет frozen image, migration history, GAR integrity и exact status
регионов `50,77`, активирует только application processes через
`change-release` и завершает путь HTTP smoke. Команда не восстанавливает и не
перезаписывает данные.

Проверка состояния:

```shell
make prod-ps
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

Ignored `compose.override.yaml` сохраняется при смене tag. До update проверьте,
что в нём остался нужный host binding. Для отдельного физического consumer это
точный private/LAN IP сервера Geo Registry, а не `127.0.0.1` и не универсальный
`0.0.0.0`; варианты приведены в [сетевом runbook](network-access.md).

Read-only preflight проверяет image, managed PostgreSQL и итоговый HTTP binding,
но не останавливает процессы и не меняет БД:

```shell
BACKUP_DIR=/srv/backups/application-postgres-20261109T120000Z
make deploy-update-preflight \
  DOCKER_PLATFORM=linux/amd64 \
  DEPLOY_BACKUP_DIR="${BACKUP_DIR}" \
  DEPLOY_SCHEMA_COMPATIBILITY=backward-compatible
```

После review выполните update:

```shell
make deploy-update \
  DOCKER_PLATFORM=linux/amd64 \
  DEPLOY_BACKUP_DIR="${BACKUP_DIR}" \
  DEPLOY_SCHEMA_COMPATIBILITY=backward-compatible
```

Команда показывает точные release/container/database, просит подтверждение,
останавливает HTTP producer, ставит workers на pause, завершает их через
graceful stop, применяет migrations, выполняет региональные gates, активирует
exact image, снимает pause и запускает smoke. При non-interactive automation
используйте `DEPLOY_UPDATE_FLAGS=--yes` только после успешного preflight.
Backup повторно проверяется под target lock, включая bytes, SHA-256, database и
совпадение `application_revision` с source OID текущего app container.

Deployment smoke ограничен общим budget `45` секунд. Каждый HTTP request имеет
connect timeout `3` секунды и total timeout не больше `10` секунд либо остатка
общего budget. Он проверяет `/health/live`, `/health/startup`, `/health/ready`,
а затем публичный `GET /api/addresses/search` по representative адресу
`Химки Лавочкина 17` региона `50` и ожидает GAR `object_id=43685301`. Значения
можно переопределить через `DEPLOY_SMOKE_CONNECT_TIMEOUT_SECONDS`,
`DEPLOY_SMOKE_REQUEST_TIMEOUT_SECONDS` и `DEPLOY_SMOKE_DEADLINE_SECONDS`; все
они задаются целым положительным числом секунд.

Во время `deploy-start` и `deploy-update` в operation evidence записываются
sanitized endpoint без credentials, три timeout/budget, отдельные статусы
health и address search и итоговый `smoke_status`. Ошибка либо timeout после
activation классифицируется как partial deployment и требует обычного recovery
handover. Короткий smoke намеренно не заменяет полный `make smoke-release`.

После update проверьте локальный binding, health endpoint и доступ с разрешённого
consumer host:

```shell
make prod-ps
make deploy-smoke
ss -H -ltnp 'sport = :<published-port>'
```

На отдельном consumer host:

```shell
curl --fail http://<geo-registry-private-ip>:<published-port>/health/ready
```

Если update прервался после quiesce, не запускайте workers вручную и не удаляйте
`partial.env`. Сохраните вывод, проверьте exact evidence path из сообщения,
`docker compose ... ps/logs` и состояние migrations. Затем выберите одну ветку:
`roll-forward`, `restore`, `manual-recovery` либо `safe-abort` только когда
доказано отсутствие durable изменений. Зафиксируйте handover точным
подтверждением, например:

```shell
make deploy-handover \
  DEPLOY_RECOVERY_BRANCH=roll-forward \
  DEPLOY_HANDOVER_CONFIRM=handover:moscow-primary:roll-forward
```

Handover архивирует evidence в
`docker/data/deployment/<target>/handovers/` и снимает partial-блок, но не
запускает containers, rollback, migrations или restore. После него отдельно
выполните ровно выбранную recovery procedure. Code rollback не гарантирует
обратную совместимость уже применённых migrations.

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

- `make deploy-port-preflight` — до data gates показать resolved HTTP binding и
  остановиться, если порт занят посторонним listener/container;
- `make data-restore-status` — определить, продолжается ли restore, завершён ли
  он и какой шаг безопасен дальше;
- `make data-status` — показать GAR checkpoints, активные statements и COPY
  workers во время import/rebuild;
- `make deploy-image` — повторно подготовить exact image без запуска services;
- `make deploy-update-preflight` — проверить существующий managed target;
- `docker/data/deployment/<target>/partial.env` — прочитать точный failed step,
  identity и recovery branch перед handover;
- `make deploy-handover ...` — зафиксировать явное recovery-решение без
  автоматического rollback/restore;
- `docker compose --env-file config/prod.secrets.env logs -f postgres` — PostgreSQL;
- `docker compose --env-file config/prod.secrets.env logs -f app` — HTTP;
- `make verify-data-backup ...` — повторная read-only проверка backup;
- не используйте `docker compose down -v` и не копируйте dump внутрь volume;
- cleanup старого container/volume выполняется отдельно только после rollback
  window.

Низкоуровневый backup/restore contract находится в
[data-backup-restore.md](data-backup-restore.md), source/import lifecycle — в
[data-lifecycle.md](../guides/data-lifecycle.md), offline packaging — в
[offline-release.md](offline-release.md). Разбор уже встречавшихся ошибок,
потерянной SSH-сессии, низкой CPU load и orphan containers — в
[диагностике развёртывания](troubleshooting.md).

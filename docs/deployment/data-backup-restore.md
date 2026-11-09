# Backup И Restore Данных

- Статус: `tooling ready; production backup/restore rehearsal pending`
- Область: application PostgreSQL, Nominatim и OpenSearch projection
- Главное ограничение: restore никогда не выполняется поверх действующей БД

Production Compose переключает только процессы приложения. Logical backup не
входит в application image. Он живёт отдельно либо по явному
`RELEASE_INCLUDE_APPLICATION_DATA=1` упаковывается в full-data offline archive;
в обоих случаях restore остаётся отдельной подтверждаемой операцией.

Для локального development restore с последующим OpenSearch rebuild используйте
[development data bootstrap](../guides/development-data-bootstrap.md); этот
runbook остаётся production-oriented и не подменяет development config.

## Граница Ответственности

| Система | Роль | Backup | Restore gate |
| --- | --- | --- | --- |
| Application PostgreSQL | source of truth: GAR, regional read model, migrations и durable queues | transaction-consistent custom `pg_dump`, zstd, SHA-256 и manifest | только новая shadow-БД, затем migrations/integrity/queue checks |
| Nominatim PostgreSQL | отдельный geocoder dataset | отдельный logical dump с PBF provenance | новая совместимая PostgreSQL/Nominatim instance, `/status` и representative reverse checks |
| OpenSearch | rebuildable projection | canonical backup отсутствует; сохраняется status/parity evidence | новый пустой index через `opensearch:reindex`, alias и parity через `opensearch:status` |

Raw Docker volume не является переносимым backup. Application PostgreSQL и
Nominatim имеют разные PostgreSQL major versions и не должны попадать в общий
filesystem snapshot. OpenSearch volume не становится source of truth; snapshot
repository допустим только как необязательный ускоритель и не отменяет rebuild
из PostgreSQL.

## Формат Backup

Каждый PostgreSQL backup — отдельный каталог:

```text
application-postgres-20261107T120000Z/
├── application-postgres.dump
└── manifest.txt
```

Manifest связывает component, UTC time, application revision, database image,
source PostgreSQL version, исходный размер БД, dump compression/size/SHA-256 и
provenance. Dump не содержит паролей, но содержит production data и поэтому
должен храниться в защищённом immutable/versioned location с отдельным access
control и retention policy.

Backup создаётся во временном каталоге на том же filesystem, проверяется через
`pg_restore --list` и checksum, а затем атомарно переименовывается. Незавершённый
временный каталог удаляется; уже опубликованный backup никогда не
перезаписывается. Во время `pg_dump` каждые 30 секунд печатаются elapsed time,
размер уже записанного сжатого архива и видимая PostgreSQL activity. После
streaming отдельно обозначаются TOC и checksum phases.

Для переносимого full-data release backup дополнительно оборачивается в
`application-postgres-backup.tar` с двумя файлами и связывается checksum-ами с
`source-manifest.txt`. Команда `make extract-release-data` проверяет оба уровня
manifest до публикации обычного backup-каталога.

## Preconditions Release Window

До backup оператор фиксирует:

1. абсолютный `BACKUP_ROOT`, retention, владельца и проверку off-host copy;
2. release revision, GAR regions/releases и Nominatim PBF manifest/checksum;
3. отсутствие migrations, GAR import/rebuild и Nominatim updates;
4. paused workers, завершение текущих jobs и отсутствие новых publishers;
5. clean application checkout либо явно подтверждённый полный
   `BACKUP_APPLICATION_REVISION`;
6. свободное место не меньше logical database size плюс резерв.

Сам `pg_dump` использует одну transaction-consistent snapshot, но он не может
откатить внешние side effects job-а. Поэтому release rollback point снимается
после quiesce, а не во время активной миграции или импорта.

## Read-only Preflight

Для managed production Compose используйте короткий контракт. Он сам выбирает
service `postgres`, БД `geo_registry` и owner `geo_registry`:

```shell
make data-backup-preflight BACKUP_ROOT=/srv/backups/geo-registry
make data-backup BACKUP_ROOT=/srv/backups/geo-registry
```

Без `BACKUP_ROOT` каталог создаётся в `docker/data/backups/`. Следующие
низкоуровневые команды нужны для development, Nominatim или явно выбранного
legacy PostgreSQL.

Пример защищённого каталога; фактический путь утверждает оператор:

```shell
make backup-data-preflight \
  BACKUP_COMPONENT=application-postgres \
  BACKUP_ROOT=/srv/backups/geo-registry
```

Для Nominatim обязательно связать dump с утверждённым PBF либо его manifest:

```shell
make backup-data-preflight \
  BACKUP_COMPONENT=nominatim \
  BACKUP_ROOT=/srv/backups/geo-registry \
  BACKUP_PROVENANCE_NAME=russia-260907.osm.pbf \
  BACKUP_PROVENANCE_SHA256=<64-hex-characters>
```

Preflight читает database size/version, проверяет container и capacity, но не
создаёт каталогов, dumps или locks длиннее обычных metadata queries. Для
внешнего PostgreSQL container можно передать `BACKUP_CONTAINER`; имя БД и роль
переопределяются только явными `BACKUP_DATABASE_NAME` и
`BACKUP_DATABASE_USER` при прямом запуске скрипта.

## Создание И Проверка

После quiesce повторить те же параметры без `-preflight`:

```shell
make backup-data \
  BACKUP_COMPONENT=application-postgres \
  BACKUP_ROOT=/srv/backups/geo-registry

make backup-data \
  BACKUP_COMPONENT=nominatim \
  BACKUP_ROOT=/srv/backups/geo-registry \
  BACKUP_PROVENANCE_NAME=russia-260907.osm.pbf \
  BACKUP_PROVENANCE_SHA256=<64-hex-characters>
```

Каждый опубликованный каталог проверяется повторно, желательно на restore host
и с его PostgreSQL tools:

```shell
make verify-data-backup \
  BACKUP_DIR=/srv/backups/geo-registry/application-postgres-<UTC> \
  VERIFY_CONTAINER=<compatible-postgres-container>
```

Нулевой exit подтверждает bytes, SHA-256, custom archive TOC и то, что
`pg_restore` не старее source major. Это ещё не доказывает успешный restore.

## Restore Только В Shadow-БД

Целевой container должен быть отдельным новым cluster либо явно выделенным
restore target с достаточным местом, нужными extensions и уже созданной ролью.
Имя shadow-БД обязано отсутствовать. Скрипт отклоняет in-place restore и не
удаляет БД автоматически даже после ошибки.

Во время restore heartbeat показывает elapsed time, размер БД, relations и
текущую activity. Процент не показывается, потому что compressed archive,
табличные данные, indexes и WAL несопоставимы по объёму. Для managed production
deployment состояние можно независимо прочитать из второй сессии:

```shell
make data-restore-status
```

Если управляющая SSH-сессия исчезла, эту команду запускают до любой повторной
попытки. Активный `pg_restore` продолжают ждать; существующую БД без активного
restore сначала проводят через `make deploy-start`.

Сначала read-only проверка:

```shell
make restore-data-preflight \
  BACKUP_DIR=/srv/backups/geo-registry/application-postgres-<UTC> \
  RESTORE_CONTAINER=<target-postgres-container> \
  RESTORE_DATABASE=geo_registry_restore_v1 \
  RESTORE_DATABASE_OWNER=geo_registry \
  RESTORE_ADMIN_USER=geo_registry
```

Фактическое создание требует точного подтверждения, включающего target
container и новую БД:

```shell
make restore-data-shadow \
  BACKUP_DIR=/srv/backups/geo-registry/application-postgres-<UTC> \
  RESTORE_CONTAINER=<target-postgres-container> \
  RESTORE_DATABASE=geo_registry_restore_v1 \
  RESTORE_DATABASE_OWNER=geo_registry \
  RESTORE_ADMIN_USER=geo_registry \
  RESTORE_CONFIRM=create:<target-postgres-container>:geo_registry_restore_v1
```

Для Nominatim используется тот же путь с его отдельным backup, совместимым
PostgreSQL major/image и новой БД `nominatim`. Provisioning нового Nominatim
cluster/volume остаётся инфраструктурной операцией и не должен переиспользовать
активный volume.

## Проверка Application Restore

До запуска постоянных application processes восстановленная БД проверяется
одноразовым maintenance container той же immutable revision. Credentials
передаются только через ignored `config/prod.secrets.env`; DSN и остальные
несекретные параметры определены production Compose. Загрузите frozen image
  через единый `make deploy-image`, который выбирает registry или offline
  payload и в обоих случаях проверяет frozen release identity.

Обязательные команды с host checkout:

```shell
docker/compose/prod.sh run --rm --no-deps app bin/app migrate:history
docker/compose/prod.sh run --rm --no-deps app \
  bin/app gar:integrity --regions=50,77 --deep --only-failed
docker/compose/prod.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77 --exact
docker/compose/prod.sh run --rm --no-deps app \
  bin/app worker:status --group=geo-registry --children
```

Дополнительно сверяются import manifests, row counts регионов 50/77,
application schema revision и отсутствие unexpected active/reserved jobs.
Только после operator approval меняется DSN и пересоздаются application
processes. Исходная БД сохраняется до окончания rollback window.

## Восстановление OpenSearch

До data switch сохранить вывод:

```shell
docker/compose/prod.sh run --rm --no-deps app bin/app opensearch:status
```

После application PostgreSQL restore создать пустой OpenSearch target и
выполнить из той же application revision:

```shell
docker/compose/prod.sh run --rm --no-deps app \
  bin/app opensearch:reindex --batch-size=10000 --concurrency=8
docker/compose/prod.sh run --rm --no-deps app bin/app opensearch:status
```

Gate требует healthy cluster, один ожидаемый read alias, parity source/target,
`invalid_regions=0` и успешный representative search smoke. Alias предыдущего
index или старый volume не переключается в новую release только потому, что он
существует.

## Nominatim Gate

Восстановленный Nominatim запускается отдельно от application release. Нужно
проверить `/status`, provenance PBF, отсутствие неожиданных replication updates
и representative reverse queries для Москвы и Московской области. После этого
application получает новый URL через runtime configuration. Старый instance и
его volume остаются нетронутыми до окончания rollback window.

## Что Закрывает Delivery Gate

Перед production-like rehearsal сформируйте read-only план на restore host.
Source и rehearsal target обязаны различаться; off-host copy указывается
нечувствительным evidence label, а не URL с credentials:

```shell
make recovery-rehearsal-plan \
  DEPLOY_TARGET=moscow-recovery-rehearsal \
  DEPLOY_EXPECTED_DOCKER_CONTEXT=production-rehearsal \
  RECOVERY_SOURCE_TARGET=moscow-primary \
  DEPLOY_BACKUP_DIR=/srv/backups/application-postgres-<UTC> \
  RECOVERY_OFF_HOST_COPY_REFERENCE=vault-copy-<UTC> \
  RECOVERY_NOMINATIM_STRATEGY=rebuild \
  RECOVERY_OBSERVATION_SECONDS=600
```

План проверяет release/config/image identity, backup bytes/SHA-256, отсутствие
active application и, когда PostgreSQL доступен, отсутствие target database.
Состояние `postgres-not-running` оставляет database absence как обязательный
deferred gate самого restore. Вывод определяет RPO как интервал от
`backup_created_at` до rehearsal cutover, а RTO — от начала restore до успешного
bounded smoke. Команда ничего не загружает, не запускает, не восстанавливает и
не создаёт rehearsal evidence; mutating rehearsal требует отдельного разрешения.

Tooling и read-only capacity preflight сами по себе не закрывают gate. До
первого production switch нужны сохранённые evidence:

- оба dump-каталога скопированы в утверждённое protected/off-host location;
- checksum повторно проверен на restore host;
- оба dumps восстановлены в новые targets и прошли component-specific checks;
- OpenSearch полностью пересобран из восстановленного PostgreSQL;
- зафиксированы RPO/RTO, retention, ответственный оператор и решение по
  удалению shadow/старых targets после rollback window.

Сам tooling прошёл isolated rehearsal на PostgreSQL 17: custom dump с
контрольной строкой был повторно проверен, dry-run не создал БД, подтверждённый
restore создал новую shadow-БД и сохранил строку. После проверки shadow-БД,
dump-каталог и tmpfs integration container удалены. Это доказывает механику
скриптов, но не заменяет restore полного production dataset и Nominatim.

Полная последовательность для нового сервера — checkout tag, image load/pull,
restore, DSN, GAR/OpenSearch gates, `change-release` и smoke — находится в
[сквозном руководстве развёртывания](release-deploy.md). Ответы на вопросы
«процесс завис?», «можно повторить restore?» и «почему CPU низкий?» находятся в
[диагностике развёртывания](troubleshooting.md).

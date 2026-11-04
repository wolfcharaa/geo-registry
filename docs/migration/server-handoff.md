# Перенос Source Workspace На Data Server

- Статус: `Source handoff ready; full-data execution pending`
- Область: `R1 release parity preparation`
- Обновлено: `2026-11-04`
- Связанный runbook: [release-parity.md](release-parity.md)

## Граница Этого Этапа

Текущий deployment использует Composer path repositories и bind mounts трёх
соседних Git repositories. Поэтому переносится согласованный source workspace:

```text
workspace/
├── geo-registry/
└── library/
    ├── MessageBusAsync/
    └── MessageBusAsyncWorker/
```

`make server-source-bundle` создаёт transport archive из трёх self-contained
Git bundles, этого документа и manifest с branch, полными commit IDs и SHA-256.
Перед сборкой команда требует чистое состояние всех repositories и проверяет,
что revisions библиотек совпадают с `app/composer.lock`.

В архив намеренно не входят:

- GAR XML/ZIP, Nominatim PBF и любые production/import data;
- `config/.env`, credentials, Postman environment и secrets;
- Composer `vendor`, Docker images, named volumes, dumps и indexes;
- локальные runtime artifacts и старые BoltDB queues.

Это перенос исходного workspace, а не production image release и не offline
release bundle. Текущий Compose остаётся development topology до отдельного
production deployment slice с pinned images, backup и rollback mechanics.

## Сборка На Текущей Машине

```shell
make server-source-bundle
```

По умолчанию архив создаётся в `/tmp` и не попадает в Git. Для явного пути:

```shell
make server-source-bundle \
  SERVER_SOURCE_OUTPUT=/absolute/path/geo-registry-server-source.tar
```

Перед передачей сохранить напечатанный SHA-256 отдельно от архива. Не добавлять
в transport archive данные или `.env`: они имеют собственные provenance,
checksum, retention и access rules.

## Восстановление Workspace На Сервере

Распаковывать архив следует в новый пустой staging directory. Сначала сверить
внешний checksum, затем manifest и bundles:

```shell
mkdir geo-registry-source
tar -xf geo-registry-server-source-<revision>.tar -C geo-registry-source
cd geo-registry-source

git init --bare --quiet bundle-verifier.git
git -C bundle-verifier.git bundle verify "$(pwd)/geo-registry.bundle"
git -C bundle-verifier.git bundle verify "$(pwd)/message-bus-async.bundle"
git -C bundle-verifier.git bundle verify "$(pwd)/message-bus-async-worker.bundle"
```

Проверка в пустом bare repository подтверждает, что bundles содержат полную
историю без скрытых prerequisites. Временный `bundle-verifier.git` не входит в
workspace и после проверки может быть удалён.

Создать точную соседнюю структуру и checkout веток из `source-manifest.txt`:

```shell
mkdir -p workspace/library
git clone --branch docs/async-migration-roadmap \
  geo-registry.bundle workspace/geo-registry
git clone --branch feature/async-core-registry \
  message-bus-async.bundle workspace/library/MessageBusAsync
git clone --branch main \
  message-bus-async-worker.bundle workspace/library/MessageBusAsyncWorker
```

Имена веток выше соответствуют текущей подготовленной линии. Manifest является
authoritative для каждого нового архива. После clone все три `HEAD` должны
совпасть с полными commit IDs manifest; dirty checkout продолжать нельзя.

## Read-Only Preflight

Создать ignored `workspace/geo-registry/config/.env` из example и заменить
development credentials, Monaco PBF/update URLs и остальные значения на
утверждённую server configuration. Секреты не должны попадать в shell history,
manifest или Git.

До build/start/import выполнить только read-only preflight:

```shell
cd workspace/geo-registry
make server-preflight \
  GAR_SOURCE=/absolute/path/to/gar/xml-or-directory \
  SERVER_STORAGE_PATH=/absolute/path/to/data-filesystem \
  SERVER_MIN_FREE_GB=<approved-free-space-budget> \
  SERVER_MIN_MEMORY_GB=<approved-docker-memory-budget>
```

Оба budget задаются оператором явно: preflight не угадывает требования по
размеру архива. Free-space budget должен одновременно учитывать распакованный
GAR, application PostgreSQL, внутреннюю PostgreSQL Nominatim, OpenSearch,
временные rebuild artifacts, backups и согласованный запас. Файловая система
`SERVER_STORAGE_PATH` должна быть той, на которой фактически будут размещены
эти данные; перенос Docker data root/volumes остаётся server configuration.

Preflight не запускает containers и не меняет данные. Он проверяет:

- non-root execution, наличие Docker/Compose и Linux container daemon;
- чистые repositories и совпадение library HEAD с Composer lock;
- абсолютный читаемый GAR source и доступный data filesystem;
- отсутствие development-only credentials и Monaco dataset в `.env`;
- утверждённые minimum free space и Docker memory;
- корректность разрешённой Compose configuration.

## После Успешного Preflight

На data server выполняется существующий [release parity runbook](release-parity.md):

1. build/install и fast/contract/integration gates на одной revision;
2. применение migrations и запуск full Nominatim import;
3. canonical GAR import с checkpoints;
4. региональный `address_search` rebuild и parity;
5. OpenSearch blue-green rebuild и alias switch;
6. strict Postman, legacy/AMPHP comparison и worker lifecycle evidence.

Фактический build, import и cutover являются отдельными изменяющими состояние
операциями. Успешный source handoff или preflight не закрывает R1/G5.

## Rollback Boundary

До начала import rollback равен удалению нового server workspace и не затрагивает
рабочий legacy runtime. После начала migrations/import применяются backup и
rollback правила `release-parity.md` и `worker-cutover.md`; source archive не
является backup базы, индексов, Nominatim или legacy queues.

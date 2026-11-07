# Перенос Source Repository На Data Server

- Статус: `Source handoff ready; full-data execution pending`
- Область: `R1 release parity preparation`
- Обновлено: `2026-11-07`
- Связанный runbook: [release-parity.md](release-parity.md)

## Граница Этого Этапа

Приложение устанавливает `romanfedorskij/message-bus-async` и
`romanfedorskij/message-bus-async-worker` из Packagist по
`app/composer.lock`. Соседние Git checkout библиотек, Composer path
repositories и bind mounts `/library` больше не входят в source workspace.

`make server-source-bundle` создаёт source transport v2 из:

- application Git bundle;
- этого server guide;
- manifest с application branch/commit, SHA-256 и locked version/reference
  обеих MessageBus packages.

Composer `vendor` в архив не входит. Для source build на сервере нужен доступ к
Packagist и GitHub dist archives. Если сервер изолирован от сети, используйте
[offline application release](../deployment/offline-release.md), где готовый
`vendor` уже находится внутри Docker image.

В source archive намеренно не входят:

- GAR XML/ZIP, Nominatim PBF и любые production/import data;
- `config/.env`, credentials, Postman environment и secrets;
- Composer `vendor`, Docker images, named volumes, dumps и indexes;
- локальные runtime artifacts и старые BoltDB queues.

Source handoff не заменяет production image release, data backup или rollback.

## Сборка На Текущей Машине

Команда требует clean application branch и проверяет, что обе MessageBus
dependencies в lock имеют обычную release distribution, а не `path`:

```shell
make server-source-bundle
```

По умолчанию архив создаётся в `/tmp`. Для явного пути:

```shell
make server-source-bundle \
  SERVER_SOURCE_OUTPUT=/absolute/path/geo-registry-server-source.tar
```

Сохраните напечатанный SHA-256 отдельно от архива. Не добавляйте в transport
данные или `.env`: у них собственные provenance, checksum и access rules.

## Восстановление Source На Сервере

Распакуйте архив в новый пустой staging directory. Сначала сверьте внешний
checksum, затем application bundle:

```shell
mkdir geo-registry-source
tar -xf geo-registry-server-source-<revision>.tar -C geo-registry-source
cd geo-registry-source

git init --bare --quiet bundle-verifier.git
git -C bundle-verifier.git bundle verify "$(pwd)/geo-registry.bundle"
```

Создайте application checkout из branch, указанной в
`source-manifest.txt`, и проверьте полный commit:

```shell
git clone --branch main geo-registry.bundle geo-registry
cd geo-registry
git rev-parse HEAD
```

Для public release вместо branch checkout используйте соответствующий
annotated tag. Dirty checkout продолжать нельзя. Package versions и source
references берутся из tracked `app/composer.lock`, а не из произвольной
локальной копии библиотек.

## Read-Only Preflight

Создайте ignored `config/.env` из example и замените development credentials,
Monaco PBF/update URLs и остальные значения на утверждённую server
configuration. Секреты не должны попадать в shell history, manifest или Git.

Для зафиксированного локального Nominatim PBF используется read-only каталог:

```dotenv
NOMINATIM_PBF_URL=
NOMINATIM_DATA_PATH=/absolute/host/path/to/pbf-directory
NOMINATIM_PBF_PATH=/data/nominatim/approved-snapshot.osm.pbf
```

`NOMINATIM_PBF_URL` и `NOMINATIM_PBF_PATH` взаимоисключающие. Локальный path
должен указывать на файл внутри `/data/nominatim`, а соответствующий host
directory — существовать и быть читаемым до preflight.

Для data server в ignored `config/.env` обязательно выберите production-профиль
application PostgreSQL:

```dotenv
POSTGRES_TUNING_PROFILE=prod
```

Профиль, его границы ресурсов и допустимые operator overrides описаны в
[postgresql-tuning.md](../postgresql-tuning.md).

До build/start/import выполните только read-only preflight:

```shell
make server-preflight \
  GAR_SOURCE=/absolute/path/to/gar/xml-or-directory \
  SERVER_STORAGE_PATH=/absolute/path/to/data-filesystem \
  SERVER_MIN_FREE_GB=<approved-free-space-budget> \
  SERVER_MIN_MEMORY_GB=<approved-docker-memory-budget>
```

Оба budget задаются оператором явно. Free-space budget должен учитывать
распакованный GAR, application PostgreSQL, PostgreSQL Nominatim, OpenSearch,
временные rebuild artifacts, backups и согласованный запас.

Preflight не запускает containers и не меняет данные. Он проверяет:

- non-root execution, Docker/Compose и Linux container daemon;
- clean application repository;
- locked versions/references MessageBus packages и отсутствие path
  distribution;
- абсолютный читаемый GAR source и доступный data filesystem;
- отсутствие development credentials и Monaco dataset в `.env`;
- production-профиль PostgreSQL и capacity host;
- утверждённые minimum free space и Docker memory;
- разрешённую Compose configuration без library bind mounts.

## После Успешного Preflight

Для connected source build установите exact dependencies из lock:

```shell
make build
make install
```

Затем выполняется существующий [release parity runbook](release-parity.md):

1. fast/contract/integration gates на одной application revision;
2. migrations и full Nominatim import;
3. canonical GAR import с checkpoints;
4. региональный `address_search` rebuild и parity;
5. OpenSearch blue-green rebuild и alias switch;
6. strict Postman, legacy/AMPHP comparison и worker lifecycle evidence.

Фактический build, import и cutover являются отдельными изменяющими состояние
операциями. Успешный source handoff или preflight не закрывает R1/G5.

## Rollback Boundary

До начала import rollback равен удалению нового server checkout и не
затрагивает рабочий legacy runtime. После начала migrations/import применяются
backup и rollback правила `release-parity.md` и `worker-cutover.md`; source
archive не является backup базы, индексов, Nominatim или legacy queues.

# Контекст Для Продолжения На Data Server

- Назначение: `handoff в новую Codex-сессию после переноса repositories`
- Состояние на момент записи: `source/config verified; GAR extracted; clean revision and server preflight pending`
- Обновлено: `2026-11-05`
- Основной roadmap: [implementation-roadmap.md](implementation-roadmap.md)
- Server runbook: [server-handoff.md](server-handoff.md)

## Как Начать Новую Сессию

Открыть новую Codex-сессию с рабочим каталогом в корне `geo-registry` на
сервере. Первым сообщением передать:

```text
Продолжи миграцию Geo Registry со Spiral на AMPHP.

Сначала полностью прочитай AGENTS.md и
docs/migration/server-session-context.md. Затем выполни только read-only
проверку из раздела «Первый Шаг Новой Сессии» и сообщи расхождения.

Не пересматривай уже принятые архитектурные решения без обнаруженного
конфликта с кодом или серверным окружением. Не запускай containers, migrations,
GAR/Nominatim import, rebuild или cutover до успешного server preflight и моего
явного подтверждения изменяющего состояние этапа.
```

Этот файл восстанавливает рабочий контекст, но не заменяет проверку repository
и сервера. При расхождении authoritative являются Git, Composer lock,
исполняемые checks и фактическое окружение; расхождение сначала фиксируется, а
не исправляется переписыванием истории.

## Ожидаемая Source Identity

Repositories должны иметь соседнюю структуру:

```text
workspace/
├── geo-registry/
└── library/
    ├── MessageBusAsync/
    └── MessageBusAsyncWorker/
```

| Repository | Branch | Commit |
| --- | --- | --- |
| `geo-registry` | `docs/async-migration-roadmap` | `78f92321924ffbca15b27c158ba698bf974eca00` |
| `MessageBusAsync` | `feature/async-core-registry` | `9d25f26cabe5f69c08aff331eea2ef985ff37bc3` |
| `MessageBusAsyncWorker` | `main` | `306ff70a634772bd51b0b9feb53cb79e20492d19` |

Source archive, из которого проверялось восстановление workspace:

```text
geo-registry-server-source-78f92321924f.tar
sha256=dac2b59f34cbf6eaebf128f20b5577a092f7088efe105039cb0e6e0de06abdaf
```

Архив является transport artifact и в Git не хранится. Если context-файл
передан отдельным более поздним commit, `geo-registry` может быть чистым
потомком указанного baseline; новая сессия должна явно показать такой commit и
его diff, а не считать это ошибкой автоматически. Revisions библиотек обязаны
совпадать с references в `app/composer.lock`.

## На Чём Остановились

Локальная реализация первого AMPHP release подготовлена. Завершены:

- отдельные packages `romanfedorskij/message-bus-async` и
  `romanfedorskij/message-bus-async-worker` версии `0.1.x` с namespace
  `Fedor\MessageBus`;
- parity проверенного поведения MessageBus v6, interceptor pipeline, explicit
  registry, codecs/upcasters, async PostgreSQL producer и worker lifecycle;
- default Thesis DIC modules core/worker и application-owned runtime roots;
- HTTP scenarios find/search/suggest/reverse-geocode, migrations, GAR XML
  importer, regional read-model rebuild и OpenSearch blue-green rebuild;
- PostgreSQL durable workers и idempotent coordinate enrichment;
- fast, contract и real PostgreSQL/OpenSearch integration gates;
- strict Postman release runner, release scope и worker cutover runbooks;
- source-only server archive и read-only `make server-preflight`.

Последние подтверждённые локальные gates на application revision перед source
handoff:

```text
make check             81 tests / 368 assertions; PHPStan и Deptrac clean
make contract          12 tests / 227 assertions
make test-integration  15 tests / 218 assertions
composer audit         no known security advisories
make doctor            Compose configuration valid
```

Bundle был независимо распакован; все три repositories восстановились на
ожидаемые commits. На новом сервере эти результаты являются baseline, а не
заменой повторных release gates.

Переход на data server выбран потому, что полный GAR занимает примерно `53 GB`
в исходном архиве и около `454 GB` после распаковки; локальной машине не хватало
места для корректного full-data цикла. Пользователь сообщил, что projects уже
перенесены на сервер. Наличие, формат, checksum и доступность самого GAR archive
новая сессия обязана проверить отдельно.

На старой машине `/Users/romanfedorskij/project/www/data` содержал весь набор,
доступный текущему Spiral-приложению. Этот локальный путь не является server
contract. Прямое подключение legacy `address_search.dump` не принято как
готовый data path: после canonical restore/import обязателен региональный C2
rebuild с parity check.

## Принятые Решения — Не Открывать Заново Без Нового Конфликта

- Трёхслойная pragmatic hexagonal структура следует
  [architecture.md](../architecture.md): `UserInterface -> Capability <- Infrastructure`.
- `Application` содержит composition/process shell, а не business scenarios.
- HTTP runtime выполняет только non-blocking I/O; XMLReader и тяжёлые import/
  rebuild operations остаются в CLI.
- PostgreSQL — source of truth; OpenSearch — optional rebuildable projection.
- Search/suggest ограничиваются вычисленным регионом; nationwide `DISTINCT` не
  является основным query path.
- `address_search` строится как регионально partitioned projection; фактические
  планы и размеры проверяются на full dataset.
- Core MessageBus и worker extension остаются разными packages. Worker package
  зависит от core, но core не зависит от worker runtime.
- Используется существующий interceptor mechanism; новый параллельный pipeline
  не создаётся.
- Проверенное поведение MessageBus v6 переносится parity-first и не проходит
  повторный design round без подтверждённой причины.
- Lifecycle ресурсов управляется Thesis DIC; application задаёт свои scope и
  correlation overrides.
- OpenSearch client остаётся native `amphp/http-client`; generated SDK, Guzzle,
  implicit retries и альтернативная authentication требуют design review.
- Application PostgreSQL и внутренняя PostgreSQL Nominatim имеют разный
  lifecycle, migrations, volumes и backup path.
- Data.Mos и legacy OSM aliases остаются deferred E1 и не входят в первый
  release.
- Лицензия текущих трёх новых repositories — MIT.

## Что Ещё Не Выполнено

R1/G5 не закрыты. На сервере с реальными данными остаются:

1. проверить source identity, server capacity и configuration;
2. повторить gates на одной server revision;
3. подготовить full Nominatim dataset той же географии;
4. выполнить canonical GAR import с checkpoints;
5. перестроить regional `address_search` и доказать parity;
6. выполнить OpenSearch blue-green rebuild и alias cutover;
7. заполнить representative Postman environment без secrets;
8. сравнить legacy Spiral и AMPHP semantics;
9. проверить health, graceful shutdown, worker drain/restart и stale recovery;
10. отдельно подтвердить drain либо осознанное списание legacy RoadRunner/
    BoltDB queues;
11. получить operator approval перед traffic cutover.

Текущий Compose является development/data-migration topology, а не готовым
production image release. Успешный import или Postman smoke не разрешает
автоматически переключать production traffic.

## Неизвестные Параметры Сервера

Новая сессия должна заполнить фактическими значениями без записи secrets:

```text
SERVER_WORKSPACE=<absolute path>
GAR_SOURCE=<absolute XML file or directory>
GAR_RELEASE=<release/date>
GAR_SHA256_OR_MANIFEST=<checksum or manifest location>
NOMINATIM_PBF=<absolute path or approved URL>
NOMINATIM_PBF_SHA256=<checksum>
SERVER_STORAGE_PATH=<filesystem carrying actual data>
SERVER_MIN_FREE_GB=<approved budget>
SERVER_MIN_MEMORY_GB=<approved Docker budget>
SERVER_PLATFORM=<os/arch>
DOCKER_VERSION=<value>
COMPOSE_VERSION=<value>
BACKUP_LOCATION=<immutable or protected location>
LEGACY_BASE_URL=<if parity comparison is available>
TARGET_BASE_URL=<release candidate endpoint>
```

Не помещать сюда passwords, tokens, private keys, DSN с credentials или
содержимое `config/.env`.

## Проверено На Data Server 2026-11-05

- `SERVER_WORKSPACE=/srv/projects/geo-registry`;
- все три checkout совпадают с ожидаемыми branch/commit, а library HEAD — с
  `app/composer.lock`;
- canonical transport bundle отсутствует: пользователь подтвердил перенос
  проектов обычным archive; после удаления всех `19 047` AppleDouble-файлов
  `._*` Git integrity трёх repositories проверена через `git fsck`;
- `SERVER_PLATFORM=Linux x86_64`, Docker Engine `29.8.1`, Compose `5.5.1`,
  Docker memory `62791569408` bytes, Docker root `/srv/docker`;
- target Compose изолирован как `geo-registry-amp`, host ports:
  app `8900`, PostgreSQL `5437`, Nominatim `8088`, OpenSearch `9201`;
- ignored `config/.env` собран из совместимых server values legacy
  `phpfiasgeocoder`, имеет mode `0600`, не содержит development credentials и
  Monaco dataset;
- `GAR_ARCHIVE=/srv/backups/gar/gar_xml.zip`, release `2026.09.01` (`v.288`),
  SHA-256
  `84145e76512e7c6f2f833b330452a177857540e5493fbda1b820ac44bc7fcb8a`;
- `GAR_SOURCE=/srv/backups/gar/gar_xml_2026-09-01`: extraction завершена с
  exit code `0`; каталог содержит `1739` файлов (`1738` XML), суммарно
  `427100572249` bytes, entry names полностью совпадают с ZIP;
- `NOMINATIM_PBF=/srv/projects/martin/docker/data/tiles/russia-260907.osm.pbf`,
  SHA-256
  `67e50dff49404de27f3b617340673f0942cb1c03b9bc0662d68dd571c6666b84`,
  provenance manifest:
  `/srv/projects/martin/config/manifests/russia-260907.osm.pbf.manifest.json`;
- `LEGACY_BASE_URL=http://127.0.0.1:8899`, planned
  `TARGET_BASE_URL=http://127.0.0.1:8900`.

`SERVER_STORAGE_PATH=/srv/backups/gar` (filesystem `/srv`); после extraction
доступно `507500642304` bytes. Рабочие thresholds `350 GiB` free и `48 GiB`
Docker memory фактически выполняются, но остаются pending operator approval.

Target ports свободны, containers/volumes Compose project `geo-registry-amp`
ещё не создавались. Legacy `geo-registry` app/database/Nominatim/OpenSearch
остались `Up` и healthy после подготовки.

Полный `make server-preflight` остаётся pending до фиксации source changes в
clean revision и явного утверждения
`SERVER_MIN_FREE_GB`/`SERVER_MIN_MEMORY_GB`.

## Первый Шаг Новой Сессии

До любых изменений выполнить из корня `geo-registry`:

```shell
pwd
git status --short
git branch --show-current
git rev-parse HEAD

git -C ../library/MessageBusAsync status --short
git -C ../library/MessageBusAsync branch --show-current
git -C ../library/MessageBusAsync rev-parse HEAD

git -C ../library/MessageBusAsyncWorker status --short
git -C ../library/MessageBusAsyncWorker branch --show-current
git -C ../library/MessageBusAsyncWorker rev-parse HEAD

uname -a
docker version
docker compose version
docker info --format '{{.OSType}} {{.Architecture}} {{.MemTotal}} {{.DockerRootDir}}'
df -h <SERVER_STORAGE_PATH>
```

Если workspace имеет другой layout, сначала зафиксировать реальные пути и
проверить, что Composer path repositories разрешаются в эти же два checkout.
Не создавать symlink или копию library source молча.

После заполнения server parameters создать ignored `config/.env` из example,
заменить development credentials и Monaco dataset, затем запустить read-only
gate:

```shell
make server-preflight \
  GAR_SOURCE=<absolute-path> \
  SERVER_STORAGE_PATH=<absolute-path> \
  SERVER_MIN_FREE_GB=<approved-budget> \
  SERVER_MIN_MEMORY_GB=<approved-budget>
```

Preflight должен завершиться успешно до `make build`, `make up`, migrations и
imports. Он не должен выводить значения secrets.

## Порядок Продолжения После Preflight

Следовать [release-parity.md](release-parity.md) без перестановки data gates:

```text
source/config/capacity verification
  -> build + locked dependency install
  -> fast/contract/integration evidence
  -> application migrations
  -> full Nominatim readiness
  -> canonical GAR import
  -> regional address_search rebuild/parity
  -> OpenSearch rebuild/alias switch
  -> strict AMPHP Postman
  -> legacy/AMPHP comparison
  -> worker lifecycle and legacy queue evidence
  -> operator approval
  -> separate cutover
```

Каждая долгая data operation должна сохранять revision, input manifest,
timestamps, exit code и собственный parity result. Не объявлять R1 закрытым по
одному успешному процессу или только по readiness.

## Canonical Документы

- [architecture.md](../architecture.md) — целевые boundaries приложения.
- [implementation-roadmap.md](implementation-roadmap.md) — исполненные и
  оставшиеся gates.
- [application-libraries.md](application-libraries.md) — dependency decisions.
- [release-scope.md](release-scope.md) — surface первого release.
- [server-handoff.md](server-handoff.md) — перенос и server preflight.
- [release-parity.md](release-parity.md) — full-data R1 runbook.
- [worker-cutover.md](worker-cutover.md) — drain/cutover/rollback workers.
- [parity-fixture.md](parity-fixture.md) — synthetic baseline.

## Как Обновлять Этот Handoff

Перед следующим переносом сессии обновить только проверенные факты:

- новые commits и branches всех трёх repositories;
- выполненные команды и точные результаты;
- заполненные non-secret server parameters;
- созданные data manifests/evidence locations;
- текущий незавершённый шаг и причина остановки.

Не превращать этот файл в журнал каждого действия: подробные решения должны
оставаться в canonical roadmap/runbooks, а здесь хранится минимальная точка
входа для следующей сессии.

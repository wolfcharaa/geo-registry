# Контекст Для Продолжения На Data Server

- Назначение: `handoff в новую Codex-сессию после переноса repositories`
- Состояние на момент записи: `regions 50/77 imported; partitioned search live; release parity incomplete`
- Обновлено: `2026-11-07`
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

Текущая workspace содержит один application repository. MessageBus packages
устанавливаются из Packagist, а их authoritative identity находится в
`app/composer.lock`:

| Artifact | Release | Source reference |
| --- | --- | --- |
| `geo-registry` | current application branch/tag | `git rev-parse HEAD` |
| `romanfedorskij/message-bus-async` | `v0.1.0` | `0ea19e855fb0d1e476498e323c8dc4205652d8c3` |
| `romanfedorskij/message-bus-async-worker` | `v0.1.0` | `c9369b4230333537b3193d15e3855982a25dafeb` |

Source archive, из которого проверялось восстановление workspace:

```text
geo-registry-server-source-78f92321924f.tar
sha256=dac2b59f34cbf6eaebf128f20b5577a092f7088efe105039cb0e6e0de06abdaf
```

Этот архив является историческим transport artifact формата v1 и в Git не
хранится. Новый `make server-source-bundle` создаёт application-only формат v2
с package versions/references в manifest. Если context-файл передан отдельным
более поздним commit, новая сессия должна показать фактический application
commit и diff, а не считать расхождение ошибкой автоматически.

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
- application-only source archive v2 и read-only `make server-preflight`.

Последние подтверждённые gates на data server:

```text
make check             85 tests / 404 assertions; PHPStan и Deptrac clean
make contract          12 tests / 227 assertions
make test-integration  16 tests / 223 assertions
make smoke             passed against http://127.0.0.1:8900
composer audit         no known security advisories
make doctor            Compose configuration valid
```

Исторический bundle v1 был независимо распакован; все три repositories тогда
восстановились на ожидаемые commits. После публикации packages этот layout
заменён application-only source bundle v2. На новом сервере старые результаты
являются baseline, а не заменой повторных release gates.

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

R1/G5 не закрыты. Canonical GAR subset регионов 50/77 импортирован и
partitioned `address_search` переключён, но остаются:

1. импортировать Data.Mos/OSM aliases либо явно принять отсутствие legacy alias
   parity; сейчас `address_aliases=0`;
2. запустить target Nominatim с согласованным dataset и проверить reverse flow;
3. настроить OpenSearch relevance и повторить representative parity после
   aliases; projection уже построена, но online decorator оставлен выключенным;
4. повторить representative legacy/target semantic parity после aliases;
5. выполнить strict full Nominatim/Postman smoke и сохранить representative
   legacy/target comparison evidence;
6. выполнить подготовленный backup/restore rehearsal отдельно для application
   PostgreSQL, Nominatim и rebuildable OpenSearch до первого production switch;
7. отдельно подтвердить drain либо осознанное списание legacy RoadRunner/
   BoltDB queues;
8. получить operator approval перед traffic cutover и удалить transitional
   `/geocode`/`/search` только после approval.

Development Compose остаётся data-migration topology. Отдельный production
Compose и offline workflow готовы для immutable application image, но наличие
release tooling не разрешает автоматически переключать production traffic без
data backup/compatibility, parity и operator approval.

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

На `2026-11-05` target ports были свободны, containers/volumes Compose project
`geo-registry-amp` ещё не создавались. Этот historical checkpoint заменён
следующим разделом.

## Проверено На Data Server 2026-11-06

- canonical GAR импорт ограничен регионами 50 и 77;
- active partitioned `address_search` содержит `14 607 719` строк региона 50 и
  `13 976 843` строк региона 77;
- `address_aliases` пуст (`0` строк); Data.Mos и OSM alias import не выполнены;
- target HTTP/PostgreSQL работают как `geo-registry-amp-app-1` и
  `geo-registry-amp-postgres-1`; `GET /health/live` возвращает `200`;
- historical checkpoint: target Nominatim тогда не был запущен; актуальное
  состояние приведено ниже;
- target OpenSearch `3.9.0` запущен на `9201`; alias `places` указывает на
  `places-v20261106110423355` из `28 584 562` документов;
- port `9200` принадлежит legacy OpenSearch: alias
  `geo_registry_addresses` указывает на индекс из `28 692 782` документов и не
  является target projection;
- historical checkpoint: target config сохранял `OPENSEARCH_INDEX=places`, а
  search и coordinate update flags были выключены до relevance gate; актуальное
  решение о search contract приведено ниже;
- `NOMINATIM_ENABLED=0` исключает отсутствующий Nominatim из runtime graph;
  при одновременном отключении Nominatim/OpenSearch readiness с одним
  PostgreSQL отвечал примерно за `1.3 ms` вместо прежних `18 s`; после запуска
  OpenSearch текущий readiness с двумя компонентами занимает около `19 ms`;
- PostgreSQL search `Москва Тверская 1` по региону 77 выполняется примерно за
  `0.19 s`; symmetric pruning benchmark дал около `9%` выигрыша;
- полный OpenSearch rebuild занял `361.57 s`, primary store — `5.3 GiB`;
  concurrency-16 benchmark дал p95 `78 ms` против PostgreSQL `587 ms`, но
  top-10 overlap пяти запросов составил только `58%`; подробности в
  [opensearch-benchmark.md](opensearch-benchmark.md);
- новый единый `app/bin/app` использует
  `thesis/symfony-console-module 0.1.0`; default `server:run`, migrations, GAR,
  OpenSearch и worker commands видны в одном lazy command registry;
- commit `23e7f12` содержит оптимизацию PostgreSQL search; executable `bin/app`
  является единым console entrypoint для server, migrations, GAR, OpenSearch и
  worker operations.

## Проверено 2026-11-07

- operational CLI закрыт через `gar:integrity`, `gar:activity`, `gar:releases`,
  `opensearch:status` и exact-confirmation cleanup; application fast gate —
  `114 tests / 544 assertions`, contract — `13 tests / 240 assertions`,
  real-service integration — `20 tests / 267 assertions`, включая XML import ->
  partitioned build -> PostgreSQL search, стабильный house top-3 и exact unit
  fixture;
- target workers прошли enqueue/consume, pause/resume, exact drain/restart и
  stale recovery; one-shot drain/stop/kill/restart теперь требуют точный
  `worker-instance-id`; повторный controlled restart HTTP и двух idle workers
  сохранил backlog `0`, корректно пометил старые instances `stopped`, поднял
  новые `running/idle` и вернул live/ready/startup и Docker health в `ok`;
- production application image собирается self-contained из application
  repository и locked Packagist dist packages, имеет pinned PHP/Composer
  bases, non-root runtime и immutable OCI digest;
- production Compose содержит только `app`, `worker-address`, `worker-runtime`,
  использует полный `DOCKER_IMAGE_PREFIX` из deployment namespace и не владеет
  PostgreSQL/OpenSearch/Nominatim;
- commits `75fb404` и `f287e15` добавили production topology и offline delivery:
  tracked release identity, checksum manifest, exact-image switch и
  симметричный rollback;
- изолированный test release `v0.0.0-e2e` создал flat archive из пяти файлов
  размером около `47 MiB`; Git bundle проверен в пустом bare repository,
  `verify-release` успешно прошёл как с уже загруженным digest, так и после
  удаления test image и восстановления через checksum + `docker load`;
- production release/tag не создавался, traffic и data services не менялись.
- application PostgreSQL (`17.11`) занимает `100027537075` bytes, Nominatim
  PostgreSQL (`16.15`) — `5168200727` bytes; оба services сейчас healthy;
- read-only backup preflight для обоих PostgreSQL lifecycle прошёл при
  `310011285504` bytes free на `/srv`. Dump не создавался: protected
  `BACKUP_LOCATION`, retention и off-host copy всё ещё требуют operator
  approval;
- добавлены fail-closed logical backup/checksum/manifest и restore только в
  отсутствующую shadow-БД; OpenSearch зафиксирован как rebuildable projection,
  а не source-of-truth volume. Полный порядок описан в
  [data-backup-restore.md](../deployment/data-backup-restore.md).
- isolated PostgreSQL 17 rehearsal подтвердил custom dump, checksum/TOC,
  read-only restore preflight, exact confirmation и восстановление контрольной
  строки в новой shadow-БД. Временные БД, dump и integration container удалены;
  production data не менялись.
- commits `2f4bb3a`, `7f7bec6` и `5288fb3` исправили реальный suggest GAR level
  `8`, staged transitions level `2..7`, house-level ranking, параметризовали
  Postman values и заменили HTML router errors на стабильный JSON;
- commit `12fb32a` добавил structured street/house/building/structure keys;
  blue-green rebuild `28649238/28649238` завершился за `635664 ms`, переключил
  alias на `places-v20261107100010468` и сохранил предыдущий index для rollback;
- на application revision `12fb32a` deep GAR integrity прошёл `13/13`, OpenSearch green parity —
  `28649238/28649238`, `invalid_regions=0`; strict target Newman прошёл
  `13/13` requests и `26/26` assertions; warm average `82 ms`, включая
  OpenSearch search, Nominatim reverse и тот же representative GAR identity;
- legacy `78d2dd2` и target выбрали один object `11073052` первым в search,
  совпали в первых четырёх top-5, вернули одинаковые find rows и выбранную
  suggest-цепочку. Search contract top-1/top-4 + unordered top-6 принят
  `2026-11-07`; расширенная сверка шести исторических запросов дала одинаковый
  top-1 в `5/6` и top-10 overlap `31/60`, поэтому exact global ordering не
  обещается. Открыты approval дополнительных fuzzy candidates и Nominatim
  snapshot/candidate-count difference;
- target JSON/JUnit evidence находится в session path
  `/tmp/geo-registry-release-smoke/amphp-12fb32a-warm.{json,xml}` и ещё требует
  переноса в protected evidence location.

Следующий delivery gate — выбрать protected `BACKUP_LOCATION` и выполнить
полный backup/restore rehearsal подготовленного runbook. После него остаются
approval fuzzy/Nominatim differences, legacy queue approval и traffic cutover.

## Первый Шаг Новой Сессии

До любых изменений выполнить из корня `geo-registry`:

```shell
pwd
git status --short
git branch --show-current
git rev-parse HEAD

jq '.packages[] | select(.name | startswith("romanfedorskij/message-bus-async")) | {name, version, source, dist}' app/composer.lock

uname -a
docker version
docker compose version
docker info --format '{{.OSType}} {{.Architecture}} {{.MemTotal}} {{.DockerRootDir}}'
df -h <SERVER_STORAGE_PATH>
```

Lock должен содержать `dist.type=zip`; `path` distribution или library symlink
означают, что workspace не соответствует текущему release contract.

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
- [offline-release.md](../deployment/offline-release.md) — image archive,
  manifest, release switch и code rollback.
- [parity-fixture.md](parity-fixture.md) — synthetic baseline.

## Как Обновлять Этот Handoff

Перед следующим переносом сессии обновить только проверенные факты:

- новый application commit/branch и locked versions/references packages;
- выполненные команды и точные результаты;
- заполненные non-secret server parameters;
- созданные data manifests/evidence locations;
- текущий незавершённый шаг и причина остановки.

Не превращать этот файл в журнал каждого действия: подробные решения должны
оставаться в canonical roadmap/runbooks, а здесь хранится минимальная точка
входа для следующей сессии.

# Release Parity Runbook

- Статус: `Representative search contract approved; other differences await approval`
- Область: `R1 release parity`
- Владелец решения: application release operator
- Связанный cutover: [worker-cutover.md](worker-cutover.md)

## Что Доказывает Gate

`make smoke-release` выполняет одну и ту же Postman collection в строгом
release-режиме. Перед запуском Newman target обязательно компилирует explicit
MessageBus registry через `make registry-check`; invalid binding, service map
или codec останавливают gate до HTTP-запросов. В отличие от обычного
contract-запуска strict mode требует:

- `live`, `startup` и `ready` со статусом `200`, а все readiness-компоненты —
  `ok`;
- успешные и непустые `search`, три стадии `suggest`, `find-by-id` и
  `reverse-geocode`;
- representative `addressId` в `search`, `find-by-id` и `reverse-geocode`;
- хотя бы один `search_backend=opensearch`, то есть PostgreSQL fallback не
  маскирует неготовую проекцию;
- точные `400`, `404` и `422` для негативных контрактов.

Runner использует официальный multi-architecture image
`postman/newman:6.1.3-alpine` по manifest digest
`sha256:d9b5e780ead0026bbb37f3759cd7b10fc8a88cd4030c8266a7c5e343d75a5198`.
JUnit и полный JSON report записываются вне repository; functional failures не
подавляются и не повторяются автоматически.

Gate не импортирует данные и не меняет deployment сам. Это намеренное
разделение: import/rebuild являются operator-controlled blocking operations, а
HTTP smoke остаётся read-only. Target collection запускается против AMPHP, а
исходная legacy collection — отдельно против Spiral: у legacy runtime нет
`/health/startup`, поэтому одна collection не выдаётся за общий контракт.

## Обязательные Входы

До запуска фиксируются:

1. Git revision и digests application, PostgreSQL, OpenSearch, Nominatim и
   Newman images.
2. GAR release/region и checksum входного каталога.
3. Nominatim PBF из той же географии, что и representative GAR address.
4. Ignored файл `config/release.postman_environment.json`, созданный из
   `docs/api/postman/geo-registry.local.postman_environment.json` и содержащий
   реальные согласованные значения `addressQuery`, `suggestCity`,
   `suggestDistrict`, `suggestThoroughfare`, parent IDs, `addressId` и reverse
   coordinates. Secrets в Postman environment запрещены.
5. `OPENSEARCH_SEARCH_ENABLED=1`; для проверки enrichment projection отдельно
   включается `OPENSEARCH_COORDINATE_UPDATES_ENABLED=1`.

`baseUrl` и `releaseStrict` всегда переопределяются runner-ом. Синтетические
значения repository fixture не доказывают full Nominatim smoke: координаты в
release environment должны реально разрешаться выбранным Nominatim dataset и
сопоставляться с тем же GAR object.

## Последовательность

### 1. Быстрые И Real-Service Gates

```shell
make doctor
make check
make contract
make test-integration
make registry-check
git diff --check
```

Все команды должны выполняться на той же revision, которая далее готовит
release dataset. Сохраняются stdout, exit codes и CI links.

### 2. Подготовка Full Stack

```shell
make config
make build
make install
make up
make migrate
make worker-up
```

Первичная загрузка Nominatim должна завершиться до следующих шагов. Нельзя
использовать Monaco default одновременно с Postman environment для другого
региона и объявлять reverse parity успешным.

### 3. Import И Проекции

`GAR_SOURCE` — абсолютный host path к согласованному release или его
representative subset. Каталог монтируется только на время blocking CLI import:

Поддержанный release path загружает canonical GAR и затем перестраивает
`address_search`: её `region_code` берётся из активной административной
иерархии, а не из ОКАТО. Legacy `address_search.dump` не содержит этот явный
ключ и не может быть включён напрямую. Его использование потребует отдельной
staging/backfill/parity процедуры; до её реализации выбирается canonical
restore/import и C2 rebuild ниже.

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume "${GAR_SOURCE}:/data/gar:ro" \
  app bin/app gar:import /data/gar

docker/compose/dev.sh run --rm --no-deps \
  app bin/app gar:address-search:rebuild

docker/compose/dev.sh run --rm --no-deps \
  app bin/app gar:integrity --regions=50,77 --deep --only-failed

docker/compose/dev.sh run --rm --no-deps \
  app bin/app opensearch:reindex

docker/compose/dev.sh run --rm --no-deps \
  app bin/app opensearch:status
```

Каждая команда обязана завершиться нулевым exit code и своим parity check.
После alias switch приложение пересоздаётся с включённым OpenSearch search:

```shell
OPENSEARCH_SEARCH_ENABLED=1 \
  docker/compose/dev.sh up --detach --no-build --force-recreate app
```

### 4. Executable Postman

```shell
cp docs/api/postman/geo-registry.local.postman_environment.json \
  config/release.postman_environment.json
# Заполнить согласованные representative values без credentials.

make smoke-release \
  POSTMAN_ENVIRONMENT=config/release.postman_environment.json \
  RELEASE_ARTIFACT_DIR=/tmp/geo-registry-release-smoke \
  RELEASE_RUN_NAME=amphp
```

Отдельный `make registry-check` в первой фазе сохраняется для самостоятельного
artifact/evidence. `make smoke-release` повторяет его намеренно, чтобы release
Postman нельзя было запустить на revision с невалидным registry.

При нестандартном порте или удалённом стенде передаётся
`POSTMAN_BASE_URL=https://release-candidate.example`. Для legacy evidence
используются исходные collection/environment, потому что они фиксируют точный
Spiral contract без target-only startup check:

```shell
make smoke-release \
  POSTMAN_COLLECTION=/path/to/phpfiasgeocoder/config/postman/geo-registry.postman_collection.json \
  POSTMAN_ENVIRONMENT=/path/to/phpfiasgeocoder/config/postman/geo-registry.local.postman_environment.json \
  POSTMAN_BASE_URL=https://legacy.example \
  RELEASE_ARTIFACT_DIR=/tmp/geo-registry-release-smoke \
  RELEASE_RUN_NAME=legacy
```

Legacy environment и target release environment должны описывать один адрес и
координаты. Исходная collection не получает target strict assertions; её
назначение — зафиксировать legacy responses для semantic comparison. Оба
JSON/JUnit artifacts сохраняются вместе с revision manifest.

### 5. Lifecycle И Workers

```shell
make worker-status
make worker-drain
make worker-status
make worker-resume
make worker-restart
make worker-status
```

Отдельно проверяются `TERM` для HTTP/worker containers, отсутствие нового claim
после drain, завершение текущего job в пределах grace period и stale recovery
после controlled restart. Drain старых RoadRunner/BoltDB queues подтверждается
только evidence из [worker-cutover.md](worker-cutover.md); новый PostgreSQL
worker status не заменяет эту проверку.

## Target Evidence 2026-11-07

Application commit `12fb32a` прошёл один согласованный target flow на canonical GAR регионов
50/77 и Nominatim PBF `russia-260907.osm.pbf`:

- `make check`: `114 tests / 544 assertions`;
- `make contract`: `13 tests / 240 assertions`;
- `make test-integration`: `20 tests / 267 assertions`; isolated data-flow smoke
  выполняет XML import -> partitioned build -> PostgreSQL search, house fixture
  закрепляет полный top-3, unit fixture — единственный exact level `11` result;
- `gar:integrity --regions=50,77 --deep --only-failed`: `13` checks,
  `failed=0`, `unavailable=0`;
- `opensearch:status`: green cluster, alias
  `places-v20261107100010468`, source/target `28649238/28649238`,
  `invalid_regions=0`;
- controlled `TERM`/restart HTTP и двух idle workers завершился без ошибок:
  старые instances стали `stopped`, новые имеют `running/idle`, durable backlog
  до и после равен `0`, `live/ready/startup` вернули `200/ok`, Docker health —
  `healthy`;
- strict Newman: cold `13/13` requests и `26/26` assertions, average `122 ms`,
  range `30..663 ms`; после завершения merges warm run — average `82 ms`,
  range `36..210 ms`.

Representative flow использовал Московскую область, Одинцово, Можайское шоссе
и один GAR house identity, одновременно найденный через OpenSearch,
`find-by-id` и Nominatim reverse. Во время подготовки gate выявлены и исправлены
четыре target defect: улично-дорожная сеть GAR имеет level `8`, staged
переходы level `2..7` должны вести к `thoroughfare`, house query должен отдавать
приоритет базовому level `10`, а неизвестный API route должен возвращать JSON
`404`, а не HTML.

Commit `12fb32a` дополнительно перенёс structured street/house/building/
structure keys в derived projection. Blue-green rebuild обработал `28649238`
documents за `635664 ms`, exact parity прошла, alias переключён атомарно, а
предыдущий index сохранён для rollback. Representative search overlap вырос с
`2/5` до `4/5`; первые четыре objects и их порядок совпали с legacy.

JUnit/JSON evidence находится в
`/tmp/geo-registry-release-smoke/amphp-12fb32a.{xml,json}` и warm pair
`amphp-12fb32a-warm.{xml,json}`. Это session artifacts:
до production approval его нужно перенести в утверждённое protected evidence
location вместе с revision/dataset manifest.

## Legacy Comparison Evidence 2026-11-07

Read-only запросы выполнены к legacy commit `78d2dd2` на `8899` и target
application commit `12fb32a` на `8900` с одинаковыми representative values:

- `find-by-id(11073052)` в обеих реализациях вернул две одинаковые `adm`/`mun`
  строки и GUID `44551f6d-4b23-49be-9631-b803e7618a89`;
- `search(Одинцово Можайское 1, region=50)` после восстановления house-level
  structured ranking выбрал object `11073052` первым в обеих реализациях;
  первые четыре objects top-5 и их порядок совпали, пятый различается;
- staged `suggest` выбрал одинаковые objects и transitions:
  `862378/level 2 -> thoroughfare`, затем `864576/level 8 -> search`;
- target district suggestion вернул ещё три fuzzy candidates сверх двух legacy
  candidates при запрошенном limit `5`;
- target reverse вернул пять GAR candidates с `11073052` первым, legacy на тех
  же координатах вернул только raw Nominatim fallback `Школьная улица`;
- missing-query совпал полностью (`400/missing_query`), invalid parent — по
  `422/suggestion_context_not_found`; неизвестный route в обеих реализациях
  имеет `404`, но только target возвращает stable `route_not_found` code;
- оба readiness endpoints сообщили `status=ok`; target дополнительно имеет
  отдельный startup probe.

Дополнительный запрос с `limit=20` показал, что search difference ограничен
границей limit, а не candidate set. В legacy позиции 5/6 — `81524836` (Татарки) и
`80467807` (Часцы) — имеют одинаковый score `1528.1375`, после чего срабатывает
лексический tie-break. Target содержит те же objects на позициях 5/6, но
небольшой BM25 delta (`5216.5767` против `5209.2993`) меняет их порядок. Поэтому
exact fifth candidate связывает contract с внутренним score/tie-break поискового
движка. Release relevance contract для этого representative flow: одинаковый
top-1, порядок первых четырёх и тот же unordered top-6 set. Operator/product
принял его `2026-11-07`; порядок score-tie tail не является compatibility
contract.

Расширенная read-only сверка шести исторических search-запросов дала одинаковый
top-1 в `5/6` случаев и суммарный top-10 overlap `31/60`. Поэтому принятое
решение не объявляет exact global ordering: оно относится к зафиксированному
Одинцово flow, а другие запросы остаются regression evidence для будущего
ranking tuning.

Это semantic evidence текущей сессии, но не protected release artifact. Перед
production approval одинаковые normalized responses и revision/dataset
manifest нужно сохранить в утверждённом evidence location.

## Матрица Сравнения

Для каждого representative запроса сохраняется не только status, но и
нормализованный semantic result. Request/correlation IDs, timing, score и
порядок равнозначных результатов сравниваются по своим правилам, а не как raw
JSON bytes.

| Сценарий | Legacy artifact | AMPHP artifact | Ожидание | Осознанное отличие |
| --- | --- | --- | --- | --- |
| `find-by-id` | direct HTTP @ `78d2dd2`: `11073052` | `amphp-12fb32a-warm.json` | тот же GAR identity и hierarchy | Совпали две `adm`/`mun` строки и GUID |
| `search` | direct HTTP @ `78d2dd2`: `11073052` rank 1 | `amphp-12fb32a-warm.json`: `11073052` rank 1 | тот же representative address; target использует OpenSearch | Top-1 и первые четыре совпали; позиции 5/6 содержат тот же unordered set, contract принят `2026-11-07` |
| staged `suggest` | direct HTTP: `862378 -> 864576` | `amphp-12fb32a-warm.json`: `862378 -> 864576` | те же переходы city/district/thoroughfare | Выбранная цепочка совпала; target возвращает три дополнительных fuzzy district candidates, approval pending |
| `reverse-geocode` | direct HTTP: raw `Школьная улица` fallback | `amphp-12fb32a-warm.json`: пять GAR candidates, `11073052` первый | согласованный адрес либо явно принятая dataset-разница | Nominatim snapshots различаются; target GAR match лучше, но candidate count/difference требует approval |
| validation/not-found | direct HTTP @ `78d2dd2` | `amphp-12fb32a-warm.json` | те же status и stable error codes | Missing query совпал; invalid parent status/code совпали; target-only `route_not_found` расширяет legacy `404` envelope |
| health | direct HTTP: `ready=ok` | `amphp-12fb32a-warm.json`: `live`, `ready`, `startup` | общие probes сохраняют смысл | target-only `startup` явно показывает завершение composition и не меняет address API |

Любое принятое отличие записывается здесь с причиной, compatibility impact и
approval. `pending` нельзя интерпретировать как parity.

## Условия Закрытия R1

- Fast, contract и real-service artifacts относятся к одной revision.
- Import -> regional read model -> OpenSearch alias -> strict API flow прошёл на
  зафиксированном dataset manifest.
- Legacy и AMPHP результаты сравнены по матрице, отличия утверждены.
- Health, graceful shutdown, worker drain/restart и stale recovery имеют
  operator evidence.
- Старые RoadRunner queues drained или явно списаны по отдельному approval.

До выполнения всех условий checklist R1 и статус этого документа остаются
незавершёнными.

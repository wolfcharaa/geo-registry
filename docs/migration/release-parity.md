# Release Parity Runbook

- Статус: `Executable gate ready; representative execution pending`
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
   реальные согласованные значения `addressQuery`, parent IDs, `addressId` и
   reverse coordinates. Secrets в Postman environment запрещены.
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

## Матрица Сравнения

Для каждого representative запроса сохраняется не только status, но и
нормализованный semantic result. Request/correlation IDs, timing, score и
порядок равнозначных результатов сравниваются по своим правилам, а не как raw
JSON bytes.

| Сценарий | Legacy artifact | AMPHP artifact | Ожидание | Осознанное отличие |
| --- | --- | --- | --- | --- |
| `find-by-id` | pending | pending | тот же GAR identity и hierarchy | pending |
| `search` | pending | pending | тот же representative address; target использует OpenSearch | pending |
| staged `suggest` | pending | pending | те же переходы city/district/thoroughfare | pending |
| `reverse-geocode` | pending | pending | та же GAR identity для согласованных координат | pending |
| validation/not-found | pending | pending | те же status и stable error codes | pending |
| health | `live`, `ready` | `live`, `ready`, `startup` | общие probes сохраняют смысл | target-only `startup` явно показывает завершение composition и не меняет address API |

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

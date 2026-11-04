# Архитектура Geo Registry

- Версия документа: `0.7.0`
- Обновлено: `2026-11-04`
- Статус: `Target architecture; scaffold is transitional`
- План миграции: [migration/implementation-roadmap.md](migration/implementation-roadmap.md)
- Принятые зависимости: [migration/application-libraries.md](migration/application-libraries.md)
- Первый business module: [AddressRegistry](../app/src/Module/AddressRegistry/MODULE.md)

Этот документ — основной источник архитектурных правил для нового кода Geo
Registry. Он описывает целевую модель и допустимый путь от текущего AMPHP
scaffold. Это не утверждение, что все каталоги и runtime roots уже реализованы.
Текущее состояние и порядок slices фиксирует implementation roadmap.

## Архитектурная Модель

Geo Registry строится как модульное AMPHP-приложение с прагматичной
трёхслойной hexagonal architecture:

- `UserInterface` — HTTP/CLI/message boundary, transport validation, mapping и
  presentation;
- `Capability` — executable business scenarios, MessageBus contracts,
  orchestration, ports и typed results;
- `Infrastructure` — PostgreSQL, HTTP/search clients, files, import, queue и
  другие technical adapters.

`Feature` используется только для отключаемого, заменяемого или независимо
перестраиваемого расширения. `Application` является process shell и composition
boundary, а не четвёртым business layer. Обязательный `Domain` layer и DDD-
обвязка не вводятся.

Главные свойства системы:

- база PostgreSQL приложения является source of truth;
- OpenSearch является optional rebuildable projection;
- Nominatim является внешней интеграцией со своей изолированной PostgreSQL;
- HTTP и worker paths выполняют только cooperative non-blocking I/O;
- тяжёлые GAR import/build операции запускаются только в CLI runtime;
- async delivery имеет at-least-once semantics, поэтому handler обязан иметь
  явную duplicate/idempotency policy там, где повтор опасен.

## Текущее Переходное Состояние

Сейчас `app/src/Bootstrap.php` запускает application-owned Thesis DIC module
для отдельного `HttpRuntime`. Первые vertical slices
`AddressRegistry/FindAddressById`, `AddressRegistry/SearchAddress`, staged
`AddressRegistry/SuggestAddress` и optional `ReverseGeocode` проходят через
transport DTO, MessageBus, capability-owned ports и AMPHP adapters. Suggest
parent orchestration вызывает context/administrative/thoroughfare scenarios только через nested
MessageBus dispatch. Reverse feature получает typed Nominatim location и
переиспользует `SearchAddress` через nested dispatch; его binding и route
подключаются только HTTP composition. `RouterFactory` также содержит
демонстрационные health, legacy Nominatim и OpenSearch routes, напрямую
использующие concrete adapters. Они остаются разрешённым scaffold, но не
являются эталоном для новых business scenarios. Независимые `HttpRuntime`, `CliRuntime` и
`WorkerRuntime` имеют отдельные DIC graphs; worker предоставляет управляемый
run/control/status lifecycle.

Optional OpenSearch search подключается в HTTP composition как decorator core
`SearchAddressPort`. При выключенном feature, пустой выдаче или technical
failure запрос исполняет PostgreSQL reader; cancellation не превращается в
fallback. Feature нормализует raw hits до core `SearchAddressMatch`, поэтому
transport и capability handler не зависят от OpenSearch payload.

До появления остальных parity replacements сохраняются:

- `GET /health/live`, `/health/startup` и `/health/ready` как ранний
  operational contract;
- `POST /api/addresses/find-by-id` как первый текущий business contract;
- `GET /api/addresses/search` как текущий region-aware business contract;
- `POST /api/addresses/suggest` как текущий staged region-aware business
  contract;
- `GET /api/addresses/reverse-geocode` как текущий optional feature contract;
- `GET /geocode` и `GET /search` как временные integration probes;
- typed runtime/integration settings из одного immutable environment snapshot;
- HTTP resource lifecycle через Thesis DIC disposer-ы.

Новый business flow не расширяет эти временные handlers. Он создаётся
вертикальным slice внутри принятого business module и подключается через
runtime composition. Demo routes удаляются только после появления их
контрактного replacement.

## Целевая Структура

```text
app/
├── bin/
│   ├── server.php
│   ├── console
│   └── worker
├── config/
│   ├── di/
│   ├── message_bus_codecs.php
│   ├── message_bus_http.php
│   ├── message_bus_registry.php
│   └── message_bus_worker.php
├── src/
│   ├── Application/
│   │   ├── Http/
│   │   ├── Console/
│   │   ├── Worker/
│   │   └── DependencyInjection/
│   ├── Module/
│   │   └── AddressRegistry/
│   │       ├── MODULE.md
│   │       ├── UserInterface/
│   │       ├── Capability/
│   │       ├── Infrastructure/
│   │       └── Feature/          # optional
│   ├── Database/
│   │   └── Migration/
│   └── SharedKernel/             # только после второго реального потребителя
├── tests/
│   ├── Unit/
│   ├── Scenario/
│   ├── Integration/
│   └── Contract/
└── var/                          # generated/cache, не source
```

Имя первого module принято: `AddressRegistry`, namespace
`App\Module\AddressRegistry`. В нём реализован первый executable scenario
`FindAddressById`; его source-verifiable карточка находится в `MODULE.md`.
Пустые layer directories для будущих slices не добавляются заранее.

`Database/Migration` принадлежит приложению. Имена миграций и SQL показывают
module ownership, но отдельная таблица сама по себе не создаёт новый module.

## Направление Зависимостей

```text
Application shell ──> module public entrypoints + composition
UserInterface     ──> Capability messages/results
Capability        ──> scenario-local ports
Infrastructure    ──> implements Capability ports
Feature           ──> core public contracts / port decoration
Capability        -X-> Infrastructure concrete classes
Core Capability   -X-> Feature internals
```

Разрешённая зависимость всегда направлена к contract owner. Capability владеет
смыслом outbound port, Infrastructure знает этот port и реализует его.
Application знает concrete implementations только в composition root.

Запрещено:

- вызывать другой handler напрямую вместо MessageBus composition;
- передавать AMPHP request/response, SQL row, HTTP payload, OpenSearch document
  или container object через public Capability contract;
- использовать `array<string, mixed>` как стабильный port/message/result shape;
- принимать business decision в controller, SQL adapter или HTTP client;
- помещать business scenario в `Application`, `Config` или generic `Service`;
- создавать `SharedKernel`, `Common`, `Model` или helper layer без второго
  подтверждённого потребителя.

## UserInterface

`UserInterface` владеет transport boundary:

- читает route/query/body/CLI arguments и выполняет transport validation;
- преобразует input в immutable typed MessageBus message;
- добавляет actor, correlation и cancellation context;
- преобразует typed result/expected failure в HTTP/CLI representation;
- не выполняет SQL, внешний HTTP или business ordering для executable
  scenario.

Presenter нужен только когда transport shape отличается от application result.
Простой read endpoint может обойтись без Capability, если нет business rule,
orchestration, cache/transaction policy, reuse или MessageBus lifecycle.

## Capability

Capability является единицей исполняемого поведения. Обычный scenario folder
содержит только необходимые элементы:

```text
Capability/<Area>/<Scenario>/
├── <Scenario>Message.php
├── <Scenario>Handler.php
├── <Scenario>Result.php          # только при осмысленном outcome
└── <Scenario>*Port.php           # только для исходящего contract
```

Правила:

- message и result immutable; обязательные поля non-nullable;
- port говорит на языке use case и возвращает typed objects, scalars,
  `list<TypedObject>`, `null` или empty list;
- capability-to-capability composition идёт через MessageBus context, когда
  важны envelope, interceptors, nested scope, cancellation или diagnostics;
- transaction, retry, cache и async/sync split определяются сценарием, а не
  скрываются внутри adapter-а;
- `Policy`, `Specification`, `Criteria`, `Input`, named collection и отдельная
  domain model не создаются только ради архитектурной формы.

Expected application outcome может быть состоянием result. Exceptions
используются для adapter failure, невозможного состояния и programming error;
transport status/JSON не попадает в Capability exception.

## Infrastructure

Infrastructure отвечает за technical implementation:

- AMPHP PostgreSQL gateways/readers/writers;
- Nominatim и OpenSearch clients;
- GAR XML/COPY import, staging и projection builders;
- MessageBus queue/control adapters;
- files, migrations, logging/telemetry adapters и technical options.

Adapter нормализует raw storage/HTTP/search shape до typed port contract до
возврата в Capability. Infrastructure может решать «как выполнить I/O», но не
«какой следующий business step выбрать».

Concrete dependency без отдельного port допустима для чистого technical
service, если нет независимого business contract или второго adapter-а. Port не
создаётся ради mockability или симметрии каталогов.

## Feature

Feature — optional extension над core module. Предварительно к нему относятся:

- OpenSearch projection/search backend;
- reverse geocode через Nominatim;
- coordinate, Data.Mos и OSM enrichment;
- заменяемые export/read overlays.

Feature может иметь локальные `UserInterface/Capability/Infrastructure`, если
сам владеет workflow, retry/transaction boundary или несколькими adapters.
Thin status/read overlay не получает Message/Handler/Port только ради формы.

Core Capability не знает feature internals. Feature вызывает core public
scenario через MessageBus либо подключается как port decorator/replacement в
composition.

## Process Roots И Lifecycle

Приложение имеет три независимых Thesis DIC root exports:

| Runtime | Ответственность | Допустимый I/O |
| --- | --- | --- |
| `HttpRuntime` | HTTP server, routes, request scopes, health | Только cooperative non-blocking |
| `CliRuntime` | migrations, GAR import, projection build, admin commands | Blocking разрешён для CLI-only adapters |
| `WorkerRuntime` | PostgreSQL queue consumption, per-job MessageBus scope, worker control lifecycle | Только cooperative non-blocking внутри job |

Каждый root получает один immutable environment snapshot и собственный graph.
HTTP resources не импортируются в CLI/worker автоматически. Singleton pools и
clients закрываются disposer-ами root scope; каждый HTTP request и queue job
получает отдельный scoped lifecycle.

`thesis/dic` является application composition engine. MessageBus core сохраняет
neutral resolver/scope contracts и не требует container package. До финального
L5 application владеет DIC modules; L5 добавит в core и worker libraries
готовые default `Thesis\Dic\Module` classes через Composer `suggest`, не делая
DIC обязательной dependency.

## MessageBus И Async Boundary

Приложение использует:

- `romanfedorskij/message-bus-async` для typed query/command/event API,
  registry, interceptors, codecs и PostgreSQL producer/status/cancel;
- `romanfedorskij/message-bus-async-worker` для consumer, job scope,
  heartbeat/recovery, worker control и отдельного worker executable.

Dependency direction остаётся `application -> worker -> core`; core никогда не
ссылается на worker symbols.

Registry собирается по explicit class list в build/fast gate. Runtime читает
compiled artifact и не выполняет filesystem scanning. Durable messages имеют
stable alias, stable binding ID, versioned JSON codec и непрерывную upcaster
chain. PHP serialization не используется.

Application-owned `ApplicationMessageBusModule` компилирует registry до сборки
runtime graph, проверяет каждую handler/context/strategy/interceptor service
reference по explicit service map и экспортирует один `MessageBus`. Все три
roots получают один application-owned codec registry. Если graph содержит
async binding без stable alias и versioned codec либо alias указывает на другой
message class, compilation завершается ошибкой до запуска process.

HTTP, CLI и worker producer-side composition подключает native AMPHP
`PostgresQueueGateway`, `JsonEnvelopeSerializer`, clock и
`QueueExecutionStrategy`. Sync dispatch не обращается к queue; async handler
binding сериализуется только explicit codec-ом и сохраняется в общей таблице
`message_bus__queue_jobs`.

Один root dispatch открывает scope; nested dispatch переиспользует его. Worker
открывает новый scope на каждую job и исполняет только authoritative binding из
queue row. Interceptors остаются единственным cross-cutting pipeline contract;
параллельный middleware API не вводится.

Default sync flow включает application `CorrelationContextInterceptor`. Он
переносит request/operation/job identifiers из текущего fiber context, а
message/correlation identifiers берёт из immutable MessageBus envelope. Nested
dispatch временно заменяет только message metadata и после завершения
восстанавливает parent context.

Следующий `MessageBusTelemetryInterceptor` пишет только bounded code metadata:
event, message class, binding ID, duration и exception class. Message payload,
headers и query data в telemetry log не попадают. Worker boundary заранее
устанавливает `job_id` из queue row, после чего тот же interceptor pipeline
работает без отдельного worker middleware API.

Worker graph содержит реальный `PostgresMessageConsumer`, lease/cancellation
control, PostgreSQL control plane, JSON envelope serializer и
`QueueWorkerRunner`. Каждый job открывает отдельный Thesis scope; scoped worker
передаёт тот же resolver в authoritative binding execution. Текущий baseline с
нулём production async bindings использует пустой explicit codec registry, а
не fallback serialization. `address_async` закреплён за
`postgres/address`, `runtime_async` — за `postgres/runtime`; первый реальный
binding добавляется только вместе со своим capability slice и codec-ом.

Application PostgreSQL schema владеет queue table и семью worker-control
tables. Они создаются обычной target-owned migration, а не runtime DDL из
worker executable. Development Compose держит два opt-in services по одному на
physical queue. Оба входят в control group `geo-registry`; pause/resume/drain/
restart проходят через persisted control commands, а SIGTERM использует
cooperative graceful shutdown.

`app/bin/worker` использует library `WorkerRuntimeProviderInterface` и держит
run/control/status operation целиком внутри application `Dic::run()`. Поэтому
container scope, PostgreSQL pool и disposers живут ровно время CLI operation;
`Dic::build()` и runtime, выведенный за пределы managed lifecycle, запрещены.

At-least-once delivery означает, что crash после business effect и до ack может
повторить handler. Side-effecting scenarios обязаны явно выбрать idempotency,
deduplication или безопасный повтор и покрыть это contract test.

Blocking GAR import и тяжёлый address/OpenSearch rebuild не являются
`address_async` handlers: по принятой runtime boundary они остаются в
`CliRuntime`. Их постановка в queue потребует отдельного process-isolation
решения, а не скрытого запуска blocking adapter внутри AMPHP worker.

## Cancellation И Ошибки

Cancellation передаётся от process/request/job boundary во все долгие AMPHP
operations и scenario ports. Adapter проверяет cancellation на безопасных
границах, но не объявляет отменённой операцию, результат которой в storage
неизвестен.

HTTP/worker код не использует `sleep()`/`usleep()`, PDO, native `pg_*` или
blocking filesystem/network I/O. Исключение допускается только внутри явно
CLI-only adapter-а.

Ошибки разделяются на:

- transport validation — формируется в UserInterface;
- expected capability failure — transport-neutral application contract;
- integration/storage failure — typed Infrastructure exception с безопасным
  сообщением;
- programming/configuration failure — fail-fast при graph/registry bootstrap.

Секреты, DSN credentials, raw external payload и stack trace не возвращаются в
public response и не пишутся в обычный log context.

## Данные И Интеграции

Application PostgreSQL и внутренняя PostgreSQL Nominatim имеют разные lifecycle,
migrations, backup, restore и volumes. Приложение не выполняет migration против
Nominatim database.

Canonical GAR/address tables остаются source of truth. Search/suggest online
path использует rebuildable region-aware read model. C2 перестраивает
`address_search` как LIST-partitioned projection после integrity/parity gate и
атомарно переключает active table.
После определения региона downstream context передаёт `regionCode` явно и не
выполняет nationwide `DISTINCT`; отсутствие региона разрешено только как legacy
HTTP compatibility fallback.

OpenSearch индекс строится из canonical/read-model data и переключается через
versioned physical index + alias только после успешной загрузки и parity check.
OpenSearch outage не меняет canonical state и при принятой optional policy даёт
readiness `degraded`, а не liveness failure. Online decorator выключен по
умолчанию и включается `OPENSEARCH_SEARCH_ENABLED=1`. C3 выполняет build одним
AMPHP CLI process с bounded NDJSON concurrency; HTTP/per-item failure оставляет
старый alias активным. Предыдущие и failed physical indexes удаляются только
отдельной cleanup command с точным подтверждением и active-target guard.

Nominatim используется через application-owned typed client/feature port.
Community Docker image допустим в development; production image/digest и data
update policy являются отдельным deployment решением.

## Configuration И Observability

Environment читается один раз до сборки graph и преобразуется в immutable typed
runtime/integration settings. Feature code не вызывает `getenv()` и не читает
global configuration.

Production logs пишутся JSON Lines в stdout; HTTP scope принимает или создаёт
request/correlation IDs, возвращает их в response headers и изолирует context
между fibers. Единая модель также содержит message/operation/job IDs для
подключения MessageBus и worker roots в A3.
Telemetry является optional module: при его отсутствии graph использует no-op
providers. Liveness проверяет только локальное состояние process; readiness
различает required и optional integrations; startup отражает завершение
необходимой инициализации.

## Проверки Архитектуры

Изменение считается готовым только с пропорциональными executable gates:

- formatter и PHPStan level max со strict rules;
- Testo suites `Unit`, `Scenario`, `Integration`, `Contract`;
- Deptrac rules для направления слоёв и package boundaries;
- компиляция трёх Thesis DIC graphs без внешних сервисов;
- MessageBus registry compile и deterministic artifact check;
- real PostgreSQL/OpenSearch integration для затронутых adapters;
- OpenAPI 3.1 + executable Postman update при изменении HTTP contract.

Тесты зеркалят source ownership. Unit не обращается к live services;
Integration использует ephemeral resources, применяет migrations с нуля и не
читает development volumes или internet.

## Правило Вертикального Slice

Business slice завершается вместе:

1. transport contract и validation;
2. message/handler/result;
3. scenario ports и adapters;
4. composition/registry;
5. migrations или projection changes;
6. unit/scenario/integration/contract tests;
7. OpenAPI/Postman/MODULE.md, если затронут public behavior.

Нельзя заранее создавать пустой module skeleton, абстракции без потребителя или
таблицы без scenario owner. Каждый созданный module получает русскоязычный
`MODULE.md` с каталогом всех executable capabilities/features; документ
обновляется в том же slice, что и поведение.

## Управление Изменениями

Этот документ меняется при изменении layer ownership, runtime roots, package
boundaries, data ownership или обязательных architecture gates. Версия
документа повышается по semver: major — несовместимая boundary, minor — новый
contract/data flow, patch — уточнение без изменения модели.

Implementation progress, временные ограничения и sequencing не дублируются
здесь: они остаются в roadmap. Исследовательские аргументы и rejected options
остаются в migration documents.

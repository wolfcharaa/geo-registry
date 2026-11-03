# Архитектура Geo Registry

- Версия документа: `0.1.0`
- Обновлено: `2026-11-03`
- Статус: `Target architecture; scaffold is transitional`
- План миграции: [migration/implementation-roadmap.md](migration/implementation-roadmap.md)
- Принятые зависимости: [migration/application-libraries.md](migration/application-libraries.md)

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

Сейчас `app/src/Bootstrap.php` вручную собирает один HTTP runtime.
`RouterFactory` содержит демонстрационные health, Nominatim и OpenSearch routes
и напрямую использует concrete adapters. Это разрешённый scaffold, но не
эталон для новых business scenarios.

До появления parity replacement сохраняются:

- `GET /health/live` и `GET /health/ready` как ранний operational contract;
- `GET /geocode` и `GET /search` как временные integration probes;
- `AppConfig` как единый environment object;
- прямой composition в `Bootstrap`.

Новый business flow не расширяет эти временные handlers. Он создаётся
вертикальным slice внутри принятого business module и подключается через
runtime composition. Demo routes удаляются только после появления их
контрактного replacement.

## Целевая Структура

```text
app/
├── bin/
│   ├── server.php
│   └── console
├── config/
│   ├── di/
│   ├── message_bus_registry.php
│   └── message_bus_runtime.php
├── src/
│   ├── Application/
│   │   ├── Http/
│   │   ├── Console/
│   │   ├── Worker/
│   │   └── DependencyInjection/
│   ├── Module/
│   │   └── <AcceptedModule>/
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

Предлагаемое имя первого module — `AddressRegistry`, но gate G1 остаётся
открытым. До принятия имени каталог module и `MODULE.md` не создаются.

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
| `WorkerRuntime` | async queue consumption, job scopes, control lifecycle | Только cooperative non-blocking внутри job |

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

Один root dispatch открывает scope; nested dispatch переиспользует его. Worker
открывает новый scope на каждую job и исполняет только authoritative binding из
queue row. Interceptors остаются единственным cross-cutting pipeline contract;
параллельный middleware API не вводится.

At-least-once delivery означает, что crash после business effect и до ack может
повторить handler. Side-effecting scenarios обязаны явно выбрать idempotency,
deduplication или безопасный повтор и покрыть это contract test.

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
path использует rebuildable region-aware read model; после определения региона
downstream context передаёт `regionCode` явно и не выполняет nationwide
`DISTINCT`.

OpenSearch индекс строится из canonical/read-model data и переключается через
versioned physical index + alias только после успешной загрузки и parity check.
OpenSearch outage не меняет canonical state и при принятой optional policy даёт
readiness `degraded`, а не liveness failure.

Nominatim используется через application-owned typed client/feature port.
Community Docker image допустим в development; production image/digest и data
update policy являются отдельным deployment решением.

## Configuration И Observability

Environment читается один раз до сборки graph и преобразуется в immutable typed
runtime/integration settings. Feature code не вызывает `getenv()` и не читает
global configuration.

Production logs пишутся JSON в stdout и содержат correlation/message/job IDs.
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

# План переноса Geo Registry со Spiral на AMPHP

- Статус: `Architecture agreed; implementation roadmap active`
- Версия документа: `0.2.0`
- Обновлено: `2026-11-01`
- Исходное приложение: `phpfiasgeocoder`
- Целевое приложение: `geo-registry`
- Детальный порядок работ: [implementation-roadmap.md](implementation-roadmap.md)
- Dependency baseline: [application-libraries.md](application-libraries.md)

## Назначение

Документ фиксирует дорабатываемый план переноса бизнес-логики GAR/FIAS-сервиса
со Spiral/RoadRunner на AMPHP. План описывает целевую архитектуру, границы
runtime, состав сценариев, порядок вертикальной миграции, проверку паритета и
открытые решения.

Это не план построчного переноса framework-кода. Переносятся публичные контракты,
бизнес-сценарии, typed models, SQL-семантика и проверенное поведение. Spiral,
RoadRunner и AMPHP остаются деталями соответствующих application/runtime
оболочек.

Концептуальные фазы ниже сохраняют архитектурный контекст. Актуальный порядок
implementation slices, их prerequisites и exit gates определяет отдельный
[roadmap](implementation-roadmap.md).

## Источники Решений

План основан на текущей реализации и следующих архитектурных документах:

- `phpfiasgeocoder/docs/architecture.md` — фактическая карта исходного сервиса;
- `mvdcake/docs/architecture.md` — эталон ownership и прагматичной трёхслойной
  архитектуры;
- `geo-registry/AGENTS.md` — ограничения целевого AMPHP-приложения;
- реализация `romanfedorskij/message-bus` v6 — переносимый MessageBus без
  Spiral-адаптера.

Из архитектуры `mvdcake` принимаются следующие принципы:

- бизнес-модуль является владельцем поведения, данных и изменений;
- внутри модуля основными слоями являются только `UserInterface`, `Capability`
  и `Infrastructure`;
- `Feature` используется для optional/replaceable extension или overlay;
- wiring и runtime composition не считаются четвёртым слоем модуля;
- `Domain`, `Shared`, `Model`, `Policy`, `Input`, `Criteria` и обязательный
  `Result` не создаются ради архитектурной формы;
- миграция выполняется вертикально, законченными scenario slices.

## Текущее Состояние

### Исходное Spiral-приложение

На момент составления плана исходник содержит:

- 176 PHP-файлов в `app/src`;
- 52 файла с прямой зависимостью от Spiral;
- 36 файлов с контрактами или runtime `romanfedorskij/message-bus`;
- 41 файл, использующий Yiisoft DB;
- 11 файлов с нативным `pg_*`;
- 21 миграцию;
- 18 unit-тестов;
- Postman collection с HTTP-контрактами.

Исходное приложение уже отделяет значительную часть сценариев от Spiral.
Основная framework-зависимость сосредоточена в Kernel/Bootloader, HTTP filters и
controllers, Console commands, DI, RoadRunner Jobs/Queue и configuration access.

### Целевое AMPHP-приложение

Целевой проект уже имеет:

- AMPHP HTTP server и router;
- неблокирующие клиенты PostgreSQL, Nominatim и OpenSearch;
- параллельную readiness-проверку;
- Docker development-контур;
- PHPStan, formatter и Testo;
- начальную проверку OpenSearch request factory.

Пока отсутствуют application capabilities, MessageBus, DI container, CLI,
durable workers, миграции GAR, `address_search`, основной HTTP-контракт,
feature composition и документация модуля.

## Лицензионный Gate

Исходный `phpfiasgeocoder` объявлен как `proprietary`, а целевой `geo-registry`
как `MIT`. До копирования исходных файлов необходимо явно подтвердить одно из
решений:

- [ ] право перенести исходный код и опубликовать результат под MIT подтверждено;
- [ ] целевая лицензия будет изменена;
- [ ] перенос выполняется как независимая реализация поведения без копирования
  proprietary source.

Этот gate блокирует массовое копирование, но не блокирует проектирование,
контрактные тесты и независимую реализацию.

## Целевая Архитектура

### Владелец Бизнес-Логики

Предлагаемый основной модуль — `AddressRegistry`.

`Gar` не используется как имя корневого модуля: GAR является главным источником
данных, но модуль также владеет адресным поиском, подсказками, Nominatim,
OpenSearch, Data.Mos и OSM enrichment. `GeoRegistry` остаётся именем приложения,
а `AddressRegistry` точнее описывает бизнес-владение.

Решение о названии пока считается предлагаемым:

- [ ] подтвердить `AddressRegistry`;
- [ ] выбрать другое имя до первого scenario slice.

### Структура Приложения

```text
app/
├── bin/
│   ├── server.php
│   └── console
├── config/
│   ├── di/
│   │   ├── application.php
│   │   ├── address-registry.php
│   │   └── features.php
│   ├── message_bus_registry.php
│   └── message_bus_runtime.php
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
│   │       └── Feature/
│   ├── Database/
│   │   └── Migration/
│   └── SharedKernel/              # только после реального второго потребителя
├── tests/
│   └── Module/
│       └── AddressRegistry/
└── var/
    └── cache/                     # generated, не источник
```

`Application` содержит только process/runtime shell: запуск HTTP server,
Symfony Console, worker bootstrap, logging, graceful shutdown и общую
composition. Бизнес-сценарии в `Application` не размещаются.

`Database/Migration` остаётся проектным каталогом. Имена миграций и SQL должны
показывать ownership `AddressRegistry`, но отдельная таблица не становится
основанием для отдельного модуля.

Новые module-local `Bootstrap` директории не создаются. Простое связывание
описывается в `config/di/*.php`; сложные factories и runtime initialization — в
`Application/DependencyInjection`.

### Три Основных Слоя

#### UserInterface

Содержит HTTP controllers/handlers, CLI commands, request parsing, transport
validation, presenters и mapping ошибок.

Правила:

- transport принимает raw query/body и преобразует их в typed message;
- controller/command не содержит business ordering и не вызывает SQL/HTTP
  adapter напрямую для исполняемого business scenario;
- framework request/response objects не пересекают границу `Capability`;
- presenter используется только когда transport shape отличается от result;
- простой технический read допускается без Capability только при отсутствии
  orchestration, business rules, cache policy и reuse.

#### Capability

Содержит executable scenarios, immutable messages, handlers, scenario-local
ports, typed contracts, orchestration, sync/async split и ожидаемые ошибки.

Правила:

- capability-to-capability composition выполняется через MessageBus context;
- handler другого capability напрямую не внедряется;
- public contract не возвращает SQL row, OpenSearch document, HTTP payload,
  ORM entity или `array<string,mixed>`;
- `Result` создаётся только когда имеет scenario-level смысл;
- обязательные поля остаются non-nullable;
- technical options и adapter results не импортируются из `Infrastructure` в
  message/result contract.

#### Infrastructure

Содержит PostgreSQL adapters, GAR XML import, files, cache, Nominatim/OpenSearch
clients, queue adapters, migration support и technical configuration.

Правила:

- infrastructure реализует ports, которыми владеют capabilities;
- SQL, HTTP/search payload и storage details нормализуются до typed contract;
- infrastructure отвечает «как», но не принимает решение «что делать дальше»;
- concrete dependency допустима для чистого technical service, если отдельный
  port не выражает реальную границу.

### Feature

`Feature` используется для расширений, которые можно отключить, заменить или
пересобрать независимо от PostgreSQL core:

```text
Module/AddressRegistry/Feature/
├── OpenSearch/
├── ReverseGeocode/
├── CoordinateEnrichment/
├── DataMosEnrichment/
└── OsmAddressAliases/
```

Предварительная классификация:

- PostgreSQL address search, suggest, find-by-id, GAR import и build
  `address_search` — core capabilities;
- OpenSearch — replaceable search backend и derived index;
- reverse geocode — executable feature со своим workflow и Nominatim adapter;
- runtime coordinate enrichment — async feature над найденными адресами;
- Data.Mos и OSM aliases — source-specific enrichment features.

Feature может иметь локальные `UserInterface/Capability/Infrastructure`, только
если сам владеет workflow, retries, transaction boundary или несколькими
adapter contracts. Тонкий status/read endpoint не получает Message/Handler/Port
ради формы.

Обратная зависимость запрещена: core `Capability` не зависит от feature
internals. Feature использует public capability contracts или декорирует port в
composition root.

### Направление Зависимостей

```text
Application shell -> module public entrypoints and composition
UserInterface     -> Capability message/result
Capability        -> scenario-local port
Infrastructure    -> implements port
Feature           -> core public contract / port decoration
Core Capability   -X-> Feature internals
```

## Runtime-Границы

AMPHP не является требованием переписать все процессы в асинхронном стиле.
Целевое приложение имеет три runtime с разными ограничениями.

### HTTP Runtime

- AMPHP HTTP server;
- `amphp/postgres` для database I/O;
- `amphp/http-client` для Nominatim/OpenSearch;
- request deadline/cancellation распространяется в long I/O;
- запрещены PDO, `pg_*`, XMLReader parsing, `proc_open`, filesystem scans и
  длительные batch operations внутри request handler;
- sync MessageBus handlers допустимы: AMPHP I/O приостанавливает fiber, а не
  process/event loop.

### CLI Runtime

- Symfony Console;
- миграции, import, rebuild, reindex, status, integrity и diagnostics;
- допускаются PDO, native `pg_*`, XMLReader и дочерние процессы;
- blocking CLI code не переиспользуется внутри HTTP request path;
- команды остаются тонкими entrypoints над capabilities или явно техническими
  maintenance services.

### Worker Runtime

- отдельные процессы с отдельным bootstrap/container;
- durable MessageBus jobs;
- PostgreSQL queue вместо RoadRunner BoltDB;
- foreground report выполняется `QueryHandler`, background processing —
  отдельным `CommandHandler`, возвращающим `void`;
- aliases и binding IDs являются стабильными serialized contracts;
- старые RoadRunner queues должны быть drained до cutover, а не перенесены
  неявно.

### Database Access

Предлагаемая стратегия:

- HTTP/read adapters используют `amphp/postgres`;
- migrations, queue worker и тяжёлые batch operations могут использовать PDO,
  Yiisoft DB и native `pg_*` в отдельных процессах;
- application PostgreSQL остаётся отдельным от внутренней базы Nominatim;
- PostgreSQL `address_search` остаётся source of truth для runtime search;
- OpenSearch остаётся derived/rebuildable index.

## MessageBus

Используется новая package family `romanfedorskij/message-bus-async` и
`romanfedorskij/message-bus-async-worker` без `message-bus-spiral`. Старая v6
остаётся только источником MIT behavioral fixtures до завершения migration gate.

Предлагаемые flows:

```text
default         sync, application-level default
address         sync, core address scenarios
address_async   async PostgreSQL queue, import/rebuild/bootstrap
runtime_async   async PostgreSQL queue, runtime enrichment events
```

Необходимые элементы:

- PSR-11 container;
- compiled registry, создаваемый в check/release flow;
- `config/message_bus_registry.php` для discovery и diagnostics;
- `config/message_bus_runtime.php` для producer/worker runtime;
- schema migration для queue и worker control tables;
- отдельный Compose worker service;
- registry compilation test/gate;
- documented drain/restart procedure при изменении durable bindings.

Принятое решение: новый `message-bus-async` core владеет AMPHP-compatible
PostgreSQL producer/status/cancel API и общей queue schema. Consumer loop,
heartbeat, recovery/control plane и worker CLI вынесены в отдельный
`message-bus-async-worker`. Blocking PDO producer в HTTP runtime запрещён.

## Карта Переноса

### Переносится Почти Без Изменений

- immutable messages и typed contracts;
- capability handlers без framework types;
- `ScenarioException`/`PortException` semantics;
- query parser и чистые ranking/matching rules;
- Postman requests и test cases;
- GAR XML catalog/import logic в CLI runtime;
- SQL и migration semantics;
- stable message aliases и binding IDs, если contract не меняется.

### Адаптируется

- Spiral filters -> transport request parsers;
- Spiral controllers -> AMPHP request handlers;
- `ResponseWrapper` -> application JSON response/presenter;
- `EnvironmentInterface` -> typed config/options;
- Yiisoft DB readers -> AMPHP PostgreSQL adapters для HTTP;
- Spiral Console commands -> Symfony Console commands;
- blocking Nominatim/OpenSearch clients -> AMPHP clients;
- feature bootloaders -> DI definitions/decorators.

### Не Переносится

- Spiral Kernel и Bootloader lifecycle;
- Spiral Tokenizer discovery;
- Spiral request scope;
- RoadRunner HTTP worker configuration;
- RoadRunner Jobs/BoltDB queue adapter;
- Spiral Cache bridge;
- framework-specific filter attribute mapping tests.

## Исправления Границ При Переносе

Перенос не должен закреплять известные архитектурные протечки исходника:

- `ImportGarXml` не должен возвращать или создавать
  `Infrastructure\Import\GarImportOptions`;
- `BuildAddressSearch` не должен возвращать или создавать
  `Infrastructure\Search\AddressSearchBuildOptions`;
- `BootstrapGarHandler` не должен использовать infrastructure result types как
  public capability contract;
- Nominatim/OpenSearch raw arrays не должны выходить за adapter boundary;
- capability exceptions остаются transport-free; HTTP status mapping принадлежит
  `UserInterface`;
- `AppConfig` не должен превращаться в один объект со всеми настройками сервиса:
  configuration делится по runtime/integration responsibility.

## Scenario Inventory

Статусы: `Not started`, `Foundation`, `In progress`, `Parity`, `Done`, `Deferred`.

| Сценарий | Ownership | Runtime | Исходный контракт | Статус |
| --- | --- | --- | --- | --- |
| Health live/ready | Application | HTTP | `/health/live`, `/health/ready` | Foundation |
| FindAddressById | Core Capability | HTTP sync | `gar.address.find_by_id` | Not started |
| SearchAddress | Core Capability | HTTP sync | `gar.address.search` | Not started |
| SuggestAddress | Core Capability | HTTP sync | `gar.address.suggest` | Not started |
| ResolveSuggestionContext | Core Capability | nested sync | `gar.address.resolve_suggestion_context` | Not started |
| SuggestAdministrativeArea | Core Capability | nested sync | `gar.address.suggest_administrative_area` | Not started |
| SuggestThoroughfare | Core Capability | nested sync | `gar.address.suggest_thoroughfare` | Not started |
| ImportGarXml | Core Capability | CLI/worker | `gar.import` | Not started |
| BuildAddressSearch | Core Capability | CLI/worker | `gar.address_search.build` | Not started |
| BootstrapGar | Core Capability | CLI/worker | `gar.bootstrap` | Not started |
| ReverseGeocode | Feature | HTTP sync | `gar.address.reverse_geocode` | Not started |
| AddressCoordinatesResolved | Feature | HTTP producer/worker | `gar.address_coordinates.resolved` | Not started |
| OpenSearch search decorator | Feature | HTTP sync | `SearchAddressPort` decoration | Not started |
| OpenSearch reindex | Feature | CLI | derived index rebuild | Not started |
| Nominatim coordinate enrichment | Feature | CLI/worker | `gar:geocode` | Not started |
| Data.Mos import/enrichment | Feature | CLI | source-specific enrichment | Deferred |
| OSM alias import | Feature | CLI | source-specific enrichment | Deferred |

## Фазы Миграции

### Phase 0 — Решения И Контрактная База

- [ ] закрыть лицензионный gate;
- [ ] подтвердить имя `AddressRegistry`;
- [ ] подтвердить PostgreSQL queue и способ non-blocking enqueue из HTTP;
- [ ] решить, сохраняется ли Yiisoft DB Migration без переписывания миграций;
- [ ] зафиксировать API compatibility policy;
- [ ] сохранить исходную Postman collection и representative response fixtures;
- [ ] определить dataset/fixtures для parity testing.

Готовность: решения записаны в этом документе или отдельных ADR, перенос source
может выполняться без скрытых архитектурных предположений.

### Phase 1 — Документация И Runtime Foundation

- [ ] создать `docs/architecture.md` как source of truth целевого проекта;
- [ ] создать `Module/AddressRegistry/MODULE.md` с версией и scenario catalog;
- [ ] адаптировать Postman collection под целевой base URL;
- [ ] добавить PSR-11 container и DI definitions;
- [ ] разделить HTTP, Console и Worker bootstrap;
- [ ] добавить generic MessageBus и registry compilation;
- [ ] добавить единый error mapping и transport request parsing;
- [ ] добавить checks для architecture direction и registry compile.

Готовность: приложение стартует, registry компилируется, health endpoints и
console работают через новую composition без бизнес-сценариев в bootstrap.

### Phase 2 — Database Foundation И Первый Vertical Slice

- [ ] перенести/подключить migrations;
- [ ] подготовить `address_search` fixture или restore path;
- [ ] перенести typed address documents и presenter;
- [ ] реализовать `FindAddressById` целиком:
  `HTTP -> Message -> Handler -> Port -> Amp PostgreSQL Adapter -> Result`;
- [ ] перенести focused tests и Postman request;
- [ ] сравнить Spiral и AMPHP response на одном dataset.

Готовность: первый business endpoint достиг contract parity без Spiral types и
blocking I/O в HTTP path.

### Phase 3 — PostgreSQL Search И Suggest

- [ ] перенести query parser;
- [ ] реализовать PostgreSQL `SearchAddress`;
- [ ] реализовать staged `SuggestAddress` и nested capabilities;
- [ ] перенести scenario/port error mapping;
- [ ] перенести parser, handler, presenter и HTTP tests;
- [ ] выполнить Postman parity без OpenSearch.

Готовность: PostgreSQL backend полностью обслуживает основной HTTP API и остаётся
корректным fallback.

### Phase 4 — Optional Search И Geocode Features

- [ ] подключить OpenSearch как decorator над core search port;
- [ ] перенести mapping/query/ranking logic;
- [ ] отдельно согласовать bulk streaming/retry/auth design до reindex;
- [ ] реализовать reverse geocode как feature-local executable flow;
- [ ] распространить request cancellation/deadlines;
- [ ] проверить fallback OpenSearch -> PostgreSQL и Nominatim -> typed error/result.

Готовность: feature можно отключить без изменения core capability, а parity
подтверждён для enabled и disabled modes.

### Phase 5 — CLI И Data Pipelines

- [ ] добавить Symfony Console application;
- [ ] перенести status/releases/integrity/index maintenance commands;
- [ ] перенести GAR XML import в blocking CLI/worker runtime;
- [ ] перенести build/shadow rebuild `address_search`;
- [ ] перенести OpenSearch blue-green reindex;
- [ ] заменить дочерний `php app.php` на новый console entrypoint;
- [ ] проверить cleanup/recovery при частичном failure;
- [ ] добавить smoke-команды для import -> build -> search.

Готовность: полный dataset можно подготовить без Spiral/RoadRunner, HTTP process
не выполняет blocking batch work.

### Phase 6 — Durable Workers И Runtime Enrichment

- [ ] создать PostgreSQL queue/worker-control schema;
- [ ] добавить Compose worker services;
- [ ] перенести `address_async` и `runtime_async` bindings;
- [ ] реализовать non-blocking HTTP producer или утверждённую альтернативу;
- [ ] проверить retry, idempotency, stale recovery и graceful shutdown;
- [ ] описать drain/restart/cutover процедуру;
- [ ] опустошить RoadRunner BoltDB queues перед переключением.

Готовность: async contracts выполняются без RoadRunner, registry producer/worker
собран из одной revision, старые jobs не потеряны молча.

### Phase 7 — Дополнительные Enrichment Features

- [ ] перенести Data.Mos import/enrichment при подтверждённой необходимости;
- [ ] перенести OSM aliases import;
- [ ] проверить rebuild/reindex implications;
- [ ] добавить feature-local documentation и tests.

Готовность: дополнительные источники не расширяют core contracts своими raw
fields и могут быть отключены независимо.

### Phase 8 — Cutover И Cleanup

- [ ] выполнить полный Postman/smoke parity run;
- [ ] сравнить representative search/suggest/find/reverse responses;
- [ ] проверить rebuild/reindex на production-like dataset;
- [ ] проверить readiness, shutdown, worker drain и restart;
- [ ] удалить временные demo `/geocode` и `/search` routes;
- [ ] удалить migration bridges и stale Spiral references;
- [ ] обновить README и operator documentation;
- [ ] зафиксировать решение о decommission исходного runtime.

Готовность: AMPHP-сервис является единственным runtime для согласованного scope,
а все deferred gaps перечислены явно.

## Verification Matrix

Для каждого vertical slice обязательны пропорциональные проверки:

| Boundary | Минимальная проверка |
| --- | --- |
| Capability | handler behavior, composition, result/error semantics |
| MessageBus | registry compile, alias/binding stability, handler signature |
| Port | typed contract и mapping adapter failure |
| PostgreSQL adapter | SQL integration на fixture/schema |
| HTTP | request validation, status/error mapping, response contract |
| CLI | option validation, dry-run, failure/cleanup behavior |
| Worker | enqueue, consume, retry, idempotency/recovery, shutdown |
| Feature | enabled/disabled behavior и core fallback |
| Public API | Postman request и Spiral/AMPHP parity fixture |

Общие checks целевого проекта:

```text
composer validate --strict
composer check
message-bus registry compile
compose config/doctor
focused integration tests
HTTP smoke/Postman parity
```

## Документация Кода

Целевая документация не должна превращаться в копию folder tree или набор
docblock-комментариев.

### `docs/architecture.md`

Содержит устойчивые правила: ownership, слои, dependency direction, runtime
границы, MessageBus, Feature, database source of truth и правила vertical
migration. Не содержит текущий процент готовности.

### `Module/AddressRegistry/MODULE.md`

Русскоязычный versioned catalog фактически реализованных capabilities/features.
Для каждого сценария описывает цель пользователя/оператора, правила, вход,
observable outcome, route/CLI/MessageBus entrypoint и ссылку на Postman request.
Файл обновляется вместе с изменением контракта.

### Этот План

Содержит этапы, статусы, открытые решения, migration debt и cutover gates.
Документ изменяется по мере работы и не заменяет постоянную архитектурную
документацию.

### ADR

Отдельные ADR создаются только для решений с реальными альтернативами и
последствиями. Предварительный список:

- HTTP/CLI/Worker runtime boundaries;
- MessageBus и PostgreSQL queue;
- database access: AMPHP vs PDO/native pgsql;
- licensing/provenance;
- OpenSearch bulk/retry/auth;
- data migration/cutover strategy.

### Postman

Postman collection остаётся executable HTTP contract. `MODULE.md` ссылается на
requests, но не дублирует большие JSON-примеры. При изменении HTTP contract код,
tests, module knowledge и Postman обновляются вместе.

## Открытые Решения

| Решение | Предлагаемый вариант | Статус |
| --- | --- | --- |
| Основной модуль | `AddressRegistry` | Proposed |
| Лицензия | подтвердить право на MIT-перенос | Blocking |
| DI container | `thesis/dic ^0.5.3`, три runtime roots | Accepted |
| MessageBus | новые `message-bus-async` и `message-bus-async-worker` 0.1.x | Accepted |
| Async storage | PostgreSQL queue, at-least-once | Accepted |
| HTTP enqueue | AMPHP-compatible producer в core package | Accepted |
| Migrations | сохранить Yiisoft DB Migration и 21 migration | Accepted |
| HTTP database | `amphp/postgres` | Accepted by current scaffold |
| Batch database | PDO/Yiisoft DB/native `pg_*` только вне HTTP | Accepted |
| Cache | добавить после переноса корректного uncached flow | Proposed |
| OpenSearch bulk | async CLI, bounded streaming, blue-green alias switch | Accepted baseline |
| Data cutover | dumps/restore против full re-import | Open |
| Data.Mos/OSM | перенос после core parity | Deferred |

## Definition Of Done

Миграция согласованного scope завершена, когда:

- код организован вокруг владельца `AddressRegistry` и трёх основных слоёв;
- нет Spiral/RoadRunner runtime dependencies;
- HTTP path не выполняет blocking I/O;
- MessageBus registry компилируется как release artifact;
- sync/async contracts и aliases стабильны;
- PostgreSQL остаётся source of truth, OpenSearch — derived feature;
- основной HTTP API достиг contract parity;
- CLI data pipeline и workers имеют recovery/verification path;
- `MODULE.md`, Postman и архитектурная документация соответствуют коду;
- licensing и data cutover decisions закрыты;
- все deferred возможности перечислены, а не потеряны неявно.

## История Документа

### 0.2.0 — 2026-11-01

- добавлены ссылки на принятый dependency baseline и implementation roadmap;
- технические open decisions синхронизированы с 27 принятыми решениями;
- AMPHP PostgreSQL producer и отдельный worker package закреплены как baseline;
- концептуальные фазы отделены от исполнимого порядка slices.

### 0.1.0 — 2026-11-01

- создан исходный план;
- применена module ownership модель из `mvdcake`;
- зафиксирована трёхслойная архитектура внутри `AddressRegistry`;
- разделены HTTP, CLI и Worker runtime;
- добавлены scenario inventory, phases, gates и open decisions.

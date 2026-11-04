# Roadmap реализации Geo Registry на AMPHP

- Статус: `Active; C3 OpenSearch rebuild complete, W1 application worker runtime next`
- Версия документа: `0.56.0`
- Обновлено: `2026-11-04`
- Архитектурный план: [spiral-to-amphp.md](spiral-to-amphp.md)
- Dependency baseline: [application-libraries.md](application-libraries.md)

## Назначение

Этот документ превращает согласованную архитектуру и dependency baseline в
последовательность небольших, проверяемых implementation slices. Он является
источником истины для порядка работ, зависимостей между slices, migration gates
и критериев готовности.

Roadmap не разрешает автоматически копировать proprietary-код, публиковать
пакеты, менять внешние repositories или выполнять data cutover. Такие действия
начинаются только после соответствующего gate и отдельного запроса на реализацию.

## Проверенная Точка Старта

### Целевой Репозиторий

В `geo-registry` уже есть AMPHP HTTP scaffold, один composition root,
PostgreSQL/Nominatim/OpenSearch clients, demo routes, Docker development stack,
одна Testo suite и один unit test. Пока отсутствуют бизнес-модуль,
`message-bus-async`, Thesis DIC, Symfony Console, migrations, OpenAPI/Postman,
architecture gates, integration profile и worker runtime.

### Исходное Приложение

`phpfiasgeocoder` содержит четыре публичных address routes, 21 migration, 18
PHPUnit tests, executable Postman collection и сценарии поиска, подсказок,
импорта, построения read model, reverse geocode и enrichment. Его Composer
package имеет лицензию `proprietary`; владелец исходного кода отдельно разрешил
перенос всех 21 migration и публичных Postman/response contracts в MIT
repository. Разрешение не распространяется на implementation code.

### Исходная MessageBus

`/Users/romanfedorskij/project/www/library/MessageBus` находится на clean release
`v6.2.3`. Это источник проверенного поведения и migration fixtures, но не место
для переписывания истории старого package. Новые packages начинают отдельную
line с `0.1.0`; Git repository topology определяется gate G4:

- `romanfedorskij/message-bus-async`, namespace `Fedor\MessageBus`;
- `romanfedorskij/message-bus-async-worker`, namespace
  `Fedor\MessageBus\Worker`.

## Неподвижные Правила Реализации

1. Миграция идёт вертикальными slices: transport, capability, port, adapter,
   composition, tests и документация завершаются вместе.
2. Новый application-код живёт внутри принятого business module
   `Module/AddressRegistry` и следует направлению
   `UserInterface -> Capability <- Infrastructure`.
3. `Application` содержит только process shell и composition; business flow там
   не размещается.
4. HTTP runtime использует только non-blocking I/O и передаёт cancellation в
   долгие операции. XMLReader, PDO, native `pg_*` и тяжёлые batch jobs остаются
   в CLI runtime.
5. PostgreSQL является source of truth; OpenSearch — отключаемая и полностью
   перестраиваемая projection.
6. Durable messages имеют stable alias, versioned JSON codec и upcasters. PHP
   `serialize()` не используется в новом write path.
7. Slice не считается завершённым без пропорциональных Testo tests, graph/
   registry checks и обновления OpenAPI/Postman/MODULE.md, когда меняется
   публичное поведение.
8. Старые demo routes удаляются только после появления parity replacement.
9. Старый MessageBus и RoadRunner queues не объявляются архивными до migration
   gate и явного drain/cutover.

## Правило Parity-First Для MessageBus

`romanfedorskij/message-bus` v6.2.3 является behavioral specification для всех
уже реализованных и проверенных временем возможностей. Новая package line не
перепроектирует их через повторные раунды вариантов.

1. Существующий public behavior сначала переносится из v6 source, tests и docs;
   namespace, package layout и composition adapters меняются отдельно.
2. Уже принятые отклонения берутся из
   [application-libraries.md](application-libraries.md) и не обсуждаются заново.
3. Новое решение открывается только при подтверждённом конфликте с AMPHP,
   neutral lifecycle/scopes, разделением `core -> worker`, PHP 8.4 либо новым
   durable compatibility требованием.
4. Каждое отклонение от v6 фиксирует driver, compatibility impact и executable
   migration test. Отсутствие такого delta означает сохранение v6 semantics.
5. До завершения parity reconciliation текущий код L2 compiler/artifact является
   pre-release scaffold, а не новым окончательным контрактом.

### Наследуется Из v6 Без Нового Design Round

- attribute discovery по explicit class list и repeatable handler attributes;
- flow model, handler cardinality, primary selection и invocation modes;
- staged registry compilation, structured diagnostics и validation rules;
- compiled registry definition, dumper/writer/loader lifecycle;
- alias/binding identity rules: обязательность для durable async boundary и
  допустимая auto identity для локального sync binding;
- interceptor pipeline, envelope metadata, nested dispatch и fan-out behavior;
- queue state/retry/status/cancel/control contracts и их behavioral fixtures.

### Уже Принятые Осознанные Отклонения

- packages `message-bus-async` и `message-bus-async-worker`, namespace
  `Fedor\MessageBus`, PHP `^8.4.12`;
- neutral resolver/scope/disposal вместо обязательного PSR-11 в core;
- AMPHP cancellation и non-blocking PostgreSQL producer;
- worker operational runtime вынесен в optional package;
- explicit versioned JSON codecs/upcasters вместо reflection serializer и PHP
  serialization в новом durable write path;
- segregated query/command/event interfaces с composite facade.

## Execution Gates

| Gate | Что должно быть подтверждено | Что блокирует | Статус |
| --- | --- | --- | --- |
| G0 Provenance | Право переноса под MIT либо clean-room реализация только по наблюдаемым контрактам | Копирование исходных PHP/SQL/test файлов | `Accepted` |
| G1 Module name | `AddressRegistry` принят как окончательное имя | Создание module namespace и `MODULE.md` | `Accepted` |
| G2 Contract baseline | Зафиксированы Postman collection, OpenAPI draft, representative responses и ошибки | Первый HTTP parity slice | `Accepted` |
| G3 Parity dataset | Определён маленький deterministic PostgreSQL dataset с регионами и hierarchy cases | Search/Suggest parity | `Accepted` |
| G4 Package workspace | Два соседних Git repository: `MessageBusAsync` и `MessageBusAsyncWorker`; history v6 не изменяется | Реализация MessageBus 0.1.0 | `Accepted` |
| G5 Cutover data | Выбраны full re-import либо проверенный dump/restore path | Production cutover | `Deferred` |

За пределами явно разрешённых 21 migration и публичных HTTP contract artifacts
G0 продолжает требовать отдельного подтверждения либо clean-room реализацию.
Доступность proprietary implementation локально сама по себе не разрешает
перенос остальных исходных файлов.

## Карта Зависимостей

```text
G4 ──> L1 MessageBus core ──> L2 registry/codecs ──> L3 async producer
                                                    └── L4 worker package
L2 + L4 ──> L5 final Thesis DIC modules (deferred package polish)
G1 ──> A1 quality/docs ──> A2 DIC/runtime roots ──> A3 bus integration
G0 ──> D1 migration continuity
D1 + G3 ──> D2 parity fixtures

G1 + G2 + L2 + A3 + D2
  └── V1 FindAddressById ──> V2 PostgreSQL Search ──> V3 Suggest
                               ├── V4 ReverseGeocode
                               └── V5 OpenSearch search

D1 ──> C1 GAR import ──> C2 regional read model ──> C3 OpenSearch rebuild
L3 + L4 + A3 ──> W1 durable runtime ──> W2 coordinate enrichment

V1–V5 + C1–C3 + W1–W2 ──> R1 release parity ──> R2 cutover
```

Library, application foundation и database fixture tracks можно вести
параллельно после закрытия их собственных gates. G0 application provenance не
блокирует новую MIT MessageBus library. Business slices не начинаются до
готовности MessageBus registry/composition и соответствующей data fixture.

## Принятые Gate-Решения

### G0 — Provenance Для Истории Схемы

- User answer: `2026-11-04` — подтверждено право прямого переноса всех 21
  migration из proprietary `phpfiasgeocoder` в MIT `geo-registry` с сохранением
  PHP, SQL и FQCN.
- Accepted scope: только migration source и необходимая совместимость с текущим
  Yiisoft API; это решение не распространяется автоматически на остальной код,
  tests и документацию исходного приложения.
- State: 21 migration перенесена с исходными identifiers и SQL semantics;
  compatibility edits ограничены typed-вызовом quoter и проверкой строковых
  metadata keys, генерируемый SQL не изменён.

### G1 — Module Name

- User answer: `2026-11-04` — «вариант А».
- Accepted: business module и PHP namespace называются `AddressRegistry` и
  `App\Module\AddressRegistry`.
- Boundary: `GeoRegistry` остаётся именем приложения, GAR — главным источником
  данных, но не именем business owner.
- State: создан `Module/AddressRegistry/MODULE.md` версии 0.1.0 без пустых layer
  directories и вымышленных scenario cards; executable behavior пока отсутствует.

### G2 — Executable HTTP Contract Baseline

- User answer: `2026-11-04` — принят вариант А: прямой перенос разрешённых
  публичных Postman/response contracts без переноса implementation code.
- Accepted: OpenAPI 3.1 YAML является structural source, sanitized Postman v2.1
  collection — executable companion, а synthetic environment использует G3
  fixture вместо исходных имён, IDs и координат.
- Boundary: health operations, `FindAddressById`, `SearchAddress` и
  `SuggestAddress` отражают текущий AMPHP runtime; остальные address operations
  помечены `planned` до своего V-slice.
- Gate: Contract suite проверяет parsing, schema references, response examples,
  operationId/method/path coverage и отсутствие legacy/secret values.
- Artifact: [HTTP contract baseline](../api/http-contract-baseline.md).

### G3 — Target-Owned Parity Dataset

- Accepted: synthetic fixture v1 с регионами 77/78, одинаковыми названиями
  района, города, улицы и дома, а также `adm`/`mun` hierarchy variants.
- State: V3 поднял fixture до v2, добавив municipal projection documents для
  региона, района и города; UUID/ID synthetic identity сохранена.
- Coverage: stable UUID lookup, active/inactive selection, regional path,
  nationwide ambiguity, search documents и deterministic empty/not-found data.
- Artifact: [parity-fixture.md](parity-fixture.md), SQL source с закреплённым
  SHA-256 и real PostgreSQL integration test.
- Boundary: malformed input относится к G2 transport contract и не кодируется
  искусственной строкой в database fixture.

### G4 — Package Workspace

- User answer: `2026-11-01` — «вариант А».
- Accepted: два независимых соседних Git repository:
  `/Users/romanfedorskij/project/www/library/MessageBusAsync` и
  `/Users/romanfedorskij/project/www/library/MessageBusAsyncWorker`.
- State: оба repository инициализированы на пустой ветке `main`; commits,
  remotes и package files пока не создавались.
- Invariant: `/Users/romanfedorskij/project/www/library/MessageBus` остаётся на
  clean `release/v6.2.3` и используется только как read-only behavioral source.

### L1.1 — PHP Platform Baseline

- User answer: `2026-11-01` — «вариант А».
- Accepted: core и worker packages требуют PHP `^8.4.12`; разные compatibility
  matrix для двух packages не вводятся.
- State: в обоих repository созданы Composer metadata, MIT license, namespace
  roots и pre-release README. Runtime API и dependencies пока не объявлены сверх
  реализованного scaffold.
- Consequences: implementation может использовать PHP 8.4 language baseline;
  Geo Registry и Thesis DIC проверяются на той же минимальной patch version.

### L1.2 — Message Kind И Query Result Typing

- User answer: `2026-11-01` — «вариант А».
- Accepted: immutable payload реализует один из marker-интерфейсов
  `Query<TResult>`, `Command` или `Event`; query result связывается с message
  через PHPDoc generic для static analysis.
- State: markers реализованы в `Fedor\MessageBus\Message`; container,
  transport, aliases, versions и execution behavior в них не добавлены.
- Consequences: public bus lanes могут принимать точный message kind, а
  `QueryBusInterface::ask()` — возвращать связанный `TResult`; runtime registry
  по-прежнему отвечает за handler binding и diagnostics.

### L1.3 — Root Execution Control

- User answer: `2026-11-01` — «вариант А».
- Accepted: `ask/dispatch/publish` получают optional immutable
  `ExecutionOptions`; он несёт AMPHP `Cancellation` и immutable scalar headers.
  Timeout выражается `Amp\TimeoutCancellation`, отдельная deadline abstraction
  не вводится.
- State: `ExecutionOptions` реализован в core package, `amphp/amp ^3.1` добавлен
  как прямая dependency. Default использует `NullCancellation`; некорректные
  header names/value types отклоняются при создании options.
- Consequences: root caller может отменить I/O, internal context создаёт только
  bus implementation, а nested executions наследуют cancellation/headers без
  передачи mutable context со стороны application.

### L1.4 — Event Fan-Out Result

- User answer: `2026-11-01` — «вариант А».
- Accepted: `EventPublisherInterface::publish()` возвращает aggregate
  `PublishResult` с per-binding local/queued executions и structured failures.
  Если хотя бы один binding не опубликован, выбрасывается `PublishFailed`,
  сохраняющий partial result.
- State: реализованы `PublishedExecution`, `PublishedExecutionMode`,
  `PublishFailure`, `PublishResult`, `PublishFailed` и узкие
  `QueryBusInterface`, `CommandBusInterface`, `EventPublisherInterface` вместе с
  composite `MessageBusInterface`.
- Consequences: успешные queue IDs доступны caller-у, partial fan-out нельзя
  проигнорировать случайно, а application services зависят от минимального bus
  interface.

### L1.5 — Handler Signature И Nested Context

- User answer: `2026-11-01` — «вариант А».
- Accepted: core поддерживает contextless `__invoke(Message)` и orchestration
  `__invoke(Message, MessageContextInterface)`. Registry compiler определяет и
  проверяет invocation mode один раз; runtime reflection discovery запрещён.
- State: read-only `MessageContextInterface` реализован как composite bus
  contract и предоставляет cancellation, message/correlation/causation IDs и
  immutable headers. Создание context остаётся обязанностью bus implementation.
- Consequences: простой handler не получает лишнюю dependency, nested flow
  сохраняет causation/cancellation, а constructor dependency на concrete bus не
  создаёт cycle.

### L1.6 — Envelope Identity И Causation

- User answer: `2026-11-01` — «вариант А».
- Accepted: library создаёт immutable envelope через replaceable
  `MessageIdGeneratorInterface`. Root получает новый `messageId` и внешний либо
  сгенерированный `correlationId`; nested execution получает новый `messageId`,
  наследует correlation и использует parent `messageId` как causation.
- State: реализованы `Envelope`, portable immutable `Headers`, `EnvelopeFactory`
  и native `RandomMessageIdGenerator` на 128-bit `random_bytes()`. Durable codec
  сможет восстановить IDs прямым созданием envelope.
- Consequences: OpenTelemetry IDs не заменяют message identity, application не
  генерирует технические IDs, а binding/flow metadata не добавлены в envelope до
  проектирования registry.

### L1.7 — Interceptor Pipeline

- User answer: `2026-11-01` — «переносим существующий interceptor, новое не
  пишем».
- Accepted: сохраняются `InterceptorInterface` и `PipelineInterface`; отдельные
  `MiddlewareInterface`/`Next` не вводятся. Generic pipeline оборачивает handler
  для query, command и event одинаково.
- State: реализован per-execution `Pipeline<TResult>` из заранее разрешённых
  interceptor instances и terminal callable. Он не зависит от container,
  registry classes или старых container exceptions и не позволяет выполнить
  terminal handler повторно.
- Consequences: logging, tracing, validation, cache и transaction behavior
  остаются interceptors; registry compiler определяет их порядок, а context
  предоставляет immutable envelope.

### L1.8 — Neutral Service Resolution И Scope Lifecycle

- User answer: `2026-11-01` — «вариант А».
- Accepted: compiled binding хранит `ServiceReference<T>`, core использует
  `ServiceResolverInterface`, а root execution открывает
  `ServiceScopeInterface` через factory. Nested executions переиспользуют scope;
  disposal гарантируется в `finally`.
- State: реализованы typed service reference, resolver/scope/factory contracts и
  `ServiceScopeRunner`. Core не зависит от Thesis DIC или PSR-11; resolver не
  экспортируется через message context.
- Consequences: Thesis integration адаптирует native scoped providers, handlers
  и interceptors разрешаются в текущем execution scope, а lifecycle failure не
  оставляет scope без disposal.

### L2.1 — Registry Declaration Source

- User answer: `2026-11-01` — «вариант А».
- Accepted: сохраняются compile-time attributes `MessageAlias`, `QueryHandler`,
  `CommandHandler` и `EventSubscriber`. Compiler получает явный список message и
  handler classes; Composer/PSR-4 и filesystem scanning не используются.
- State: декларативные attributes перенесены в core без зависимости от готовых
  binding objects и registration provenance. Публичный аргумент атрибутов
  сохраняет имя v6 `middleware`, но значения компилируются в существующие
  `InterceptorInterface`; второй runtime middleware contract не вводится.
- Consequences: reflection допустим только при compilation по входному class
  list; runtime читает уже скомпилированный registry и не выполняет discovery.
  Kind, signature, aliases, binding IDs и interceptor types валидируются
  compiler-ом с агрегированными diagnostics.

### L2.2 — Compiled Registry Artifact

- User answer: `2026-11-01` — «вариант А».
- Accepted: compiler генерирует deterministic PHP manifest, который возвращает
  только scalar arrays. Top level содержит `schemaVersion`, SHA-256 `checksum` и
  registry payload; timestamp и runtime compilation fallback отсутствуют.
- State: реализованы immutable `CompiledRegistry`/`HandlerBinding`, schema v1,
  deterministic dumper, atomic writer и loader. Payload хранит aliases, message
  kind, handler/interceptor `ServiceReference`, flow, priority, primary и
  invocation mode; flow registry и delivery overrides проходят artifact
  round-trip. Schema/checksum wrapper остаётся provisional scaffold до переноса
  точного v6 definition/dumper/loader lifecycle.
- Consequences: одинаковый registry даёт побайтно одинаковый OPcache-friendly
  PHP artifact. Checksum обнаруживает повреждение и ручной рассинхрон, но не
  является подписью; загружаемый через `require` artifact обязан быть trusted
  build output. Runtime reflection/discovery fallback не добавляется.

### L2.3 — Superseded Strict Identity Experiment

- User answer: `2026-11-01` — «вариант А».
- Historical experiment: каждый message обязан иметь один явный unique `MessageAlias`, а
  каждый handler binding — явный unique `bindingId`. Identity из PHP class/method
  не генерируется. Compiler завершает весь explicit class-list pass, собирает
  diagnostics и не создаёт registry при наличии хотя бы одной ошибки.
- State: реализованы `RegistryCompiler`, structured diagnostic codes/severity,
  source location, binding/alias context, repair suggestion,
  `RegistryCompilationResult` и strict `RegistryCompilationFailed`. Compiler
  проверяет marker kind, attributes, signature/context/return types,
  interceptors, query cardinality и command primary selection.
- Consequences: rename/refactoring PHP classes не меняет durable identity;
  missing/duplicate declarations не скрывают соседние ошибки. Один command
  handler становится primary автоматически, если явно не отключён; несколько
  handlers требуют ровно один `primary: true`. Runtime получает уже полностью
  проверенный graph и не повторяет Reflection validation.

### L2.R — Parity Reconciliation Correction

- User correction: `2026-11-02` — не переобсуждать и не перепроектировать
  решения, уже реализованные и проверенные в v6.
- Supersedes: L2.1–L2.3 считаются implementation experiments до сверки с v6
  fixtures. Выбор explicit codecs не переоткрывается: он уже принят как
  Decision 15.2 в dependency baseline.
- Required correction: вернуть v6 flow/compiler/diagnostic semantics, включая
  alias/binding requirements только на durable boundary и auto identity для
  допустимых sync bindings; затем перенести v6 artifact lifecycle и contract
  tests. Новые hardening-поля разрешены только как явно обоснованный additive
  delta после parity.

### L2.R1 — Flow, Identity, Delivery И Cardinality Parity

- State: реализовано и проверено executable fixture-ом `2026-11-02`.
- Restored from v6: `FlowDefinition`/`FlowRegistry`, sync/async flow validation,
  global middleware, transport/queue и delivery defaults/overrides.
- Identity: локальные sync bindings допускают отсутствие `MessageAlias` и
  `bindingId` и получают deterministic `auto.*`; async bindings требуют stable
  `bindingId`, а их messages — `MessageAlias`.
- Discovery: message metadata может быть получена из handler attribute без
  повторного добавления message class в explicit class list.
- Cardinality: async query запрещён; один sync command без явного выбора
  становится primary, а несколько sync command handlers требуют ровно один
  явный primary.
- Artifact: flow registry и per-binding delivery settings сохраняются при
  dump/load round-trip.
- Verified: PHP syntax, PHPStan level max и behavioral fixture для flow,
  identity, delivery, cardinality и artifact round-trip.

Остаток L2 parity reconciliation переносится без нового design round:

- [x] Восстановить v6 flow, sync/async identity, delivery и cardinality.
- [x] Перенести diagnostic model, stage values и project validation rules v6.
- [x] Перенести применимую diagnostic fixture matrix v6 для aliases, bindings,
  cardinality, message kinds, handler/interceptor signatures и flows.
- [x] Перенести actual v6 early-exit compiler graph и partial graph-context
  fixtures без facade stage mapping.
- [x] Перенести registration owner/source metadata v6.
- [x] Перенести custom flow context/factory contract с AMPHP cancellation вместо
  worker runtime dependency.
- [x] Перенести local execution request/result contracts и
  `SequentialExecutionStrategy` с neutral service resolution.
- [x] Перенести producer `QueueExecutionStrategy` в core; consumption/process
  control остаётся в worker package.
- [x] Восстановить `MessageRegistryDefinition`, VarExporter dumper, atomic writer,
  compiled registry hydration и runtime loader lifecycle v6.
- [x] Прогнать полный перенесённый v6 registry/compiler fixture set.

### L2.R2 — Diagnostic Contracts, Definition И Artifact Lifecycle

- State: реализовано и проверено `2026-11-02`.
- Diagnostics: возвращены v6 severity/origin/target/hint, stage values, typed
  compilation exception, warning policy и project validation rules. Actual
  early-exit compiler graph и partial graph context остаются в следующем gate;
  facade stage mapping не объявляется полным compiler parity.
- Definition: возвращены schema/library version, generation time, source hash,
  flows, message index, bindings, aliases и reverse message names.
- Artifact lifecycle: `symfony/var-exporter`, atomic file writer,
  `CompiledMessageRegistry::fromFile()` и `RegistryRuntimeLoader` с v6
  `preferCompiled`/`requireCompiled` semantics заменили provisional
  checksum-wrapper.
- Accepted adaptation: compiled handler/interceptor references остаются typed
  `ServiceReference`; owner/source metadata проходит binding round-trip.
- Verified: Composer validation, PHPUnit `7 tests / 50 assertions`, PHP syntax и
  PHPStan level max по `src + tests`.

### L2.R3 — Actual Compiler Stage Graph

- State: реализовано и проверено `2026-11-02`.
- Compiler выполняет отдельные early-exit stages `HandlersDiscovered`,
  `AliasesHydrated`, `BindingsNormalized` и `CoreValidated`; facade больше не
  выводит stage из diagnostic code.
- `RegistryCompilationGraphContext` сохраняет partial
  `HandlerBindingDefinition` graph, aliases, reverse names и flows на точке
  остановки.
- `MessageRegistryDefinition` хранит нормализованные compile-time bindings, а
  runtime `CompiledRegistry` получает typed `ServiceReference` только после
  успешной core validation.
- Verified: PHPUnit `8 tests / 64 assertions` и PHPStan level max по
  `src + tests`; PHPStan `2.3.4` закреплён как package-owned dev dependency и
  запускается воспроизводимо через `composer analyse`.

### L2.R4 — Custom Flow Context И Factory

- State: реализовано и проверено `2026-11-02`.
- `FlowDefinition` снова хранит `contextInterface` и `contextFactory`, включая
  v6 inference default factory и deterministic artifact round-trip.
- `MessageContextFactoryInterface` сохраняет v6 extension point, но вместо
  worker runtime control получает AMPHP `Cancellation`; heartbeat/process
  control не попали в core.
- `DefaultMessageContext` остаётся read-only nested facade и объединяет parent и
  nested cancellation через `CompositeCancellation`.
- Compiler проверяет context/factory contracts и flow-specific context handler
  signature на `CoreValidated` stage.
- Verified: PHPUnit `11 tests / 81 assertions` и PHPStan level max по
  `src + tests`.

### L2.R5 — Local Sequential Execution Strategy

- State: реализовано и проверено `2026-11-02`.
- Возвращены v6 `ExecutionRequest`, `HandlerExecutionStrategyInterface`,
  per-binding result contracts и lookup по binding ID/action class.
- `SequentialExecutionStrategy` сохраняет descending priority, flow-before-
  binding interceptor ordering и `failFast=false` failure collection.
- Accepted adaptation: старый PSR-11-aware invoker заменён container-neutral
  `CallableInvokerInterface` и typed `ServiceResolverInterface`; runtime
  reflection discovery не возвращён.
- AMPHP cancellation проверяется перед выполнением каждого binding.
- Verified: PHPUnit `14 tests / 93 assertions` и PHPStan level max по
  `src + tests`.

### L2.R6 — Queue Producer Execution Strategy

- State: реализовано и проверено `2026-11-02`.
- Возвращены producer-only queue provider, batch enqueue, queue message,
  enqueue result/failure и retry policy snapshot contracts v6.
- `QueueExecutionStrategy` сохраняет delivery merge order
  `flow -> binding -> execution`, single/batch mapping и per-binding failures.
- Accepted adaptations: queue providers получают AMPHP `Cancellation`;
  envelope serialization остаётся typed port до отдельного Decision 15.2 codec
  slice; flow/binding metadata принадлежит `QueueMessage`, а не core envelope.
- `psr/clock 1.0.0` закреплён как прямая dependency для deterministic
  `availableAt`.
- Worker consumption, heartbeat, recovery и process control не добавлены в core.
- Verified: PHPUnit `20 tests / 127 assertions`, PHPStan level max и PHP syntax
  по `src + tests`.

### L2.R7 — Registry Diagnostic Fixture Matrix

- State: реализовано и проверено `2026-11-02`.
- Перенесены применимые cases `RegistryDiagnosticsTest` v6: duplicate/multiple
  aliases, duplicate binding IDs, missing flows, query/command cardinality,
  async query, ambiguous message kind, handler return/argument variants,
  interceptor contract и invalid flow definitions.
- Accepted adaptation: fixtures используют новые `Query<TResult>`/`Command`/
  `Event` markers и обязательный `InterceptorInterface`; отдельный middleware
  contract не возвращён.
- Public diagnostic target снова получает `messageClass`, handler, method и
  flow из partial compilation graph, поэтому structured diagnostics не теряют
  v6 binding context.
- Cache policy/idempotency, neutral resolver failures и compile CLI не
  объявлены compiler diagnostics: они остаются отдельными interceptor,
  integration и worker/CLI slices по уже принятым package boundaries.
- Verified: PHPUnit `27 tests / 184 assertions`, PHPStan level max и
  `git diff --check`.

### L2.R8 — Explicit Versioned JSON Codecs

- State: реализовано и проверено `2026-11-02` по принятому Decision 15.2.
- `MessageCodecInterface` связывает stable alias, message class и current
  message version; `JsonValue` допускает только portable JSON tree без objects,
  resources и non-finite numbers.
- `MessageUpcasterInterface` выполняет один шаг `N -> N+1`, а
  `MessageCodecRegistry` отклоняет duplicate codecs и разрывы цепочки от версии
  `1` до current.
- `JsonEnvelopeSerializer` хранит message alias/version/payload независимо от
  envelope schema version и восстанавливает identity, causation и headers без
  reflection/object normalization и PHP serialization.
- `MessageCodecRegistryValidationRule` проверяет, что каждый async binding alias
  имеет codec для того же message class; sync-local messages codec не требуют.
- Unknown alias/version и malformed payload дают typed controlled failures;
  golden fixture доказывает чтение message version `1` codec-ом version `3`.
- Verified: PHPUnit `34 tests / 211 assertions` и PHPStan level max по
  `src + tests`.

### L2.R9 — Composite MessageBus Runtime

- State: реализовано и проверено `2026-11-02`.
- Concrete `MessageBus` реализует segregated query/command/event interfaces и
  группирует bindings по уже скомпилированным flows/strategies.
- Root `ask/dispatch/publish` открывает один `ServiceScopeInterface`; nested
  facade переиспользует scope, а disposal выполняется в `finally` на success и
  failure paths.
- Nested envelope получает новый message ID, сохраняет correlation, записывает
  parent message ID как causation и объединяет immutable headers.
- Command runtime поддерживает primary sync binding вместе с async fan-out и
  async-only command; event runtime агрегирует local/queued executions и
  сохраняет successes в `PublishFailed` при partial failure.
- Accepted adaptation: context получает per-execution `NestedMessageBus`, а не
  resolver или fiber-local mutable state; advanced worker binding execution
  остаётся отдельным worker contract.
- Verified: PHPUnit `38 tests / 239 assertions` и PHPStan level max по
  `src + tests`.

### L2.R10 — Parallel Scope Isolation И Optional Container Adapters

- State: реализовано и проверено `2026-11-02`.
- Параллельные AMPHP fibers открывают независимые root scopes, а nested query
  переиспользует resolver только своего root execution.
- `ServiceScopeInterface::dispose()` получает исходную execution error, поэтому
  lifecycle adapter может корректно передать её container disposer-ам.
- `PsrContainerServiceResolver` предоставляет optional PSR-11 bridge с runtime
  проверкой типа относительно compiled `ServiceReference`.
- `ThesisServiceResolver` использует provider closures, а
  `ThesisServiceScopeFactory` адаптирует public `Scoped<T>::run()` lifecycle без
  зависимости core package от Thesis DIC.
- Thesis scope удерживается отдельным fiber до deterministic disposal; success,
  failure и parallel paths проверены на реальном `thesis/dic` `0.5.3`.
- `psr/container` и `thesis/dic` объявлены только в `suggest` и `require-dev`.
- Verified: PHPUnit `41 tests / 249 assertions`, PHPStan level max,
  `composer validate --strict` и `git diff --check`.

### L2.R11 — V6 Behavioral Parity Closure

- State: применимый L1/L2 fixture set прогнан и закрыт `2026-11-02`.
- Восстановлен v6 direct path для contextless sync binding без interceptors:
  context factory и execution strategy не разрешаются без необходимости.
- Contextless invocation mode проверен через runtime, repeatable attributes,
  custom flow и compiled artifact round-trip; invalid signatures и async query
  сохраняют v6 diagnostic contracts.
- Local event subscribers снова выполняются по descending priority, сохраняя
  aggregate result и partial-failure behavior нового public API.
- Runtime loader проверен в build-only режиме с обязательным compiled artifact;
  optional service resolvers возвращают typed missing/type mismatch failures.
- К L3 отнесены PostgreSQL queue schema, status/cancel и idempotency/replay; к
  L4 — consumption, internal binding execution, heartbeat и process control.
- Runtime discovery, reflection/PHP serialization и monolithic advanced bus API
  не возвращены по уже принятым modernisation deltas; cache остаётся deferred
  до доказанного application scenario.
- Target application использует query dispatch, nested dispatch и event publish;
  `SagaHandler`, `dispatchAll`, fan-out facade и cache annotations не являются
  необходимыми compatibility contracts первой package line.
- Verified: PHPUnit `48 tests / 270 assertions`, PHPStan level max и
  `git diff --check`.

## Общий Definition Of Done Для Slice

Каждый slice обязан:

- иметь один наблюдаемый результат и не смешивать независимые причины изменения;
- сохранять typed contracts без raw SQL/HTTP/OpenSearch payload на границах;
- проходить formatter, strict PHPStan, Deptrac и применимые Testo suites;
- собирать применимые `HttpRuntime`, `CliRuntime`, `WorkerRuntime` DIC graphs;
- компилировать MessageBus registry, если добавлены message/handler/binding;
- иметь deterministic cleanup для созданных schema/index/queue resources;
- обновлять documentation и executable contracts в том же slice;
- фиксировать parity result либо явно зарегистрированное отличие;
- не оставлять новый scenario наполовину в legacy и target layouts.

## Wave 0 — Зафиксировать Безопасную Базу

### P1 — Provenance И Scope

- [x] Закрыть G0 и записать выбранный режим переноса.
- [x] Подтвердить либо заменить имя `AddressRegistry`.
- [ ] Определить, какие endpoints и CLI commands входят в первый release.
- [ ] Зафиксировать намеренно deferred Data.Mos и OSM scope.

Готовность: можно создавать module namespace и переносить разрешённые contracts
без риска смешать clean-room implementation с неподтверждённым source reuse.

### P2 — Executable Contract Snapshot

- [x] Скопировать или независимо восстановить разрешённую Postman collection.
- [x] Создать OpenAPI 3.1 skeleton с operation IDs для health и address API.
- [x] Сохранить representative success/error responses без secrets и real data.
- [x] Определить compatibility policy: exact JSON, additive fields, ordering,
  pagination, error codes и status codes.
- [x] Создать operationId-to-Postman coverage check.

Готовность: будущая AMPHP-реализация сравнивается с неизменяемой contract base,
а не с памятью о поведении исходного приложения.

### P3 — Parity Dataset

- [x] Выбрать небольшой набор регионов и адресных hierarchy transitions.
- [x] Включить data cases для find-by-id, search ranking,
  city/district/street suggest, not-found и ambiguity; malformed input закрепить
  отдельно в G2 transport contract.
- [x] Зафиксировать способ загрузки fixture через migrations/SQL builder.
- [x] Исключить production dumps, персональные данные и случайную зависимость от
  Nominatim internet state.

Готовность: PostgreSQL adapters и оба search backends можно сравнивать на одном
deterministic dataset.

## Wave 1 — MessageBus Async Package Family

### L1 — Core Package Skeleton И Local API

Package: новый `message-bus-async`, версия `0.1.0`.

- [x] Создать независимый package без изменения ветки/history v6.
- [x] Зафиксировать PHP `^8.4.12`, MIT license и namespace
  `Fedor\MessageBus`.
- [x] Добавить marker-интерфейсы `Query<TResult>`, `Command` и `Event` без
  runtime/container metadata.
- [x] Добавить immutable `ExecutionOptions` с AMPHP cancellation и root headers.
- [x] Ввести `QueryBusInterface::ask()`, `CommandBusInterface::dispatch()`,
  `EventPublisherInterface::publish()` и composite `MessageBusInterface`.
- [x] Определить read-only nested context, cancellation и handler signatures.
- [x] Определить immutable envelope identity и causation inheritance.
- [x] Перенести generic interceptor pipeline без параллельного middleware API.
- [x] Оставить core независимым от Thesis DIC, Symfony Console и worker package.
- [x] Реализовать composite runtime с root scope, nested causation и
  local/queued fan-out.
- [x] Перенести только доказанные behavioral contracts из v6 через tests.

Готовность: local query/command/event flows выполняются через hand-written
container/fakes, имеют стабильный public API и не содержат worker runtime.

### L2 — Registry, Scoped Resolution И Versioned Codecs

- [x] Сверить provisional L2 implementation с v6 registry/flow/compiler tests и
  устранить необоснованные semantic deltas.
- [x] Сохранить compile-time attributes и explicit class-list source без
  runtime scanning.
- [x] Реализовать versioned deterministic PHP artifact, atomic writer и
  validated runtime loader.
- [x] Реализовать explicit registry definitions и deterministic compilation.
- [x] Добавить stable aliases/binding IDs и duplicate/missing diagnostics.
- [x] Реализовать versioned JSON codecs и upcaster chain.
- [x] Добавить PSR-11 bridge и Thesis DIC adapter как optional integrations.
- [x] Проверить request/message scope isolation и disposer lifecycle между
  параллельными fibers.

Готовность: registry компилируется в artifact без внешних сервисов, durable
fixtures версии N читаются текущим codec, а application services не доступны
через service locator.

### L3 — Async Producer И PostgreSQL Contracts

- [x] Определить общую queue schema, state machine и at-least-once semantics.
- [x] Реализовать AMPHP-safe PostgreSQL producer/status/cancel API.
- [x] Добавить claim/lease/ack/retry/reject contracts и stale recovery fixtures.
- [x] Проверить idempotency/replay policy и отсутствие blocking PDO в producer.
- [x] Оставить transactional outbox вне `0.1.0`, сохранив extension seam.

Готовность: HTTP fiber неблокирующе публикует durable message, producer и status
API integration-tested на real PostgreSQL, schema совместима с worker package.

#### L3.R1 — Shared Schema И AMP Producer Gateway

- State: producer/status/cancel slice закрыт `2026-11-02`.
- Общая v6-compatible schema сохраняет routing, retry snapshot, lease,
  heartbeat, cancellation, error и serialized-envelope columns и индексы для
  pending/running/interrupted jobs.
- `QueueJobState` фиксирует проверенную матрицу переходов; `interrupted` остаётся
  recoverable technical status без преждевременно придуманного recovery API.
- `PostgresQueueGateway` реализует single/atomic batch enqueue, status lookup,
  pending cancel и cooperative running cancellation через `amphp/postgres`
  `2.2.1`; PDO в package отсутствует.
- Stable message alias вынесен в `SerializedEnvelope` и проверяется против alias
  JSON payload до восстановления сообщения.
- Cancellation проверяется до write, между batch writes и до commit. Уже
  начатый SQL не бросается через cancelled await, чтобы insert не получил
  неоднозначный committed/unknown outcome.
- Fast gate: PHPUnit `93 tests / 365 assertions`, PHPStan level max и
  `composer validate --strict`. Real PostgreSQL 17 gate: `1 test / 8
  assertions`, включая apply-from-zero, enqueue/status/list/cancel и batch.
- Следующий slice закрыт в L3.R2/L3.R3: worker transitions и handler-level
  idempotency/replay перенесены без producer-side auto-retry или PDO.

#### L3.R2 — Worker Transitions И Stale Recovery

- State: consumer transition slice закрыт `2026-11-02` в optional
  `message-bus-async-worker` package.
- Сохранены v6 `ConsumerOptions`, `ReceivedQueueMessage` и consumer operation
  names; I/O contracts получили `Amp\Cancellation`, raw PostgreSQL row удалён
  из public received-message contract.
- `PostgresMessageConsumer` реализует transactional claim через
  `FOR UPDATE SKIP LOCKED`, persisted attempts/lease/heartbeat и typed envelope
  reconstruction на общей core schema.
- Ack/retry/reject/cancel/heartbeat/cancellation polling защищены job ID,
  `running` state и lease owner; stale worker получает typed `QueueLeaseLost` и
  не может завершить уже переарендованную job.
- Stale recovery выбирает и переводит jobs в одной transaction с row locks:
  незавершённый attempt возвращается в `pending` с persisted retry delay,
  exhausted attempt переходит в `failed`.
- Dependency direction проверен: core не ссылается на worker namespace; local
  source workspace связывает два соседних repository через Composer path.
- Fast worker gate: PHPUnit `3 tests / 12 assertions`, PHPStan level max и
  `composer validate --strict --no-check-lock`. Real PostgreSQL 17 gate:
  `1 test / 25 assertions` для claim, heartbeat, cancellation flag,
  ack/retry/reject/cancel, lease loss и stale recovery.
- Следующий slice закрыт в L3.R3: v6 handler-level idempotency/replay policy
  перенесена без нового deterministic enqueue contract; runner/job scope и
  graceful process lifecycle остаются L4.

#### L3.R3 — Handler Idempotency И Replay Policy

- State: idempotency/replay slice закрыт `2026-11-02` в core package.
- Проверка v6.2.3 подтвердила, что проверенный механизм — handler interceptor,
  policy registry, normalized key, intent fingerprint и atomic store decisions,
  а не producer-side `ON CONFLICT` dedup.
- `RequiresIdempotencyKey` использует binding-aware envelope и сохраняет четыре
  v6 решения: claimed выполняется и complete-ится, completed-same становится
  no-op, in-progress классифицируется retryable, conflict — non-retryable.
- Failure handler-а не complete-ит claim; `createdAt` сохраняется в durable
  envelope, поэтому replay видит время исходного сообщения.
- Flow contract требует stable binding ID, owner metadata, policy того же owner,
  command/event kind и transaction boundary снаружи idempotency guard.
- В handler attributes возвращены v6 `ownerKind`, `ownerId` и `source*` поля;
  отдельный middleware runtime contract не добавлен.
- Core PostgreSQL producer использует только `amphp/postgres`; blocking PDO и
  автоматический retry неоднозначного enqueue отсутствуют.
- Deterministic enqueue key остаётся отдельным transport enhancement: старая v6
  библиотека его не реализовывала, поэтому скрытый dedup contract не введён.
- Fast core gate: PHPUnit `102 tests / 414 assertions`, PHPStan level max и
  `composer validate --strict`.
- Следующий slice закрыт в L3.R4: transactional outbox оставлен вне `0.1.0`, а
  extension seam доказан через существующий producer port.

#### L3.R4 — Transactional Outbox Extension Seam

- State: L3 закрыт `2026-11-02`; outbox storage/relay не входят в core `0.1.0`.
- Новый `OutboxInterface` не добавлен: `QueueExecutionStrategy` уже зависит
  только от `QueueProviderInterface`, а `QueueMessage` несёт полный routing,
  retry и serialized-envelope snapshot.
- Будущий application adapter может записать тот же `QueueMessage` в outbox и
  вернуть ID outbox record без изменения bus, strategy или codecs.
- Adapter владеет transaction joining, schema, relay, dedup/recovery и
  observability; core не принимает application transaction object и не зависит
  от ambient global transaction state.
- Contract fixture проверяет заменяемость direct PostgreSQL gateway на
  outbox-style provider через существующий port.
- Fast core gate: PHPUnit `103 tests / 419 assertions`, PHPStan level max и
  `composer validate --strict`.
- Следующий slice: L4 consumer runner, job scope, heartbeat и graceful
  cancellation в optional worker package.

### L4 — Worker Extension `0.1.0`

Package: новый `message-bus-async-worker`.

- [x] Создать независимый package scaffold на PHP `^8.4.12` с dependency
  direction `worker -> core`.
- [x] Реализовать consumer loop, job scope, heartbeat и graceful cancellation.
- [x] Добавить retry/reject, stale recovery и worker control plane.
- [x] Предоставить отдельный Symfony Console executable и signal adapter.
- [x] Доказать dependency direction только `worker -> core`.
- [x] Проверить совместимость core/worker release line `0.1.x`.

Готовность: worker восстанавливает stale lease, не теряет ack/retry transition,
корректно завершается и не добавляет worker dependencies в core package.

#### L4.R1 — AMPHP Runner И Per-Job Scope

- State: runner/job-scope slice закрыт `2026-11-02` в worker package.
- `QueueWorkerRunner` использует AMPHP `delay()` и cancellation вместо
  `usleep()`; consumer claim прекращается сразу после process stop request.
- Каждая claimed job открывает отдельный core `ServiceScopeInterface`, разрешает
  `QueueWorkerInterface` внутри него и dispose-ит scope с исходной ошибкой.
- Concurrent monitor heartbeat-ит lease, poll-ит durable cancellation и
  отменяет cooperative handler при lease loss или после graceful timeout.
- Process stop позволяет текущей job завершиться в grace window; timeout ведёт
  к retry/reject по attempt budget. Durable cancellation ведёт к `cancel`.
- Ack/retry/reject/cancel выполняются отдельной uncancelled transition phase,
  чтобы shutdown не оборвал фиксацию результата посередине.
- `NonRetryableMessageExceptionInterface` reject-ится; обычная ошибка retry-ится
  до исчерпания snapshot; stale worker после `QueueLeaseLost` ничего не пишет.
- Fast worker gate: PHPUnit `10 tests / 41 assertions`, PHPStan level max и
  `composer validate --strict --no-check-lock`.
- Следующий slice закрыт в L4.R2: worker control contracts, PostgreSQL storage и
  registry/status lifecycle.

#### L4.R2 — Worker Control Storage И Registry

- State: contracts/storage slice закрыт `2026-11-03` в worker package.
- Проверенная v6-модель перенесена без нового design round: command/receipt,
  desired state, target specificity, inbox cursor, acknowledgement,
  worker/child registry и status repository.
- `DefaultWorkerControlService` сохраняет pause/resume desired state,
  idempotency и TTL one-shot команд: 60 секунд для kill, 300 секунд для
  drain/stop/restart.
- `PostgresWorkerControlGateway` реализует command, desired-state, inbox,
  registry и status ports через `amphp/postgres`; каждый I/O contract принимает
  AMPHP `Cancellation`.
- `PostgresWorkerControlSchemaGenerator` владеет полным v6-shaped набором
  commands, desired states, workers, children, deliveries, acknowledgements и
  audit без PDO и без schema-version subsystem старой библиотеки.
- Cursor продвигается по последней просмотренной, а не только совпавшей
  команде; expired commands исключаются, desired state выбирается по
  specificity и времени. PostgreSQL FK требует зарегистрировать worker до
  child/ack lifecycle.
- Real PostgreSQL gate проверяет DDL, idempotent append, targeting/cursor,
  desired-state resolution, acknowledgement upsert и worker/child lifecycle:
  PHPUnit `3 tests / 44 assertions`.
- Fast worker gate: PHPUnit `18 tests / 75 assertions`, PHPStan level max и
  `composer validate --strict --no-check-lock`.
- Текущая граница: runner ещё не poll-ит inbox, не применяет process commands и
  не обновляет control registry автоматически.
- Следующий slice закрыт в L4.R3: control inbox/registry подключены к AMPHP
  runner без обязательной process dependency.

#### L4.R3 — Control-Aware AMPHP Runner

- State: runtime control integration закрыт `2026-11-03` в worker package.
- Сохранена v6 composition boundary: `WorkerControlRuntime` агрегирует control
  service, command/desired repositories, inbox, registry и status facade;
  queue-only runner по-прежнему работает без этого optional runtime.
- `WorkerControlSession` переносит проверенную state machine
  pause/resume/drain/stop/kill/restart и используется основным loop и
  concurrent monitor без process-global state.
- Runner регистрирует single-mode worker до первого claim, poll-ит control
  между jobs и во время suspended handler, пишет idle/busy heartbeat,
  acknowledgement и финальный stopped/failed/restarting lifecycle.
- Pause останавливает новые claims; drain/stop/restart дают текущей job
  завершиться; kill cooperative-cancel-ит handler и сохраняет v6 retry/reject
  по attempt budget. Restart возвращает настроенный process exit code в
  `QueueWorkerRunResult`.
- Unit fixture доказывает stop до claim и kill текущей job; real PostgreSQL
  session fixture доказывает register → poll/ack → heartbeat → stop через FK и
  фактический gateway.
- Real PostgreSQL gate: PHPUnit `4 tests / 51 assertions`; fast worker gate:
  PHPUnit `22 tests / 102 assertions`, PHPStan level max и
  `composer validate --strict --no-check-lock`.
- Следующий slice закрыт в L4.R4: job-scoped adapter исполняет точный queued
  binding через отдельный advanced core contract.

#### L4.R4 — Scoped Exact-Binding Execution

- State: exact-binding core/worker slice закрыт `2026-11-03`.
- Core получил отдельный `BindingEnvelopeExecutorInterface`; composite
  application bus API не расширен worker-specific методом.
- `MessageBus::executeEnvelopeToBinding()` принимает восстановленный envelope,
  resolver уже открытого job scope и AMPHP `Cancellation`, проверяет binding и
  тип message, затем принудительно исполняет только этот binding через local
  sequential pipeline даже для async flow.
- Worker `MessageBusQueueWorker` десериализует envelope, использует
  authoritative `QueueMessage::bindingId` и передаёт текущий resolver; повторный
  fan-out и re-enqueue исключены.
- Scope ownership не дублируется: runner открывает и dispose-ит один job scope,
  core executor использует его resolver для handler, interceptors, context и
  nested dispatch.
- Core gate: PHPUnit `104 tests / 426 assertions`, PHPStan level max и
  `composer validate --strict --no-check-lock`; worker fast gate: PHPUnit
  `23 tests / 109 assertions` с теми же статическими gates.
- Проверка dependency graph не нашла worker namespace/package references в
  core `src`/`composer.json`; worker lock разрешает path-pinned core `0.1.0`
  через объявленный `^0.1` constraint.
- Следующий slice: отдельный Symfony Console worker executable и optional
  signal/process adapter поверх AMPHP cancellation.

#### L4.R5 — Worker Console Executable И Signal Adapter

- State: executable slice закрыт `2026-11-03`; L4 functional scope завершён.
- Worker package зависит от `symfony/console ^7.4` и устанавливает отдельный
  `vendor/bin/message-bus-worker`; core не получил Console/PCNTL dependencies.
- Application-owned bootstrap обязан вернуть явный `WorkerRuntime` с runner и
  optional control runtime. Автоматический импорт application container и
  обязательный PSR-11 bridge не введены.
- CLI сохраняет проверенную v6 command surface: `worker:run`, общий
  `worker:control`, action aliases pause/resume/drain/stop/kill/restart,
  `worker:status` и `worker:schema:postgres`.
- `worker:run` передаёт consumer/runner options, печатает итоговые counters и
  возвращает restart exit code process supervisor-у.
- Optional `PcntlSignalCancellation` переводит SIGTERM/SIGINT/SIGQUIT в
  cooperative AMPHP cancellation и восстанавливает предыдущие signal handlers.
  Без `ext-pcntl` worker сохраняет queue/control behavior.
- V6 `auto/fork` mode намеренно не перенесён в async kernel: multi-process
  orchestration остаётся отдельным подключаемым process/worker package.
- Fast worker gate: PHPUnit `29 tests / 135 assertions`, PHPStan level max,
  `composer validate --strict --no-check-lock`, executable list/schema smoke и
  `git diff --check`.
- Следующий активный track: A1 application quality/docs skeleton. L5 DIC modules
  сохранены как последний package-polish slice после стабилизации public API.

### L5 — Default Thesis DIC Modules Для Обеих Библиотек

Timing: `Deferred until final package-polish`. Этот slice выполняется в
последний момент перед фиксацией первого package release, когда публичные
core/worker contracts и фактическая A3 composition уже стабилизированы. Он не
должен сейчас останавливать A1–A3 или менять neutral library contracts.

- [ ] В core добавить первичный default `Thesis\Dic\Module`, предлагаемый public
  class `Fedor\MessageBus\Integration\Thesis\MessageBusModule`.
- [ ] В worker добавить первичный default `Thesis\Dic\Module`, предлагаемый
  public class
  `Fedor\MessageBus\Worker\Integration\Thesis\WorkerModule`.
- [ ] В обоих packages объявить `thesis/dic` только через Composer `suggest` и
  `require-dev`; обязательной runtime dependency не добавлять.
- [ ] Default modules должны экспортировать только library-owned composition и
  extension points, не импортировать application services и допускать явное
  application override/import поверх defaults.
- [ ] Проверить отдельную установку каждого module и совместный core+worker
  graph на реальном `thesis/dic`, включая scope lifecycle и disposal.
- [ ] Описать минимальный copyable application bootstrap, чтобы после установки
  библиотеки был готовый стандартный Module class без ручного повторения всех
  library bindings.

Готовность: приложение может подключить core и worker через два поставляемых
Module class, а пользователи других DI/container решений не получают Thesis DIC
в обязательных dependencies.

## Wave 2 — Application Foundation

### A1 — Architecture, Test И CI Skeleton

- [x] Создать устойчивый `docs/architecture.md`.
- [x] После принятия G1 создать `Module/AddressRegistry/MODULE.md` версии 0.1.0.
- [x] Разделить Testo suites на Unit, Scenario, Integration и Contract.
- [x] Добавить Deptrac rules и project checks для запрещённых dependencies.
- [x] Добавить Compose integration profile с ephemeral PostgreSQL/OpenSearch.
- [x] Разделить `make check`, `make test-integration` и `make contract`; добавить
  отдельный CI contract job.
- [ ] Добавить `make smoke-release` после принятия состава release smoke.

Готовность: пустой module skeleton не нужен; gates проверяют реальный scaffold,
Testo suites запускаются отдельно, integration resources не используют dev
volumes.

#### A1.R1 — Architecture И Executable Quality Gates

- State: G1/G2-dependent часть A1 закрыта `2026-11-04`; A1 остаётся partial
  только из-за состава release smoke.
- `docs/architecture.md` стал целевым источником layer/runtime/data boundaries и
  явно отделяет transitional HTTP scaffold от placement нового business code.
- Testo разделён на Unit, Scenario, Integration и Contract; fast gate запускает
  Unit+Scenario без фиктивных placeholder tests.
- `deptrac/deptrac 4.7.2` включён в `composer check`; проверяются scaffold и
  target module layers, `worker -> core`, Thesis DIC ownership и allowlist
  внешних AMPHP/PostgreSQL/Console/Validator dependencies. Текущий gate:
  `0 violations`, `0 uncovered`, `64 allowed`.
- Отдельный integration Compose project поднимает PostgreSQL 17 и OpenSearch
  3.9 на `tmpfs`, loopback ports и не использует development data volumes.
- `make test-integration` собирает PHP 8.4 runner, ждёт health, выполняет Testo и
  всегда удаляет containers/network через shell trap. Real-service gate:
  `2 tests / 7 assertions` для PostgreSQL query и OpenSearch
  health/index/search/delete lifecycle.
- GitHub Actions разделён на `fast` и следующий после него `integration` job;
  CI вызывает те же Composer/Make entrypoints, что и local workflow.
- `make contract` выполняет отдельную Contract suite для OpenAPI/Postman;
  `make smoke-release` ждёт состав принятого release smoke.
- `AddressRegistry/MODULE.md` версии 0.1.1 фиксирует ownership и отличает G2
  migration baseline от ещё отсутствующего executable module companion.

### A2 — Typed Config, Thesis DIC И Runtime Roots

- [x] Разбить текущий `AppConfig` на immutable runtime/integration settings из
  одного environment snapshot.
- [x] Подключить Thesis DIC и создать независимые `HttpRuntime`, `CliRuntime`,
  `WorkerRuntime` root exports.
  - [x] `HttpRuntime` собирается application-owned Thesis DIC module;
  - [x] `CliRuntime` собирается отдельным Thesis DIC module;
  - [x] `WorkerRuntime` содержит queue runner, control plane и job scopes.
- [x] Добавить Symfony Console application и `app/bin/console`.
- [x] Вынести JSON logging, correlation context и graceful shutdown в
  application shell.
- [x] Реализовать live/ready/startup registry с required/optional policy.
- [x] Проверить три DIC graph без внешних сервисов.

Готовность: server и console запускаются из DIC composition; liveness не делает
network I/O, optional outage даёт `degraded`, process-specific resources не
попадают в чужой graph.

### A3 — MessageBus Application Integration

- [x] Подключить выпущенный либо path-pinned development build core package.
- [x] Описать application flows и registry source явно, без runtime scanning.
- [x] Добавить Thesis scope bridge и correlation interceptor seam.
- [x] Добавить telemetry interceptor поверх существующего pipeline contract.
- [x] Включить registry compile в fast gate.
- [ ] Включить registry compile в release gate после принятия G2.
- [x] Подключить worker executable к managed DIC lifecycle без `Dic::build()` и
  утечки process resources.
- [x] Доказать nested dispatch и cancellation через representative target-owned
  test, не перенося business scenario раньше data foundation.

Готовность: application dispatch работает через public bus interfaces, registry
artifact воспроизводим, три runtime graph компилируются.

#### A3.R1 — Explicit Registry Source И Fast Compile Gate

- State: application registry foundation закрыт `2026-11-04`; A3 остаётся
  active до scope bridge, interceptor seams и representative dispatch test.
- Core `romanfedorskij/message-bus-async 0.1.0` подключён path-pinned из
  соседнего repository без копирования library source в приложение.
- `config/message_bus_registry.php` возвращает typed explicit source с class,
  flow и service declarations; filesystem/runtime scanning отсутствует.
- Source hash строится детерминированно и не зависит от порядка class/service
  declarations; duplicate message classes отклоняются до compilation.
- CLI command `message-bus:registry:check` компилирует registry без внешних
  сервисов и входит в `composer check`; текущий честный baseline содержит
  `0 bindings`, пока target-owned scenarios ещё не добавлены.
- Release gate остаётся G2-dependent и не считается закрытым только наличием
  fast command.
- Continuation: application-owned Thesis scope bridge и correlation
  interceptor закрыты следующим A3.R2.

#### A3.R2 — Thesis Scope Bridge И Correlation Interceptor

- State: core application dispatch composition закрыта `2026-11-04`; A3
  остаётся active до telemetry seam, worker root и G2-dependent release gate.
- Application-owned `ApplicationMessageBusModule` использует public
  `ThesisServiceResolver`/`ThesisServiceScopeFactory`; container dependency не
  протекла в neutral core contracts.
- Каждый handler, context factory, execution strategy и interceptor проверяется
  по explicit service map при graph configuration; undeclared service ID или
  несовместимый type завершают bootstrap fail-fast.
- `HttpRuntime` и `CliRuntime` получают один compiled `MessageBus` export из
  своих независимых DIC graphs; runtime scanning/container lookup отсутствуют.
- Default sync flow использует существующий `InterceptorInterface` через
  `CorrelationContextInterceptor`, без второго middleware abstraction.
- Representative Scenario test доказывает новый nested message ID, общие
  correlation/causation metadata, один DIC scope, disposal и inherited AMP
  cancellation.
- Fast gate: PHPStan max, Deptrac `0 violations / 0 uncovered`, registry compile
  и Testo `27 tests / 82 assertions`.
- Continuation: telemetry interceptor и реальный `WorkerRuntime` закрыты
  следующим A3.R3; фиктивный worker root не создавался.

#### A3.R3 — Telemetry И Real Worker Graph

- State: третий runtime graph закрыт `2026-11-04`; operational worker
  executable ожидает managed bootstrap contract в worker library.
- Path-pinned `romanfedorskij/message-bus-async-worker 0.1.0` подключён как
  runtime dependency; core сохраняет отсутствие обратной worker dependency.
- `WorkerRuntimeModule` собирает PostgreSQL consumer/lease control, worker
  control gateway/runtime, JSON envelope serializer, `QueueWorkerRunner` и
  отдельный per-job Thesis resolver scope.
- Job boundary устанавливает message/correlation/job identifiers до исполнения
  authoritative binding и гарантированно очищает fiber context после job.
- Default sync flow добавил `MessageBusTelemetryInterceptor` после correlation:
  start/complete/failure logs содержат только code metadata, duration и
  exception class, без message payload.
- Текущий нулевой business registry честно использует пустой codec registry;
  PHP serialization или неизвестный-message fallback не добавлены.
- Fast gate: PHPStan max, Deptrac `0 violations / 0 uncovered`, три DIC graph,
  registry compile и Testo `29 tests / 91 assertions`.
- На границе A3.R3 `BootstrapResolver` ещё требовал уже построенный runtime;
  application не использовал `Dic::build()` без managed teardown. Разрыв закрыт
  следующим A3.R4 lifecycle-aware provider contract.

#### A3.R4 — Managed Worker Executable

- State: executable lifecycle закрыт `2026-11-04`; A3 остаётся partial только
  из-за G2-dependent release registry gate.
- Worker library добавил `WorkerRuntimeProviderInterface`; все run, control и
  status commands выполняют operation внутри provider callback. Старый direct
  `WorkerRuntime` bootstrap сохранён как backward-compatible путь.
- `BootstrapResolver::resolve()` не выпускает managed runtime за пределы
  lifecycle; `run()` поддерживает direct и managed bootstrap формы.
- Application `ApplicationWorkerRuntimeProvider` оборачивает реальный
  `WorkerRuntimeModule` в `Dic::run()`, поэтому PostgreSQL pool и scoped
  services закрываются на success и failure.
- `app/bin/worker` использует стандартный `config/message_bus_worker.php` и на
  Symfony Console 8.1 экспортирует worker run/control/status/schema surface.
- Worker library gates: PHPUnit `31 tests / 141 assertions`, PHPStan max;
  application fast gate: Testo `31 tests / 102 assertions`, PHPStan max,
  Deptrac `0 violations / 0 uncovered`, registry compile и три DIC graph.
- Следующий незаблокированный application slice — D1 migration continuity.

## Wave 3 — Database Foundation И Первый HTTP Slice

### D1 — Migration Continuity

- [x] Подключить `yiisoft/db-migration` и PostgreSQL adapter только в CliRuntime.
- [x] Сохранить migration identifiers и history continuity всех 21 migrations.
- [x] Отделить application PostgreSQL от Nominatim PostgreSQL.
- [x] Добавить migrations-from-zero integration test и schema validation.
- [x] Добавлять исправления только новыми migrations, не переписывая применённую
  историю.

Готовность: пустая база детерминированно доходит до current schema; HTTP graph не
содержит Yiisoft/PDO/native pgsql batch services.

- State: D1 закрыт `2026-11-04`; G0 принят явным подтверждением владельца
  исходного кода, после чего все 21 migration перенесены в application namespace.
- `yiisoft/db-migration 2.2` и `yiisoft/db-pgsql 2.0` подключены прямыми
  production dependencies; PHP image содержит `pdo_pgsql`.
- Application-owned `MigrationCliModule` экспортирует `migrate:create`, `up`,
  `down`, `new`, `history`, `redo` и безопасно маркированный `mark` command.
- Команды используют application `DATABASE_DSN`; Amp config преобразуется в
  PDO DSN без помещения credentials в DSN string. Connection остаётся lazy и
  не открывается при построении CLI graph или вызове `list`.
- Application PostgreSQL и Nominatim используют разные Compose services,
  credentials и volumes; migration commands не получают Nominatim connection.
- Parameterless migrations создаются neutral `Yiisoft\Injector\Injector` без
  PSR-11 bridge. HTTP/worker modules не импортируют migration module.
- `MigrationHistoryContract` и unit fixture фиксируют точный chronological class
  list, revertibility всех 21 migrations и 18 transactional markers.
- Integration fixture на пустой PostgreSQL 17 применяет всю историю через
  реальный `CliRuntime`, проверяет ключевые tables/indexes и расширения
  `postgis`/`hstore`/`pg_trgm`, затем выполняет полный rollback.
- Development и integration используют ARM64/AMD64 CargoSense PostGIS 17/3.6
  image, собранный из official PostGIS Alpine Dockerfile и закреплённый digest-ом.
- Новая policy: уже перенесённые migration identifiers и SQL не редактируются;
  будущие изменения схемы оформляются только последующими migration classes.

### D2 — Target-Owned Parity Fixture

- [x] Загрузить G3 dataset в canonical tables и `address_search`.
- [x] Добавить fixture version и checksum.
- [x] Проверить expected IDs, hierarchy, region codes и search documents.
- [x] Обеспечить быстрый reset между Integration suites.

Готовность: каждый core HTTP slice воспроизводится локально и в CI без полного
GAR import.

- State: D2 закрыт `2026-11-04`; fixture полностью принадлежит target project и
  не копирует source application fixtures или production data.
- Fixture v1 содержит 11 canonical objects, 10 active objects и 14
  `address_search` documents в регионах 77/78.
- Идентичные район/город/улица/дом создают намеренную nationwide ambiguity;
  region code и полный `path_arr` позволяют проверять scoped query path.
- `AddressRegistryParityFixture` проверяет SHA-256, загружается одной transaction
  и быстро очищает rows без rollback migration history.
- Integration gate подтверждает UUID lookup, `adm`/`mun`, inactive exclusion,
  generated `search_vector` и cleanup. Malformed input остаётся частью G2.

### V1 — `FindAddressById`

- [x] Создать typed message/result/document contract и scenario-local port.
- [x] Реализовать handler и AMPHP PostgreSQL adapter.
- [x] Добавить transport DTO, Symfony validation, presenter и error mapping.
- [x] Зарегистрировать `POST /api/addresses/find-by-id` и stable alias/binding.
- [x] Добавить Unit, Scenario, Integration, Contract и Postman coverage.

Готовность: первый business endpoint достигает согласованного parity; controller
не знает SQL/handler, adapter не возвращает raw row, cancellation propagation
проверена.

- State: V1 закрыт `2026-11-04`; `findAddressById` переведён из `planned` в
  `current` в OpenAPI/Postman.
- Query и result типизированы, binding
  `address-registry.find-address-by-id.v1` компилируется из explicit registry.
- HTTP boundary использует Symfony Validator; capability передаёт AMPHP
  cancellation в PostgreSQL reader и не знает transport/storage details.
- Reader ищет UUID, `object_id` и числовые Data.Mos identifiers, возвращает оба
  hierarchy documents в детерминированном порядке и не выполняет retry.
- Fast gate: Testo `38 tests / 171 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`, registry `1 binding`; Contract gate:
  `4 tests / 165 assertions`; Integration gate на PHP 8.4/PostgreSQL 17:
  `5 tests / 42 assertions`.
- Следующий vertical slice — V2 `SearchAddress` на PostgreSQL.

## Wave 4 — PostgreSQL Search И Suggest

### V2 — `SearchAddress` На PostgreSQL

- [x] Перенести как поведение query parsing/ranking без framework types.
- [x] Реализовать typed `SearchAddressPort` и AMPHP PostgreSQL reader.
- [x] Передавать `regionCode` явно и не выполнять nationwide `DISTINCT` в online
  path.
- [x] Добавить `GET /api/addresses/search`, presenter и compatibility cases.
- [x] Сохранить PostgreSQL adapter как обязательный fallback backend.

Готовность: core search работает без OpenSearch и совпадает с fixture contract
по ordering, limits, hierarchy и error semantics.

- State: V2 закрыт `2026-11-04`; `searchAddresses` переведён в `current`,
  PostgreSQL остаётся обязательным core backend без OpenSearch dependency.
- Typed query/criteria/result/match contracts используют общий
  `AddressDocument`, `AddressHierarchy` и alias evidence; binding
  `address-registry.search-address.v1` компилируется explicit registry.
- Forward migration добавляет вычисляемый `address_search.region_code` и индекс,
  не меняя canonical GAR tables; C2 сохраняет ответственность за partitioned
  read model и shadow cutover.
- Региональный вызов применяет predicate до ranking. Nullable region остаётся
  только additive legacy HTTP fallback и также не использует `DISTINCT`.
- Ranking объединяет Russian FTS, exact/trigram score с порогом `0.3`, level и
  deterministic tie-breakers; alias match возвращает typed evidence.
- Fast gate: Testo `43 tests / 197 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`, registry `2 bindings`; Contract gate:
  `6 tests / 181 assertions`; Integration gate на PHP 8.4/PostgreSQL 17:
  `7 tests / 49 assertions`.
- Следующий vertical slice — V3 staged `SuggestAddress`.

### V3 — Staged `SuggestAddress`

- [x] Реализовать `ResolveSuggestionContext`,
  `SuggestAdministrativeArea`, `SuggestThoroughfare` и parent orchestration.
- [x] Выполнять nested scenarios через MessageBus context, не внедрять handlers.
- [x] Реализовать региональный сегмент read-model query path; физический LIST
  partition cutover и shadow rebuild сохранить за C2.
- [x] Покрыть city/district/street transitions, invalid parent и empty result.
- [x] Добавить `POST /api/addresses/suggest` в OpenAPI/Postman/MODULE.md.

Готовность: suggest ищет только в вычисленном регионе, возвращает явный
`next_stage`, а PostgreSQL query plan не сканирует весь read model.

- State: V3 закрыт `2026-11-04`; `suggestAddresses` переведён в `current`, а
  bindings parent/nested capabilities компилируются в explicit registry.
- Parent handler вызывает nested scenarios только через
  `MessageContextInterface::ask()`, сохраняя scope, correlation и cancellation.
- Первый administrative stage допускает глобальный поиск; после выбора parent
  resolver вычисляет `regionCode`, и materialized candidate set ограничивается
  им до fuzzy ranking без online `DISTINCT`.
- Физические LIST partitions, shadow rebuild, parity check и cutover не
  дублируются в HTTP slice и остаются exit gate C2.
- Fast gate: Testo `50 tests / 231 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`, registry `6 bindings`; Contract gate:
  `8 tests / 197 assertions`; Integration gate на PHP 8.4/PostgreSQL 17:
  `9 tests / 62 assertions`.
- Следующий vertical slice — V4 `ReverseGeocode` через Nominatim.

## Wave 5 — Optional Online Features

### V4 — Reverse Geocode Через Nominatim

- [x] Оформить feature-local capability, port и typed Nominatim result.
- [x] Реализовать AMPHP client adapter с deadline/cancellation.
- [x] Выполнять GAR match через public `SearchAddress` contract.
- [x] Отделить Nominatim fallback location от core address document.
- [x] Проверить fake-server status, malformed JSON, timeout и cancellation.

Готовность: feature отключается composition-ом, raw Nominatim payload не
покидает adapter, outage отображается в typed error/result.

- State: V4 закрыт `2026-11-04`; `reverseGeocodeAddresses` переведён в
  `current`, а feature binding добавлен только в HTTP registry.
- Nominatim adapter нормализует response до `ReverseGeocodeLocation`, сохраняет
  cancellation/deadline и отображает protocol/malformed failures в typed
  `ReverseGeocodeUnavailable`.
- Parent handler вызывает core `SearchAddress` через
  `MessageContextInterface::ask()`; при пустом GAR match fallback остаётся
  отдельной feature location и не создаёт `AddressDocument`.
- Fast gate: Testo `57 tests / 258 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`, core registry `6 bindings`, HTTP registry
  `7 bindings`; Contract gate: `11 tests / 222 assertions`; Integration gate на
  PHP 8.4/PostgreSQL 17/OpenSearch: `9 tests / 62 assertions`.
- Следующий vertical slice — V5 optional OpenSearch decorator.

### V5 — OpenSearch Search Decorator

- [x] Расширить native AMPHP client только используемыми typed operations.
- [x] Реализовать mapping/query parser и decorator над core search port.
- [x] Нормализовать hits в тот же contract, что PostgreSQL backend.
- [x] Проверить enabled/disabled/degraded fallback и per-request cancellation.
- [x] Покрыть mapping/query real OpenSearch integration tests.

Готовность: отключение или outage OpenSearch не меняет core API contract;
PostgreSQL остаётся корректным fallback и source of truth.

- State: V5 закрыт `2026-11-04`; feature включается только через
  `OPENSEARCH_SEARCH_ENABLED`, не добавляет MessageBus binding и декорирует
  существующий `SearchAddressPort` в HTTP composition.
- Strict mapping и query factory сохраняют hierarchy/region predicates;
  result mapper удаляет raw hit shape и маркирует typed matches backend-ом
  `opensearch`.
- Empty, malformed и unavailable projection переходят в PostgreSQL с безопасным
  warning; cancellation пробрасывается без fallback.
- Typed client дополнен только используемыми create/delete/index/refresh/search
  operations. Streaming bulk и blue-green alias lifecycle остаются C3.
- Fast gate: Testo `63 tests / 289 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`; Contract gate: `11 tests / 222 assertions`;
  Integration gate на PHP 8.4/PostgreSQL 17/OpenSearch:
  `9 tests / 66 assertions`.
- Следующий vertical slice — C3 OpenSearch blue-green rebuild.

## Wave 6 — CLI Data Pipeline

### C1 — GAR XML Import

- [x] Реализовать CLI-only XMLReader catalog/import pipeline.
- [x] Использовать bounded batches и native `ext-pgsql` COPY/staging/upsert.
- [x] Вынести progress/resume state в operation tables, не в log history.
- [x] Проверить cleanup, restart и partial-file failure.
- [x] Не регистрировать blocking import как HTTP/worker MessageBus handler.

Готовность: representative XML импортируется с ограниченной памятью и
максимальной CLI throughput; HttpRuntime не может получить import adapters.

Реализовано:

- `gar:import` принимает один XML-файл или каталог, строит dependency-ordered
  catalog для всех XML-семейств текущей canonical schema и запускается только из
  `CliRuntime`.
- `XMLReader` передаёт bounded text batches в native `pg_copy_from`; temporary
  staging, set-based upsert и checkpoint фиксируются одной транзакцией.
- `gar_import_operations` и `gar_import_files` отделяют progress/recovery от
  diagnostic logs. Manifest и advisory lock защищают resume и параллельный
  запуск одного source.
- Повтор completed manifest является no-op; failed/running operation
  возобновляется с последнего batch, а `--restart` сохраняет прежнюю операцию как
  `abandoned` и начинает новую.
- Representative и partial XML fixtures проверяют canonical mapping, type
  resolution, hierarchy path, cleanup, resume и restart на PostgreSQL 17.
- Fast gate: Testo `68 tests / 305 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`; Contract gate: `11 tests / 222 assertions`;
  Integration gate на PHP 8.4/PostgreSQL 17/OpenSearch:
  `10 tests / 97 assertions`.

### C2 — Regional `address_search` Rebuild

- [x] Реализовать shadow rebuild и progress state по region batches.
- [x] Создать partitioned read model по `region_code` новой migration.
- [x] Добавить integrity/parity checks до переключения read path.
- [x] Сохранить benchmark gate перед изменением canonical GAR tables.
- [x] Проверить recovery после прерывания между build и switch.

Готовность: search/suggest read model пересобирается без долгого partial state,
online queries читают только выбранный регион.

Реализовано:

- `gar:address-search:rebuild` переносит проверенную v6 последовательность
  prepare/build/index/parity/switch в отдельный blocking CLI capability без
  MessageBus handler.
- Operation snapshot разбит на `(region_code, hierarchy_type)` tasks с
  зафиксированным maximum hierarchy id; каждый data batch и checkpoint
  коммитятся атомарно и возобновляются после failure.
- Shadow parent использует `LIST(region_code)`, отдельные physical partitions и
  composite uniqueness с partition key. Тяжёлый denormalization SQL заранее
  схлопывает active versions и сохраняет enrichment active projection.
- `--build-only` доказывает recovery в границе между build и switch: active
  table остаётся обычной, ready shadow — partitioned; повторный запуск выполняет
  один atomic cutover и удаляет previous table после commit.
- Parity блокирует switch при незавершённых checkpoints, пропущенных source
  documents, duplicates или некорректном регионе. `ANALYZE` выполняется до
  cutover, а integration `EXPLAIN` подтверждает чтение только partition 77.
- Operation сохраняет elapsed milliseconds, scanned/written и physical rows как
  benchmark baseline. Решение о canonical GAR partitioning остаётся отдельным и
  требует production-like evidence.
- Fast gate: Testo `69 tests / 313 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`; Contract gate: `11 tests / 222 assertions`;
  Integration gate на PHP 8.4/PostgreSQL 17/OpenSearch:
  `11 tests / 121 assertions`.

### C3 — OpenSearch Blue-Green Rebuild

- [x] Реализовать NDJSON bulk streaming с bounded in-flight requests.
- [x] Проверять HTTP и per-item bulk errors без неявного retry.
- [x] Строить versioned physical index и выполнять parity check.
- [x] Переключать alias атомарно только после успешной загрузки.
- [x] Удалять старый index отдельной подтверждённой cleanup operation.

Готовность: failure до alias switch не влияет на текущий search; retry разрешён
только после отдельной классификации идемпотентности.

Реализовано:

- `opensearch:reindex` читает одну repeatable-read PostgreSQL snapshot курсором
  `(region_code, id)` и строит deterministic document identity
  `region_code:hierarchy_type:object_id`.
- Один AMPHP CLI process держит не больше `--concurrency` NDJSON `_bulk`
  requests; batch payload ограничен `--batch-size`. Process fan-out версии 6 не
  перенесён.
- Native client проверяет HTTP status, размер bulk response и каждый item.
  Неявного retry нет; при failure остальные in-flight requests отменяются,
  active alias не меняется, versioned target сохраняется для диагностики.
- Transport failure во время самой atomic `_aliases` operation маркируется как
  ambiguous outcome и требует read-back alias перед любым ручным retry.
- Bulk refresh/replica settings заменяются normal settings перед refresh.
  Source count, подтверждённые items и physical target count образуют parity
  gate для одной atomic `_aliases` operation.
- `opensearch:index:cleanup` принимает только versioned prefix configured alias,
  требует точного `--confirm` и отказывает для active target. Rebuild не удаляет
  previous или failed indexes автоматически.
- Real integration test выполняет C2 regional build, успешный C3 cutover,
  item-level mapping failure без смены alias, повторный успешный cutover и
  cleanup guards на PostgreSQL 17/OpenSearch 3.9.
- Fast gate: Testo `76 tests / 347 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`; Contract gate: `11 tests / 222 assertions`;
  Integration gate: `12 tests / 143 assertions`.

## Wave 7 — Durable Workers И Enrichment

### W1 — Application Worker Runtime

- [ ] Подключить совместимые core/worker packages `0.1.x`.
- [ ] Создать queue/worker-control migrations и WorkerRuntime composition.
- [ ] Добавить отдельный Compose worker service и operational commands.
- [ ] Зарегистрировать `address_async` и `runtime_async` bindings.
- [ ] Проверить enqueue, consume, retry, reject, stale recovery, idempotency и
  graceful shutdown на real PostgreSQL.

Готовность: producer и worker собраны из одной revision, durable transitions
наблюдаемы, registry mismatch блокирует запуск.

### W2 — Coordinate Enrichment

- [ ] Публиковать `AddressCoordinatesResolved` через non-blocking producer.
- [ ] Реализовать idempotent PostgreSQL update и optional OpenSearch updater.
- [ ] Проверить replay, duplicate delivery и partial optional feature outage.
- [ ] Документировать drain/restart/cutover binding procedure.
- [ ] До переключения опустошить либо явно списать старые RoadRunner queues.

Готовность: at-least-once delivery не создаёт некорректных повторных effects, а
отключение OpenSearch не блокирует canonical PostgreSQL update.

## Wave 8 — Deferred Sources И Cutover

### E1 — Data.Mos И OSM Aliases

Выполняется только после core parity и отдельного подтверждения scope.

- [ ] Перенести Data.Mos import/enrichment как optional feature.
- [ ] Перенести OSM aliases import как optional feature.
- [ ] Не расширять core contracts raw source-specific fields.
- [ ] Проверить implications для rebuild/search fixtures.

### R1 — Release Parity

- [ ] Выполнить fast и real-service integration gates.
- [ ] Выполнить full Nominatim/Postman smoke.
- [ ] Проверить import -> read-model build -> OpenSearch rebuild -> API flow.
- [ ] Сравнить find/search/suggest/reverse responses на representative dataset.
- [ ] Проверить live/ready/startup, shutdown, queue drain и restart.
- [ ] Зафиксировать все осознанные contract differences.

Готовность: release candidate имеет воспроизводимые artifacts и не зависит от
непроверенного legacy runtime behavior.

### R2 — Cutover И Cleanup

- [ ] Закрыть G5 и выполнить выбранный data path.
- [ ] Переключить traffic после readiness/parity approval.
- [ ] Удалить demo `/geocode` и `/search` routes и migration bridges.
- [ ] Decommission Spiral/RoadRunner только после rollback window.
- [ ] Объявить старые MessageBus packages архивными отдельным решением.
- [ ] Обновить README, operator docs и final migration status.

Готовность: AMPHP является единственным runtime согласованного scope, rollback
path проверен, deferred work перечислен явно.

## CI Gates По Этапам

| Контур | Когда обязателен | Состав |
| --- | --- | --- |
| Fast PR | С A1 | Composer validation, formatter, PHPStan, Deptrac, Unit+Scenario, DIC graphs, registry compile, OpenAPI/Postman coverage |
| Integration PR | С D1/L3 | Ephemeral PostgreSQL/OpenSearch, migrations from zero, Testo Integration+Contract, schema/index cleanup |
| Scheduled | С V4/C1 | Full Nominatim, executable Postman, representative import/rebuild/parity |
| Release | С R1 | Все применимые gates на pinned dependencies/images, artifacts и recorded compatibility result |

Functional failures не скрываются автоматическими retries. Каждый контур имеет
собственный timeout и публикует JUnit/diagnostic artifacts без secrets.

## Текущий Фокус

G4 и L1.1–L1.8 остаются принятыми modernisation deltas. L2.R1 восстановил flow,
identity, delivery и cardinality, L2.R2 — diagnostic contracts, validation
rules, registration metadata и definition/artifact lifecycle, L2.R3 — actual
early-exit compiler graph, L2.R4 — custom flow context/factory с AMPHP
cancellation, L2.R5 — local sequential execution strategy, L2.R6 — producer
`QueueExecutionStrategy`, L2.R7 — применимую v6 diagnostic fixture matrix и
typed diagnostic targets, L2.R8 — принятые explicit versioned JSON codecs и
upcasters, L2.R9 — composite runtime с root/nested scope lifecycle, L2.R10 —
parallel scope isolation и optional PSR-11/Thesis DIC adapters, а L2.R11 закрыл
применимый v6 behavioral fixture set. L3.R1 добавил общую PostgreSQL schema,
state machine и AMP producer/status/cancel gateway с real PostgreSQL 17 gate.
L3.R2 добавил в optional worker package claim/lease/ack/retry/reject/cancel,
heartbeat и transactional stale recovery на общей schema. L3.R3 перенёс
проверенную v6 handler-idempotency interceptor модель и зафиксировал, что
deterministic producer enqueue dedup в baseline отсутствовал. L3.R4 доказал
outbox extension seam через существующий `QueueProviderInterface`, не добавляя
outbox storage/relay в core `0.1.0`. L3 закрыт. L4.R1 добавил AMPHP consumer
loop, per-job scope, heartbeat/cancellation monitor и graceful process stop.
L4.R2 перенёс worker control storage/registry, L4.R3 подключил control state
machine к runner, L4.R4 закрыл scoped exact-binding execution без повторного
enqueue, а L4.R5 добавил отдельный Console executable и optional signal adapter.
L4 завершён. A1 закрыла architecture source, tiered Testo, Deptrac, real
PostgreSQL/OpenSearch integration job и executable contract gate; состав
release smoke остаётся отдельным решением. A2 получил typed
settings и первый независимый
`HttpRuntime` Thesis DIC root с container-owned PostgreSQL lifecycle и отдельный
`CliRuntime` с исполняемым Symfony Console entrypoint. A3 подключил path-pinned
core/worker packages, explicit registry source, fast compile gate,
correlation/telemetry interceptors и реальный `WorkerRuntime` с PostgreSQL
consumer/control plane; nested dispatch/cancellation доказаны target-owned
Scenario test. Worker library и `app/bin/worker` теперь
поддерживают managed `Dic::run()` provider lifecycle с гарантированным teardown;
direct runtime bootstrap сохранён для совместимости. D1 завершён: CLI root
содержит изолированный Yiisoft migration graph и PDO PostgreSQL adapter, все 21
исходные migration и target-owned forward migrations проходят apply-from-zero и
полный rollback на PostgreSQL 17/PostGIS.
D2 добавил target-owned fixture с regional ambiguity, checksum и быстрым reset;
V3 расширил его до v2 municipal administrative documents. G1 закрепил
`AddressRegistry` как business owner, G2 сохранил разрешённый
публичный HTTP contract в OpenAPI/Postman и включил отдельный coverage gate. V1
реализовал первый source-verifiable scenario `FindAddressById`: current HTTP
route, typed MessageBus query/result, AMPHP PostgreSQL reader и все четыре Testo
suites. V2 добавил current `SearchAddress`, региональный predicate, FTS/trigram
ranking, alias evidence и compatibility fallback без nationwide `DISTINCT`.
V3 реализовал current staged `SuggestAddress`, nested MessageBus orchestration
и региональный candidate path. V4 добавил current optional `ReverseGeocode`,
typed Nominatim boundary и GAR match через public `SearchAddress`. V5 подключил
optional OpenSearch decorator с PostgreSQL fallback и real regional mapping
gate. C1 добавил CLI-only XMLReader/COPY importer с operation checkpoints и
проверенным partial-file recovery. C2 перенёс shadow rebuild в регионально
partitioned projection с parity gate и atomic cutover. C3 добавил native async
OpenSearch blue-green rebuild, atomic alias cutover и отдельную cleanup
operation. Следующий slice — W1 application worker runtime.
JSON stdout,
HTTP request/correlation и worker job lifecycle находятся в application shell.
Default Thesis DIC modules для core и worker зафиксированы в L5 как намеренно
последний package-polish slice после стабилизации фактической A3 composition.
Вопросы с вариантами открываются только для реально отсутствующего в v6 и
dependency baseline поведения. G0 разрешает 21 migration и публичные HTTP
contract artifacts, но не implementation code.

## История Документа

### 0.56.0 — 2026-11-04

- завершён C3 CLI-only blue-green rebuild OpenSearch projection;
- добавлены repeatable-read source snapshot, regional document IDs и bounded
  concurrent NDJSON `_bulk` без implicit retry;
- HTTP/per-item failures и exact source/index/target parity блокируют alias
  switch, versioned target сохраняется для диагностики;
- atomic alias cutover сохраняет previous index, отдельная cleanup command
  требует точного подтверждения и запрещает удаление active target;
- Fast gate: Testo `76 tests / 347 assertions`, PHPStan max, Deptrac
  `0 violations / 0 uncovered`; Integration gate на PostgreSQL 17/OpenSearch
  3.9: `12 tests / 143 assertions`; следующий slice — W1 worker runtime.

### 0.55.0 — 2026-11-04

- завершён C2 CLI-only regional `address_search` shadow rebuild;
- добавлены operation/region checkpoints, bounded batches и LIST partitions по
  `region_code` без изменения canonical GAR tables;
- indexes, integrity/parity, `build-only` recovery и atomic cutover проверены на
  PostgreSQL 17, включая реальный partition-pruning plan;
- build counters сохранены как benchmark gate; следующий slice — C3 OpenSearch
  blue-green rebuild.

### 0.54.0 — 2026-11-04

- завершён C1 CLI-only GAR XML catalog/import pipeline;
- добавлены bounded native COPY/staging/upsert batches и прямой `ext-xmlreader`
  platform requirement;
- progress/resume перенесены в `gar_import_operations` и `gar_import_files`,
  completed no-op, partial failure, resume, restart и cleanup проверены на
  PostgreSQL 17;
- blocking adapter остался вне HTTP/worker/MessageBus composition; следующий
  slice — C2 regional `address_search` rebuild.

### 0.53.0 — 2026-11-04

- завершён V5 optional OpenSearch decorator над core `SearchAddressPort`;
- добавлены strict address mapping, regional query factory и typed hit mapper;
- native AMPHP client расширен только реально используемыми typed operations;
- disabled/empty/outage/malformed fallback и cancellation покрыты unit tests,
  mapping/query — real OpenSearch integration test;
- PostgreSQL сохранён source of truth; следующий slice — C1 GAR XML import.

### 0.52.0 — 2026-11-04

- завершён V4 optional `ReverseGeocode` с feature-local capability, port и
  stable MessageBus binding;
- raw Nominatim response изолирован в AMPHP adapter, deadline/cancellation и
  typed outage покрыты fake HTTP client tests;
- GAR normalization переиспользует public `SearchAddress`, а fallback location
  не подменяет core `AddressDocument`;
- отдельный HTTP registry подключает feature без импорта Nominatim в CLI/worker;
- MODULE, OpenAPI и Postman синхронизированы; следующий slice — V5 OpenSearch
  decorator.

### 0.51.0 — 2026-11-04

- завершён V3 staged `SuggestAddress` с parent orchestration и тремя nested
  capabilities через MessageBus context;
- добавлен current POST route, Symfony transport validation и стабильные
  `400`/`422`/`503` outcomes;
- PostgreSQL reader вычисляет parent region и ограничивает следующие stages до
  ranking без nationwide `DISTINCT`;
- parity fixture v2 добавляет municipal projection documents для всех
  административных стадий в двух синтетических регионах;
- physical partition/shadow cutover явно сохранён за C2;
- MODULE, OpenAPI и Postman синхронизированы; следующий slice — V4
  `ReverseGeocode`.

### 0.50.0 — 2026-11-04

- завершён V2 `SearchAddress` с typed criteria/result/match contracts и stable
  MessageBus binding;
- добавлены current GET route, Symfony validation, regional `region_code` и
  legacy nationwide fallback без `DISTINCT`;
- PostgreSQL reader реализует Russian FTS, trigram ranking и alias evidence;
- target-owned forward migration добавила вычисляемый региональный ключ и индекс
  только в disposable `address_search` projection;
- Unit, Scenario, Contract и real PostgreSQL Integration gates закрыты;
  следующий slice — V3 staged `SuggestAddress`.

### 0.49.0 — 2026-11-04

- завершён V1 `FindAddressById` с typed capability contract, scenario-local port
  и AMPHP PostgreSQL adapter;
- зарегистрированы current HTTP route и stable MessageBus binding;
- добавлены Symfony validation, compatibility presenter и `400`/`503` mapping;
- Unit, Scenario, Contract и real PostgreSQL Integration gates подтверждают
  transport, dispatch/cancellation, response envelope и storage lookup;
- `MODULE.md`, OpenAPI и Postman синхронизированы; следующий slice — V2
  `SearchAddress`.

### 0.48.0 — 2026-11-04

- G2 принят по варианту А: публичный contract перенесён без implementation code;
- добавлены OpenAPI 3.1 YAML, sanitized Postman v2.1 collection/environment и
  representative synthetic success/error responses;
- зафиксирована compatibility policy и distinction `current`/`planned` routes;
- Contract suite и `make contract` проверяют schema references и полное
  operationId/method/path coverage; CI получил отдельный contract job, а test
  runners монтируют repository docs только для чтения;
- следующий vertical slice — V1 `FindAddressById`.

### 0.47.0 — 2026-11-04

- вариантом А принято имя `AddressRegistry` и namespace
  `App\Module\AddressRegistry`;
- G1 закрыт, создан source-verifiable `MODULE.md` версии 0.1.0;
- пустые layer directories, planned scenario cards и Postman без реального
  module entrypoint намеренно не создавались;
- следующий gate — G2 executable HTTP contract baseline.

### 0.46.0 — 2026-11-04

- G3 принят как synthetic двухрегиональный fixture без production/source data;
- version/checksum, regional hierarchy, UUID lookup, `adm`/`mun`, inactive
  exclusion и generated search documents закреплены real PostgreSQL test;
- destructive schema reset ограничен identity изолированного integration runtime;
- D2 закрыт, V1 ожидает решений G1/G2 по module и HTTP contract.

### 0.45.0 — 2026-11-04

- G0 закрыт явным разрешением владельца на перенос 21 migration в MIT project;
- сохранены все identifiers, chronological history, revertibility и
  transactional policy исходной схемы;
- real PostgreSQL/PostGIS integration gate доказал apply-from-zero, schema
  contract и полный rollback;
- PostGIS 17/3.6 development/integration image закреплён multi-architecture
  CargoSense digest-ом;
- D1 закрыт, следующий database slice D2 ожидает G2/G3.

### 0.44.0 — 2026-11-04

- Yiisoft migration 2.2 и PostgreSQL 2.0 подключены только к `CliRuntime`;
- CLI получил семь `migrate:*` commands с lazy PDO connection к application DB;
- PHP image дополнен `pdo_pgsql`, а Nominatim database остаётся вне migration
  composition;
- D1 frontier переведён на сохранение 21 history identifiers после G0 gate.

### 0.43.0 — 2026-11-04

- worker library получил backward-compatible managed runtime provider contract;
- все worker CLI operations удерживают runtime внутри provider lifecycle;
- приложение добавило `ApplicationWorkerRuntimeProvider`, стандартный bootstrap
  и executable `app/bin/worker` без `Dic::build()`;
- Symfony Console 8.1 command surface проверена application-side smoke test;
- A3 оставлен partial только по G2-dependent release gate, frontier переведён
  на D1 migration continuity.

### 0.42.0 — 2026-11-04

- path-pinned worker package подключён к application dependency graph;
- собран третий реальный DIC root с PostgreSQL consumer/lease, worker control,
  JSON serializer, queue runner и per-job service scope;
- queue job boundary переносит message/correlation/job IDs и очищает context;
- default flow дополнен safe telemetry interceptor без payload logging;
- три runtime graph и Testo `29 tests / 91 assertions` проходят fast gate;
- managed worker executable bootstrap выделен следующим library atom, чтобы не
  использовать resource-leaking `Dic::build()`.

### 0.41.0 — 2026-11-04

- добавлен application-owned Thesis DIC module поверх neutral core scope
  contracts и explicit service map validation;
- один compiled MessageBus включён в независимые HTTP и CLI runtime graphs;
- default sync flow подключает correlation context через существующий
  `InterceptorInterface`, без нового middleware API;
- target-owned Scenario test доказал nested causation/correlation, общий scope,
  disposal и inherited cancellation;
- A3 frontier переведён на telemetry interceptor и реальный WorkerRuntime.

### 0.40.0 — 2026-11-04

- path-pinned `romanfedorskij/message-bus-async 0.1.0` подключён к приложению;
- добавлены typed explicit application registry source и deterministic source
  hash без runtime scanning;
- `message-bus:registry:check` включён в fast `composer check` и компилирует
  текущий честный baseline с нулём bindings без внешних сервисов;
- release registry gate оставлен открытым до принятия G2;
- A3 frontier переведён на Thesis scope bridge и correlation interceptor.

### 0.39.0 — 2026-11-03

- production-default logging переведён на JSON Lines через существующий
  Monolog/Amp stdout handler, development сохраняет opt-in console format;
- добавлен fiber-local correlation context с request/message/operation/job
  identifiers и явным переносом в параллельные health checks;
- HTTP middleware валидирует/создаёт request и correlation IDs, возвращает их в
  response headers и пишет duration/status без query payload;
- exception logging ограничен безопасным type/integration context;
- параллельная изоляция, JSON fields и HTTP header lifecycle покрыты fast tests.

### 0.38.0 — 2026-11-03

- добавлен отдельный application health layer и registry;
- liveness остаётся локальным, startup отражает готовность HTTP runtime;
- PostgreSQL объявлен required, Nominatim и OpenSearch — optional;
- readiness checks запускаются параллельно с per-component cancellation deadline,
  required failure даёт `unavailable/503`, optional failure — `degraded/200`;
- safe component payload и deadline/parallel semantics покрыты fast tests;
- пустой worker graph отклонён: реальный root будет собран вместе с A3 package
  composition.

### 0.37.0 — 2026-11-03

- добавлен независимый `CliRuntime` Thesis DIC root;
- `app/bin/console` запускает Symfony Console с explicit process exit code;
- console name/version вынесены в immutable settings из общего environment
  snapshot;
- executable `list` contract и DIC graph teardown покрыты fast test;
- A2 frontier переведён на независимый `WorkerRuntime` root.

### 0.36.0 — 2026-11-03

- runtime settings разделены по интеграциям и строятся из одного immutable
  environment snapshot;
- `thesis/dic ^0.5.3` и `symfony/console ^8.1` стали прямыми application
  dependencies;
- HTTP process перенесён в отдельный `HttpRuntime` и application-owned DIC
  module;
- PostgreSQL pool закрывается DIC disposer-ом, а HTTP graph-test выполняет
  production composition и teardown без внешних сервисов;
- A2 frontier переведён на отдельный Symfony Console / `CliRuntime` root.

### 0.35.0 — 2026-11-03

- создан основной `docs/architecture.md` с target hexagonal/runtime/data
  boundaries и явным transitional scaffold status;
- Testo разделён на четыре tiers, fast gate оставлен Unit+Scenario;
- Deptrac 4.7.2 включён в обязательный quality gate без baseline/skip;
- добавлен isolated tmpfs Compose project для PostgreSQL/OpenSearch и два
  real-service integration tests;
- CI разделён на fast и integration jobs с общими local/CI entrypoints;
- A1 оставлен partial только по G1/G2-dependent MODULE/contract/release smoke,
  frontier переведён на A2 typed config и Thesis DIC roots.

### 0.34.0 — 2026-11-03

- worker package получил отдельный Symfony Console executable и явный
  application-owned `WorkerRuntime` bootstrap;
- перенесены v6 run/control/status/schema commands без возвращения fork mode в
  async kernel;
- SIGTERM/SIGINT/SIGQUIT переводятся optional PCNTL adapter-ом в cooperative
  AMPHP cancellation;
- L4 завершён с gate 29 tests / 135 assertions и PHPStan level max;
- default Thesis DIC Module classes для core и worker зафиксированы в L5 как
  последний отложенный package-polish slice через Composer `suggest`.

### 0.33.0 — 2026-11-03

- core получил scoped exact-binding executor contract;
- worker adapter восстанавливает envelope и исполняет authoritative queue
  binding без повторного enqueue;
- подтверждены dependency direction `worker -> core` и совместимая `0.1.x`
  release line;
- frontier переведён на отдельный worker executable и process adapters.

### 0.32.0 — 2026-11-03

- `WorkerControlRuntime` и session state machine подключены к AMPHP runner;
- stop применяется до нового claim, kill cooperative-cancel-ит текущую job;
- register/heartbeat/ack/final lifecycle подтверждены real PostgreSQL fixture;
- итог — 22 fast tests / 102 assertions и 4 integration tests / 51 assertion.

### 0.31.0 — 2026-11-03

- перенесены v6 worker control contracts, targeting и desired state;
- добавлены AMP PostgreSQL schema/gateway, worker/child registry и status API;
- idempotency, cursor, acknowledgement и lifecycle подтверждены real database;
- итог — 18 fast tests / 75 assertions и 3 integration tests / 44 assertions.

### 0.30.0 — 2026-11-02

- worker package получил AMPHP-native consumer loop без `usleep()`;
- каждая job выполняется в отдельном disposable service scope;
- concurrent monitor проверяет heartbeat, durable cancel и process grace;
- graceful timeout retry-ит/reject-ит job, lease loss запрещает stale transition;
- final durable transition не отменяется process cancellation;
- frontier переведён на worker control plane и exact-binding adapter;
- итог — 10 fast tests / 41 assertion и зелёный PHPStan level max.

### 0.29.0 — 2026-11-02

- transactional outbox явно оставлен вне core `0.1.0`;
- существующий `QueueProviderInterface` зафиксирован как application adapter
  seam без нового dormant `OutboxInterface`;
- contract fixture доказал заменяемость direct gateway на outbox-style provider;
- transaction joining, schema, relay, dedup и recovery оставлены владельцу
  будущего adapter-а;
- L3 закрыт; frontier переведён на L4 worker runner/job scope/lifecycle;
- итог — 103 fast tests / 419 assertions и зелёный PHPStan level max.

### 0.28.0 — 2026-11-02

- durable envelope получил исходный `createdAt`, а handler context — stable
  binding ID;
- перенесена v6 idempotency interceptor/policy/store decision model;
- completed replay проверен как no-op, handler failure не complete-ит claim;
- in-progress и conflict сохранили retryable/non-retryable классификацию;
- flow contract проверяет роль, порядок transaction boundary, stable identity,
  handler kind, owner и policy ownership;
- подтверждено отсутствие PDO и producer auto-retry/dedup контракта;
- итог — 102 fast tests / 414 assertions и зелёный PHPStan level max.

### 0.27.0 — 2026-11-02

- worker package получил AMP PostgreSQL consumer и v6-compatible contracts;
- все running transitions защищены lease owner и typed lease-loss failure;
- stale recovery выполняется transactionally через row locks и retry snapshot;
- real PostgreSQL 17 fixture проверил полный transition lifecycle;
- dependency direction `worker -> core` сохранён;
- итог — 3 fast tests / 12 assertions, 1 PostgreSQL test / 25 assertions и
  зелёный PHPStan level max.

### 0.26.0 — 2026-11-02

- зафиксирована общая v6-compatible PostgreSQL queue schema и state machine;
- добавлен AMP producer/status/cancel gateway без PDO;
- atomic batch и safe-boundary cancellation проверены unit fixtures;
- schema и gateway проверены apply-from-zero на PostgreSQL 17;
- добавлен точный reference по at-least-once, state transitions и ограничениям
  producer retry;
- итог — 93 fast tests / 365 assertions, 1 PostgreSQL test / 8 assertions и
  зелёный PHPStan level max.

### 0.25.0 — 2026-11-02

- закрыт применимый v6 registry/compiler/runtime fixture set для L1/L2;
- восстановлен contextless direct path без лишнего context lifecycle;
- local subscribers выполняются по descending priority;
- проверены repeatable attributes, artifact metadata и build-only loader;
- PostgreSQL producer/idempotency и worker contracts направлены в L3/L4;
- итог — 48 тестов, 270 assertions и зелёный PHPStan level max.

### 0.24.0 — 2026-11-02

- доказана изоляция root/nested scopes между параллельными AMPHP fibers;
- добавлены optional PSR-11 resolver и Thesis DIC lifecycle adapter;
- исходная execution error передаётся container disposer-ам;
- core остаётся независимым от container packages в runtime requirements;
- итог — 41 тест, 249 assertions и зелёный PHPStan level max.

### 0.23.0 — 2026-11-02

- реализован concrete composite `MessageBus` для query, command и event lanes;
- nested calls переиспользуют root scope и сохраняют causation/correlation;
- async-only commands и local/queued event fan-out проходят общую orchestration;
- partial publish failure сохраняет успешные executions, scope всегда disposed;
- итог — 38 тестов, 239 assertions и зелёный PHPStan level max.

### 0.22.0 — 2026-11-02

- реализованы explicit `MessageCodecInterface`, `JsonValue` и непрерывная
  upcaster chain по Decision 15.2;
- envelope schema version отделена от message version;
- async registry validation требует codec с совпадающими alias/message class;
- добавлены controlled failures и backward-read golden fixture;
- итог — 34 теста, 211 assertions и зелёный PHPStan level max.

### 0.21.0 — 2026-11-02

- перенесена применимая v6 diagnostic fixture matrix для registry compiler;
- восстановлено заполнение typed diagnostic target из partial graph;
- новые marker/interceptor contracts сохранены как уже принятые adaptations;
- cache/idempotency, resolver и CLI fixtures направлены в отдельные slices;
- итог — 27 тестов, 184 assertions и зелёный PHPStan level max.

### 0.20.0 — 2026-11-02

- перенесены producer queue/retry contracts и `QueueExecutionStrategy` v6;
- сохранены delivery merge, retry snapshot, single/batch и failure semantics;
- provider ports получили AMPHP cancellation, добавлен `psr/clock 1.0.0`;
- worker operational surface остался вне core;
- добавлены behavioral fixtures; итог — 20 тестов и 127 assertions.

### 0.19.0 — 2026-11-02

- возвращены execution request/result contracts и lookup semantics v6;
- реализован priority-ordered `SequentialExecutionStrategy` с interceptor
  pipeline и configurable fail-fast;
- PSR-11 invoker адаптирован к neutral typed service resolver;
- добавлены behavioral fixtures; итог — 14 тестов и 93 assertions.

### 0.18.0 — 2026-11-02

- возвращены `FlowDefinition::context()`, context interface/factory metadata и
  artifact round-trip;
- добавлены `MessageContextFactoryInterface`, `DefaultMessageContextFactory` и
  read-only `DefaultMessageContext`;
- worker runtime control заменён AMPHP cancellation, включая nested composite;
- compiler проверяет custom context/factory и flow-specific handler signature;
- добавлены behavioral fixtures; итог — 11 тестов и 81 assertion.

### 0.17.0 — 2026-11-02

- facade stage mapping заменён actual v6 early-exit compiler graph;
- partial `HandlerBindingDefinition` graph сохраняется на каждой стадии;
- compile-time definitions отделены от runtime typed service references;
- добавлены stage-boundary fixtures; итог — 8 тестов и 64 assertions;
- PHPStan добавлен в `require-dev`, а level max закреплён командой
  `composer analyse`.

### 0.16.0 — 2026-11-02

- перенесены v6 diagnostic contracts, warning policy и project validation rules;
- actual early-exit compiler graph оставлен отдельным незакрытым parity gate;
- восстановлены `MessageRegistryDefinition`, VarExporter dumper, atomic writer,
  compiled hydration и runtime loader lifecycle без provisional checksum API;
- registration owner/source metadata адаптирована к typed `ServiceReference`;
- подтверждено по v6 source: producer `QueueExecutionStrategy` принадлежит core,
  worker package владеет consumption и operational runtime;
- добавлены PHPUnit fixtures: 7 тестов и 50 assertions, PHPStan max зелёный;
- текущий frontier переведён на custom flow context/strategy parity.

### 0.15.0 — 2026-11-02

- восстановлены v6 flow, sync/async identity, delivery и cardinality semantics;
- возвращено публичное имя атрибутов `middleware` без второго runtime contract;
- strict alias/binding identity помечена как superseded experiment: stable
  identity обязательна только на async durable boundary;
- зафиксирован остаток reconciliation по diagnostics, validation rules,
  context/strategy и exact definition/artifact lifecycle.

### 0.14.0 — 2026-11-02

- введено обязательное parity-first правило для переноса MessageBus v6.2.3;
- повторные design rounds для унаследованного behavior запрещены;
- L2 compiler/artifact переведены в reconciliation до прохождения v6 fixtures;
- Decision 15.2 по explicit codecs признан уже закрытым и не переоткрывается.

### 0.13.0 — 2026-11-01

- L2.3 принят по варианту А: alias и binding ID обязательны и никогда не
  выводятся из PHP class names;
- compiler завершает полный explicit class-list pass и агрегирует diagnostics;
- проверяются handler signatures, interceptor types и dispatch cardinality;
- текущий frontier переведён на L2.4 versioned JSON codecs/upcasters.

### 0.12.0 — 2026-11-01

- L2.2 принят по варианту А: registry сохраняется как deterministic PHP
  manifest со schema version и SHA-256 checksum;
- реализованы immutable registry/binding model, dumper, atomic writer и loader;
- runtime загружает только trusted compiled artifact без discovery fallback;
- текущий frontier переведён на L2.3 stable identity и diagnostics policy.

### 0.11.0 — 2026-11-01

- L2.1 принят по варианту А: сохранена знакомая attribute-модель;
- compiler ограничен явным class list, runtime/Composer/filesystem scanning
  запрещены;
- attributes отделены от binding, queue и provenance runtime objects;
- текущий frontier переведён на L2.2 compiled registry artifact.

### 0.10.0 — 2026-11-01

- L1.8 принят по варианту А: typed service references и neutral scoped resolver;
- `ServiceScopeRunner` гарантирует disposal на success/failure path;
- core остаётся независимым от Thesis DIC, PSR-11, Symfony Console и worker;
- текущий frontier переведён на L2.1 registry declaration source.

### 0.9.0 — 2026-11-01

- L1.7 закрепил существующую interceptor/pipeline модель без нового middleware
  abstraction;
- concrete pipeline переписан как container-neutral per-execution chain;
- context открывает immutable envelope для interceptors;
- открыт L1.8 о neutral service resolution и lifecycle scope.

### 0.8.0 — 2026-11-01

- L1.6 принят по варианту А: library-owned envelope identity и causation chain;
- добавлены portable headers, envelope factory и replaceable ID generator;
- root correlation можно seed-ить через execution options;
- открыт L1.7 о middleware pipeline contract.

### 0.7.0 — 2026-11-01

- L1.5 принят по варианту А: поддерживаются contextless и context-aware
  invokable handlers;
- `MessageContextInterface` предоставляет nested bus, cancellation и read-only
  execution metadata;
- signature discovery перенесён в будущий registry compile, не runtime path;
- открыт L1.6 о message/correlation/causation identity.

### 0.6.0 — 2026-11-01

- L1.4 принят по варианту А: publish возвращает per-binding aggregate result;
- partial failure выбрасывает `PublishFailed` с сохранёнными successes/failures;
- реализованы segregated bus interfaces и composite facade;
- открыт L1.5 о handler signature и nested execution context.

### 0.5.0 — 2026-11-01

- L1.3 принят по варианту А: root execution использует immutable options;
- `amphp/amp ^3.1` добавлен как прямая core dependency;
- реализованы cancellation propagation seed и validated scalar headers;
- открыт L1.4 о semantics результата event fan-out.

### 0.4.0 — 2026-11-01

- L1.2 принят по варианту А: query/command/event являются отдельными marker
  contracts;
- `Query<TResult>` связывает message с result type через static-analysis
  generic;
- markers добавлены в core package и описаны в README;
- открыт L1.3 о cancellation/deadline/metadata в root execution API.

### 0.3.0 — 2026-11-01

- L1.1 принят по варианту А: оба package требуют PHP `^8.4.12`;
- созданы Composer metadata, MIT licenses, namespace roots и честные pre-release
  README без несуществующего API;
- core package skeleton и dependency direction worker-to-core закреплены;
- открыт L1.2 о типизации query/command/event messages.

### 0.2.0 — 2026-11-01

- G4 принят по варианту А: core и worker получают отдельные соседние Git
  repositories;
- созданы пустые `MessageBusAsync` и `MessageBusAsyncWorker` на ветке `main`;
- старый MessageBus v6 закреплён как неизменяемый behavioral source;
- текущий фокус переведён на L1 core package baseline.

### 0.1.0 — 2026-11-01

- создан source-verifiable implementation roadmap;
- разделены library, application, data, feature, worker и cutover tracks;
- dependency baseline разложен на vertical slices и executable gates;
- первым implementation slice выбран независимый MessageBus core `0.1.0`.

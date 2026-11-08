# Библиотеки Geo Registry: журнал решений

- Статус: `Dependency baseline accepted`
- Версия документа: `0.33.0`
- Обновлено: `2026-11-06`
- Родительский план: [spiral-to-amphp.md](spiral-to-amphp.md)
- Режим работы: решения принимаются интерактивно; этот документ не разрешает
  установку зависимостей или изменение application-кода
- Маркировка вариантов: начиная с Decision 5 используются только русские буквы
  `А`, `Б`, `В`, `Г`; латинские `A`, `B`, `C`, `D` больше не используются

## Цель

Выбрать минимальный и достаточный набор подключаемых PHP-библиотек для переноса
поведения `phpfiasgeocoder` со Spiral/RoadRunner на AMPHP. Набор должен покрыть
HTTP, CLI и worker runtime, не разрушить трёхслойную архитектуру модуля и не
внести блокирующий I/O в HTTP path.

Результатом обсуждения должен стать проверяемый dependency baseline: для каждой
области либо выбрана библиотека и описаны правила её применения, либо явно
принято решение обойтись без новой зависимости или отложить её до конкретного
scenario slice.

## Scope

Обсуждаем библиотеки и связанные с ними integration policies для:

- composition root и dependency injection;
- MessageBus, durable queue и worker runtime;
- CLI и миграций;
- конфигурации и секретов;
- валидации transport input и сериализации контрактов;
- PostgreSQL, XML import, OpenSearch и внешних HTTP-интеграций;
- кэша;
- логирования, метрик и tracing;
- тестов, статического анализа и architecture checks;
- описания HTTP-контрактов и executable documentation.

## Явные Non-Goals

- установка Composer-пакетов до завершения соответствующего решения;
- реализация bootstrap, capabilities, adapters или workers;
- перенос proprietary source-кода;
- выбор библиотек «на будущее» без подтверждённого сценария;
- замена уже принятого AMPHP HTTP-стека без нового факта или обнаруженного
  конфликта;
- автоматическое добавление OpenSearch SDK, Guzzle, retries, bulk streaming или
  альтернативной authentication scheme: это требует отдельного design review.

## Ограничения

1. Целевой runtime — PHP `^8.4.12` после принятия `thesis/dic`.
2. HTTP request handlers не выполняют блокирующий I/O; сетевые и database
   адаптеры HTTP runtime используют AMPHP и распространяют cancellation/deadline.
3. CLI и worker запускаются отдельными процессами со своими container/bootstrap;
   blocking I/O разрешается только вне HTTP path.
4. Внутри `AddressRegistry` сохраняются основные слои `UserInterface`,
   `Capability`, `Infrastructure`; wiring остаётся в application composition root.
5. Capability-код зависит от портов и стабильных контрактов, а не от типов
   контейнера, ORM, queue transport или HTTP-клиента.
6. Новая библиотека принимается только при наличии владельца, конкретного
   сценария, тестовой границы и понятного способа обновления.
7. Транзитивная зависимость не считается application contract: если приложение
   напрямую использует пакет, он должен быть объявлен напрямую в `composer.json`.

## Проверенные Факты

| Факт | Evidence | Следствие |
| --- | --- | --- |
| В целевом проекте уже напрямую подключены AMPHP HTTP server/router/client, AMPHP PostgreSQL, AMPHP logging и Monolog | `app/composer.json` | Эти категории не переоткрываются без обнаруженного конфликта |
| На момент первоначального baseline `Bootstrap` вручную создавал config, logger, HTTP client, PostgreSQL pool, integration clients, router и server | исторические `app/src/Bootstrap.php` и `0.1.0` | Ручная сборка была понятна, но росла бы при переносе handlers, CLI и workers; заменена DIC modules |
| На момент первоначального baseline в scaffold был только HTTP entrypoint `app/bin/server.php` | исторические `app/bin/` и `0.1.0` | Три runtime graph спроектированы явно; с `0.65.0` они доступны через общий lazy `bin/app` без общего resource lifecycle |
| В исходном приложении 18 команд наследуют `Spiral\Console\Command`; команды уже используют Symfony Console input arguments/options, а единый `app.php` передаёт управление Spiral kernel | `phpfiasgeocoder/app/src/**/*Command.php`, `phpfiasgeocoder/app/app.php` | При переносе нужен полноценный console framework, но Spiral command base/discovery следует заменить application-owned registration без изменения пользовательской формы arguments/options |
| Текущий `AppConfig::fromEnvironment()` напрямую читает `getenv()` и собирает 11 параметров HTTP и integrations в один объект; Compose уже загружает `config/.env` и передаёт приложению явный набор environment variables | `app/src/Config/AppConfig.php`, `config/.env.example`, `docker/compose/dev.sh`, `docker/compose/dev/compose.yaml` | PHP runtime не обязан повторно читать `.env`; конфигурацию можно один раз преобразовать в typed runtime/integration objects на composition boundary |
| Symfony Config предназначен для структуры, validation и merge нескольких config formats/sources, а Symfony Dotenv и `vlucas/phpdotenv` загружают `.env` в process environment | [Symfony Config](https://symfony.com/doc/current/components/config.html), [Symfony Dotenv](https://symfony.com/packages/dotenv), [`vlucas/phpdotenv`](https://github.com/vlucas/phpdotenv) | При единственном external source — environment, уже подготовленном Compose/orchestrator, эти packages не дают достаточной ценности для обязательной runtime dependency |
| Текущий PHPStan работает на `max` со strict rules и bleeding edge, но проект не содержит executable ruleset для зависимостей `UserInterface`, `Capability`, `Infrastructure`, Feature и Application composition | `app/phpstan.neon`, `app/composer.json`, `docs/migration/spiral-to-amphp.md` | Type analysis не заменяет architecture gate; правила, уже записанные в migration plan, должны стать машинно проверяемыми |
| `deptrac/deptrac` группирует classes в layers через collectors и проверяет разрешённые связи ruleset-ом; актуальный package `deptrac/deptrac` заменил abandoned `qossmic/deptrac` и устанавливается как dev dependency | [Deptrac concepts](https://github.com/deptrac/deptrac/blob/4.x/docs/concepts.md), [официальный repository](https://github.com/deptrac/deptrac), [Packagist](https://packagist.org/packages/deptrac/deptrac) | Deptrac подходит для namespace/layer/module dependency direction, но не проверяет runtime DI graph, MessageBus registry или disposal |
| PHPStan поддерживает project-specific custom rules над AST и типовой моделью | [PHPStan Custom Rules](https://phpstan.org/developing-extensions/rules) | Custom rules полезны для точечных semantic запретов, но самостоятельная реализация полного layer graph дублирует специализированный Deptrac |
| В исходном приложении 21 migration class реализует `RevertibleMigrationInterface`; 18 дополнительно помечены `TransactionalMigrationInterface`, а три оставлены non-transactional, включая `CREATE INDEX CONCURRENTLY` | `phpfiasgeocoder/app/src/Database/Migration/M*.php` | История схемы уже выражает transaction policy и rollback; смена migration engine потребует переписать и повторно доказать весь schema contract |
| `yiisoft/db-migration` 2.2 и `yiisoft/db-pgsql` 2.0 поддерживают PHP 8.1–8.5; migration package предоставляет create/up/down/history/new/redo commands и уже зависит от Symfony Console | [Yii DB Migration](https://packagist.org/packages/yiisoft/db-migration), [Yii DB PostgreSQL](https://packagist.org/packages/yiisoft/db-pgsql) | Пакеты совместимы с выбранными PHP 8.4 и Symfony Console и могут работать только в blocking CLI runtime, не попадая в HTTP data path |
| Yii Migration `MigrationService` создаёт migration classes через `Yiisoft\Injector\Injector`, который может работать без PSR-11 container для parameterless constructors; текущие 21 migration не имеют constructor dependencies | локальные `yiisoft/db-migration/src/Service/MigrationService.php`, `yiisoft/injector/src/Injector.php`, migration classes | Сохранение Yiisoft migrations не требует общего PSR-11 bridge к Thesis Dic; migration composition можно собрать явно внутри `CliRuntime` |
| Крупные GAR-таблицы используют глобальный `PRIMARY KEY(id)`, многочисленные foreign keys и self-links `prev_id`/`next_id`; `region_code` физически хранится только в `administrative_reestr_hierarchy` | `M260902040000CreateGarSchema.php` | Сквозное региональное партиционирование требует денормализации partition key и изменения primary/foreign key contracts, а не только `PARTITION BY` |
| Hot-path запросы к `object_params` соединяются и фильтруются по `object_id`, `type_id` и датам; importer выполняет upsert через `ON CONFLICT (id)` | `AddressSearchDenormalizer.php`, `GarXmlFileDescriptor.php`, `GarXmlImporter.php` | `object_params` является разумным первым кандидатом для hash partitioning по `object_id`, но потребует composite uniqueness/conflict target и parity tests импорта |
| PostgreSQL 17 требует включать все partition key columns в `PRIMARY KEY`/`UNIQUE`; pruning помогает только когда predicates совместимы с partition bounds, а обычную таблицу нельзя превратить в partitioned in-place | [PostgreSQL 17: Table Partitioning](https://www.postgresql.org/docs/17/ddl-partitioning.html) | Нужна отдельная cutover migration с новой таблицей/copy-or-attach и benchmark доказательством; неверный ключ может увеличить planning cost и сканирование partitions |
| Запущенной PostgreSQL Geo Registry сейчас нет среди Docker containers | локальный `docker ps`, `2026-11-01` | Фактические relation sizes, cardinality, skew и query plans пока не измерены; окончательное включение partitioning нельзя обосновать только формой DDL |
| Progressive suggest сначала материализует nationwide `current_objects` через `DISTINCT ON (ao.reestr_object_id)`, а ограничение по выбранному parent применяется позже через `hierarchy.path_arr` | `PostgresAddressSuggestionReader::suggestUnsafe()` | Пользовательский контекст уже сужает поиск, но текущая форма SQL не даёт отфильтровать регион до expensive dedup; проблема находится и в read model/query shape, не только в физическом размере raw tables |
| `AddressSuggestion` и nested suggest messages передают `parentObjectId`, но не `regionCode`; при этом build `address_search` уже умеет ограничивать rebuild регионами и использует shadow-table cutover | `AddressSuggestion.php`, `SuggestAddressHandler.php`, `AddressSearchDenormalizer.php`, `AddressSearchShadowRebuilder.php` | Регион можно сделать явной частью suggest context и materialized projection, не денормализуя его сразу во все canonical GAR tables |
| Текущая `address_search` является денормализованной read projection, но не хранит `region_code`; uniqueness задана как `(object_id, hierarchy_type)` | `M260902130000CreateAddressSearch.php` и последующие migrations | Региональное partitioning projection потребует добавить region key в row contract и unique key, зато не ломает foreign/self references исходных GAR tables |
| Текущий GAR importer читает XML через `XMLReader` и загружает batches через `ext-pgsql` `COPY FROM STDIN`, затем merge-ит staging table; index management, denormalization и shadow rebuild используют Yiisoft DB/PDO | `GarXmlImporter.php`, `GarImportIndexManager.php`, `AddressSearchDenormalizer.php`, `AddressSearchShadowRebuilder.php` | Batch path уже обоснованно использует blocking drivers по специализации; перенос на один клиент не должен ухудшить COPY throughput или связать HTTP runtime с blocking connection |
| Установленный `amphp/postgres ^2.2` предоставляет async query/execute/prepared statements/transactions, но не экспортирует streaming COPY API | локальные interfaces `Amp\Postgres\PostgresConnection`, `PostgresExecutor`, `PostgresLink` | Сохранить текущий bulk COPY в долгоживущем AMPHP worker без блокировки loop можно только через отдельный процесс либо новый специализированный async adapter |
| Исходное приложение прямо объявляет `ext-xmlreader` и `ext-pgsql`; целевой scaffold пока объявляет только `ext-pgsql`, а его Dockerfile устанавливает `pcntl` и `pgsql` | оба `app/composer.json`, `docker/php/Dockerfile` | При сохранении streaming XML import `ext-xmlreader` должен стать явным platform requirement и проверяться container build-ом |
| Исходный OpenSearch reindex читает PostgreSQL batches, пишет `_bulk` в новый physical index, временно меняет refresh/replica settings и после успеха атомарно переключает alias; parallel mode размножает blocking CLI через `proc_open` | `OpenSearchReindexCommand.php`, `OpenSearchAddressIndex.php` | Blue-green/alias semantics стоит сохранить, но process fan-out и blocking HTTP client не обязаны переноситься в новый AMPHP CLI |
| Целевой scaffold уже имеет небольшой native async OpenSearch REST client на `amphp/http-client` с cancellation, health/search и generic request; bulk NDJSON, mapping и alias lifecycle ещё не реализованы | `app/src/Infrastructure/OpenSearch/*`, `README.md` | Индексацию можно добавить вертикально без OpenSearch SDK/Guzzle, переиспользуя async transport и добавляя только реально нужные endpoints |
| Spiral Filters в исходных HTTP endpoints имеют пустой `FilterDefinition`; type/range/enum checks фактически реализованы вручную методами самих filters. Целевой scaffold пока парсит query parameters и clamp-ит limit прямо в `RouterFactory` | `AddressSearchFilter.php`, `SuggestAddressFilter.php`, `FindAddressByIdFilter.php`, target `RouterFactory.php` | Миграции нужен явный transport input contract и единый error shape; сложную существующую rule model сохранять не требуется |
| Symfony Validator работает standalone, отделяет constraints от validators и возвращает список structured violations; вне Symfony Framework metadata sources включаются явно | [Symfony Validation](https://symfony.com/doc/current/validation.html) | Он может декларативно описать transport DTO, но не заменяет parsing/hydration raw HTTP body/query и должен оставаться в UserInterface boundary |
| Старый MessageBus уже хранит JSONB envelope со stable message name, content type и envelope `schemaVersion`, но JSON serializer получает payload через `get_object_vars()` и восстанавливает message через `new $class(...$payload)`; также поддерживается PHP serialize | `JsonMessageSerializer.php`, `SerializedEnvelope.php`, `SerializedEnvelopeNormalizer.php`, queue schema | Property/constructor refactor может сломать отложенные сообщения; новая library line нуждается в explicit message codecs/versioning и не должна писать PHP object serialization |
| Исходный проект содержит Postman Collection v2.1 с environments, examples и test scripts; OpenAPI-файла нет. Родительский migration plan уже определяет Postman как executable HTTP contract | `phpfiasgeocoder/config/postman/*`, `spiral-to-amphp.md` | Postman parity нужно сохранить, но structural schema/required fields лучше вынести в отдельный machine-readable API contract |
| OpenAPI latest имеет линию 3.2, но текущий Postman документирует import/generation/sync для OpenAPI 3.0 и 3.1 | [OpenAPI versions](https://spec.openapis.org/oas/), [Postman: generate collections](https://learning.postman.com/docs/design-apis/specifications/generate-collections) | Для repository source-of-truth разумно выбрать OpenAPI 3.1 до подтверждения полной поддержки 3.2 всем toolchain |
| Целевой scaffold уже пишет PSR-3 через Monolog + async `Amp\Log\StreamHandler` в stdout, но использует human-oriented `ConsoleFormatter`; исходное приложение дополнительно пишет import/rebuild progress в PostgreSQL `parser_logs` | target `Bootstrap::logger()`, source `ParserLogRepository.php`, `GarImportRunner.php`, `GarAddressSearchRebuildCommand.php` | Transport logging dependency уже выбрана; нужно отделить machine-readable logs от queryable/resumable operation state и не переносить DB log sink в HTTP runtime |
| В проекте нет metrics/tracing library или collector. OpenTelemetry PHP объявляет traces/metrics/logs stable, поддерживает W3C Trace Context и отдельное context storage для fibers; fiber integration требует отдельной runtime configuration/verification | [OpenTelemetry PHP status](https://opentelemetry.io/docs/languages/php/), [context](https://opentelemetry.io/docs/languages/php/context/), [propagation](https://opentelemetry.io/docs/languages/php/propagation/) | OTel подходит как vendor-neutral contract, но AMPHP concurrency и exporter backpressure/shutdown должны быть доказаны contract tests до production enablement |
| PromPHP агрегирует multi-process metrics через Redis/APCu adapters; in-memory adapter подходит только когда shared aggregation не нужна | [PromPHP repository](https://github.com/PromPHP/prometheus_client_php) | Прямой Prometheus client добавит вторую metrics API и storage choice; Collector-based OTLP позволяет не связывать application core с Prometheus aggregation |
| Текущий `/health/live` локален процессу, а `/health/ready` параллельно требует доступность PostgreSQL, Nominatim и OpenSearch; Docker healthcheck использует только liveness, smoke — оба endpoints | target `RouterFactory::readiness()`, `docker/compose/dev/compose.yaml`, `docker/scripts/smoke-check.sh` | Нынешняя readiness semantics конфликтует с optional OpenSearch/fallback: отказ optional integration не должен автоматически выводить весь address service из traffic |
| Orchestrator semantics различают liveness (перезапуск сломанного процесса), readiness (исключение из traffic) и startup (защита медленного старта) | [Kubernetes probes](https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/) | Dependency outage нельзя проверять liveness-ом; иначе исправный process попадает в restart loop из-за внешнего сервиса |
| Target lock содержит `testo/testo 0.10.56`; framework уже включает assertions, data providers, lifecycle, coverage, filtering, JSON/JUnit output и cooperative fiber plugin. Пока есть одна Unit suite и один test | target `composer.lock`, `testo.php`, `app/tests/` | Testo функционально покрывает базовый unit/scenario runner и AMPHP fiber tests; нужны suite boundaries и migration proof, а не второй framework по умолчанию |
| Исходное приложение содержит 18 PHPUnit 12.5 unit tests; все наследуются от `PHPUnit\Framework\TestCase` | source `app/tests`, source `composer.json` | Потребуется контролируемая конвертация assertions/hooks/mocks; постоянный dual-runner увеличит CI и dependency surface |
| Development Compose уже содержит PostgreSQL 17, OpenSearch и Nominatim, но обычный GitHub Actions job не запускает service containers; Nominatim dev service импортирует Monaco PBF и имеет длинный startup budget | target Compose, `.github/workflows/check.yaml` | Полный dev stack слишком тяжёл для каждого integration run; real-service tests нужно разделить по стоимости и поднимать минимальный профиль |
| В исходнике 176 PHP-файлов, 36 MessageBus-зависимостей и три разных runtime-профиля | [spiral-to-amphp.md](spiral-to-amphp.md) | Composition должна масштабироваться дальше текущего scaffold |
| Generic MessageBus требует `Psr\Container\ContainerInterface`; handlers, middleware, context factories и strategies разрешаются через контейнер | `/Users/romanfedorskij/project/www/phpfiasgeocoder/app/vendor/romanfedorskij/message-bus/docs/examples/frameworks/generic-psr11.md` | Нужен настоящий PSR-11 contract, даже если wiring останется ручным |
| `romanfedorskij/message-bus` предлагает PHP-DI для standalone-приложений, Symfony DI для Symfony-приложений и Dice как альтернативу через PSR-11 adapter | `/Users/romanfedorskij/project/www/phpfiasgeocoder/app/vendor/romanfedorskij/message-bus/composer.json` | PHP-DI является наиболее близким upstream recommendation для текущего standalone AMPHP-приложения |
| PHP-DI 7.1.1 реализует PSR-11, поддерживает autowiring, PHP definitions и compiled container; пакет совместим с PHP 8+ | [PHP-DI definitions](https://php-di.org/doc/definition.html), [Packagist](https://packagist.org/packages/php-di/php-di), [официальный repository](https://github.com/PHP-DI/PHP-DI) | Можно сочетать удобное autowiring с явными module/runtime bindings |
| Symfony DependencyInjection работает standalone, поддерживает PSR-11, autowiring, private services, compile-time validation и dumping compiled container | [Symfony Service Container](https://symfony.com/doc/current/service_container.html), [Packagist](https://packagist.org/packages/symfony/dependency-injection) | Это сильная альтернатива, если ценность строгой compilation model оправдает больший configuration surface |
| `romanfedorskij/message-bus` уже напрямую требует Symfony Console и VarExporter | `message-bus/composer.json` из исходного проекта | Выбор PHP-DI не исключает Symfony components; он исключает только Symfony DI как composition engine |
| `thesis/dic` `0.5.3` требует PHP `^8.4.12`, имеет MIT-лицензию и не зависит от `psr/container` | [`composer.json`](https://github.com/thesis-php/dic/blob/0.5.x/composer.json), [Packagist](https://packagist.org/packages/thesis/dic) | Пакет совместим только после ужесточения project PHP constraint и не является drop-in PSR-11 container |
| `thesis/dic` использует типизированные `Ref<T>`, module-local bindings, isolated `import()`, eager dependency-graph validation, singleton/scoped lifetimes и disposers | [README](https://github.com/thesis-php/dic), [modularity](https://github.com/thesis-php/dic/blob/0.5.x/docs/modularity.md), [lifetimes](https://github.com/thesis-php/dic/blob/0.5.x/docs/lifetime.md), [disposal](https://github.com/thesis-php/dic/blob/0.5.x/docs/disposal.md) | Его runtime model лучше PHP-DI выражает request/message scope и cleanup долгоживущего AMPHP-процесса |
| Публичный API `Thesis\Dic` возвращает root export через `Dic::run()`/`build()`; внутренний container разрешает `Ref`, а не строковый ID | [`Dic.php`](https://github.com/thesis-php/dic/blob/0.5.x/src/Dic.php), [`Internal/Container.php`](https://github.com/thesis-php/dic/blob/0.5.x/src/Dic/Internal/Container.php) | Для MessageBus нужен application-owned PSR-11 adapter или изменение самого MessageBus contract |
| MessageBus реально вызывает `has(string)` и `get(string)` для handlers, middleware, strategies и runtime aliases | `ReflectionCallableInvoker.php`, `MessageBus.php`, `MessageBusRuntime.php` в локальном generic MessageBus | Bridge должен поддерживать именованные service IDs и PSR-11 exceptions, а не только экспортировать один root service |
| Исходное приложение имеет 10 sync query handlers с возвращаемым результатом и nested query dispatch, а также 3 async command handlers и 1 async event subscriber | `phpfiasgeocoder/app/src/Capability`, `Feature/Nominatim/Capability` | Выбранный bus должен сохранить request/reply semantics и не только доставку async messages |
| Установленный `romanfedorskij/message-bus` имеет стабильную версию `v6.1.0`, 43 test-файла, 35 documentation-файлов и встроенные query result, compiled registry, PostgreSQL queue, retries, idempotency, status/cancel и worker control plane | локальные `vendor/composer/installed.php`, `message-bus/README.md`, `tests/`, `docs/` | Это наиболее близкая к behavioral parity реализация; разрывы ограничены Thesis service resolution и AMPHP transport |
| В core собственного bus контейнер используется напрямую в `MessageBus` и `ReflectionCallableInvoker`, но callable invocation и bootstrap service resolution уже выделены в `CallableInvokerInterface` и `ServiceReferenceResolverInterface` | локальные `MessageBus.php`, `Invoker/*`, `Bootstrap/ServiceReference/*` | Уход от PSR-11 требует замены узкой resolution boundary и явной сборки зависимостей, а не переписывания dispatch/registry contracts |
| Queue layer собственного bus уже отделён интерфейсами provider/consumer/status/control, но PostgreSQL реализации используют PDO, а worker loop — `usleep()` | локальные `QueueProviderInterface.php`, `MessageConsumerInterface.php`, `Queue/Postgres/*`, `QueueWorkerRunner.php` | AMPHP transport и cancellable worker можно добавить за существующими портами; blocking adapters следует оставить legacy-совместимостью вне HTTP path |
| Собственный bus содержит 273 source PHP-файла, 41 `*Test.php` и 35 documentation-файлов | локальные `src/`, `tests/`, `docs/` | Полный rewrite уничтожит значительную проверенную поверхность; рациональный путь — совместимый v7 refactoring вертикальными шагами |
| Актуальный checkout библиотеки находится в `/Users/romanfedorskij/project/www/library/MessageBus`, чистый HEAD — tag `v6.2.3` на branch `release/v6.2.3`; в нём 276 source PHP-файлов и 45 `*Test.php` | локальные `git status`, `git log`, `src/`, `tests/` | Дальнейший design следует строить от v6.2.3, а не от установленной в исходном приложении v6.1.0 |
| `romanfedorskij/message-bus` v6.2.3 является единым sync/async package и напрямую требует PSR-11; `MessageBus`, context и registry связывают `dispatch()`/queries с `publish()`/queue flows | локальные `composer.json`, `MessageBus.php`, `Context/*`, `Registry/MessageRegistryCompiler.php`; [Packagist](https://packagist.org/packages/romanfedorskij/message-bus) | Простое переименование полного кода в `message-bus-async` создаст package name, который скрывает наличие sync query contracts |
| Уже опубликован `romanfedorskij/message-bus-amphp` v1.0.0: это узкий AMPHP Fiber execution adapter, зависящий от `romanfedorskij/message-bus ^4.0`; у него нет installs/dependents | [Packagist](https://packagist.org/packages/romanfedorskij/message-bus-amphp) | Новый `message-bus-async` пересекается с существующим AMPHP package по позиционированию; migration/deprecation path для `message-bus-amphp` нужно определить до публикации нового имени |
| В v6.2.3 worker concerns занимают 41 PHP-файл в `src/Worker`, часть из 37 файлов `src/Queue`, 16 CLI-файлов и общий `MessageBusRuntime`; `symfony/console`, pcntl/posix и process lifecycle используются именно operational worker surface | актуальный checkout `library/MessageBus`: `src/Worker`, `src/Queue`, `src/Cli`, `src/Runtime` | Worker можно вынести в optional package, но сначала нужно разрезать агрегированный runtime и разделить producer-facing queue contracts от consumer/control-plane implementation |
| Queue status и cancel вызываются producer/application side, тогда как heartbeat, ack/retry/reject, consumer loop и worker registry исполняются worker side | `QueueStatusRepositoryInterface.php`, `QueueJobControlInterface.php`, `MessageConsumerInterface.php`, `QueueWorkerRunner.php`, `Worker/*` | Status/cancel public contracts должны оставаться в core; worker package зависит от core и реализует consumer/lifecycle, обратная зависимость запрещена |
| Проверенные варианты имён `romanfedorskij/message-bus-async-worker`, `message-bus-worker`, `message-bus-async-runtime` и `message-bus-async-workers` на 2026-11-01 не опубликованы в Packagist P2 API | HTTP `404` от соответствующих `repo.packagist.org/p2/...json` endpoints | Имена сейчас свободны от опубликованного package под этим vendor, но окончательно считаются занятыми только после регистрации |
| Старый `MessageBusInterface` объединяет typed query, sync command, async publish, batch publish, fan-out и internal binding execution; общий `dispatch()` возвращает `mixed`, уточнённый только PHPDoc conditional type | актуальный checkout: `MessageBusInterface.php`, `MessageBusFanOutInterface.php`, `MessageContextInterface.php` | Новая библиотека может сохранить одну реализацию/registry, но application-facing interfaces стоит разделить по capabilities, чтобы query users не зависели от queue API и наоборот |
| Старая PostgreSQL queue claim-ит job через `FOR UPDATE SKIP LOCKED`, коммитит состояние `running` до вызова handler, выполняет `ack` только после success и возвращает stale `running` jobs в retry по heartbeat/lock TTL | актуальный checkout: `PostgresQueueStorage::claimNextAvailable()`, `ack()`, `retry()`, `recoverStale()`, `QueueWorkerRunner::run()` | Фактическая семантика — at-least-once: crash между business effect и ack может повторно выполнить handler, поэтому duplicate-safe/idempotent execution является обязательным contract, а не опцией transport-а |
| В исходном приложении всего четыре `publish()` call site: три CLI-команды ставят самостоятельную batch-операцию в очередь, а runtime geocode publisher публикует результат внешнего lookup; ни один publish не выполняется внутри database transaction | `GarBootstrapCommand.php`, `GarImportCommand.php`, `GarDenormalizeCommand.php`, `RuntimeAddressGeocodePublisher.php` | Transactional outbox не нужен для behavioral parity первого migration slice; retry/idempotency нужны, а outbox включается при появлении подтверждённого transactional dual-write scenario |
| `thesis/message-bus` на 2026-11-01 опубликован только как `0.5.x-dev`; пакет использует AMPHP, `amphp/postgres` и `thesis/pgmq`, но не зависит от `thesis/dic` | [Packagist](https://packagist.org/packages/thesis/message-bus), [`composer.json`](https://github.com/thesis-php/message-bus/blob/0.5.x/composer.json) | Выбор Thesis Dic не даёт автоматической интеграции с Thesis MessageBus; production dependency потребует разрешить dev stability |
| Thesis MessageBus handlers и `Endpoint::handle()` имеют `void` semantics; reply является отдельным routed message, а не синхронным typed query result | [`Endpoint.php`](https://github.com/thesis-php/message-bus/blob/0.5.x/src/MessageBus/Endpoint.php), [`Handlers.php`](https://github.com/thesis-php/message-bus/blob/0.5.x/src/MessageBus/Handling/Handlers.php) | Полная замена потребует нового sync QueryBus и переписывания nested capability composition |
| Thesis async runtime предоставляет transaction scope, deduplication, outbox/recoverability и PGMQ consumer с cancellation | [`TransactionalRuntime.php`](https://github.com/thesis-php/message-bus/blob/0.5.x/src/MessageBus/Consumption/Internal/TransactionalRuntime.php), [Thesis PGMQ](https://github.com/thesis-php/pgmq) | Это перспективная async модель, но не бесплатная замена существующему bus |
| `thesis/pgmq` требует установленное PostgreSQL extension PGMQ, а текущий проект использует стандартный `postgres:17-alpine` | [Thesis PGMQ](https://github.com/thesis-php/pgmq), `docker/compose/dev/compose.yaml` | Переход на Thesis async transport меняет database image, migrations/operations и deployment surface |

## Research: `thesis/dic`

### Вердикт

`thesis/dic` может подойти архитектурно лучше PHP-DI, но не является прямой
заменой в текущем плане. Его сильная сторона — не сокращение строк wiring, а
явная composition model для долгоживущих процессов: локально типизированные
refs, изоляция конфигурации, проверка полного dependency graph, request/job
scopes и гарантированный cleanup ресурсов.

Главный разрыв — отсутствие PSR-11. Это не исправляется одним `implements`:
MessageBus разрешает произвольные строковые IDs во время dispatch, тогда как
`thesis/dic` разрешает закрытые `Ref<T>` внутри текущего scope. Нужен отдельный
bridge, который заранее преобразует разрешённый набор ID в scoped provider
closures.

### Сравнение С PHP-DI

| Критерий | PHP-DI `^7.1` | `thesis/dic ^0.5.3` |
| --- | --- | --- |
| PSR-11 | Нативный `ContainerInterface` | Нет; нужен bridge |
| Идентичность сервиса | Строковый/class ID | Отдельный типизированный `Ref<T>` |
| Autowiring | Reflection с fallback на concrete classes | Только явно зарегистрированные module-local bindings; ничего не сканируется и не угадывается |
| Module isolation | Несколько definition files с override order | `import()` изолирует bindings, `apply()` явно разделяет scope |
| Graph validation | Container build/compiled mode | Весь объявленный graph проверяется eager при build |
| Dumped artifact | Поддерживается compiled container | Нет code-generation/dump; graph строится при старте процесса |
| Request/job scopes | Нет встроенной scope model | Singleton, scoped, `canBeScoped`, `Scoped<T>` |
| Resource cleanup | Application-owned | Встроенные disposers с aggregation ошибок |
| Минимальный PHP | PHP 8+ | PHP 8.4.12+ |
| Зрелость на 2026-11-01 | `7.1.1`, около 69 млн установок, более тысячи dependents | `0.5.3`, около 250 установок, один dependent; публичный API ещё pre-1 |
| Цена интеграции с текущим MessageBus | Низкая | Средняя: bridge, tags/providers и contract tests |

### Предлагаемая Форма Bridge

Если выбирается `thesis/dic`, PSR-11 совместимость ограничивается application
shell и не становится вторым общим container API:

1. Каждый доступный MessageBus service получает application tag со стабильным
   строковым ID.
2. Composition собирает allowlist `id => Closure(): object`; closure создаётся
   через `Dic::provider($ref)` и поэтому разрешает service в текущем Dic scope.
3. Маленький `Psr11ContainerAdapter` реализует только `has()`/`get()` и корректные
   PSR-11 exceptions; неизвестные IDs не autowire-ятся магически.
4. Adapter, MessageBus и handlers объявляются `canBeScoped()`.
5. HTTP открывает один Dic scope на весь request, worker — на одно queue message,
   CLI — на один command. Nested MessageBus dispatch остаётся в том же scope.
6. Thesis types остаются в `Application/DependencyInjection`; Capability и
   Infrastructure adapters получают обычные constructor dependencies.

Bridge требует отдельных contract tests:

- `has()` видит только экспортированный allowlist;
- `get()` возвращает один и тот же service внутри scope;
- два параллельных HTTP scopes не разделяют scoped state;
- nested dispatch использует текущий scope;
- missing/failed provider переводится в корректный PSR-11 exception;
- disposers исполняются после request/message и при исключении.

### Риски И Gates

- В `composer.json` нужно поднять constraint с PHP `^8.4` до `^8.4.12`, а Docker
  image не должен оставаться неявно совместимым с более ранним `8.4.x`.
- `^0.5.3` ограничивает обновления линией `<0.6`; переход на следующий minor
  pre-1 выполняется только отдельным upgrade review. Changelog уже содержит
  существенные BC changes между `0.4` и `0.5`.
- Release gate будет проверять build каждого runtime graph, но не создавать
  dumped container artifact.
- Standard `vendor/bin/message-bus` bootstrap не управляет Thesis disposal
  lifecycle. Предпочтителен application Symfony Console entrypoint внутри
  `Dic::run()`; если нужен vendor binary, его bootstrap/cleanup проектируется
  отдельно.
- Термин `Thesis\Dic\Module` означает composition unit и не должен создавать
  module-local `Bootstrap` внутри `AddressRegistry`; runtime modules живут в
  application shell.

## Research: MessageBus

### Требуемый Контракт Переноса

Bus в целевом приложении должен одновременно поддержать:

- sync `Query<TResult>` с единственным typed result;
- sync command без business result;
- nested capability dispatch в текущем request/message scope;
- стабильные binding IDs и compiled registry;
- async command/event delivery через PostgreSQL;
- non-blocking enqueue из HTTP;
- retries, idempotency, status/cancel и graceful worker lifecycle;
- отдельный Thesis scope на HTTP request и queue message.

### Оценка `thesis/message-bus`

Thesis MessageBus хорошо совпадает с async половиной задачи. Он нативно использует
AMPHP, умеет transactional dispatch, deduplication, outbox, recoverability/DLQ,
delayed delivery и cancellation consumer-а. Его handler registry хранит callable,
поэтому service-locator bridge для самих handlers не требуется.

Для текущей миграции есть четыре разрыва:

1. Нет синхронного `Query<TResult>`: `Endpoint::handle()` и handlers возвращают
   `void`, а reply доставляется как отдельное routed message.
2. Пакет пока доступен только как `0.5.x-dev`, без stable tag.
3. Транспорт построен поверх PGMQ extension, которого нет в текущем PostgreSQL
   image и deployment plan.
4. Пакет не интегрирован с `thesis/dic`; handler closures и scopes всё равно надо
   собирать в application composition.

Следовательно, полная замена `romanfedorskij/message-bus` не является
dependency substitution: это смена application interaction model и значительная
переработка десяти sync queries, nested dispatch и worker operations.

### Оценка `romanfedorskij/message-bus` v6.1

Текущий generic MessageBus уже соответствует исходным contracts и operational
требованиям: query result, command/event separation, nested context, flows,
compiled registry, PostgreSQL queue, retry snapshot, status/cancel, idempotency и
worker control plane. Для AMPHP/Thesis остаются две явные integration-задачи:

1. scope-aware Thesis-to-PSR-11 allowlist bridge для handler/middleware/strategy
   resolution;
2. AMPHP-compatible PostgreSQL producer для non-blocking HTTP enqueue; consumer
   может быть перенесён отдельно, не блокируя первый vertical slice.

Thesis lifecycle при этом не теряется: HTTP adapter выполняет request внутри
`Scoped<HttpKernel>`, worker adapter оборачивает обработку каждого job в
`Scoped<QueueJobProcessor>`, а PSR-11 providers разрешаются внутри текущего scope.

### Вариант C против модернизации собственной библиотеки

Реальный выигрыш варианта C — не в интеграции с `thesis/dic` и не в sync query
model. Thesis MessageBus не зависит от Thesis Dic, а синхронный typed QueryBus
всё равно придётся создавать самостоятельно. Выигрыш находится только в async
runtime: готовые AMPHP/PGMQ transport, transaction scope, deduplication,
outbox/recoverability, delayed delivery и cancellation.

| Критерий | C — Thesis MessageBus + новый QueryBus | D — собственная новая MessageBus generation |
| --- | --- | --- |
| Sync query и nested dispatch | Новая реализация и миграция 10 handlers | Сохраняются существующие contracts и semantics |
| Async I/O | Уже AMPHP-native | Нужно реализовать AMP PostgreSQL adapters и cancellable runner |
| Transaction/outbox/dedup | Уже входят в Thesis runtime | Нужно спроектировать или явно отложить по scenario need |
| Queue infrastructure | Обязателен PGMQ extension и новый database image | Можно сохранить текущую schema и stock PostgreSQL |
| Worker operations | Нужно заново покрыть status/cancel/control plane | Существующие contracts и control plane сохраняются |
| DI/lifecycle | Callable registry удобен, но Dic wiring/scopes всё равно application-owned | Core получает provider/resolver ports; Thesis adapter остаётся снаружи библиотеки |
| Stability | Только `0.5.x-dev` | Стабильная v6 база, но новый contract нужно выпустить и сопровождать |
| Ownership | Меньше собственного async-runtime кода, зависимость от Thesis roadmap | Больше maintenance, полный контроль над roadmap и reuse в других приложениях |
| Миграционный риск | Высокий: меняются query и async interaction models одновременно | Средний: contracts сохраняются, инфраструктура заменяется по вертикальным slices |

Поэтому C выигрывает, только если мы сознательно хотим отказаться от собственной
async runtime реализации и готовы принять Thesis/PGMQ interaction model как
стратегическую. Для текущей задачи behavioral migration это не даёт достаточного
выигрыша: экономия на реализации async transport оплачивается новым QueryBus,
переписыванием query/nested flows, изменением PostgreSQL operations и потерей
готового control plane.

Под «переписать собственную библиотеку под новый лад» не подразумевается rewrite
всех source-файлов. Предлагается отдельная новая generation с контролируемой
миграцией от v6:

1. Убрать обязательный `ContainerInterface` из core constructor; все runtime
   dependencies передавать явно, а handlers разрешать через library-neutral
   provider/resolver contract. PSR-11 оставить optional compatibility adapter.
2. Добавить Thesis adapter вне core: allowlisted providers получают сервисы из
   текущего Dic scope. Библиотека не должна зависеть от `thesis/dic`.
3. Реализовать AMP PostgreSQL provider/consumer/status/control adapters за
   существующими queue interfaces; PDO adapters оставить для blocking CLI и BC.
4. Заменить `usleep()`/process-centric wait в async runner на event-loop delay,
   cancellation и явный job-scope runner. Каждый job открывает и закрывает Dic
   scope, nested dispatch остаётся в нём.
5. Отдельным gate решить transaction/outbox/dedup. Это главный функциональный
   выигрыш Thesis, поэтому его нельзя молча объявить покрытым только заменой PDO
   на AMPHP.
6. Сохранить wire/envelope format, stable binding IDs, compiled registry,
   retry snapshots и control contracts; переход со старой v6 подтвердить contract и
   compatibility tests.

Новая generation остаётся общей библиотекой, а не fork-ом внутри Geo Registry. В
application repository живут только composition adapters и scenario tests.

Проверка текущих async call sites не обнаружила transactional dual-write:
публикация либо является самостоятельной CLI-операцией постановки batch job, либо
следует за внешним geocode lookup. Поэтому outbox важен как будущая возможность
библиотеки, но не является обязательным gate behavioral migration этого
приложения. Это не отменяет at-least-once delivery, idempotent handlers и
recoverability текущей очереди.

## Текущий Dependency Baseline

### Уже Принято Scaffold-ом

| Область | Пакет | Статус | Граница применения |
| --- | --- | --- | --- |
| HTTP server | `amphp/http-server` | accepted by scaffold | Только application HTTP runtime |
| HTTP routing | `amphp/http-server-router` | accepted by scaffold | Transport routing, не бизнес-оркестрация |
| Outbound HTTP | `amphp/http-client` | accepted by scaffold | Nominatim/OpenSearch и другие HTTP adapters |
| HTTP PostgreSQL | `amphp/postgres` | accepted by scaffold | Неблокирующие HTTP adapters |
| Logging bridge | `amphp/log` + `monolog/monolog` | accepted by scaffold | Общий PSR-3 logger; policy ещё предстоит уточнить |
| Tests | `testo/testo 0.10.x` как единственный runner | `Decisions 17.1–17.3 accepted` | Tiered CI: fast + real-service integration в PR, full stack smoke scheduled/release |
| Static analysis | `phpstan/phpstan` + strict rules | accepted by scaffold | Обязательный quality gate |
| Formatting | `friendsofphp/php-cs-fixer` | accepted by scaffold | Style gate |

### Требует Решений

| Область | Нужный результат | Статус |
| --- | --- | --- |
| DI/container | `thesis/dic ^0.5.3`, три runtime-specific root export и lifecycle scopes | `Decisions 1 and 10 accepted` |
| MessageBus | `message-bus-async 0.1.x` core + `message-bus-async-worker 0.1.x`, namespace `Fedor\MessageBus` | `Decisions 2–9 accepted` |
| CLI | direct `symfony/console`, application console и отдельный worker executable | `Decision 11 accepted` |
| Durable queue | PostgreSQL producer/consumer, AMPHP-safe HTTP enqueue, at-least-once delivery | `Decision 9 accepted; schema/API details pending implementation` |
| Migrations | `yiisoft/db-migration ^2.2` + `yiisoft/db-pgsql ^2.0`, 21 migration и history continuity | `Decisions 14.1–14.2 accepted; regional read-model partitioning, raw-table benchmark gate` |
| GAR import | CLI-only `XMLReader` + bounded batches + `ext-pgsql` COPY/staging/upsert | `Decisions 14.3–14.4 accepted; no parser library added` |
| OpenSearch projection | native `amphp/http-client`, async CLI blue-green rebuild и alias switch | `Decision 14.5 accepted; distributed rebuild deferred to benchmark` |
| Configuration | application-owned typed runtime/integration config из immutable environment snapshot | `Decision 12 accepted; no package added` |
| Validation | `symfony/validator ^8.1` на UserInterface transport DTO; application-owned violation contract | `Decision 15.1 accepted` |
| Serialization | explicit versioned JSON codecs/upcasters для durable messages; отдельные HTTP presenters | `Decision 15.2 accepted; no serializer package` |
| Cache | только после корректного uncached flow | deferred pending scenarios |
| Observability | JSON stdout, optional OpenTelemetry module, layered health probes | `Decisions 16.1–16.3 accepted` |
| API contract | OpenAPI 3.1 structural source + committed executable Postman companion | `Decision 15.3 accepted` |
| Architecture checks | `deptrac/deptrac ^4.7` + три DIC graph checks + registry compile | `Decision 13 accepted` |

## Дерево Решений

```text
1. Composition engine / PSR-11 container
   ├── 2. MessageBus integration и registry lifecycle [accepted: own new generation]
   │   └── 3. Первый usable async baseline [accepted: migration baseline]
   │       └── 4. Package topology [accepted: clean-slate core + worker extension]
   │           └── 5. Worker extension boundary [accepted: operational extension]
   │               ├── 6. Worker package name [accepted: message-bus-async-worker]
   │               │   └── 7. PHP namespace family [accepted: Fedor\\MessageBus]
   │               ├── 8. Sync flow public API [accepted: segregated + facade]
   │               └── 9. Durable queue guarantees и non-blocking producer [accepted: at-least-once]
   ├── 10. Runtime-specific bootstrap: HTTP / CLI / Worker [accepted: three explicit roots]
   │   ├── 11. Console integration [accepted: separate Symfony consoles]
   │   └── 12. Configuration ownership [accepted: typed app-owned config]
   └── 13. Container graph и architecture checks [accepted: Deptrac + executable graphs]

14. Data toolchain
   ├── 14.1 Migration engine и history continuity [accepted: Yiisoft]
   ├── 14.2 Partitioning strategy и evidence gate [accepted: region-first read model]
   ├── 14.3 Blocking batch DB access [accepted: CLI-only import]
   ├── 14.4 GAR XML streaming [accepted: XMLReader + COPY]
   └── 14.5 OpenSearch indexing/rebuild [accepted: async CLI blue-green]

15. Contract toolchain
    ├── 15.1 Transport validation [accepted: Symfony Validator]
    ├── 15.2 Serialization [accepted: explicit JSON codecs]
    └── 15.3 Postman/OpenAPI documentation [accepted: OpenAPI + Postman]

16. Operations toolchain
    ├── 16.1 Structured logging [accepted: JSON stdout + operation state]
    ├── 16.2 Metrics/tracing [accepted: optional OpenTelemetry module]
    └── 16.3 Health/readiness [accepted: layered required/optional probes]

17. Test toolchain
    ├── 17.1 Unit/scenario runner [accepted: Testo only]
    ├── 17.2 Adapter/integration tests [accepted: Compose + protocol fakes]
    └── 17.3 Architecture/contract/smoke gates [accepted: tiered CI]
```

Текущий frontier пуст: все ветви dependency baseline рассмотрены и приняты.
Документ готов стать входом для отдельного implementation roadmap; сам по себе
он по-прежнему не разрешает устанавливать зависимости или менять application-код.

## Раунды Решений

### Decision 1 — Какой container становится composition engine?

- Status: accepted
- Context: generic MessageBus требует PSR-11, а ручной `Bootstrap` должен быть
  разделён на HTTP, CLI и Worker composition. Решение определит формат DI
  definitions, способ проверки service graph и правила подключения модулей и
  optional features.
- Options:
  - A — `php-di/php-di` `^7.1`; PSR-11, autowiring concrete services и отдельные
    PHP definitions для interfaces/scalars/factories. Это соответствует upstream
    recommendation MessageBus для standalone-приложения и даёт небольшую
    application-facing поверхность. Цена: часть ошибок проявляется при сборке или
    первом resolution, а compiled mode требует дисциплины definitions.
  - B — `symfony/dependency-injection` `^7.4`; standalone compiled container,
    private services, aliases, decorators, tags и строгая validation service graph.
    Это наиболее сильная модель для сложной composition. Цена: больше
    configuration/compiler semantics, больше Symfony-specific кода в application
    shell и риск постепенно собрать framework-like kernel вокруг AMPHP.
  - C — сохранить ручную сборку и написать минимальный PSR-11 registry/adapter;
    максимальная явность и отсутствие новой production dependency. Цена:
    собственная реализация lifecycle, lazy/shared semantics, handler registry,
    diagnostics и test tooling; при 176 исходных файлах это становится
    поддерживаемой нами DI-библиотекой.
  - D — `thesis/dic` `^0.5.3` плюс узкий application-owned PSR-11 bridge;
    типизированные refs, изолированная composition, eager graph validation,
    request/job scopes и disposers лучше соответствуют долгоживущим AMPHP
    runtime. Цена: PHP 8.4.12+, pre-1 API, отсутствие dumped container и
    обязательные bridge/lifecycle contract tests.
- Recommendation: D, если мы готовы считать небольшой PSR-11 bridge осознанной
  integration boundary и принять pre-1 upgrade policy. Для этого приложения
  scopes/disposal, module isolation и локальная типизация composition дают
  больше архитектурной ценности, чем нативный service locator PHP-DI. Если
  приоритетом является минимальный integration risk и максимальная зрелость
  экосистемы, остаётся A.
- User answer: `2026-11-01` — «да принимаем вариант D нам он лучше подойдёт под
  async из-за лучшего управлением lifecycle».
- Accepted: вариант D — `thesis/dic ^0.5.3` становится composition engine.
  Основной критерий — управляемый lifecycle async HTTP/worker runtime. Принимаются
  PHP `^8.4.12`, scoped request/message execution, disposers, pre-1 upgrade gate и
  необходимость узкой integration boundary для библиотек со строковым service
  locator API.
- Consequences: разблокированы выбор MessageBus, разбиение bootstrap, формат
  runtime composition modules и graph-build checks. HTTP получает scope на
  request, worker — на queue message, CLI — на command. Thesis types остаются в
  application composition shell.
- Conflict check: варианты A и B напрямую согласуются с PSR-11 требованием;
  D согласуется только через явно протестированный bridge и меняет container
  compilation gate на eager graph-build gate. C формально согласуется с PSR-11,
  но противоречит цели снизить стоимость composition при полном переносе.

### Decision 2 — Какой MessageBus и interaction model переносим?

- Status: accepted
- Context: после выбора `thesis/dic` нужно решить, сохраняем ли единый bus с
  behavioral parity или принимаем Thesis async model и разделяем sync/async
  interaction. Решение определяет объём переписывания capabilities, PostgreSQL
  image, worker operations и необходимость PSR-11 bridge.
- Options:
  - A — сохранить `romanfedorskij/message-bus` `^6.1` как единый sync/async bus;
    добавить scope-aware Thesis-to-PSR-11 bridge и AMPHP PostgreSQL producer.
    Сохраняются 10 query contracts, nested dispatch, binding IDs, registry,
    status/cancel и worker control plane. Цена: два узких application adapters и
    отдельная работа над non-blocking enqueue.
  - B — разделить bus: `romanfedorskij/message-bus` оставить для sync queries, а
    `thesis/message-bus 0.5.x-dev` использовать для async command/event delivery.
    Получаем AMP-native PGMQ, transactional outbox/dedup/recoverability. Цена:
    две envelope/metadata/retry модели, две operational поверхности, dev-only
    dependency и PostgreSQL image с PGMQ extension.
  - C — полностью перейти на `thesis/message-bus`, а для sync queries создать
    новый typed application QueryBus. Получаем единый Thesis async stack без
    PSR-11 в bus. Цена: переписывание десяти query handlers и nested dispatch,
    новый собственный QueryBus, потеря готового worker control plane и принятие
    `0.5.x-dev`/PGMQ как production foundation.
  - D — развить `romanfedorskij/message-bus` в отдельную v7: сохранить typed
    queries, nested dispatch, compiled registry, wire contracts и control plane,
    но убрать обязательный PSR-11 service locator из core, добавить neutral
    provider/scope contracts, AMP PostgreSQL adapters и cancellable worker.
    Цена: мы остаёмся владельцами библиотеки и должны отдельно спроектировать
    transaction/outbox/dedup, release compatibility и migration path v6 -> v7.
- Recommendation: D, если MessageBus остаётся стратегической общей библиотекой,
  которую готовы сопровождать независимо от Geo Registry. Это сохраняет
  behavioral parity и позволяет забрать сильные стороны Thesis async design без
  принятия dev-only bus и PGMQ как обязательной платформы. Если отдельный v7
  release не входит в scope, fallback — A; C оправдан только осознанным отказом
  от собственной async runtime в пользу Thesis roadmap.
- User answer: `2026-11-01` — «да фиксируем я все равно хотел обновить библиотеку
  под современные возможности».
- Accepted: вариант D — собственный MessageBus развивается как отдельная
  reusable generation, а не как application-local fork. Сохраняются typed queries,
  nested dispatch, compiled registry, stable binding/wire contracts и worker
  control plane; PSR-11 становится optional adapter, добавляются neutral
  providers/scopes, AMPHP PostgreSQL adapters и cancellable worker lifecycle.
- Consequences: появляется отдельный library workstream с compatibility gate
  со старой v6. Geo Registry использует только публичные contracts и application
  composition adapters. Точная package identity/versioning переоткрыта
  предложением clean-slate `message-bus-async 0.1.0`. Transactional
  outbox/delivery dedup не считаются автоматически решёнными и получают
  отдельный scope decision.
- Conflict check: A согласуется с принятым Decision 1 при условии scope-aware
  bridge. B и C не конфликтуют с Thesis Dic, но конфликтуют с целью behavioral
  parity и минимальной вертикальной миграции без дополнительного обоснования. D
  согласуется с Decision 1, только если Thesis-specific adapter не проникает в
  reusable core и migration приложения не блокируется полным rewrite библиотеки.

### Decision 3 — Что входит в первый usable release новой MessageBus?

- Status: accepted
- Context: Geo Registry не должен ждать полного переизобретения MessageBus, но
  первый release должен уже позволять безопасно исполнять HTTP publish и worker
  jobs в AMPHP/Thesis lifecycle. В текущем приложении нет publish внутри database
  transaction, поэтому transactional outbox не требуется для behavioral parity.
  При этом blocking PDO producer и `usleep()` worker несовместимы с целевым HTTP
  runtime.
- Options:
  - A — core-only first release: убрать обязательный PSR-11, ввести neutral
    provider/resolver и scope contracts, сохранить PDO queue; AMP transport и
    worker выпустить позднее. Самый маленький library release, но Geo Registry
    останется на временном v6/application bridge и миграция async path не
    разблокируется.
  - B — migration baseline first release: core modernization плюс AMP PostgreSQL
    provider/consumer/status/control adapters, cancellation-aware worker,
    job-scope boundary и совместимость существующей queue schema/envelope.
    Сохранить текущие retry/idempotency/control contracts; transactional outbox
    и delivery dedup добавить отдельным scenario-driven increment.
  - C — full transactional first release: до первого release реализовать также
    outbox, delivery dedup, transaction-aware handler runtime и recoverability contract.
    Получаем наиболее полный async foundation, но блокируем migration функциями,
    для которых в текущем приложении ещё нет transactional dual-write scenario.
- Recommendation: B. Это минимальная самостоятельная generation, которая
  действительно разблокирует Geo Registry и проверяет новый lifecycle end-to-end. A выпускает
  новый major без главного practical adapter, а C связывает migration с
  расширением платформы, не подтверждённым текущими call sites.
- User answer: `2026-11-01` — «фиксируем B».
- Accepted: вариант B — первый usable release включает neutral core composition,
  AMP PostgreSQL provider/consumer/status/control adapters,
  cancellation-aware worker, job-scope boundary и совместимость queue
  schema/envelope. Текущие retry/idempotency/control contracts сохраняются;
  transactional outbox и delivery dedup развиваются отдельными increments.
- Consequences: открыты решения по package topology, sync flow ownership и
  durable delivery guarantees. Outbox остаётся запланированным capability, но
  не блокирует первый application migration slice.
- Conflict check: A формально согласуется с Decision 2, но не выполняет цель
  non-blocking HTTP enqueue. B полностью согласуется с Decisions 1–2 при условии,
  что Thesis adapter остаётся вне core. C архитектурно допустим, но расширяет
  scope за пределы подтверждённых требований Geo Registry.

### Decision 4 — Какова package boundary новой `message-bus-async`?

- Status: accepted
- Context: пользователь предлагает начать отдельную библиотеку
  `message-bus-async` с версии `0.1.0`, развивать её вместо major-upgrade старого
  package и позже архивировать `romanfedorskij/message-bus`. Но Geo Registry
  сохраняет sync `Query<TResult>` и nested dispatch, а уже опубликованный
  `romanfedorskij/message-bus-amphp` занимает близкую AMPHP integration niche.
- Options:
  - A — standalone full replacement `romanfedorskij/message-bus-async` `0.1.0`:
    async-first, но не async-only. Новый package независимо реализует local typed
    query/command dispatch, nested context, registry/envelope contracts и AMP
    queue/worker runtime; он не зависит от старого bus. После migration
    `message-bus` и `message-bus-amphp` deprecated/archived. Один package для
    приложения, но README и description должны явно объяснять sync lane.
  - B — async extension `message-bus-async` `0.1.0`, зависящий от
    `romanfedorskij/message-bus ^6.2`. Быстрее переиспользует core, но старый
    package нельзя архивировать, пока extension от него зависит; сохраняются
    PSR-11 и legacy contracts, от которых хотим уйти.
  - C — новая package family: `message-bus-core` `0.1.0` для contracts,
    registry/envelope и sync dispatch, `message-bus-async` `0.1.0` для AMP
    transport/worker. Старые packages архивируются после compatibility migration.
    Граница честная, но сразу создаёт два versioned release и cross-package BC
    surface до стабилизации API.
  - D — отказаться от нового package name и выпустить
    `romanfedorskij/message-bus` v7, а `message-bus-amphp` deprecated. Сохраняет
    Composer identity и upgrade continuity, но противоречит выбранному clean-slate
    `0.1.0` и сильнее связывает новую архитектуру с прежним public API.
- Recommendation: A. Оно соответствует предложенному clean slate и принятому
  единому sync/async interaction model без преждевременного package split. Название
  фиксирует async-first runtime, а публичный positioning обязан сказать, что
  local typed query lane остаётся частью библиотеки. Существующий
  `message-bus-amphp` следует deprecated в пользу нового package, а старый
  `message-bus` архивировать только после migration/compatibility checklist.
- User answer: `2026-11-01` — «вариант А, потому что воркеров хочется вынести так
  же в отдельный подключаемый пакет, чтобы отделить асинхронное ядро и расширение
  под воркеров».
- Accepted: вариант A с уточнением package set. `message-bus-async` `0.1.0`
  является clean-slate async-first replacement без зависимости от старого bus и
  сохраняет local typed query/command dispatch, nested context, registry и
  producer-facing queue API. Consumer/worker runtime поставляется отдельным
  optional package, также не зависящим от старой библиотеки.
- Consequences: первый migration baseline реализуется согласованным release двух
  packages, а не одним monolith. Core можно использовать в HTTP/CLI producer без
  Symfony Console, pcntl/posix и worker control plane. Старые `message-bus` и
  `message-bus-amphp` архивируются только после migration/compatibility checklist.
- Conflict check: A согласуется с Decisions 1–3, только если сохраняет sync query
  contracts, необходимые Geo Registry. Вариант с async-only API конфликтует с
  Decision 2 и потребует заново открыть выбор QueryBus.
- Resolution `2026-11-09`: `romanfedorskij/message-bus-amphp` deprecated,
  локальный checkout удалён, runtime-зависимостей на package в Geo Registry и
  актуальном семействе библиотек нет. Упоминания выше сохранены как историческое
  evidence принятого package-topology решения; отдельная поддержка или
  migration work для package больше не планируется.

### Decision 5 — Что именно уходит в optional worker package?

- Status: accepted
- Context: текущий `MessageBusRuntime` агрегирует bus, producer, consumer,
  runner, status/cancel, worker control и PostgreSQL lifecycle. Простое
  перемещение `src/Worker` недостаточно: consumer/runner живут в `src/Queue`, а
  status/cancel нужны HTTP-приложению без установленного worker package. Новая
  dependency graph должна быть направлена только `worker -> core`.
- Options:
  - А — thin runner package: worker extension содержит только consumer loop,
    runner и CLI, а все worker control models/repositories и PostgreSQL storage
    остаются в core. Минимум перемещений, но core продолжает тянуть operational
    concepts и почти не выигрывает от разделения.
  - Б — operational worker extension: core содержит messages, registry/envelope,
    local dispatch, publish/producer, queue job/retry contracts, status/cancel
    API и общий AMP PostgreSQL queue gateway/schema. Worker package содержит
    consumer contracts/implementation, ack/retry/reject execution, job scopes,
    worker runner, heartbeat/recovery, worker control plane, Symfony Console и
    optional process/signal adapters. Worker зависит от core, core не знает о
    worker package.
  - В — worker owns весь transport: core содержит только dispatch/publish ports,
    а PostgreSQL producer, status и cancel тоже находятся в worker extension.
    Граница физически простая, но HTTP-приложению придётся установить worker
    package только ради enqueue/status/cancel, что делает extension не optional.
  - Г — сразу три packages: core, PostgreSQL transport и worker runtime. Самая
    чистая transport independence, но для `0.1.0` создаёт три синхронно
    версионируемых API и усложняет первый migration slice.
- Recommendation: Б. Это даёт реальную optional worker dependency без
  преждевременного выделения transport package. Producer/status/cancel остаются
  доступны HTTP runtime, а Symfony Console, consumer loop и control plane не
  загрязняют core. PostgreSQL transport можно выделить третьим package позже,
  когда появится второй transport или независимый reuse scenario.
- User answer: `2026-11-01` — после уточнения неоднозначной буквы выбран второй
  вариант: operational worker extension. Все следующие варианты пользователь
  попросил обозначать русскими буквами.
- Accepted: вариант Б — core владеет producer, queue job/retry contracts,
  status/cancel API и общей AMP PostgreSQL queue schema/gateway; optional worker
  package владеет consumer execution, ack/retry/reject, job scopes,
  heartbeat/recovery, worker control plane, Symfony Console и optional
  process/signal adapters. Dependency direction: только `worker -> core`.
- Consequences: Б потребует разделить текущий combined PostgreSQL storage на
  producer/status-control gateway в core и consumer-side implementation в worker
  package либо дать worker безопасно использовать публичный core storage port.
  Queue schema ownership остаётся в core; worker control schema принадлежит
  worker package.
- Conflict check: Б согласуется с Decisions 1–4 и сохраняет worker optional. В
  конфликтует с non-blocking HTTP enqueue без worker dependency. Г допустим, но
  нарушает принцип не создавать dependency до подтверждённого второго consumer.

### Decision 6 — Как назвать optional worker package?

- Status: accepted
- Context: package должен однозначно принадлежать новой
  `message-bus-async` family, не выглядеть расширением архивируемого
  `message-bus` и не обещать отдельный transport. Проверенные candidate names
  пока не опубликованы под vendor `romanfedorskij` в Packagist.
- Options:
  - А — `romanfedorskij/message-bus-async-worker`. Явно связан с core package,
    обозначает один runtime component, хотя умеет запускать много worker
    instances; длиннее остальных, но не конфликтует с legacy naming.
  - Б — `romanfedorskij/message-bus-worker`. Короче, но выглядит extension-ом
    старого `romanfedorskij/message-bus` и после его архивирования теряет явную
    связь с новой async family.
  - В — `romanfedorskij/message-bus-async-runtime`. Покрывает runner, control
    plane и CLI, но термин runtime слишком широк и может быть ошибочно принят за
    HTTP/application runtime самого core.
  - Г — `romanfedorskij/message-bus-async-workers`. Подчёркивает управление
    несколькими процессами, но Composer package обычно именуется по component в
    единственном числе, а не по числу runtime instances.
- Recommendation: А — `romanfedorskij/message-bus-async-worker`. Оно сохраняет
  очевидную package family, не связывается с legacy bus и оставляет место для
  будущих sibling extensions, например transport package, если он действительно
  понадобится.
- User answer: `2026-11-01` — «вариант А».
- Accepted: вариант А — Composer package называется
  `romanfedorskij/message-bus-async-worker`; первая release line начинается с
  `0.1.x` и требует совместимую `message-bus-async 0.1.x`.
- Consequences: выбранное Composer-имя станет основой README/install commands и
  deprecation replacement metadata старых packages. PHP namespace решается
  отдельно и не обязан дословно повторять Composer slug.
- Conflict check: все варианты совместимы с Decisions 1–5; Б создаёт naming
  ambiguity с архивируемой библиотекой, В размывает runtime ownership, Г менее
  естественен для package family.

### Decision 7 — Какой PHP namespace получает новая package family?

- Status: accepted
- Context: старый package экспортирует `Wolfcharaa\MessageBus\*`. Новый core и
  worker должны иметь общую namespace family, но не обязаны копировать Composer
  slug. Collision-free namespace позволяет временно установить старую и новую
  библиотеки вместе для migration adapters и compatibility tests.
- Options:
  - А — сохранить `Wolfcharaa\MessageBus\*`, worker разместить в
    `Wolfcharaa\MessageBus\Worker\*`. Минимум изменений application imports, но
    старый и новый packages нельзя безопасно установить одновременно из-за
    одинаковых FQCN и PSR-4 mappings.
  - Б — `Wolfcharaa\MessageBusAsync\*` и
    `Wolfcharaa\MessageBusAsync\Worker\*`. Сохраняется GitHub/legacy brand root и
    устраняется collision, но Composer vendor `romanfedorskij` и PHP vendor
    `Wolfcharaa` продолжают расходиться.
  - В — `RomanFedorskij\MessageBusAsync\*` и
    `RomanFedorskij\MessageBusAsync\Worker\*`. Namespace явно отражает новую
    async generation и совпадает с Composer vendor, но слово `Async` попадает в
    нейтральные типы `Query`, `Envelope` и local dispatch API.
  - Г — короткий новый root `Fedor\MessageBus\*` и
    `Fedor\MessageBus\Worker\*`. Namespace не повторяет Composer vendor дословно,
    не конфликтует со старым `Wolfcharaa\MessageBus`, а async identity остаётся
    на уровне package/runtime, не загрязняя нейтральные contract names.
- Recommendation: Г — короткий clean-slate namespace. Он даёт безопасный
  coexistence path и наиболее естественные FQCN. Worker extension
  получает более узкий `Fedor\MessageBus\Worker` и зависит от core
  namespace, не создавая параллельную model hierarchy.
- User answer: `2026-11-01` — сначала выбран вариант Г, затем namespace сокращён
  до «просто Fedor».
- Accepted: вариант Г — core использует `Fedor\MessageBus\*`, worker extension —
  `Fedor\MessageBus\Worker\*`. Composer slug отражает
  async-first distribution, но нейтральные PHP contracts не получают суффикс
  `Async`.
- Consequences: выбор Г означает механическую миграцию imports/attributes из
  `Wolfcharaa\MessageBus` и позволяет писать side-by-side compatibility tests.
  Wire aliases и binding IDs при этом сохраняются независимо от PHP namespace.
- Conflict check: А противоречит безопасному coexistence старой и новой package
  line. Б/В/Г совместимы с Decisions 1–6; В делает async частью contract naming,
  хотя core сохраняет sync query lane.

### Decision 8 — Как выглядит public API local sync lane?

- Status: accepted
- Context: старый `MessageBusInterface` смешивает query result, sync command,
  publish, fan-out и worker/internal binding methods. Новая package family
  сохраняет единый registry и nested context, но чистый API должен позволять
  capability handler-ам зависеть только от реально нужного interaction port.
- Options:
  - А — оставить один широкий `MessageBusInterface` с legacy-style
    `dispatch()`/`publish()` и conditional `mixed` result. Минимум migration
    изменений, но query-only code видит queue/fan-out/internal operations, а
    неправильный message kind обнаруживается позднее.
  - Б — только раздельные interfaces: `QueryBus::ask()`,
    `CommandBus::dispatch()` и `EventPublisher::publish()`, без общего facade.
    Максимальная interface segregation, но nested orchestration и composition
    должны собирать три отдельных collaborators даже при одной реализации.
  - В — core предоставляет только async publisher, а sync QueryBus остаётся
    application-owned. Уменьшает библиотечный API, но повторяет уже отклонённую
    модель Thesis и теряет общий registry/context для десяти queries.
  - Г — segregated capabilities плюс composite facade. Публичные
    `QueryBusInterface::ask(Query<TResult>): TResult`,
    `CommandBusInterface::dispatch(Command): void` и
    `EventPublisherInterface::publish(Event): PublishResult` реализуются одним
    `MessageBus`; composite `MessageBusInterface` объединяет их только для
    composition/nested context. Fan-out и binding execution выносятся в
    отдельные advanced/internal contracts.
- Recommendation: Г. Сохраняется одна implementation/registry/context model и
  behavioral parity, но application handlers получают узкий typed port. Метод
  `ask()` устраняет двусмысленность старого `dispatch(): mixed`, а worker package
  использует отдельный envelope/binding executor вместо полного application bus.
- User answer: `2026-11-01` — «вариант Г».
- Accepted: вариант Г — `QueryBusInterface::ask()`,
  `CommandBusInterface::dispatch()` и `EventPublisherInterface::publish()`
  являются узкими application-facing capabilities, реализованными одним
  composite `MessageBusInterface`. Fan-out и binding/envelope execution получают
  отдельные advanced/internal contracts.
- Consequences: Г требует migration map `dispatch(Query)` -> `ask(Query)` и
  `dispatch(Command)` -> `dispatch(Command)`, но позволяет статическому анализу
  различать result-bearing и void interactions. Nested context может реализовать
  те же три capabilities с сохранением causation envelope.
- Conflict check: А совместим функционально, но противоречит цели очистить
  public contracts. Б допустим, однако усложняет orchestration без выигрыша в
  реализации. В конфликтует с Decisions 2 и 4. Г согласуется со всей принятой
  package/lifecycle моделью.

### Decision 9 — Какую delivery guarantee обещает durable queue?

- Status: accepted
- Context: текущая queue уже работает как at-least-once: claim фиксируется до
  handler, success подтверждается отдельным ack, stale running job после crash
  возвращается в retry. Новая AMP implementation должна назвать эту семантику
  прямо и не обещать exactly-once для внешних side effects, которые невозможно
  атомарно объединить с queue ack.
- Options:
  - А — at-most-once: окончательно забирать job до handler execution. Дубликатов
    меньше, но crash после claim теряет работу без retry; неприемлемо для GAR
    import/build jobs.
  - Б — at-least-once: transactional claim с visibility/heartbeat lease,
    explicit ack/retry/reject, stale recovery и persisted retry snapshot.
    Duplicate execution допустим, поэтому state-changing handlers обязаны иметь
    idempotency policy/key или доказанный naturally-idempotent effect.
  - В — заявить exactly-once в пределах одной PostgreSQL transaction, связав
    queue state, idempotency и business writes. Работает только для одного DB
    boundary и не покрывает OpenSearch, HTTP, files или crash после внешнего
    effect; как общий library promise вводит в заблуждение.
  - Г — выбирать at-most/at-least/exactly-once per flow. Гибко на бумаге, но
    создаёт несколько несовместимых failure models и ложную уверенность для
    transport-external side effects.
- Recommendation: Б — честный at-least-once contract. Он сохраняет recoverability
  существующей очереди, совместим с AMP cancellation и не маскирует distributed
  atomicity problem. Transactional outbox/inbox может позже усиливать отдельные
  сценарии, но не переименовывает общую delivery guarantee в exactly-once.
- User answer: `2026-11-01` — «вариант Б».
- Accepted: вариант Б — durable queue гарантирует at-least-once delivery через
  transactional claim, visibility/heartbeat lease, явные ack/retry/reject и
  stale recovery. State-changing handlers обязаны иметь idempotency key/policy
  либо доказанный naturally-idempotent effect.
- Consequences: Б делает idempotency/replay tests release gate для каждого
  state-changing async binding. Worker завершает ack только после успешного
  handler scope; cancellation/retry/reject transitions также должны быть
  durable. Queue schema хранит lease owner/expiry, attempts и retry policy
  snapshot.
- Conflict check: Б согласуется с Decisions 3–5 и текущими application scenarios.
  А конфликтует с recoverability, В/Г конфликтуют с ранее отложенным outbox и
  отсутствием единой transaction boundary у OpenSearch/HTTP/file effects.

### Decision 10 — Как разделить composition roots HTTP, CLI и Worker?

- Status: accepted
- Context: в scaffold пока существует только HTTP entrypoint, а ручной
  `Bootstrap::run()` одновременно собирает process resources и управляет их
  lifecycle. После подключения Thesis Dic HTTP request, CLI command и worker
  message должны получать разные scopes и не должны неявно поднимать ресурсы
  чужого runtime.
- Options:
  - А — один универсальный root/container для HTTP, CLI и Worker. Entry point
    получает общий application runtime и выбирает нужную часть. Definitions
    меньше, но graph содержит optional/чужие ресурсы, а lifecycle boundaries
    становятся неявными.
  - Б — три явных root export: `HttpRuntime`, `CliRuntime` и `WorkerRuntime`,
    собранные из общих reusable modules/refs. Каждый entrypoint строит только
    свой graph; request/message/command scopes и disposers принадлежат
    соответствующему runtime.
  - В — один root factory с `RuntimeKind` и conditional registrations. Общие
    definitions переиспользуются, но корректность graph зависит от runtime
    branches, а eager validation должна отдельно перебирать все варианты.
  - Г — оставить ручные независимые bootstrap для CLI и Worker, применив Thesis
    Dic только к HTTP. Быстрее для первого CLI, но создаёт две lifecycle модели и
    повторяет composition rules вне выбранного engine.
- Recommendation: Б. Три process graph остаются явными и независимо
  валидируемыми, а повторение устраняется общими modules, а не универсальным
  container. Это непосредственно выражает уже принятое разделение HTTP, CLI и
  worker процессов и lifetimes.
- User answer: `2026-11-01` — «вариант Б».
- Accepted: вариант Б — application composition экспортирует отдельные
  `HttpRuntime`, `CliRuntime` и `WorkerRuntime`, собранные из общих reusable
  modules/refs. Каждый process строит и освобождает только собственный graph.
- Consequences: при Б application composition layer экспортирует три root-типа,
  а shared modules не экспортируют process-specific services. HTTP root владеет
  server/request scope, CLI — command scope, Worker — consumer/message scope и
  shutdown coordination. Graph validation запускается отдельно для каждого
  root.
- Conflict check: Б согласуется с Decision 1 и operational split Decisions 4–6.
  А/В сохраняют техническую возможность, но размывают lifecycle isolation. Г
  конфликтует с единым composition policy и усложняет проверку disposal.

### Decision 11 — Как разделить application CLI и worker CLI?

- Status: `superseded 2026-11-06` единым executable с lazy runtime boundaries
- Context: исходное приложение переносит 18 полноценных команд, уже использующих
  Symfony Console arguments/options через Spiral Console. Одновременно Decision
  5 отнесла worker control plane и его Console integration к optional worker
  package, а Decision 10 разделила `CliRuntime` и `WorkerRuntime`.
- Options:
  - А — один application executable на Symfony Console объединяет бизнес-команды
    и worker operational commands. Пользователю доступна одна точка входа, но
    обычный CLI graph начинает зависеть от optional worker package и его process
    lifecycle.
  - Б — приложение напрямую подключает `symfony/console`, владеет
    `app/bin/console` и явно регистрирует application commands из `CliRuntime`;
    worker package предоставляет отдельный executable/console для consume,
    recovery и control operations через `WorkerRuntime`.
  - В — не объявлять Symfony Console в приложении и использовать его транзитивно
    через worker package для всех команд. Меньше строк в `composer.json`, но
    нарушается правило прямой зависимости и приложение невозможно использовать
    без optional worker extension.
  - Г — отказаться от console framework и написать минимальный argv dispatcher.
    Уменьшает dependency set, но заново реализует help, validation, exit codes,
    signals и совместимость 18 переносимых команд.
- Recommendation: Б. Application и worker CLI получают независимые executables,
  graphs и release surface; при этом обе стороны используют зрелые Symfony
  Console contracts без скрытой транзитивной зависимости. Регистрация команд
  остаётся явной частью соответствующего Thesis Dic root.
- Первоначальный user answer: `2026-11-01` — «вариант Б» с раздельными
  executables.
- Superseding user answer: `2026-11-06` — использовать единый `bin/app` как
  console entrypoint для всех действий и подключить официальный
  `thesis/symfony-console-module`.
- Accepted: вариант А в уточнённой форме — один application executable и один
  lazy command registry, но не один runtime graph. `server:run` resolve-ит
  `HttpRuntime` только при выполнении; worker commands через
  `BootstrapResolver` открывают отдельный managed `WorkerRuntimeProvider`;
  blocking application commands остаются в CLI-only modules.
- Consequences: `symfony/console` и `thesis/symfony-console-module` являются
  прямыми application dependencies. Старые `bin/server.php`, `bin/console` и
  `bin/worker` удалены. Lazy `CommandTag`/`LegacyCommandTag` не создают pools,
  clients или job scopes невыбранной команды.
- Conflict check: общий executable больше не склеивает lifecycle, поскольку
  command provider является только отложенной границей resolve, а worker
  provider по-прежнему владеет собственным `Dic::run()` и disposal.

### Decision 12 — Как устроить configuration layer?

- Status: accepted
- Context: Compose уже загружает local env-файл и передаёт приложению явные
  process variables. Текущий `AppConfig` самостоятельно читает `getenv()` и
  смешивает HTTP server, PostgreSQL, Nominatim и OpenSearch settings. С тремя
  root export универсальный config заставит каждый runtime видеть лишние
  настройки и усложнит тестирование ошибок конфигурации.
- Options:
  - А — сохранить единый `AppConfig::fromEnvironment()` со статическим
    `getenv()`. Минимум изменений, но скрытый global input, общий набор полей для
    всех runtimes и рост монолитного config object.
  - Б — не добавлять config package: application-owned immutable environment
    snapshot/parser на composition boundary создаёт отдельные typed settings
    (`HttpServerConfig`, `PostgresConfig`, `NominatimConfig`,
    `OpenSearchConfig`, `WorkerConfig`). Каждый runtime импортирует только нужные
    settings; required values fail fast, secrets редактируются в diagnostics.
  - В — подключить `symfony/config` и описать configuration tree с merge файлов
    и environment values. Получаем богатую schema/normalization model, но вводим
    второй configuration graph рядом с Thesis Dic ради единственного env source.
  - Г — подключить `symfony/dotenv` или `vlucas/phpdotenv` и загружать
    `config/.env` внутри каждого PHP entrypoint. Удобно без Compose, но дублирует
    существующий launcher, связывает production runtime с local file convention
    и само по себе не создаёт typed config objects.
- Recommendation: Б. Для текущего единственного source библиотека не нужна:
  сложность находится в typed parsing, validation, ownership и redaction, а не в
  чтении формата файла. Environment snapshot делает input явным и тестируемым;
  позднее file/remote-secret source можно добавить за тем же узким reader port.
- User answer: `2026-11-01` — «вариант Б».
- Accepted: вариант Б — новая config/dotenv dependency не добавляется.
  Application-owned immutable environment snapshot преобразуется в отдельные
  typed runtime/integration settings на composition boundary.
- Consequences: при Б environment читается один раз до построения Thesis Dic
  graph. Config objects являются immutable values без доступа к globals и DI
  container; integration modules получают собственные settings. Local
  `config/.env` остаётся обязанностью Compose tooling, production —
  orchestrator/secret store, а значения secrets не выводятся в exceptions/logs.
- Conflict check: Б согласуется с Decisions 1 и 10 и текущим Docker workflow.
  А размывает runtime boundaries, В добавляет параллельный configuration DSL без
  подтверждённой потребности, Г дублирует уже существующую env injection.

### Decision 13 — Чем проверять DI graphs и архитектурные границы?

- Status: accepted
- Context: PHPStan `max` проверяет типы, но не реализует записанный dependency
  graph модулей. Deptrac проверяет статические зависимости слоёв, тогда как
  Thesis Dic build и MessageBus registry compile проверяют другие классы ошибок:
  missing/ambiguous bindings, lifetime mismatch и invalid message handlers.
- Options:
  - А — оставить только PHPStan, Testo и ручной review архитектуры; DI/registry
    проверять при обычном запуске runtime. Нет новой dependency, но нарушения
    направлений и broken secondary runtime обнаруживаются поздно.
  - Б — добавить dev dependency `deptrac/deptrac ^4.7` для layer/module rules и
    отдельный project check, который строит все три Thesis Dic root с test
    configuration и компилирует MessageBus registry. Оба gate входят в
    `composer check`/CI без baseline нарушений.
  - В — реализовать все layer/module запреты как собственные PHPStan rules и
    дополнить их DIC/registry tests. Один analyser, но проект принимает на себя
    AST/type integration и поддержку architecture rules, уже готовых в Deptrac.
  - Г — использовать только Deptrac для архитектуры и считать статический graph
    достаточным. Проще pipeline, но не обнаруживаются runtime bindings, scopes,
    disposers и ошибки MessageBus registry.
- Recommendation: Б. Deptrac отвечает только за статическое направление
  зависимостей, а project-owned graph/registry check — за executable composition.
  Это разные failure classes; объединять их в собственный analyser или заменять
  одним инструментом невыгодно. Package остаётся строго `require-dev`.
- User answer: `2026-11-01` — «вариант Б».
- Accepted: вариант Б — `deptrac/deptrac ^4.7` добавляется как dev dependency
  для layer/module rules, а project-owned check отдельно строит три Thesis Dic
  root и компилирует MessageBus registry.
- Consequences: при Б `deptrac.php` становится executable отражением
  `docs/architecture.md`: Application, module UI/Capability/Infrastructure,
  Features и external framework layers. Любой skip описывается причиной и не
  используется как общий baseline. Отдельная check-команда строит
  `HttpRuntime`, `CliRuntime`, `WorkerRuntime` на безопасных test doubles и
  компилирует MessageBus registry без подключения внешних сервисов.
- Conflict check: Б согласуется с Decisions 1, 8 и 10 и Phase 1 родительского
  migration plan. А/Г оставляют непроверяемые классы ошибок; В возможен, но
  создаёт собственный tooling без domain-specific необходимости.

### Decision 14.1 — Какой migration engine сохраняет историю схемы?

- Status: accepted
- Context: все 21 migration исходного приложения уже используют Yiisoft DB
  Migration contracts и имеют осмысленную transaction policy; три migration
  намеренно non-transactional. Миграции выполняются отдельной CLI-командой,
  поэтому blocking PDO не нарушает non-blocking HTTP invariant.
- Options:
  - А — переписать migrations в project-owned raw SQL runner. Runtime dependency
    меньше, но придётся заново реализовать history, ordering, up/down, locking,
    failure reporting и transaction policy.
  - Б — сохранить `yiisoft/db-migration ^2.2` и `yiisoft/db-pgsql ^2.0` как
    blocking migration adapter внутри `CliRuntime`; перенести 21 class без
    изменения имён/history и зарегистрировать Yii migration commands в
    application Symfony Console.
  - В — перейти на Doctrine Migrations/DBAL и переписать history в новый API.
    Инструмент зрелый, но не даёт подтверждённого выигрыша, требует mapping
    существующей history table и добавляет второй DB abstraction рядом с AMP.
  - Г — перейти на Phinx и переписать migrations/configuration. Получаем
    standalone runner, но теряем прямую совместимость с текущими interfaces,
    transaction markers и command wiring.
- Recommendation: Б. Это единственный вариант, сохраняющий проверенную schema
  history и transaction intent без migration rewrite. Yii DB/PDO остаются
  изолированы в CLI composition; application runtime и capabilities продолжают
  использовать AMP PostgreSQL ports. Перед cutover обязательны apply-from-zero,
  existing-history и rollback tests на PostgreSQL fixture.
- User answer: `2026-11-01` — «можно сохранить вариант Б и отдельно обсудить
  миграцию для доработки».
- Accepted: вариант Б — сохраняются `yiisoft/db-migration ^2.2` и
  `yiisoft/db-pgsql ^2.0`; существующие 21 migration и их history identifiers не
  переписываются. Partitioning проектируется отдельной последующей migration.
- Consequences: при Б migration connection не экспортируется из `CliRuntime` как
  application data adapter. Existing FQCN и migration history identifiers
  сохраняются; queue schema получает новые project/package migrations через тот
  же engine. `Yiisoft\Injector\Injector` создаётся без общего service locator,
  пока migrations остаются parameterless.
- Conflict check: Б согласуется с Decisions 10–12 и допускает blocking I/O только
  в CLI. А/В/Г создают migration risk без выявленного функционального пробела.

### Decision 14.2 — Как вводить партиционирование больших GAR-таблиц?

- Status: accepted
- Context: partitioning потенциально полезен на полном GAR dataset, а progressive
  suggest действительно работает после выбора региона/родителя. Однако текущий
  SQL сначала делает nationwide `DISTINCT ON` по raw `address_objects` и лишь
  затем ограничивает hierarchy path. Региональный ключ отсутствует в canonical
  tables и suggest contracts, но `address_search` уже является rebuildable read
  projection, которую можно изменить без перестройки всех foreign keys.
- Options:
  - А — оставить все таблицы непартиционированными и ограничиться индексами,
    VACUUM/ANALYZE и настройкой import pipeline. Самый безопасный baseline, но не
    использует partition pruning и изоляцию больших индексов.
  - Б — evidence-first pilot: после загрузки representative/full dataset снять
    relation sizes, cardinality/skew, import throughput и `EXPLAIN (ANALYZE,
    BUFFERS)`; первым кандидатом сделать отдельную forward migration
    `object_params` на HASH(`object_id`) с ограниченным числом partitions.
    Расширять partitioning только при измеримом выигрыше.
  - В — сразу денормализовать `region_code` во все крупные GAR-таблицы и
    разделить их LIST partitions по регионам. Максимальный pruning для строго
    region-scoped операций, но требует composite keys, перестройки foreign/self
    references и протаскивания региона во все import/query contracts.
  - Г — region-first read model: canonical GAR tables пока не partition-ить;
    создать/расширить региональную suggestion/search projection с
    `region_code`, LIST partitions и заранее схлопнутой active version. Первый
    suggest stage читает маленький глобальный каталог регионов, возвращает
    `regionCode`; последующие stages и search передают его явно и обращаются к
    одной partition без online nationwide `DISTINCT`.
- Recommendation: Г с evidence gate из Б. Аргумент пользователя о региональном
  workload верен, но применять его безопаснее сначала к disposable read model,
  а не к canonical import schema. Это устраняет nationwide dedup из online path,
  сохраняет глобальные GAR keys/foreign keys и использует уже существующий
  shadow rebuild/cutover. После benchmark raw tables можно partition-ить
  отдельно, если projection build всё ещё упирается в них.
- User answer: `2026-11-01` — «вариант Б логичен, а вариант В более правильный,
  потому что поиск идёт в рамках региона, который вычисляется в suggest; suggest
  также можно обновить, чтобы не смотреть всю таблицу через distinct».
- User answer: `2026-11-01` — «давай вариант Г в таком случае; насчёт варианта Б
  надо будет смотреть на практике».
- Accepted: Г — canonical GAR schema пока сохраняется; `region_code` становится
  явной частью suggest/search context, а регионально partitioned read model
  заранее схлопывает active version. Вариант Б применяется как benchmark gate:
  raw tables меняются только после измеримого выигрыша на практических данных.
- Consequences: при Г `regionCode` становится частью `AddressSuggestion`, parent
  context и downstream suggest/search messages. Projection имеет unique key,
  включающий partition key, например `(region_code, object_id,
  hierarchy_type)`. Region catalog остаётся маленькой global table; региональные
  partitions строятся shadow-процессом и переключаются после parity checks.
  Query contract применяет region predicate до ranking/dedup.
- Conflict check: Г сохраняет Decisions 14.1 и текущие canonical import
  contracts. В соответствует workload, но преждевременно меняет все primary/FK
  boundaries. Б остаётся следующим шагом для raw build performance, а не первым
  online-search optimization. А остаётся fallback при отрицательном benchmark.

### Decision 14.3 — Где разрешён blocking database access для batch-сценариев?

- Status: accepted
- Context: HTTP runtime должен оставаться неблокирующим, но текущий GAR import
  получает основной throughput через blocking `ext-pgsql` COPY. Переписывание
  COPY поверх обычных async INSERT не является эквивалентной оптимизацией, а
  прямой запуск blocking importer внутри общего AMPHP worker остановит loop,
  cancellation и lease heartbeat на время операции.
- Options:
  - А — унифицировать все runtime на `amphp/postgres`, включая XML import,
    denormalization и rebuild. Один database API, но до появления async COPY
    adapter теряется текущая bulk-load semantics или требуется писать
    PostgreSQL protocol integration.
  - Б — разделить по runtime и isolation boundary: HTTP и обычные async workers
    используют `amphp/postgres`; migrations и интерактивный CLI используют
    Yiisoft/PDO; GAR import и тяжёлый rebuild сохраняют blocking
    Yiisoft/PDO/`ext-pgsql`, но queue worker запускает их только в отдельном
    batch process и наблюдает за ним из неблокирующего parent loop.
  - В — разрешить blocking adapters прямо в долгоживущем AMPHP worker. Меньше
    process orchestration, но на время COPY/rebuild перестают работать
    cooperative cancellation, heartbeat и параллельные fibers этого процесса.
  - Г — оставить import/rebuild исключительно ручными CLI-командами и не
    поддерживать их постановку в durable queue. Самая простая runtime boundary,
    но теряются уже существующие queued batch scenarios и recoverability.
- Recommendation: Б. Он сохраняет проверенный COPY path и одновременно делает
  blocking boundary физически проверяемой: blocking adapters не могут попасть
  в `HttpRuntime` или обычный `WorkerRuntime`. После уточнения пользователя
  process isolation не нужен в текущем scope, потому что import не ставится в
  очередь и целиком принадлежит `CliRuntime`.
- User answer: `2026-11-01` — «давай вариант Б, потому что импорт данных будет
  исключительно в CLI, в котором нам нужно сохранить максимальную
  производительность».
- Accepted: Б с уточнением — migrations, GAR import, denormalization и shadow
  rebuild выполняются blocking adapters внутри `CliRuntime`; GAR import является
  CLI-only operation и не имеет MessageBus handler. `HttpRuntime` и
  `WorkerRuntime` используют только неблокирующие DB adapters. Если queued
  import понадобится позднее, его process isolation открывается как новое
  решение, а не включается заранее.
- Consequences: отдельный `BatchRuntime` сейчас не вводится. Нужны раздельные DIC
  graph checks для запрета Yiisoft/PDO/`ext-pgsql` import adapters в HTTP/worker,
  CLI integration tests для exit code, signal/rollback и cleanup staging tables,
  а также прямые Composer platform requirements для используемых extensions.
- Conflict check: уточнённый Б согласуется с Decisions 10–12 и 14.1 и сознательно
  меняет прежний queued import flow на прямой CLI execution. А требует нового
  async COPY исследования; В конфликтует с async lifecycle; Г избыточно
  запрещает другие durable queue scenarios, хотя CLI-only ограничение нужно
  только import pipeline.

### Decision 14.4 — Какой streaming primitive использовать для GAR XML import?

- Status: accepted
- Context: import теперь гарантированно выполняется только в CLI, поэтому
  blocking API допустим, а приоритетами становятся throughput, ограниченная
  память и предсказуемый rollback. Текущая реализация уже читает XML forward-only
  и загружает строки в staging table через PostgreSQL COPY.
- Options:
  - А — сохранить нативный `XMLReader` + bounded tab-CSV batches +
    `ext-pgsql` COPY в temporary staging table с последующим set-based upsert.
    Объявить `ext-xmlreader` и `ext-pgsql` прямыми platform requirements.
  - Б — оставить `XMLReader`, но выполнять prepared INSERT/UPSERT для каждой
    строки. Проще error attribution, но больше round trips и ниже throughput.
  - В — загружать файл через DOM/SimpleXML и затем делать bulk insert. Проще
    navigation по документу, но размер памяти растёт вместе с GAR XML.
  - Г — подключить сторонний streaming XML parser поверх PHP extensions.
    Дополнительная abstraction имеет смысл только при доказанном пробеле
    `XMLReader`, которого в текущем importer не найдено.
- Recommendation: А. Это единственный вариант, который сохраняет текущие
  forward-only memory bounds и нативный PostgreSQL bulk path без новой
  dependency. Performance gate должен сравнивать rows/sec, COPY bytes/sec,
  merge time и peak RSS на representative regional/full files.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — нативный `XMLReader` читает GAR XML forward-only, bounded batches
  преобразуются в tab-CSV для `ext-pgsql` COPY во временную staging table, после
  чего выполняется set-based upsert. `ext-xmlreader` и `ext-pgsql` объявляются
  прямыми platform requirements; сторонний parser package не добавляется.
- Consequences: importer остаётся Infrastructure adapter `CliRuntime`; XML row
  mapping тестируется fixtures без БД, а COPY/staging/upsert — PostgreSQL
  integration tests. Batch size управляет PHP buffer/progress frequency, но не
  превращает импорт в per-row SQL.
- Conflict check: А напрямую продолжает Decision 14.3. Б снижает throughput;
  В нарушает bounded-memory goal; Г добавляет библиотеку без найденного
  функционального пробела.

### Decision 14.5 — Как строить и переключать OpenSearch projection?

- Status: accepted
- Context: OpenSearch остаётся optional derived projection, а PostgreSQL
  regional `address_search` — источником истины. Старое приложение уже умеет
  blue-green rebuild и alias switch, но использует blocking HTTP и несколько
  `proc_open` процессов. В целевом scaffold уже есть native async REST client на
  `amphp/http-client`, который пока не реализует bulk/index lifecycle.
- Options:
  - А — расширить native AMPHP client типизированными mapping, `_bulk`, refresh,
    settings и alias operations. Full rebuild выполняет один `CliRuntime` с
    bounded async concurrency по регионам/batches, пишет versioned physical
    index, проверяет parity и атомарно переключает read alias.
  - Б — разбить rebuild на durable MessageBus jobs по регионам/ranges; workers
    параллельно пишут build index, а coordinator переключает alias после общего
    manifest/parity gate. Выше recoverability и horizontal scale, но появляется
    distributed coordination, cleanup stale builds и конфликт с CLI-only
    простотой data toolchain.
  - В — подключить отдельный OpenSearch PHP SDK для indexing CLI, оставив native
    AMPHP client только HTTP search. Меньше собственного endpoint code, но два
    transport stack и лишний dependency surface для небольшого набора API.
  - Г — обновлять один production index in-place без versioned target и alias
    switch. Проще реализация, но пользователи видят частично перестроенный индекс,
    а несовместимый mapping нельзя безопасно откатить.
- Recommendation: А. Он сохраняет доказанную blue-green semantics, использует
  уже выбранный async transport и не создаёт второй OpenSearch client stack.
  `region_code` входит в mapping/document ID contract и обязателен в downstream
  suggest/search filters. Вариант Б открывается только после benchmark одного
  async CLI process на полном наборе.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — native OpenSearch adapter на `amphp/http-client` расширяется
  только mapping, bounded `_bulk`, settings, refresh и alias operations. Full
  rebuild выполняется одним `CliRuntime` с bounded async concurrency, пишет
  versioned physical index, проверяет parity и атомарно переключает read alias.
  Distributed MessageBus rebuild остаётся deferred до отрицательного benchmark.
- Consequences: нужны streaming/bounded NDJSON body, per-item bulk error parsing,
  cancellation/deadline, deterministic versioned index name, parity manifest,
  atomic alias switch и cleanup policy старых physical indexes. Retry допустим
  только для доказанно idempotent batch с теми же document IDs.
- Conflict check: А согласуется с запретом на SDK/Guzzle/retries без отдельного
  review и с Decisions 10, 14.2–14.4. Б преждевременно связывает optional search
  projection с durable worker topology; В дублирует transport; Г ослабляет
  rollback и zero-partial-read guarantees.

### Decision 15.1 — Чем валидировать transport input?

- Status: accepted
- Context: исходные Spiral filters почти не используют rule engine: их
  `FilterDefinition` пуст, а проверки реализованы вручную. В новом приложении
  HTTP adapter должен отличать malformed shape/type от корректного typed
  capability message, собирать несколько ошибок и отдавать стабильные field
  paths/codes, не протаскивая HTTP или validator types в Capability.
- Options:
  - А — `symfony/validator` standalone: request mapper декодирует query/body в
    transport DTO, PHP attributes описывают constraints, а adapter переводит
    `ConstraintViolationList` в application-owned error response.
  - Б — application-owned parser/validator без зависимости: endpoint-specific
    mapper использует общие scalar/enum/UUID/range primitives и возвращает
    собственный `InputViolation` list.
  - В — mapper-first library наподобие Valinor для преобразования raw arrays в
    typed DTO, а semantic constraints проверять отдельно. Сильнее для сложных
    nested payloads, но добавляет mapping model, которой текущие endpoints не
    требуют.
  - Г — создавать capability commands/queries прямо из raw input и полагаться
    на исключения их constructors. Меньше transport-кода, но field paths,
    несколько ошибок и HTTP-specific error contract смешиваются с Capability.
- Recommendation: А. В отличие от старых пустых filters, constraints становятся
  видимым декларативным контрактом рядом с transport DTO; standalone component
  уже зрелый и не требует Symfony Framework. Parsing остаётся application-owned,
  DTO живут только в `UserInterface`, а Capability получает чистые typed values.
  Вариант Б остаётся допустимым fallback, если первые migrated endpoints покажут,
  что набор constraints слишком мал для оправдания package.
- User answer: `2026-11-01` — «давай вариант А».
- Accepted: А — `symfony/validator ^8.1` используется standalone для constraints
  на transport DTO внутри `UserInterface`. Raw query/body parsing остаётся
  application-owned; Symfony violations сразу переводятся в стабильный
  `InputViolation` contract и не передаются в Capability.
- Consequences: нужен единый `InputViolation {path, code, message, rejectedValue?}`
  без утечки Symfony types, fail-safe JSON/query decoder, deterministic ordering
  ошибок и contract tests одинакового response shape. Domain invariants всё ещё
  проверяются внутри Capability; transport validator их не заменяет.
- Conflict check: А совместим с `symfony/console`, но не делает Symfony общим
  framework/container. Б проще dependency-wise, но повторяет уже разросшиеся
  manual checks. В пока не подтверждён сложностью payloads. Г нарушает границу
  UserInterface/Capability.

### Decision 15.2 — Как сериализовать durable messages и HTTP results?

- Status: accepted
- Context: старый MessageBus уже имеет JSON envelope и stable message aliases,
  но payload автоматически снимается с PHP object properties, а deserialization
  зависит от текущего constructor. Для at-least-once queue сообщение может
  пережить deploy, поэтому PHP refactor не должен молча менять wire schema.
  HTTP response contract имеет другой lifecycle и не обязан использовать bus
  serializer.
- Options:
  - А — native JSON + explicit codec registry: каждый durable message alias имеет
    `messageVersion` и codec `toPayload()/fromPayload()`; старые версии проходят
    через последовательные upcasters. Envelope versioning отделено от message
    schema. HTTP results преобразуются отдельными presenters/resources.
  - Б — единый `symfony/serializer` для durable messages и HTTP DTO с
    ObjectNormalizer/reflection metadata. Меньше ручных codecs, но persisted
    schema по умолчанию остаётся связанной с PHP property names/types и требует
    отдельной versioning дисциплины поверх serializer.
  - В — сохранить старый JSON serializer на `get_object_vars()` и constructor
    named arguments. Минимальный migration effort, но rename/reorder/type change
    ломает queued payloads и rollback через предыдущую версию приложения.
  - Г — использовать PHP `serialize()`/igbinary для durable payloads. Хорошо
    восстанавливает object graph одной версии кода, но непереносимо, непрозрачно
    для JSONB tooling и ещё сильнее связано с PHP classes.
- Recommendation: А. Он делает compatibility явной частью public contract новой
  `message-bus-async 0.1.x`: stable alias, отдельные envelope/message versions,
  portable JSON payload и deterministic upcasters. Для HTTP остаются простые
  presenters и native `json_encode`; новая runtime dependency не нужна.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — durable messages используют stable alias, независимые
  envelope/message versions и explicit JSON codecs с последовательными
  upcasters. Reflection/object normalization и PHP serialization не входят в
  новый write path. HTTP results преобразуются отдельными presenters и не
  используют MessageBus codecs.
- Consequences: durable payload допускает только JSON scalar/list/map/null;
  UUID и enums кодируются явными strings, timestamps — RFC 3339 UTC. Registry
  compile проверяет уникальность aliases/codecs и непрерывную upcaster chain.
  Неизвестный alias/version не создаёт произвольный class, а переводится в
  controlled reject/dead-letter path. Golden fixtures доказывают backward read
  и rollback compatibility.
- Conflict check: А продолжает Decisions 2–4 и 9, но намеренно не переносит
  reflection serializer старой library. Б смешивает HTTP и durable lifecycles;
  В не выдерживает independent deploy/rollback; Г противоречит portable schema.

### Decision 15.3 — Как разделить OpenAPI и Postman?

- Status: accepted
- Context: существующая Postman Collection содержит не только requests, но и
  executable assertions, environments и representative examples. Она не
  является полной structural schema. OpenAPI в проекте пока нет; AMPHP routing
  не даёт готового framework generator, поэтому code annotations потребуют
  отдельного reflection toolchain.
- Options:
  - А — contract-first OpenAPI 3.1 YAML является structural source of truth для
    paths, parameters, bodies, responses и error schemas. Committed Postman
    Collection остаётся executable companion: requests/examples синхронизируются
    с OpenAPI, а scenario assertions и environments поддерживаются в Postman.
  - Б — code-first OpenAPI из PHP attributes на handlers/DTO через generator.
    Меньше отдельного YAML, но transport code получает documentation metadata,
    а полнота spec зависит от reflection/configuration generator-а.
  - В — Postman Collection остаётся единственным API contract. Один артефакт и
    хорошие executable tests, но слабее reusable schemas, client generation,
    compatibility diff и независимая validation responses.
  - Г — хранить только OpenAPI и отказаться от committed Postman Collection.
    Чище structural contract, но теряются существующие parity scenarios,
    environments и удобный ручной smoke workflow.
- Recommendation: А. OpenAPI 3.1 фиксирует публичную форму API, а Postman
  доказывает observable behavior. Это разные обязанности, поэтому collection не
  должна быть слепо перегенерируемым артефактом: CI сверяет operation/path/schema
  coverage, сохраняя hand-written tests. Runtime PHP dependency не требуется.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — OpenAPI 3.1 YAML является structural source of truth, а
  committed Postman Collection — executable companion с examples, environments
  и parity assertions. CI проверяет operation/path/schema coverage между ними,
  не перезаписывая вручную написанные Postman tests.
- Consequences: каждому публичному route назначается stable `operationId` и
  соответствующий Postman request; response/error examples не содержат secrets.
  Contract change обновляет handler tests, OpenAPI, Postman и module knowledge в
  одном commit. CI lint-ит OpenAPI, выполняет collection и проверяет coverage
  операций; конкретный CLI validator выбирается на этапе implementation tooling.
- Conflict check: А продолжает родительский plan и Decisions 15.1–15.2. Б
  привязывает documentation к PHP layout; В оставляет structural contract
  неполным; Г теряет уже существующий executable parity asset.

### Decision 16.1 — Как устроить structured logging и progress state?

- Status: accepted
- Context: target уже использует PSR-3 Monolog с неблокирующим stdout handler,
  поэтому новый logging transport не нужен. Старый `parser_logs` смешивает
  диагностические события с прогрессом импорта/rebuild, который операторы
  запрашивают из БД. Простое удаление таблицы потеряет recoverability/status, а
  перенос всех логов в PostgreSQL создаст blocking sink и конкуренцию с workload.
- Options:
  - А — Monolog + `Amp\Log\StreamHandler` остаются основой; production пишет
    JSON Lines в stdout, development может использовать ConsoleFormatter.
    Import/rebuild checkpoint и summary хранятся в явных operation run/progress
    tables, а не дублируются как database logs.
  - Б — сохранить `parser_logs` как основной structured log sink и дополнительно
    писать stdout. Удобны SQL-запросы, но удваивается запись, логирование зависит
    от application DB и может ухудшить import path.
  - В — сразу добавить OpenTelemetry Logs SDK/exporter и отправлять логи по OTLP.
    Унифицирует telemetry pipeline, но связывает baseline с collector/backend до
    решения о metrics/tracing и добавляет failure/backpressure policy.
  - Г — оставить текущий human console format во всех окружениях и полагаться на
    grep. Минимум настройки, но нестабильные поля затрудняют correlation, alerting
    и автоматический разбор ошибок.
- Recommendation: А. Уже выбранный async stdout path достаточен, а JSON formatter
  Monolog не требует новой dependency. Stable fields: `event`, `service`,
  `runtime`, `level`, `request_id`/`message_id`, `correlation_id`, `operation_id`,
  `duration_ms`, integration и structured exception. CLI progress output остаётся
  отдельным UX channel. Query payloads, credentials и персональные адресные
  данные проходят redaction/allowlist policy.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — production использует JSON Lines через существующие Monolog и
  `Amp\Log\StreamHandler` в stdout; development может включать human formatter.
  Import/rebuild status, checkpoints и summaries хранятся в отдельных operation
  tables. PostgreSQL `parser_logs` не переносится как общий log sink.
- Consequences: `LoggerInterface` остаётся application contract; context
  processors получают scoped IDs из HTTP/message/CLI lifecycle. В БД появляются
  узкие operation state tables только там, где нужны status/resume/audit.
  Logging failure не откатывает business transaction; stdout backpressure и
  shutdown flush покрываются runtime tests.
- Conflict check: А продолжает scaffold и Decisions 10, 12, 14.3. Б возвращает
  blocking infrastructure в logging path; В преждевременно решает следующий
  metrics/tracing вопрос; Г не обеспечивает machine-readable observability.

### Decision 16.2 — Как подключить metrics и distributed tracing?

- Status: accepted
- Context: HTTP, AMPHP fibers, MessageBus queue и внешние integrations требуют
  общей correlation chain, но конкретный backend/collector пока не выбран.
  OpenTelemetry PHP signals stable и имеют W3C propagation, однако implicit
  context между параллельными fibers и exporter shutdown/backpressure должны
  быть проверены именно в target runtime.
- Options:
  - А — application code зависит только от `open-telemetry/api ^1.10` и вручную
    инструментирует HTTP, MessageBus, PostgreSQL/OpenSearch/Nominatim boundaries.
    SDK + OTLP exporter + local Collector подключаются optional composition
    module; без него API работает через no-op providers.
  - Б — добавить `promphp/prometheus_client_php` и `/metrics`, а tracing отложить.
    Быстрый Prometheus path, но correlation между HTTP, queue и integrations
    остаётся только в logs, а multi-process aggregation требует Redis/APCu.
  - В — использовать PromPHP для metrics и OpenTelemetry только для traces.
    Полное покрытие сигналов, но две instrumentation API, две configuration model
    и риск расхождения labels/resource attributes.
  - Г — не добавлять metrics/tracing libraries и ограничиться structured logs.
    Минимальный baseline, но нельзя получить latency histograms, queue depth/SLO
    или связный trace через async boundaries.
- Recommendation: А с compatibility gate. Это один vendor-neutral semantic
  contract без обязательного backend. `traceparent`/`tracestate` проходят HTTP и
  MessageBus headers; metric labels имеют bounded cardinality и не содержат
  object/address IDs. SDK/exporter включается только после parallel-fiber test,
  non-blocking/bounded export test и graceful flush timeout.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — application instrumentation зависит от
  `open-telemetry/api ^1.10`; SDK, OTLP exporter и Collector подключаются
  optional composition module. Без него используются no-op providers.
  Production enablement проходит AMPHP fiber isolation, bounded export и
  graceful flush compatibility gates.
- Consequences: core capabilities не получают OTel SDK types; instrumentation
  живёт в middleware/decorators/adapters. Нужны spans `http.server`,
  `message.publish/consume`, external HTTP/DB operations и metrics request
  latency/error, pool saturation, queue depth/age/retry, import throughput.
  Export failure не влияет на business result. Sampling и resource attributes
  задаются environment/composition, не capability code.
- Conflict check: А согласуется с optional integrations и scoped lifecycle
  Thesis Dic, но требует fiber gate. Б/В вводят Prometheus-specific aggregation
  до выбора backend; Г не покрывает operations goals.

### Decision 16.3 — Какие semantics имеют health endpoints?

- Status: accepted
- Context: liveness сейчас корректно проверяет только процесс, но readiness
  считает любой отказ Nominatim/OpenSearch фатальным. OpenSearch принят как
  optional projection с PostgreSQL fallback; другие integrations также могут
  быть optional по конфигурации capability. Restart процесса не лечит outage
  внешней зависимости.
- Options:
  - А — layered probes: `/health/live` проверяет только process/event-loop;
    `/health/ready` проверяет config/schema и только dependencies, помеченные
    `required`; optional failures возвращаются как `degraded`, но не меняют 200.
    Startup probe защищает initialization; detailed component status доступен
    операторам без secrets.
  - Б — строгий readiness: любая настроенная integration должна быть доступна,
    иначе 503. Простая трактовка, но optional OpenSearch/Nominatim outage удаляет
    из traffic даже работоспособные PostgreSQL scenarios.
  - В — readiness проверяет только локальный process/config, а зависимости
    наблюдаются metrics/alerts. Избегает cascading outage, но traffic поступает
    в instance, который не может выполнить обязательные scenarios.
  - Г — объединить live/ready в один endpoint со всеми dependency checks.
    Меньше routes, но внешний outage может вызвать restart loop и смешивает две
    разные orchestrator actions.
- Recommendation: А. Health check registry объявляет для каждого component
  `required|optional`, deadline и безопасное имя; checks запускаются параллельно
  с общим коротким budget. PostgreSQL/schema обязательны. OpenSearch/Nominatim
  влияют на readiness только если соответствующая capability включена как
  required и не имеет fallback. Новая library не нужна.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — отдельные liveness/readiness/startup semantics и health registry
  с `required|optional` component policy. Liveness не делает network I/O;
  readiness возвращает 503 только при отказе required checks, а optional outage
  отражается как `degraded` с HTTP 200.
- Consequences: ответ содержит `status: ok|degraded|unavailable` и component
  statuses без DSN/exception text. Liveness не выполняет network I/O. Readiness
  failure не завершает process. Probe contracts, deadlines и degraded behavior
  покрываются tests и OpenAPI/Postman; Docker/Kubernetes configs используют
  разные endpoints по назначению.
- Conflict check: А соответствует optional integrations, OpenSearch fallback и
  orchestrator semantics. Б конфликтует с Decision 14.5 optional projection; В
  скрывает обязательный DB outage; Г смешивает restart и traffic gating.

### Decision 17.1 — Какой test runner становится единственным target?

- Status: accepted
- Context: scaffold уже выбрал Testo и имеет рабочий test, а исходник приносит
  18 PHPUnit tests. Testo lock включает необходимые unit features, coverage и
  fiber execution. Главный риск — migration assertions/hooks/mocks и pre-1 API,
  а не отсутствие второго runner.
- Options:
  - А — Testo остаётся единственным target runner. Исходные PHPUnit tests
    конвертируются slice-by-slice; suites разделяются на Unit, Scenario,
    Integration и Contract. PHPUnit не остаётся в итоговом `composer.json`.
  - Б — вернуться на PHPUnit 12.5 как единственный runner и удалить Testo.
    Минимальная стоимость переноса 18 tests и зрелая ecosystem, но теряется уже
    выбранный lightweight/fiber-oriented scaffold.
  - В — постоянно поддерживать Testo и PHPUnit параллельно. Почти нет начальной
    миграции, но два assertion/hook/plugin API, два config и удвоенный CI gate.
  - Г — Testo использовать только для unit/fiber tests, PHPUnit — для integration
    и contract tests. Явное разделение, но layer type искусственно определяет
    framework и усложняет shared fixtures.
- Recommendation: А с migration gate. Сначала конвертировать representative
  tests: data provider, exception, lifecycle, MessageBus fake и parallel fiber;
  затем переносить остальные 18. `testo/testo` фиксируется lock-файлом, а upgrade
  pre-1 minor проходит changelog/contract run. Hand-written fakes предпочтительнее
  mock library; `testo/bridge-double` добавляется только при доказанном пробеле.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — Testo является единственным target runner с Unit, Scenario,
  Integration и Contract suites. 18 исходных PHPUnit tests конвертируются
  slice-by-slice; PHPUnit не остаётся итоговой dependency. `testo/bridge-double`
  подключается только при доказанном пробеле hand-written fakes.
- Consequences: `testo.php` получает отдельные suites и environment/bootstrap
  для integration tests; default `make check` запускает быстрые Unit+Scenario,
  отдельный gate — Integration/Contract. Нужны deterministic time/IDs, no retry
  для функциональных tests и обязательные fiber cancellation/lifecycle cases.
- Conflict check: А продолжает scaffold и Decisions 1, 9–10, 13, 16.2. Б
  отменяет уже сделанный target choice без функционального основания; В/Г
  сохраняют лишнюю framework boundary.

### Decision 17.2 — На каких dependencies выполнять adapter integration tests?

- Status: accepted
- Context: PostgreSQL queue/partition/migration semantics и OpenSearch
  mapping/bulk/alias нельзя доказать mocks. Nominatim HTTP protocol можно
  детерминированно проверить fake server-ом, тогда как полный Nominatim import
  слишком дорог для каждого CI run. Existing Compose можно расширить отдельным
  test profile без новой orchestration library.
- Options:
  - А — repository-owned Compose integration profile: real PostgreSQL и
    OpenSearch для adapter tests; локальный AMPHP fake HTTP server для
    Nominatim/OTLP/error/cancellation contracts. Full Nominatim остаётся smoke
    или scheduled/manual gate.
  - Б — PHP Testcontainers library поднимает PostgreSQL/OpenSearch/Nominatim из
    самих tests. Удобная per-suite orchestration, но Docker lifecycle попадает в
    test code, а тяжёлый Nominatim всё равно остаётся непрактичным.
  - В — использовать постоянно запущенный development stack для integration
    tests. Меньше файлов, но tests зависят от пользовательских volumes/data,
    порядка запусков и могут повредить local environment.
  - Г — все adapters проверять только mocks/fakes, а реальные services оставить
    smoke tests. Быстрый CI, но SQL locks/migrations, OpenSearch mapping/aliases и
    wire compatibility остаются недоказанными до позднего этапа.
- Recommendation: А. Это минимальный reproducible stack: version-pinned images,
  ephemeral volumes, migrations from zero и уникальные schema/index names на
  suite. Default `make check` остаётся быстрым; отдельный `make test-integration`
  поднимает профиль и гарантированно удаляет test resources. Новая PHP library
  не нужна.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — repository-owned Compose integration profile поднимает
  изолированные PostgreSQL и OpenSearch с version-pinned images и ephemeral
  volumes. Nominatim, OTLP и негативные HTTP-сценарии проверяются локальными
  AMPHP protocol fakes; полный Nominatim остаётся scheduled/manual smoke gate.
- Consequences: PostgreSQL tests покрывают migrations, COPY/upsert, partitions,
  queue claim/lease/retry и concurrent transactions; OpenSearch — mapping,
  per-item bulk errors, versioned index и atomic alias switch. Fake HTTP tests
  проверяют malformed JSON, status codes, deadlines и cancellation. Tests не
  используют dev volumes и не обращаются к internet.
- Conflict check: А согласуется с Docker scaffold и layered test gates. Б
  добавляет orchestration dependency без текущего пробела; В нарушает isolation;
  Г не доказывает критическую database/search semantics.

### Decision 17.3 — Как распределить architecture, contract и smoke gates по CI?

- Status: accepted
- Context: принятые решения требуют Deptrac, трёх DIC graph checks, MessageBus
  registry compile, Testo suites, OpenAPI/Postman coverage и real-service
  integration tests. Полный Nominatim stack и representative import/parity
  проверки слишком дороги для единственного синхронного PR job, но ручной запуск
  всех тяжёлых проверок слишком поздно обнаружит несовместимость adapters.
- Options:
  - А — tiered CI. Каждый PR обязан пройти быстрый static/architecture/Unit/
    Scenario/contract-structure gate и отдельный real PostgreSQL/OpenSearch
    integration gate. Full stack Nominatim, executable Postman smoke и
    representative import/rebuild parity запускаются scheduled и перед release.
  - Б — все проверки, включая полный Nominatim import и full stack smoke,
    обязательны в каждом PR. Максимально ранняя обратная связь, но высокая
    длительность и нестабильность внешне-зависимого pipeline блокируют review.
  - В — в PR запускать только formatter, PHPStan и быстрые Testo suites, а
    integration/contract/smoke оставить manual или release-only. PR быстрый, но
    ошибки SQL/OpenSearch/contracts обнаруживаются после merge.
  - Г — объединить всё в один `make check` и один CI job без раздельных budgets и
    artifacts. Локально одна команда, но неясно, какой слой сломан, и нельзя
    независимо масштабировать или перезапускать дорогой gate.
- Recommendation: А. Fast PR gate включает Composer validation, formatting,
  PHPStan, Deptrac, Unit+Scenario, три DIC graph builds, MessageBus registry
  compile и OpenAPI/Postman coverage. Integration gate отдельно поднимает
  ephemeral PostgreSQL/OpenSearch, применяет migrations from zero и запускает
  Testo Integration+Contract. Scheduled/release gate поднимает full stack и
  выполняет executable API smoke, representative import/rebuild и parity checks.
- User answer: `2026-11-01` — «вариант А».
- Accepted: А — tiered CI с двумя обязательными PR-контурами. Fast gate проверяет
  dependency metadata, style, static analysis, architecture, Unit/Scenario и
  contract structure; integration gate проверяет Testo Integration+Contract на
  ephemeral PostgreSQL/OpenSearch. Full stack Nominatim, executable API smoke и
  representative import/rebuild parity выполняются scheduled и перед release.
- Consequences: orchestration получает явные entrypoints вроде `make check`,
  `make test-integration`, `make contract` и `make smoke-release`; каждый gate
  публикует JUnit/diagnostic artifacts и имеет собственный timeout. Cleanup
  test resources обязателен, а автоматические retries не маскируют functional
  failures. Release использует зафиксированные image/dependency versions и
  требует успешные результаты применимых gates.
- Conflict check: А сохраняет быструю PR feedback loop и проверяет реальные
  adapters до merge. Б делает дорогой full stack частью каждого изменения; В
  откладывает главные contract failures; Г смешивает разные причины, стоимость и
  ownership проверок.

## Acceptance Examples

Эти примеры будут уточняться после каждого решения:

1. HTTP entrypoint получает готовый request handler/router из HTTP container и не
   знает, как собраны capability handlers и adapters.
2. CLI entrypoint не поднимает HTTP server или AMPHP HTTP-only resources.
3. Worker entrypoint получает MessageBus, queue consumer и worker policy из
   отдельной composition.
4. Capability handler можно unit-тестировать без DI container.
5. Optional OpenSearch feature подключается definition/decorator-ом вне core
   capability и может быть отключена конфигурацией.
6. Ошибка отсутствующего interface binding обнаруживается check/release gate до
   запуска production process.
7. Если выбран `thesis/dic`, каждый HTTP request и queue message получает
   отдельный scope, а MessageBus PSR-11 bridge не экспортирует произвольные
   application services.
8. После выбора региона suggest/search передаёт `regionCode` явно, обращается к
   одной partition read model и не выполняет nationwide `DISTINCT` в online path.
9. GAR import можно запустить только через `CliRuntime`; его blocking DB adapters
   отсутствуют в HTTP и worker DIC graphs.
10. GAR XML импортируется с ограниченной памятью через `XMLReader` и COPY, а не
    materialized DOM или per-row INSERT.
11. Полный OpenSearch rebuild не меняет read alias до успешной загрузки и parity
    check нового versioned physical index.
12. Некорректный HTTP input возвращает одинаково упорядоченный список
    application-owned violations без Symfony types в response/capability.
13. Durable message fixture версии N читается текущим codec через upcaster chain,
    даже если PHP message class был переименован или изменил constructor.
14. Каждый OpenAPI `operationId` покрыт Postman request; collection выполняет
    assertions, которые не дублируются в generated structural schema.
15. Production log line является валидным JSON и содержит correlation fields;
    progress import/rebuild читается из operation state, а не из log history.
16. При выключенном telemetry module HTTP/worker работают через no-op providers;
    при включённом trace context изолирован между параллельными fibers.
17. Отказ optional OpenSearch возвращает readiness `degraded`/200, а отказ
    required PostgreSQL — `unavailable`/503; liveness в обоих случаях остаётся
    локальной проверкой процесса.
18. Representative PHPUnit tests после конвертации дают те же assertions в
    Testo, включая exception, data set, lifecycle и parallel fiber cases.
19. Integration suite с нуля применяет migrations к ephemeral PostgreSQL,
    проверяет OpenSearch mapping/alias и не читает development volumes или
    internet; Nominatim/OTLP failures воспроизводятся локальными HTTP fakes.
20. PR нельзя merge при падении fast или PostgreSQL/OpenSearch integration gate;
    полный Nominatim/import/rebuild smoke не замедляет каждый PR, но обязателен
    перед release и регулярно выполняется scheduled pipeline.

## Принятые Решения

1. `thesis/dic ^0.5.3` используется как composition engine вместо PHP-DI,
   Symfony DI или собственного контейнера. Решение принято ради явного async
   lifecycle: singleton pools/clients, scoped request/message state и disposers.
2. Собственный MessageBus развивается как отдельная reusable generation и
   остаётся единым sync/async bus приложения. Новый core не зависит от Thesis Dic
   или обязательного PSR-11; AMPHP и Thesis подключаются adapters на границах.
3. Первый usable release следует второму варианту migration baseline: neutral composition,
   AMP PostgreSQL transport/control, cancellable worker и job scope без
   обязательного transactional outbox в первом application slice.
4. Новый package set начинается с `message-bus-async 0.1.0`: это независимый от
   старой v6 async-first replacement с local typed query lane. Consumer/worker
   runtime подключается отдельным optional package; старые packages архивируются
   только после migration gate.
5. Worker extension является operational package: consumer execution, job
   scopes, recovery/heartbeat, control plane, Console и process adapters живут в
   нём; producer/status/cancel и общая queue schema остаются в core. Зависимость
   направлена только `worker -> core`.
6. Worker extension публикуется как
   `romanfedorskij/message-bus-async-worker 0.1.x` и следует совместимой release
   line core package `romanfedorskij/message-bus-async 0.1.x`.
7. Core использует PHP namespace `Fedor\MessageBus`, worker extension —
   `Fedor\MessageBus\Worker`; старая и новая package lines могут
   временно сосуществовать без FQCN collision.
8. Public API разделён на `QueryBusInterface::ask()`,
   `CommandBusInterface::dispatch()` и `EventPublisherInterface::publish()`;
   единый `MessageBusInterface` остаётся composite facade, advanced execution
   вынесен в отдельные contracts.
9. Durable queue гарантирует at-least-once delivery: claim/lease и переходы
   ack/retry/reject durable, stale jobs восстанавливаются, а idempotency/replay
   tests обязательны для state-changing async handlers.
10. Application composition имеет три явных root export: `HttpRuntime`,
    `CliRuntime` и `WorkerRuntime`. Они переиспользуют общие modules/refs, но
    независимо валидируют graph и управляют process-specific lifecycle.
11. Application использует `symfony/console` и
    `thesis/symfony-console-module` через единый `app/bin/app`; lazy commands
    сохраняют отдельные `HttpRuntime`, `CliRuntime` и managed `WorkerRuntime`
    lifecycle boundaries.
12. Configuration layer не получает отдельную dependency: immutable environment
    snapshot один раз преобразуется в typed runtime/integration settings, а
    `.env` loading остаётся обязанностью Compose/orchestrator tooling.
13. Architecture gate состоит из `deptrac/deptrac ^4.7` для статического
    dependency direction и project-owned check, строящего три Thesis Dic graph и
    компилирующего MessageBus registry без внешних сервисов.
14. Migration engine остаётся `yiisoft/db-migration ^2.2` с
    `yiisoft/db-pgsql ^2.0` внутри blocking `CliRuntime`; 21 существующая
    migration сохраняется, а schema optimizations добавляются новыми migrations.
15. Партиционирование начинается с rebuildable suggestion/search read model по
    `region_code`; canonical GAR tables не меняются без практического benchmark.
    Вариант Б сохраняется как evidence gate для последующей оптимизации raw
    import/build path.
16. GAR import является CLI-only blocking workload: он сохраняет native COPY
    path ради throughput и не регистрируется как MessageBus handler. HTTP и
    worker composition не экспортируют его DB adapters.
17. GAR XML pipeline сохраняет `XMLReader` и `ext-pgsql` COPY/staging/upsert;
    `ext-xmlreader` становится прямым platform requirement, сторонний streaming
    parser не добавляется.
18. OpenSearch projection перестраивается одним async `CliRuntime` через native
    AMPHP REST adapter, versioned physical index и atomic alias switch;
    distributed rebuild и отдельный SDK не входят в baseline.
19. Transport DTO валидируются standalone `symfony/validator ^8.1` только в
    `UserInterface`; Capability получает typed values и не зависит от Symfony
    violations или constraint metadata.
20. Durable MessageBus использует explicit versioned JSON codecs и upcasters;
    HTTP responses имеют отдельные presenters. Reflection serializer и PHP
    `serialize()` не входят в новый write path.
21. OpenAPI 3.1 является structural HTTP contract, Postman — committed executable
    companion; изменение публичного route обновляет оба артефакта и tests.
22. Production logging использует Monolog/Amp JSON stdout; queryable/resumable
    progress хранится в отдельных operation tables, а не в общем DB log sink.
23. Application boundaries используют `open-telemetry/api ^1.10`; SDK/OTLP и
    Collector являются optional module и включаются только после AMPHP
    compatibility gates.
24. Health model разделяет live/ready/startup и required/optional checks;
    dependency outage никогда не превращается автоматически в process restart.
25. Testo является единственным target runner; suites разделены по стоимости и
    внешним dependencies, а PHPUnit не остаётся permanent dependency.
26. Adapter integration tests используют repository-owned Compose profile с
    real PostgreSQL/OpenSearch и локальные AMPHP HTTP fakes; Testcontainers не
    добавляется, а полный Nominatim вынесен в scheduled/manual smoke.
27. CI разделён на обязательные fast и real-service integration gates для PR и
    scheduled/release full stack gate; разные budgets, artifacts и entrypoints
    не смешиваются в один непрозрачный job.

## Конфликты И Допущения

- Лицензионный gate исходного приложения остаётся открытым, но не блокирует выбор
  сторонних MIT-библиотек и проектирование composition.
- Версии пакетов будут зафиксированы Composer lock только на этапе реализации;
  до этого constraint в решении означает совместимую major/minor line, а не
  разрешение на установку.
- Название `AddressRegistry` и namespace `App\Module\AddressRegistry` приняты
  вариантом А. Библиотечные решения остаются независимыми от application
  namespace.

## Текущий Frontier

- Frontier пуст. Dependency baseline согласован полностью и передан в
  [implementation roadmap](implementation-roadmap.md) с порядком slices,
  migration gates и критериями готовности.

## История Документа

### 0.32.1 — 2026-11-04

- синхронизировано принятое имя application module `AddressRegistry`;
- dependency baseline не изменён и остаётся независимым от module namespace.

### 0.32.0 — 2026-11-01

- dependency baseline связан с отдельным implementation roadmap;
- следующий этап больше не обозначен как не начатый;
- библиотечный baseline не изменён.

### 0.31.0 — 2026-11-01

- Decision 17.3 принят по варианту А: fast и real-service integration gates
  обязательны для PR;
- full stack Nominatim, executable API smoke и import/rebuild parity вынесены в
  scheduled/release pipeline;
- frontier закрыт, dependency baseline переведён в статус accepted.

### 0.30.0 — 2026-11-01

- Decision 17.2 принят по варианту А: repository-owned Compose profile с real
  PostgreSQL/OpenSearch и local AMPHP HTTP fakes;
- Testcontainers исключён из baseline, полный Nominatim оставлен для
  scheduled/manual smoke;
- открыт Decision 17.3 о распределении architecture/contract/smoke CI gates.

### 0.29.0 — 2026-11-01

- Decision 17.1 принят по варианту А: Testo — единственный target runner с
  четырьмя suites;
- PHPUnit оставлен только как migration source для 18 существующих tests;
- открыт Decision 17.2 об adapter integration environment.

### 0.28.0 — 2026-11-01

- Decision 16.3 принят по варианту А: layered live/ready/startup probes и
  required/optional component policy;
- optional integration outage закреплён как degraded, а не global unready;
- открыт Decision 17.1 о единственном target test runner.

### 0.27.0 — 2026-11-01

- Decision 16.2 принят по варианту А: OpenTelemetry API и optional SDK/OTLP
  composition после AMPHP compatibility gates;
- Prometheus-specific client не добавлен в baseline;
- открыт Decision 16.3 по liveness/readiness/startup semantics.

### 0.26.0 — 2026-11-01

- Decision 16.1 принят по варианту А: JSON Lines через Monolog/Amp stdout и
  отдельные operation state tables;
- общий PostgreSQL `parser_logs` исключён из target logging baseline;
- открыт Decision 16.2 по OpenTelemetry/Prometheus metrics и tracing.

### 0.25.0 — 2026-11-01

- Decision 15.3 принят по варианту А: OpenAPI 3.1 structural source и Postman
  executable companion;
- зафиксирован CI coverage gate между OpenAPI operations и Postman requests;
- открыт Decision 16.1 по structured logging и progress state.

### 0.24.0 — 2026-11-01

- Decision 15.2 принят по варианту А: explicit versioned JSON codecs/upcasters
  для durable messages и отдельные HTTP presenters;
- reflection/PHP serializers исключены из нового durable write path;
- открыт Decision 15.3 о разделении OpenAPI и Postman.

### 0.23.0 — 2026-11-01

- Decision 15.1 принят по варианту А: standalone `symfony/validator ^8.1` на
  UserInterface transport DTO;
- Symfony violations изолированы application-owned `InputViolation` contract;
- открыт Decision 15.2 по durable message и HTTP serialization.

### 0.22.0 — 2026-11-01

- Decision 14.5 принят по варианту А: native AMPHP OpenSearch adapter, один async
  CLI blue-green rebuild и atomic alias switch;
- distributed MessageBus rebuild и отдельный OpenSearch SDK отложены до
  benchmark;
- открыт Decision 15.1 по transport validation.

### 0.21.0 — 2026-11-01

- Decision 14.4 принят по варианту А: `XMLReader` + bounded batches + native
  PostgreSQL COPY/staging/upsert;
- `ext-xmlreader` закреплён как прямой platform requirement без стороннего XML
  parser package;
- открыт Decision 14.5 по OpenSearch indexing/rebuild.

### 0.20.0 — 2026-11-01

- Decision 14.3 принят по варианту Б с уточнением: GAR import выполняется только
  напрямую в `CliRuntime`, без MessageBus и без заранее созданного batch worker;
- blocking Yiisoft/PDO/`ext-pgsql` adapters запрещены в HTTP/worker graphs;
- открыт Decision 14.4 о streaming XML/COPY primitive.

### 0.19.0 — 2026-11-01

- Decision 14.2 принят по варианту Г: регионально partitioned suggestion/search
  read model без немедленной перестройки canonical GAR schema;
- `regionCode` закреплён как часть downstream suggest/search context;
- вариант Б сохранён как практический benchmark gate для raw tables;
- frontier переведён на Decision 14.3 о blocking batch DB access и зафиксированы
  четыре варианта его runtime isolation.

### 0.18.0 — 2026-11-01

- подтверждён региональный характер staged suggest после выбора parent context;
- обнаружен nationwide `DISTINCT ON` до применения parent/hierarchy filter;
- вариант В отделён от более узкой стратегии: partitioned regional read model
  без немедленной перестройки canonical GAR schema;
- рекомендация Decision 14.2 изменена на Г с benchmark gate варианта Б.

### 0.17.0 — 2026-11-01

- Decision 14.1 принят: Yiisoft migration engine и существующая history
  сохраняются, оптимизации оформляются последующими migrations;
- исследованы keys, foreign/self references, import conflict target и hot-path
  predicates крупных GAR-таблиц;
- зафиксированы PostgreSQL 17 constraints для partitioned primary/unique keys;
- открыт Decision 14.2 по evidence-first partitioning strategy.

### 0.16.0 — 2026-11-01

- Decision 13 принят: Deptrac и executable DIC/registry checks образуют два
  обязательных architecture gates;
- composition-ветка решений 1–13 закрыта;
- подтверждены 21 revertible Yiisoft migration, включая 18 transactional и три
  намеренно non-transactional migration;
- открыта Decision 14.1 по migration engine и history continuity.

### 0.15.0 — 2026-11-01

- Decision 12 принят: configuration остаётся typed и application-owned без
  config/dotenv package;
- environment snapshot, fail-fast validation и secret redaction зафиксированы
  как composition boundary contract;
- исследованы Deptrac и PHPStan custom rules относительно текущего strict
  PHPStan setup;
- открыт Decision 13 по architecture, DIC graph и registry gates.

### 0.14.0 — 2026-11-01

- Decision 11 принят: application и worker получают отдельные Symfony Console
  executables и runtime graphs;
- `symfony/console` зафиксирован как прямая dependency каждой стороны, которая
  использует его API;
- проверены текущий `AppConfig`, Compose env injection и назначение Symfony
  Config/Dotenv и `vlucas/phpdotenv`;
- открыт Decision 12 по typed configuration ownership.

### 0.13.0 — 2026-11-01

- Decision 10 принят: выбраны отдельные `HttpRuntime`, `CliRuntime` и
  `WorkerRuntime` на общих modules/refs;
- зафиксированы независимые graph validation, scopes и disposal трёх process
  runtimes;
- проверено, что исходное приложение переносит 18 Spiral Console commands,
  использующих Symfony Console arguments/options;
- открыт Decision 11 по ownership application и worker CLI.

### 0.12.0 — 2026-11-01

- Decision 9 принят: durable queue честно гарантирует at-least-once delivery;
- idempotency/replay tests зафиксированы как release gate для state-changing
  async handlers;
- подтверждено, что scaffold пока имеет только HTTP entrypoint и единый ручной
  `Bootstrap::run()`;
- открыт Decision 10 по разделению composition roots HTTP, CLI и Worker.

### 0.11.0 — 2026-11-01

- Decision 7 уточнён: namespace сокращён до `Fedor\MessageBus` и
  `Fedor\MessageBus\Worker`;
- Decision 8 принят: segregated query/command/publisher capabilities плюс
  composite facade;
- подтверждена at-least-once семантика старой PostgreSQL queue;
- открыт Decision 9 по честной delivery guarantee новой AMP queue.

### 0.10.0 — 2026-11-01

- Decision 7 принят: выбрана namespace family
  `RomanFedorskij\MessageBus` / `RomanFedorskij\MessageBus\Worker`;
- зафиксирован collision-free coexistence path со старым
  `Wolfcharaa\MessageBus`;
- исследован широкий legacy `MessageBusInterface` и открыт Decision 8 по
  segregated sync/async capabilities.

### 0.9.0 — 2026-11-01

- Decision 6 принят: worker package называется
  `romanfedorskij/message-bus-async-worker`;
- зафиксирована общая `0.1.x` release line core и worker packages;
- открыт Decision 7 по collision-free PHP namespace family.

### 0.8.0 — 2026-11-01

- Decision 5 принят: выбран второй вариант — operational worker extension;
- зафиксирована dependency direction `worker -> core` и ownership
  producer/status/cancel против consumer/control plane;
- введено обязательное обозначение новых вариантов русскими буквами
  `А`, `Б`, `В`, `Г`;
- через Packagist P2 API проверены четыре candidate package name;
- открыт Decision 6 по имени worker package.

### 0.7.0 — 2026-11-01

- Decision 4 принят: `message-bus-async 0.1.0` становится clean-slate
  replacement без зависимости от старого MessageBus;
- принято уточнение о втором optional worker package;
- по исходному v6.2.3 проверена связанность queue, worker, runtime и CLI;
- status/cancel отнесены к producer-facing core API, consumer/control plane — к
  worker side;
- открыт Decision 5 по точной worker package boundary.

### 0.6.0 — 2026-11-01

- Decision 3 принят: migration baseline B входит в первый usable release;
- подключён актуальный checkout MessageBus v6.2.3 и обновлена factual baseline;
- зафиксировано предложение нового `message-bus-async 0.1.0` с последующим
  архивированием старого package;
- обнаружен уже опубликованный `message-bus-amphp` v1.0.0, занимающий близкую
  integration niche;
- открыт Decision 4 по package topology и рекомендован standalone replacement,
  который остаётся async-first, но сохраняет local typed query lane.

### 0.5.0 — 2026-11-01

- Decision 2 принят: собственная MessageBus развивается как reusable v7;
- зафиксировано сохранение typed queries, nested dispatch, registry/wire
  compatibility и worker control contracts;
- проверены четыре async publish call site исходного приложения: transactional
  dual-write не обнаружен, поэтому outbox не является migration gate;
- открыт Decision 3 по границе первого usable v7 release.

### 0.4.0 — 2026-11-01

- вариант C сравнен с модернизацией собственной MessageBus;
- подтверждено, что queue, invocation и service-resolution boundaries уже
  выделены и полный rewrite не нужен;
- добавлен вариант D: совместимая v7 с neutral providers/scopes, AMP PostgreSQL
  adapters и cancellable worker;
- рекомендация изменена на D при условии отдельного library ownership; A оставлен
  fallback, если выпуск v7 не входит в scope;
- transaction/outbox/dedup выделены как реальный незакрытый выигрыш Thesis.

### 0.3.0 — 2026-11-01

- Decision 1 принят: `thesis/dic ^0.5.3` выбран ради async lifecycle;
- исследованы `thesis/message-bus`, `thesis/pgmq` и текущий generic MessageBus;
- выявлены отсутствие sync query result, dev-only stability и PGMQ deployment
  requirement у Thesis MessageBus;
- открыт Decision 2 по единой или разделённой MessageBus model.

### 0.2.0 — 2026-11-01

- исследован `thesis/dic` `0.5.3` по официальному repository, документации,
  исходному коду и Packagist metadata;
- зафиксированы преимущества scopes, disposal, module isolation и typed refs;
- обнаружено отсутствие native PSR-11 и описана форма ограниченного bridge;
- добавлены compatibility, maturity и lifecycle gates;
- Decision 1 расширен вариантом D, рекомендация изменена с A на D при принятии
  bridge/pre-1 условий.

### 0.1.0 — 2026-11-01

- создан inventory подключаемых библиотек;
- зафиксированы ограничения и dependency baseline;
- построено первичное дерево решений;
- открыт Decision 1 по PSR-11 composition engine.

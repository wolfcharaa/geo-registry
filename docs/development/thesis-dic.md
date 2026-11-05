# Thesis DIC: Модули, Lifetime И Scope

- Зафиксированная версия: `thesis/dic 0.5.3`
- Commit библиотеки: `6214b320e8e38f536f2eb9885aa0d356b034dc3e`
- Минимальная версия PHP библиотеки: `8.4.12`
- Статус: руководство для composition roots Geo Registry

Этот документ объясняет runtime-модель Thesis DIC и задаёт правила выбора
`singleton`, `scoped` и `canBeScoped` в долгоживущих HTTP/worker process. Он не
заменяет [официальную документацию Thesis DIC](https://github.com/thesis-php/dic/tree/0.5.3),
а фиксирует проверенное применение версии, закреплённой в `app/composer.lock`.

Исследование выполнено по:

- исходникам, документации и тестам официального tag `0.5.3`;
- лекционному AMPHP-приложению `php-async-2` с request scope и scoped
  PostgreSQL transaction;
- интеграции Thesis DIC в MessageBus core/worker;
- composition roots и executable lifecycle-тестам Geo Registry.

## Главный Вывод Для Geo Registry

Большое количество singleton-объектов само по себе не является ошибкой.
`object()` создаёт singleton по умолчанию, и это корректно для immutable или
concurrency-safe process services: pool, HTTP client, logger, config, router,
MessageBus и stateless handlers.

Настоящий вопрос звучит не «сколько singleton-ов», а:

> Какой unit of work владеет состоянием сервиса и где открывается scope этого
> unit of work?

В Geo Registry MessageBus уже открывает отдельный scope на внешний dispatch, а
worker — на job. HTTP transport graph отдельного Thesis request scope пока не
имеет. Поэтому механическая замена HTTP action с singleton на `scoped()` не
сделает его request-scoped: он будет создан в корневом scope `Dic::run()` и
проживёт весь `HttpRuntime::run()`.

Текущий код не требует массовой смены lifetime. Request scope нужно добавить
до появления request-owned transaction, unit of work или другого mutable
request state.

## Runtime-Модель

```text
Dic::run(root module, main)
└── Container                         process lifetime
    ├── singleton cache               pool, client, logger, runtime
    └── root Scope                    lifetime всего main callback
        └── resolved scoped services  не обязательно request-scoped

singleton Scoped<T> handle
├── run(request A) → Scope A → scoped instances A → dispose A
├── run(request B) → Scope B → scoped instances B → dispose B
└── параллельные run() используют независимые Scope
```

Контейнер хранит один экземпляр каждого singleton. Каждый `Scope` имеет
собственный cache scoped-сервисов и делегирует singleton-ссылки контейнеру.
Повторный resolve одного `Ref` внутри одного scope возвращает тот же экземпляр;
resolve в другом scope создаёт новый.

`Dic::run()` сам создаёт корневой scope, resolve-ит export корневого module,
выполняет callback и затем всегда освобождает root scope и container. В HTTP
process callback работает до остановки сервера, поэтому root scope также живёт
весь process.

## Точные Значения API

| Конструкция | Семантика |
|---|---|
| `$dic->object(Foo::class)` | `Foo` является singleton по умолчанию. |
| `->singleton()` | Явно закрепляет один экземпляр на container. |
| `->scoped()` на `ObjectConfig`/`ClosureConfig` | Один экземпляр `Ref` на каждый уже открытый scope. Сам scope не создаёт. |
| `->canBeScoped()` | Singleton, если все transitive dependencies singleton; scoped, если хотя бы одна dependency scoped. |
| `$dic->scoped($ref)` | Создаёт singleton-safe handle `Scoped<T>`; каждый `run()` открывает новый scope, resolve-ит `$ref` и освобождает scope. |
| `$dic->provider($ref)` | Создаёт `Closure(): T`, resolve-ящую `$ref` в том container/scope, где provider был создан. Новый scope не открывает. |
| `$dic->value(...)` | Transparent carrier: наследует lifetime вложенных `Ref`; plain value является singleton. |
| `$dic->taggedList(...)` | Transparent carrier списка; становится scoped, если содержит scoped service. |
| `->disposer(...)` | Освобождает созданный экземпляр при teardown owning scope/container и получает исходную ошибку или `null`. |
| `$dic->import($module)` | Новый изолированный configuration scope: свои bindings и autoconfiguration, общий runtime container. |
| `$dic->apply($configurator)` | Тот же `Dic`: bindings и autoconfiguration разделяются. |
| `Dic::run(...)` | Production entrypoint с гарантированным teardown при success и exception. |
| `Dic::build(...)` | Resolve без teardown; только тесты и диагностика. |

### Две Разные Операции С Именем `scoped`

Это основная ловушка API:

```php
$transaction = $dic
    ->object(PostgresTransaction::class, [$pool, 'beginTransaction'])
    ->scoped(); // lifetime самого transaction Ref

$requestTransaction = $dic->scoped($transaction);
// Ref<Scoped<PostgresTransaction>> — handle, открывающий новый scope в run()
```

Первая строка только определяет cache, в котором живёт service. Вторая создаёт
границу единицы работы.

### `canBeScoped()` Не Означает «Новый Каждый Раз»

Если handler зависит только от pool и stateless collaborators, он останется
singleton даже при resolve внутри разных scopes. Это подтверждено тестом
библиотеки `canBeScopedStaysSingletonWithoutScopedDependencies`.

Если тому же handler добавить scoped transaction, lifetime распространится
вверх по dependency graph, и handler станет scoped. Именно поэтому
`canBeScoped()` подходит для handler/controller/application service, которым
разрешено быть singleton, пока у них нет per-unit-of-work state.

Если новый экземпляр нужен независимо от dependency graph, требуется явный
`->scoped()`.

### Singleton Не Может Захватить Scoped Dependency

Thesis DIC проверяет полный graph при build. Явный singleton не может зависеть
от `scoped()` или даже от declaration `canBeScoped()`, пусть последний сейчас и
разрешился в singleton. Это fail-fast защита от будущего изменения глубокой
dependency.

Исправлять нужно владельца dependency:

- сделать зависимый service `canBeScoped()`/`scoped()`; либо
- если long-lived singleton должен запускать отдельные units of work, передать
  ему `Scoped<T>` handle через `$dic->scoped($ref)`.

Не следует превращать саму transaction/request context в singleton ради
успешной сборки graph.

### Provider Не Является Scope Factory

Provider захватывает текущий runtime container/scope:

```php
$provider = $dic->provider($handler); // Closure(): Handler
```

Повторные вызовы provider внутри одного scope возвращают один scoped handler.
В другом scope provider должен быть resolve-ен заново. Вызов provider сам по
себе не создаёт и не освобождает scope.

Именно поэтому MessageBus сначала открывает `Scoped<ThesisServiceResolver>`, а
resolver уже вызывает providers. Менять порядок на «singleton resolver +
provider scoped handler» нельзя: provider захватит неправильного владельца или
graph не соберётся.

## Как Выбирать Lifetime

| Владелец состояния | Lifetime | Примеры |
|---|---|---|
| Весь process | singleton | PostgreSQL connection pool, HTTP client, logger, compiled registry, server runtime |
| Один request/message/job | scoped | transaction, unit of work, job-local resolver, request context с mutable state |
| Stateless service, способный получить scoped dependency | canBeScoped | controller/action, MessageBus handler, reader/decorator, application service |
| Уже созданный immutable value | value/inferred | typed config, registry, codec map |
| Process singleton, запускающий units of work | singleton + `Scoped<T>` | HTTP server → request handler; worker runner → job resolver |

Практический порядок решения:

1. Назвать unit of work: process, HTTP request, внешний MessageBus dispatch,
   worker job или CLI invocation.
2. Найти место, которое гарантированно оборачивает весь unit of work в
   `Scoped::run()`.
3. Пометить mutable state этого unit как `scoped()` и зарегистрировать
   disposer, если ресурс требует cleanup.
4. Для stateless dependents использовать `canBeScoped()`.
5. Оставить process resources singleton.
6. Доказать identity, isolation и disposal тестом, включая exception path.

`readonly` не является достаточным признаком singleton: readonly-объект может
держать mutable transaction. И наоборот, mutable singleton допустим, если его
state намеренно process-wide или явно изолирован между fibers.

## Лекционный Request Scope

Лекционное приложение показывает правильную форму границы:

1. `PostgresConnectionPool` объявлен singleton.
2. `PostgresTransaction`, созданный через `beginTransaction()`, объявлен
   `scoped()` и имеет disposer.
3. Route actions и callable adapters объявлены `canBeScoped()`.
4. Наружный `ScopedRequestHandler` является singleton и хранит
   `Scoped<RequestHandler>`.
5. На каждый `handleRequest()` вызывается `Scoped::run()`, поэтому transaction
   и весь зависимый graph уникальны для request.

Упрощённая форма:

```php
$transaction = $dic
    ->object(PostgresTransaction::class, [$pool, 'beginTransaction'])
    ->scoped()
    ->disposer(static fn(PostgresTransaction $tx) => $tx->close());

$action = $dic
    ->object(Action::class)
    ->arg('transaction', $transaction)
    ->canBeScoped();

$requestHandler = $dic
    ->object(ActionRequestHandler::class)
    ->arg('action', $action)
    ->canBeScoped();

$scopedHandler = $dic
    ->object(ScopedRequestHandler::class)
    ->arg('scoped', $dic->scoped($requestHandler));
```

`SingletonAction` в лекции также помечен `canBeScoped()`, но не имеет scoped
dependencies и поэтому остаётся одним объектом между requests. Его
`spl_object_id()` специально демонстрирует adaptive lifetime.

### Что Из Лекции Нельзя Копировать Буквально

- Entry point использует `Dic::build()`. В `0.5.3` библиотека прямо оставляет
  этот метод тестам/диагностике; production root должен использовать
  `Dic::run()`, иначе disposer-ы container не вызываются.
- Connection pool в sample не имеет disposer. В Geo Registry pool корректно
  закрывается при завершении runtime.
- Transaction middleware присутствует в source, но не включён в показанный
  route graph. Production transaction policy должна явно определять
  commit/rollback и учитывать exception/cancellation.
- Scope закрывается сразу после возврата `Response`. Если response body читает
  scoped resource потоково после возврата handler, такая граница слишком
  короткая; scope должен охватывать фактическое завершение streaming.

Лекция является хорошим примером lifetime propagation и request boundary, но
не готовым production transaction recipe.

## `import()` И `apply()`

`import()` изолирует только configuration: binding table и listeners. Runtime
container всё равно общий.

Использовать `import()` следует для самостоятельного module или third-party
integration. Зависимости пересекают границу через явные `Ref`, переданные в
constructor module, а export возвращается из `configure()`.

`apply()` подходит для частей одного composition module, которым сознательно
нужны общие bindings/autoconfiguration. Лекционный `DemoModule` применяет
route-autoconfigurator через `apply()`, поэтому convention видит actions,
объявленные в том же module.

Не следует ожидать, что binding родителя автоматически виден импортированному
module. Это намеренная локальность Thesis DIC, а не ошибка autowiring.

## Disposal

Disposer принадлежит lifetime экземпляра:

- singleton освобождается при завершении `Dic::run()`;
- scoped instance — при завершении соответствующего `Scoped::run()`;
- не созданный service не освобождается;
- disposer получает исходный `Throwable`, если scope завершился ошибкой;
- все disposer-ы выполняются best-effort, после чего ошибки агрегируются в
  `DisposalFailed`.

Не нужно полагаться на reverse-topological порядок освобождения разных
services: публичный контракт его не обещает. Cleanup одного ресурса должен быть
самодостаточным. Commit/rollback является business policy, а не просто
техническим `close()`.

## Audit Geo Registry

| Область | Текущее состояние | Вывод |
|---|---|---|
| `PostgresConnectionPool`, AMPHP/OpenSearch/Nominatim clients, logger | Singleton; pool имеет disposer | Корректные process resources. |
| `HttpRuntime`, `RouterFactory`, actions, mappers, presenters | Singleton по умолчанию | Корректно сегодня: объекты stateless/readonly. Это не request scope. |
| `CorrelationContext` | Mutable singleton с `WeakMap<Fiber, CorrelationIds>` и cleanup в `finally` | Намеренный concurrency-aware process context; подтверждён parallel-fiber тестами. Не заменять простым mutable singleton. |
| `StartupState` | Mutable singleton | Намеренное process-wide startup состояние. |
| MessageBus handlers/readers/decorators | `canBeScoped()` | Корректно: сейчас большинство остаются singleton, но автоматически последуют за будущей scoped dependency. |
| `ThesisServiceResolver` | Explicit `scoped()` | Корректно: provider closures resolve-ятся в scope внешнего dispatch. |
| `ThesisServiceScopeFactory` | Singleton с `Scoped<Resolver>` | Корректная detached boundary. |
| Nested MessageBus dispatch | Переиспользует scope внешнего dispatch | Корректно; scenario test проверяет один scope и один dispose. |
| `WorkerJobServiceResolver` | Explicit `scoped()` | Корректно: содержит mutable cached `QueueWorkerInterface`, принадлежащий job. |
| Worker consumer/control/runtime | Singleton | Корректные process services, обслуживающие последовательность jobs. |
| CLI commands и migration services | Singleton в `Dic::run()` одного CLI invocation | Допустимо: container и root scope завершаются вместе с командой. |

### Найденный Архитектурный Пробел

HTTP transport не имеет отдельного Thesis scope на request. MessageBus scope
начинается только при `ask()`/`dispatch()`, поэтому он изолирует handlers, но не
request mapper, presenter, action или middleware.

Сейчас это не приводит к shared mutable request state. Перед добавлением
request transaction/context/unit-of-work нужно:

1. ввести singleton request adapter с `Scoped<RequestHandler>` по форме лекции;
2. объявить request-owned leaf services `scoped()`;
3. объявить actions и промежуточный route graph `canBeScoped()`;
4. определить, должен ли MessageBus открывать вложенный scope или использовать
   request scope — это отдельное решение о transaction boundary;
5. проверить два параллельных request, nested dispatch, cancellation и disposal.

Массово помечать существующие actions `scoped()` до появления этой границы не
нужно: это не даст request isolation и создаст ложное ощущение исправления.

## Review Checklist

При изменении `Application/DependencyInjection` проверить:

- какой unit of work владеет каждым mutable полем;
- где именно вызывается `Scoped::run()` для этого unit;
- не живёт ли explicit scoped service только в долгом root scope;
- должен ли `canBeScoped` service оставаться singleton без scoped dependencies;
- не используется ли provider как ошибочная замена scope factory;
- является ли `import()` boundary изолированным, а передаваемые `Ref` — явными;
- зарегистрирован ли disposer для pool/transaction/stream/resource;
- проходит ли cleanup при success, exception и cancellation;
- не переживает ли scope потоковый response или background task;
- доказана ли параллельная изоляция разными object IDs/state и отдельным
  disposal.

Минимальные gates после lifecycle-изменения:

```shell
make check
make test-integration
```

Для изменения только composition graph добавить targeted Testo test, который
собирает root через `Dic::run()`. Для нового scope нужен test минимум с двумя
последовательными scopes, а для HTTP/worker concurrency — с двумя параллельными
fibers.

## Проверенные Источники

- [Thesis DIC 0.5.3](https://github.com/thesis-php/dic/tree/0.5.3) — public API,
  lifetime/disposal docs, container implementation и tests.
- [Packagist: thesis/dic](https://packagist.org/packages/thesis/dic) — release,
  PHP requirements и commit distribution.
- `ApplicationMessageBusModuleTest` — nested dispatch использует один scope и
  освобождает его один раз.
- `ServiceIntegrationTest` MessageBus core — параллельные Thesis scopes имеют
  разные экземпляры и передают ошибку disposer-у.
- `ThesisWorkerModuleTest` — worker job scope создаёт и освобождает scoped
  handler.

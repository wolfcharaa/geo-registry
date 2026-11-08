# Production-like HTTP runtime

HTTP runtime запускается через единую операторскую точку `bin/app server:run`.
При `APP_HTTP_WORKERS=1` это один AMPHP process. При значении больше единицы
команда становится supervisor-ом `amphp/cluster` и запускает указанное число
отдельных PHP-процессов, которые принимают соединения с одного socket.

## Process Model

```text
bin/app server:run (supervisor, без application DIC graph и PostgreSQL pool)
  ├─ bin/app server:run (worker 1: собственные DIC graph, loop и DB pool)
  └─ bin/app server:run (worker 2: собственные DIC graph, loop и DB pool)
                         └─ общий listening socket через amphp/cluster
```

Supervisor использует `ProcessContextFactory`: workers создаются как новые PHP
processes, а не через `fork()` уже собранного container. Поэтому сокеты,
PostgreSQL connections, HTTP clients и Thesis DIC disposers не наследуются
между процессами. Неожиданно завершившийся worker автоматически заменяется.
Worker logs передаются supervisor-у по IPC и сохраняют единый JSON/console
formatter.

Supervisor исключает первичный запуск worker pool из restart budget. После
допустимого burst быстрых замен в скользящем окне каждый следующий запуск
задерживается с экспоненциальным backoff до настроенного потолка. После окна без
рестартов budget очищается. Это ограничивает crash-loop, но не скрывает его:
каждая задержка записывается warning-событием
`http.cluster.worker.restart.delayed`.

Production image содержит закреплённый `uv 0.3.1`, а также
`pcntl`, `posix`, `sockets`, `pgsql` и `pdo_pgsql`. Revolt использует
`UvDriver`, а `amphp/postgres` — неблокирующий `ext-pgsql`: connection
открывается с `PGSQL_CONNECT_ASYNC`, а queries отправляются через
`pg_send_query*` и ожидают socket watchers event loop. `ext-pq` намеренно
не входит в image: в PHP 8.4.27 его result objects попадают под
upstream GC regression и могут завершить long-lived process с
`zend_mm_heap corrupted`.

## Настройки

| Переменная | Dev | Prod | Семантика |
| --- | ---: | ---: | --- |
| `APP_HTTP_WORKERS` | `1` | `2` | Число HTTP worker processes, `1..64` |
| `APP_HTTP_SHUTDOWN_TIMEOUT_SECONDS` | `30` | `30` | Время graceful shutdown workers |
| `APP_HTTP_WORKER_MAX_REQUESTS` | `0` | `10000` | Ротация worker после числа завершённых requests; `0` отключает лимит |
| `APP_HTTP_WORKER_MAX_MEMORY_MB` | `0` | `256` | Ротация worker при достижении RSS в MiB; `0` отключает лимит |
| `APP_HTTP_WORKER_RESTART_BURST` | `5` | `5` | Число быстрых замен за окно без задержки; `0..1000` |
| `APP_HTTP_WORKER_RESTART_WINDOW_SECONDS` | `30` | `30` | Скользящее окно restart budget; `(0..3600]` |
| `APP_HTTP_WORKER_RESTART_BACKOFF_INITIAL_SECONDS` | `0.5` | `0.5` | Первая задержка после исчерпания burst; `(0..300]` |
| `APP_HTTP_WORKER_RESTART_BACKOFF_MAX_SECONDS` | `30` | `30` | Верхняя граница задержки, не меньше initial; `(0..300]` |
| `APP_HTTP_WORKER_HEARTBEAT_INTERVAL_SECONDS` | `5` | `5` | Интервал IPC heartbeat и проверки ёмкости; `(0..300]` |
| `APP_HTTP_WORKER_HEARTBEAT_TIMEOUT_SECONDS` | `15` | `15` | Максимальный возраст heartbeat, не меньше двух интервалов; `[2×interval..900]` |
| `APP_HTTP_WORKER_STALE_TERMINATION_GRACE_SECONDS` | `5` | `5` | Grace между `SIGTERM` и `SIGKILL` stale worker; `(0..30]` |
| `APP_HTTP_CONNECTION_LIMIT` | `512` | `1024` | Одновременные connections на worker |
| `APP_HTTP_CONNECTIONS_PER_IP_LIMIT` | `64` | `0` | Лимит на client IP; `0` отключает его за reverse proxy |
| `APP_HTTP_CONCURRENCY_LIMIT` | `256` | `512` | Максимум допущенных requests на worker; только положительное значение |
| `APP_HTTP_BACKLOG` | `512` | `1024` | TCP listen backlog |
| `APP_HTTP_COMPRESSION_ENABLED` | `1` | `1` | Response compression при наличии zlib |
| `HTTP_DATABASE_POOL_SIZE` | `4` | `4` | PostgreSQL connections на HTTP worker |
| `WORKER_DATABASE_POOL_SIZE` | `4` | `4` | PostgreSQL connections на queue worker process |
| `REVOLT_DRIVER` | `UvDriver` | `UvDriver` | Явный production loop driver |
| `ADDRESS_SEARCH_CACHE_ENABLED` | `0` | `1` | Локальный bounded cache итогов `SearchAddressPort` |
| `ADDRESS_SEARCH_CACHE_MAX_ENTRIES` | `512` | `512` | Максимум записей на один HTTP worker, `1..100000` |
| `ADDRESS_SEARCH_CACHE_TTL_SECONDS` | `60` | `60` | TTL непустого результата, `1..3600` |
| `ADDRESS_SEARCH_CACHE_EMPTY_TTL_SECONDS` | `5` | `5` | TTL пустого результата, `1..300` и не больше основного TTL |
| `ADDRESS_SEARCH_CACHE_NAMESPACE` | `v1` | `v1` | Версия локальной generation, безопасные `1..64` символа |

Connection/concurrency limits относятся к одному HTTP worker. При двух workers
эффективный верхний предел вдвое больше. Admission не создаёт очередь: когда
`APP_HTTP_CONCURRENCY_LIMIT` исчерпан, следующий request сразу получает `503`
с `error_code=http_capacity_exhausted` и `Retry-After: 1`, а handler и request
scope не запускаются. Событие `http.request.admission.rejected` содержит limit,
текущий in-flight count и монотонный счётчик отказов процесса. Значение `0`
запрещено, потому что отключало бы обязательную границу нагрузки.

Контейнер получает `nofile=65536`, но его нельзя считать разрешением
бесконтрольно увеличивать application limits: нужно учитывать ingress, память и
PostgreSQL capacity.

Search cache также принадлежит одному worker process. При двух workers hot set
может занимать до `2 × ADDRESS_SEARCH_CACHE_MAX_ENTRIES`, а первый одинаковый
запрос может один раз дойти до backend-а в каждом процессе. Внутри worker-а
concurrent cold misses одного ключа объединяются. Кэш хранит итоговые immutable
matches, не сохраняет exception/cancellation и полностью очищается при recycle.
Увеличивать число entries следует по измеренному hit rate и RSS, а не только по
числу уникальных пользовательских строк.

Оба лимита ротации действуют только в cluster mode (`APP_HTTP_WORKERS > 1`).
После завершения граничного request worker пишет
`http.worker.recycle.requested`, graceful закрывает HTTP connections и свой DIC
graph, а supervisor автоматически запускает замену на общем socket. RSS берётся
из `/proc/self/status`; `memory_get_usage(true)` используется только как
portable fallback. Memory limit должен оставлять запас относительно container
limit для supervisor, второго worker, native extensions и ответа в полёте.

Restart budget общий для supervisor process. Например, при production defaults
первые пять замен за 30 секунд выполняются немедленно, затем задержки составляют
`0.5`, `1`, `2`, `4`, `8`, `16`, `30` секунд. Штатная ротация также учитывается:
если request/RSS budget вызывает больше пяти замен за 30 секунд, backoff
намеренно сглаживает одновременный churn. Перезапуск container-а обнуляет
in-memory историю, а внешний Compose restart policy остаётся вторым уровнем
защиты, если завершится сам supervisor. Ожидание backoff отменяется сигналом
остановки и не увеличивает graceful shutdown timeout.

## Worker Pool Capacity

Каждый cluster worker после запуска отправляет supervisor-у typed IPC-сигнал
`online`, затем heartbeat с заданным интервалом и `offline` после остановки
HTTP server. Supervisor также проверяет существование PID и исключает worker,
если heartbeat устарел либо process исчез. Heartbeat не записывается в log на
каждом интервале и поэтому не создаёт постоянный log traffic.

Живой PID с просроченным heartbeat считается зависшим: supervisor сверяет
identity процесса по `/proc/<pid>/stat`, отправляет `SIGTERM`, а после
`APP_HTTP_WORKER_STALE_TERMINATION_GRACE_SECONDS` — `SIGKILL`, только если PID
всё ещё относится к тому же process. `amphp/cluster` reap-ит child и запускает
замену через общий restart budget. Переходы видны по событиям
`http.cluster.worker.stale.termination_requested` и
`http.cluster.worker.stale.force_killed`; ошибка signal delivery фиксируется
отдельно и не маскируется восстановлением capacity.

До первого достижения `APP_HTTP_WORKERS` неполный pool считается стартующим, а
не degraded. После этого supervisor пишет только переходы состояния:

- `http.cluster.capacity.ready` — первичный pool достиг заданной ёмкости;
- `http.cluster.capacity.degraded` — после контрольного интервала активных
  worker-ов стало меньше ожидаемого;
- `http.cluster.capacity.restored` — ёмкость восстановлена;
- `http.cluster.worker.online` и `http.cluster.worker.offline` — lifecycle
  конкретного `worker_id`/PID.

Быстрый штатный recycle обычно успевает заменить process до следующей проверки
и не создаёт ложного `degraded`. Повторяющийся `degraded/restored` указывает на
слишком агрессивные recycle limits, crash-loop или event-loop stall. Эти события
дают проверяемый pool status через централизованные logs без входа в container и
ручного `ps`. В single-process mode heartbeat не запускается: container health
остаётся источником состояния единственного process.

## PostgreSQL Connection Budget

Перед увеличением числа процессов должна выполняться формула:

```text
APP_HTTP_WORKERS × HTTP_DATABASE_POOL_SIZE
  + число активных queue workers × WORKER_DATABASE_POOL_SIZE
  + connections CLI/migrations/monitoring
  <= max_connections - superuser_reserved_connections
```

Например, production defaults `2 × 4 + 2 × 4 = 16` оставляют запас для
операторских команд и служебных connections. Supervisor PostgreSQL pool не
создаёт. Пул каждого worker закрывается disposer-ом его собственного DIC graph.

## Signals И Проверка

`SIGTERM`, `SIGINT`, `SIGQUIT` и `SIGHUP` останавливают supervisor и дают
workers завершиться в пределах shutdown timeout. `SIGUSR1` перезапускает все
HTTP workers без замены supervisor process. Compose `stop_grace_period` должен
быть больше `APP_HTTP_SHUTDOWN_TIMEOUT_SECONDS`.

Проверка работающего контейнера:

```shell
docker/compose/dev.sh exec app php -r \
  'require "vendor/autoload.php"; echo Revolt\EventLoop::getDriver()::class, PHP_EOL;'
docker/compose/dev.sh exec app ps -o pid,ppid,comm,args
curl --fail http://127.0.0.1:8080/health/live
docker/compose/dev.sh logs app
```

В логах должны присутствовать `http.cluster.starting`, по одному
`http.server.started` на worker с разными `pid`/`worker_id`, а при остановке —
`http.server.stopping` и `http.cluster.stopped`. Повторяющееся
`http.cluster.worker.restart.delayed` означает crash-loop или слишком
агрессивные request/RSS limits и требует проверки соседних worker error logs.

Development допускает `APP_HTTP_WORKERS=1`. Fallback на `StreamSelectDriver`
допустим только для запуска кода вне project image; production image и release
preflight требуют `UvDriver`, `ext-uv`, `ext-pgsql` и остальные runtime
extensions.

## Воспроизводимый Load/Fault Gate

`server:load-check` создаёт bounded число concurrent GET requests штатным
`amphp/http-client`, полностью читает каждый response body и возвращает non-zero
exit code при превышении error budget или p95 threshold. Автоматические retries
и redirects отключены: один attempt в отчёте означает один отправленный request.
Response body ограничен 1 MiB по умолчанию, чтобы ошибочный endpoint не превратил
нагрузочную проверку в неограниченное потребление памяти.

Для development stack базовый gate запускается из отдельного CLI process в том
же container/network namespace:

```shell
make load-check

# Явный профиль и JSON для сохранения evidence:
docker/compose/dev.sh exec app bin/app server:load-check \
  http://127.0.0.1:8080/health/live \
  --requests=10000 \
  --concurrency=64 \
  --max-failures=0 \
  --max-p95-ms=250 \
  --json
```

Команда измеряет клиентскую end-to-end latency с полным чтением body. Это
release gate и инструмент сравнения конфигураций, но не замена внешнему тесту
через production ingress: запуск внутри app container делит CPU с server.
Сравнивать результаты можно только на одном host, dataset, endpoint, revision и
профиле concurrency; cold и warm прогоны сохраняются отдельно.

Смешанный внешний workload настоящего `GET /api/addresses/search`, который
проверяет JSON-контракт и отдельные p95 по регионам `50`/`77`, запускается через
`make load-search`. Параметры, backend comparison и правила сохранения evidence
описаны в [address-search-load-test.md](address-search-load-test.md).

Автоматический fault gate входит в `make test-integration`: два worker-а делят
socket, один завершает request budget, supervisor поднимает замену, после чего
cluster штатно принимает `SIGTERM`. На работающем окружении controlled restart
проверяется отдельно и не меняет container identity:

```shell
docker/compose/dev.sh kill --signal SIGUSR1 app
make smoke
docker/compose/dev.sh logs --tail=100 app
```

В логах ожидаются `http.cluster.restarting`, новые `http.server.started` с
новыми `worker_id` и последующий успешный health/load gate.

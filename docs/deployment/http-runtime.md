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

Production image содержит закреплённые `ev 1.2.3`, `pq 2.2.3` и
`raphf 2.0.2`, а также `pcntl`, `posix` и `sockets`. Revolt использует
`EvDriver`; `amphp/postgres` при наличии `pq` выбирает неблокирующий pq-driver.
Комбинация `EvDriver + ext-pgsql` запрещена fail-fast проверкой, поэтому
`ext-pq` является обязательной частью production image. `ext-pgsql` и
`pdo_pgsql` остаются для COPY, migrations и blocking CLI boundaries.

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
| `APP_HTTP_CONNECTION_LIMIT` | `512` | `1024` | Одновременные connections на worker |
| `APP_HTTP_CONNECTIONS_PER_IP_LIMIT` | `64` | `0` | Лимит на client IP; `0` отключает его за reverse proxy |
| `APP_HTTP_CONCURRENCY_LIMIT` | `256` | `512` | Одновременные requests на worker; `0` отключает limiter |
| `APP_HTTP_BACKLOG` | `512` | `1024` | TCP listen backlog |
| `APP_HTTP_COMPRESSION_ENABLED` | `1` | `1` | Response compression при наличии zlib |
| `HTTP_DATABASE_POOL_SIZE` | `4` | `4` | PostgreSQL connections на HTTP worker |
| `WORKER_DATABASE_POOL_SIZE` | `4` | `4` | PostgreSQL connections на queue worker process |
| `REVOLT_DRIVER` | `EvDriver` | `EvDriver` | Явный production loop driver |

Connection/concurrency limits относятся к одному HTTP worker. При двух workers
эффективный верхний предел вдвое больше. Контейнер получает `nofile=65536`, но
его нельзя считать разрешением бесконтрольно увеличивать application limits:
нужно учитывать ingress, память и PostgreSQL capacity.

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
preflight требуют `EvDriver`, `ext-pq` и остальные runtime extensions.

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

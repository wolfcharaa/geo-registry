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
`http.server.stopping` и `http.cluster.stopped`.

Development допускает `APP_HTTP_WORKERS=1`. Fallback на `StreamSelectDriver`
допустим только для запуска кода вне project image; production image и release
preflight требуют `EvDriver`, `ext-pq` и остальные runtime extensions.

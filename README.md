# Geo Registry

Минимальная основа для долгоживущего неблокирующего PHP-сервиса на
`amphp/http-server`. В development-контур входят отдельная PostgreSQL приложения,
Nominatim и OpenSearch.

Целевые layer/runtime/data boundaries описаны в
[docs/architecture.md](docs/architecture.md). Текущий HTTP scaffold является
переходным и не задаёт placement новых business scenarios.

## Быстрый старт

```shell
make config
make build
make install
make up
make migrate
make worker-up
make logs SERVICE=nominatim
```

Первый запуск Nominatim скачивает и импортирует тестовый Monaco extract и может
занять несколько минут. Приложение стартует сразу, но `/health/ready` возвращает
`503`, пока зависимости не готовы. На Linux для OpenSearch требуется
`vm.max_map_count=262144`; Docker Desktop стоит выделить минимум 4 GB памяти.

## Состав

```text
app/                                   сердце приложения: код, тесты, Composer
app/bin/app                            единый executable для HTTP/CLI/worker
app/src/Application/                   process runtimes и DIC modules
app/src/Config/                        environment contract
app/src/Database/Migration/            история схемы application PostgreSQL
app/src/Infrastructure/Postgres/       async application DB pool
app/src/Infrastructure/Nominatim/      async geocoding client
app/src/Infrastructure/OpenSearch/     native async REST client and factory
app/src/Module/AddressRegistry/        business module и его MODULE.md
app/src/UserInterface/Http/            routes and JSON responses
config/                                локальные настройки окружения
docker/                                образы, Compose и smoke scripts
docker/compose/dev/compose.yaml        development services
app/tests/Fixture/Database/             versioned synthetic parity data
Makefile                               стабильный интерфейс команд
```

PostgreSQL приложения и встроенная база Nominatim используют разные services и
volumes. Их миграции, backup и reset нельзя объединять. Application database
требует расширения `postgis`, `hstore` и `pg_trgm`; development и integration
используют multi-architecture образ CargoSense, собранный из Dockerfile
официального PostGIS image и закреплённый digest-ом.

## HTTP endpoints

- `GET /health/live` — локальная проверка AMPHP process без network I/O.
- `GET /health/startup` — завершён ли startup HTTP runtime.
- `GET /health/ready` — параллельная проверка required PostgreSQL и optional
  Nominatim/OpenSearch с двухсекундным deadline на компонент.

Readiness возвращает `unavailable`/`503` только при отказе required-компонента.
Отказ только optional-компонентов возвращает `degraded`/`200`. Component payload
содержит безопасные имена, policy и status без DSN и текста исключений:

`NOMINATIM_ENABLED=0` и `OPENSEARCH_ENABLED=0` полностью исключают отключённую
интеграцию из HTTP graph, readiness и transitional routes, поэтому приложение
не выполняет сетевой probe к отсутствующему service.

```json
{
  "status": "degraded",
  "components": [
    {"name": "postgres", "policy": "required", "status": "ok"},
    {"name": "opensearch", "policy": "optional", "status": "unavailable"}
  ]
}
```
- `GET /geocode?q=Monte+Carlo&limit=5` — поиск через Nominatim.
- `GET /search?q=example&limit=5` — поиск в `OPENSEARCH_INDEX`.

Публичный `GET /api/addresses/search` по умолчанию читает PostgreSQL. Значение
`OPENSEARCH_ENABLED=1` разрешает HTTP/worker integration, а
`OPENSEARCH_SEARCH_ENABLED=1` подключает optional OpenSearch decorator: непустые
валидные hits возвращаются с `search_backend=opensearch`, а empty/outage/
malformed projection прозрачно использует PostgreSQL fallback. OpenSearch
projection строится командой `opensearch:reindex`: она создаёт versioned
physical index, проверяет parity и атомарно переключает `OPENSEARCH_INDEX` как
read alias. После первого успешного cutover online feature можно включить.

## Почему собственный OpenSearch client

Интеграция построена напрямую на `amphp/http-client`: один connection pool,
fiber-aware ожидание I/O, никаких Guzzle/PSR-18 adapters и сгенерированного слоя
для сотен неиспользуемых endpoints. `OpenSearchRequestFactory` отвечает только
за URL, headers, auth и JSON; `OpenSearchClient` — за transport, decoding и
ошибки HTTP.

Это намеренно небольшой transport, а не попытка повторить официальный SDK.
Расширять его нужно вертикально по реальным потребностям:

1. добавлять типизированные методы только для используемых API;
2. держать NDJSON `_bulk` bounded по batch size и числу in-flight requests;
3. retry разрешать только для явно идемпотентных операций;
4. AWS SigV4, mTLS и observability оформлять decorators/interceptors;
5. передавать уже поддерживаемый клиентом `Cancellation` из будущих долгих
   бизнес-сценариев и фоновых jobs.

## Контейнер приложения

`app/bin/app` запускает application-owned `CliRuntime` через `Dic::run()` и
Thesis Symfony Console Module. Default-команда `server:run` лениво собирает
отдельный `HttpRuntime`; migrations, GAR, OpenSearch и worker-команды доступны
через тот же executable. Worker commands открывают реальный `WorkerRuntime`
через managed provider только на время operation, поэтому pool и scopes
закрываются при успехе и исключении. MessageBus сохраняет neutral
resolver/scope contracts,
поэтому application container не становится service locator внутри handlers.
Внутренний контейнер Testo не является runtime-контейнером приложения.
Правила выбора singleton/scoped/canBeScoped, устройство request/message/job
scope и audit текущих composition roots описаны в
[Thesis DIC guide](docs/development/thesis-dic.md).

Durable delivery использует application PostgreSQL. Migration создаёт общую
queue table и worker control plane; runtime DDL при старте не выполняется.
`address_async` обслуживает physical queue `address`, `runtime_async` — queue
`runtime`. Оба worker service находятся в opt-in Compose profile `worker`,
поэтому обычный `make up` не запускает их до применения migrations.

Reverse geocode публикует `gar.address_coordinates.resolved`, только когда
Nominatim сопоставлен с GAR document без полных координат. Runtime worker
идемпотентно заполняет все представления объекта в `address_search`; duplicate
delivery не перезаписывает уже сохранённые координаты. Необязательное точечное
обновление OpenSearch включается отдельно через
`OPENSEARCH_COORDINATE_UPDATES_ENABLED=1`: его отказ логируется, но не отменяет
canonical PostgreSQL effect. Повтор события может восстановить проекцию.

## Статический анализ и тесты

`app/phpstan.neon` включает `phpstan-strict-rules`, bleeding edge и дополнительные
проверки callable signatures, неинициализированных свойств и небезопасных array
offsets. Testo запускает unit suite из `app/tests/`; тесты объявляются атрибутом
`#[Test]` и не наследуются от базового класса.

## Логи и correlation

Production-default `LOG_FORMAT=json` пишет JSON Lines в stdout. Локальный
`config/.env.example` использует `LOG_FORMAT=console`; допустимы только эти два
значения. Каждый record получает `event`, `service` и `runtime` в `extra`, а
активный scope добавляет `request_id`, `message_id`, `correlation_id`,
`operation_id` и `job_id`, когда они известны.

HTTP boundary принимает безопасные `X-Request-ID` и `X-Correlation-ID`, создаёт
новые IDs при отсутствии/невалидном значении и возвращает принятые IDs в
response headers. Fiber-local context очищается после запроса и явно
переносится в параллельные health checks.

## Команды

```shell
make doctor   # проверить Compose
make postgres-doctor # проверить выбранный профиль PostgreSQL и ресурсы host
make check    # formatter + strict PHPStan + Deptrac + fast Testo
make architecture-check # отдельно проверить направления зависимостей
app/bin/app          # запустить HTTP server через default server:run
app/bin/app list     # показать все HTTP/CLI/worker commands
app/bin/app migrate:new --all # показать неприменённые migrations
app/bin/app migrate:up --force-yes # применить migrations к application PostgreSQL
app/bin/app opensearch:reindex --help # blue-green rebuild и alias cutover
app/bin/app opensearch:index:cleanup --help # подтверждённая очистка inactive index
app/bin/app worker:status --all # показать worker status
make worker-up       # применить migrations и запустить address/runtime workers
make worker-status   # показать persisted status обоих workers
make worker-pause    # перестать брать новые jobs
make worker-resume   # возобновить claims
make worker-drain    # завершить текущие jobs и остановиться
make worker-restart  # запросить restart через control plane
make worker-logs     # следить за логами обоих worker services
make worker-down     # остановить оба worker services
make smoke    # проверить запущенный HTTP service
make smoke-release POSTMAN_ENVIRONMENT=config/release.postman_environment.json # strict Postman parity
make server-source-bundle # собрать проверяемый source workspace для data server
make server-preflight GAR_SOURCE=/data/gar SERVER_STORAGE_PATH=/data # read-only server gate
make down     # остановить без удаления volumes
```

Параметры application PostgreSQL, профили `dev`/`prod`, обязательные границы
CPU/RAM/диска и безопасный порядок применения описаны в
[postgresql-tuning.md](docs/postgresql-tuning.md). Изменение профиля не
перезапускает работающую БД автоматически.

Порядок перехода со старых RoadRunner/BoltDB queues и rollback описан в
[worker-cutover.md](docs/migration/worker-cutover.md). Файлы старых queues нельзя
удалять до подтверждённого нулевого остатка и завершения rollback window.
Подготовка representative GAR/Nominatim dataset, rebuild проекций, строгий
Postman gate и матрица сравнения legacy/AMPHP описаны в
[release-parity.md](docs/migration/release-parity.md). `make smoke-release`
записывает JUnit/JSON artifacts в `/tmp` по умолчанию и не подменяет фактический
import, queue drain или operator approval.

Перенос на сервер с полным GAR начинается с source-only архива трёх связанных
repositories. Он фиксирует exact revisions и не включает данные, secrets,
Docker images или volumes. Сборка архива, восстановление соседней workspace
структуры и fail-closed server preflight описаны в
[server-handoff.md](docs/migration/server-handoff.md). Текущий Compose остаётся
development topology и сам по себе не является production release artifact.

Команды `migrate:*` входят только в `CliRuntime` и используют `DATABASE_DSN`.
Они работают через blocking PDO и не включаются в HTTP/worker DIC graphs. База
Nominatim остаётся отдельным service и этими командами не изменяется.

Ephemeral PostgreSQL и OpenSearch для будущей Integration suite запускаются
отдельным Compose project и не используют development data volumes:

```shell
make integration-up
make test-integration
make integration-down
```

`make test-integration` сам собирает PHP runner, ждёт health обоих сервисов,
проверяет применение и полный rollback всех migrations на пустой базе и всегда
выполняет cleanup через shell trap. Отдельные `integration-up/down` нужны для
диагностики и ручного запуска тестов.

Versioned fixture для address scenarios описан в
[docs/migration/parity-fixture.md](docs/migration/parity-fixture.md). Он содержит
только synthetic data и отдельно проверяет региональные hierarchy cases.

По умолчанию они доступны только на loopback-портах `55432` и `59200`.
Параллельный запуск можно изолировать через `INTEGRATION_PROJECT_NAME`,
`INTEGRATION_POSTGRES_PORT` и `INTEGRATION_OPENSEARCH_PORT`. Прямой Compose
вызов остаётся доступен через
`docker compose -f docker/compose/integration/compose.yaml --profile integration`.

`config/.env` игнорируется Git. Значения из `.env.example` предназначены только
для локальной разработки. Образы
[`CargoSense/docker-postgis`](https://github.com/CargoSense/docker-postgis) и
`mediagis/nominatim` являются community images. PostGIS уже закреплён digest-ом;
для production следует отдельно проверить его provenance/SBOM. Nominatim нужно
закрепить проверенным digest-ом или собрать из официального release.

## Лицензия

Проект распространяется по лицензии [MIT](LICENSE).

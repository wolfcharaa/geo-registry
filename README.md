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
make logs SERVICE=nominatim
```

Первый запуск Nominatim скачивает и импортирует тестовый Monaco extract и может
занять несколько минут. Приложение стартует сразу, но `/health/ready` возвращает
`503`, пока зависимости не готовы. На Linux для OpenSearch требуется
`vm.max_map_count=262144`; Docker Desktop стоит выделить минимум 4 GB памяти.

## Состав

```text
app/                                   сердце приложения: код, тесты, Composer
app/bin/server.php                     HTTP entrypoint
app/bin/console                        CLI entrypoint
app/src/Bootstrap.php                  composition root
app/src/Application/                   process runtimes и DIC modules
app/src/Config/                        environment contract
app/src/Infrastructure/Postgres/       async application DB pool
app/src/Infrastructure/Nominatim/      async geocoding client
app/src/Infrastructure/OpenSearch/     native async REST client and factory
app/src/UserInterface/Http/            routes and JSON responses
config/                                локальные настройки окружения
docker/                                образы, Compose и smoke scripts
docker/compose/dev/compose.yaml        development services
Makefile                               стабильный интерфейс команд
```

PostgreSQL приложения и встроенная база Nominatim используют разные services и
volumes. Их миграции, backup и reset нельзя объединять.

## HTTP endpoints

- `GET /health/live` — локальная проверка AMPHP process без network I/O.
- `GET /health/startup` — завершён ли startup HTTP runtime.
- `GET /health/ready` — параллельная проверка required PostgreSQL и optional
  Nominatim/OpenSearch с двухсекундным deadline на компонент.

Readiness возвращает `unavailable`/`503` только при отказе required-компонента.
Отказ только optional-компонентов возвращает `degraded`/`200`. Component payload
содержит безопасные имена, policy и status без DSN и текста исключений:

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

OpenSearch начинает с пустого индекса. Mapping и ingestion следует добавлять
вместе с первым бизнес-сценарием, а не фиксировать в общей оснастке.

## Почему собственный OpenSearch client

Интеграция построена напрямую на `amphp/http-client`: один connection pool,
fiber-aware ожидание I/O, никаких Guzzle/PSR-18 adapters и сгенерированного слоя
для сотен неиспользуемых endpoints. `OpenSearchRequestFactory` отвечает только
за URL, headers, auth и JSON; `OpenSearchClient` — за transport, decoding и
ошибки HTTP.

Это намеренно небольшой transport, а не попытка повторить официальный SDK.
Расширять его нужно вертикально по реальным потребностям:

1. добавлять типизированные методы только для используемых API;
2. отдельно реализовать NDJSON streaming для `_bulk`;
3. retry разрешать только для явно идемпотентных операций;
4. AWS SigV4, mTLS и observability оформлять decorators/interceptors;
5. передавать уже поддерживаемый клиентом `Cancellation` из будущих долгих
   бизнес-сценариев и фоновых jobs.

## Контейнер приложения

`app/src/Bootstrap.php` запускает application-owned Thesis DIC module отдельного
`HttpRuntime`; PostgreSQL pool закрывается disposer-ом контейнера. Следующие
независимые root exports — `CliRuntime`, уже доступный через `app/bin/console`,
и будущий worker. MessageBus сохраняет neutral resolver/scope contracts, поэтому
application container не становится service locator внутри handlers. Внутренний
контейнер Testo не является runtime-контейнером приложения.

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
make check    # formatter + strict PHPStan + Deptrac + fast Testo
make architecture-check # отдельно проверить направления зависимостей
app/bin/console list # показать CLI commands
make smoke    # проверить запущенный HTTP service
make down     # остановить без удаления volumes
```

Ephemeral PostgreSQL и OpenSearch для будущей Integration suite запускаются
отдельным Compose project и не используют development data volumes:

```shell
make integration-up
make test-integration
make integration-down
```

`make test-integration` сам собирает PHP runner, ждёт health обоих сервисов и
всегда выполняет cleanup через shell trap. Отдельные `integration-up/down` нужны
для диагностики и ручного запуска тестов.

По умолчанию они доступны только на loopback-портах `55432` и `59200`.
Параллельный запуск можно изолировать через `INTEGRATION_PROJECT_NAME`,
`INTEGRATION_POSTGRES_PORT` и `INTEGRATION_OPENSEARCH_PORT`. Прямой Compose
вызов остаётся доступен через
`docker compose -f docker/compose/integration/compose.yaml --profile integration`.

`config/.env` игнорируется Git. Значения из `.env.example` предназначены только
для локальной разработки. Образ `mediagis/nominatim` является community image;
для production следует закрепить проверенный digest или собрать Nominatim из
официального release.

## Лицензия

Проект распространяется по лицензии [MIT](LICENSE).

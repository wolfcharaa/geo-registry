# Geo Registry

Минимальная основа для долгоживущего неблокирующего PHP-сервиса на
`amphp/http-server`. В development-контур входят отдельная PostgreSQL приложения,
Nominatim и OpenSearch.

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
app/src/Bootstrap.php                  composition root
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

- `GET /health/live` — жив ли AMPHP process.
- `GET /health/ready` — параллельная проверка PostgreSQL, Nominatim и OpenSearch.
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

AMPHP не поставляет DI-контейнер: его официальный набор сосредоточен на
event loop, HTTP, async I/O, процессах и синхронизации. Пока граф зависимостей
небольшой, `app/src/Bootstrap.php` остаётся явным composition root — это проще
проверять и не превращает handlers в service locator.

Если модулей станет больше, основной кандидат — PSR-11 совместимый PHP-DI с
явными definitions и разрешением сервисов только при старте процесса. Yii DI
имеет смысл рассматривать, если появится реальная потребность сбрасывать
состояние shared-сервисов между запросами. Внутренний контейнер, установленный
как зависимость Testo, не является runtime-контейнером приложения и напрямую
использоваться не должен.

## Статический анализ и тесты

`app/phpstan.neon` включает `phpstan-strict-rules`, bleeding edge и дополнительные
проверки callable signatures, неинициализированных свойств и небезопасных array
offsets. Testo запускает unit suite из `app/tests/`; тесты объявляются атрибутом
`#[Test]` и не наследуются от базового класса.

## Команды

```shell
make doctor   # проверить Compose
make check    # formatter + strict PHPStan + Testo
make smoke    # проверить запущенный HTTP service
make down     # остановить без удаления volumes
```

`config/.env` игнорируется Git. Значения из `.env.example` предназначены только
для локальной разработки. Образ `mediagis/nominatim` является community image;
для production следует закрепить проверенный digest или собрать Nominatim из
официального release.

## Лицензия

Проект распространяется по лицензии [MIT](LICENSE).

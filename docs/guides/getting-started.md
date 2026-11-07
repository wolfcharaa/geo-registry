# Первый Запуск Geo Registry

Результат этого руководства — запущенное приложение с применённой schema и
рабочими health checks. Реальные адреса здесь не загружаются; после проверки
runtime продолжите по [руководству данных](data-lifecycle.md).

## Требования

- Linux или Docker Desktop с Docker Engine и Compose;
- Git и Make;
- ресурсы для выбранных services; границы проверяет `make doctor`;
- на Linux для OpenSearch: `vm.max_map_count=262144`.

PHP и Composer на host не нужны. Не запускайте development-команды через
`sudo`.

## 1. Получить Приложение

Geo Registry `v0.1.0` подключает опубликованные библиотеки через Packagist.
Соседние library checkout больше не требуются:

```shell
git clone --branch v0.1.0 \
  https://github.com/wolfcharaa/geo-registry.git
cd geo-registry
```

`app/composer.lock` фиксирует `v0.1.0` и source references обеих библиотек.
`make install` использует эти exact dist packages, поэтому отдельный checkout
библиотек не участвует ни в development runtime, ни в production build.

В другом Composer-приложении та же release line устанавливается напрямую из
Packagist:

```shell
composer require \
  'romanfedorskij/message-bus-async:^0.1' \
  'romanfedorskij/message-bus-async-worker:^0.1'
```

Metadata и доступные версии: [core package][packagist-core] и
[worker package][packagist-worker].

[packagist-core]: https://packagist.org/packages/romanfedorskij/message-bus-async
[packagist-worker]: https://packagist.org/packages/romanfedorskij/message-bus-async-worker

## 2. Настроить Окружение

```shell
make config
```

Команда создаёт ignored `config/.env`, не перезаписывая существующий файл.
Development defaults включают application PostgreSQL, OpenSearch и тестовый
Nominatim с Monaco extract. Это проверка integrations, а не российская база.

Для первого запуска без внешних integrations:

```dotenv
NOMINATIM_ENABLED=0
OPENSEARCH_ENABLED=0
OPENSEARCH_SEARCH_ENABLED=0
OPENSEARCH_COORDINATE_UPDATES_ENABLED=0
```

Не переносите development credentials в production.

## 3. Собрать И Запустить

```shell
make doctor
make build
make install
make up
make migrate
```

`make up` не запускает worker profile. Если нужны durable workers:

```shell
make worker-up
```

Первый import Nominatim может занять несколько минут. Если Nominatim отключён,
приложение не ждёт его network timeout.

## 4. Проверить Runtime

```shell
make ps
make smoke
curl --fail http://127.0.0.1:8080/health/live
curl --fail http://127.0.0.1:8080/health/ready
docker/compose/dev.sh run --rm --no-deps app bin/app list
```

После одних migrations address search пуст — это ожидаемо. Application schema,
Nominatim database и OpenSearch index не наполняют друг друга автоматически.

## 5. Выбрать Следующий Шаг

- [Скачать GAR, выделить регионы 50/77 и построить поиск](data-lifecycle.md).
- [Проверить HTTP API](../api/openapi.yaml).
- [Настроить PostgreSQL](../postgresql-tuning.md).
- [Подготовить production runtime](../deployment/production-compose.md).

Остановить development runtime без удаления volumes:

```shell
make down
```

# Первый Запуск Geo Registry

Это руководство поднимает development runtime и проверяет приложение до
загрузки многомиллионного GAR. Для реальных адресов продолжите по
[жизненному циклу данных](data-lifecycle.md).

## Что Нужно

- Linux или Docker Desktop с Docker Engine и Compose;
- Git и Make;
- достаточно ресурсов для выбранных сервисов; точные dev/prod границы проверяет
  `make doctor` и описывает [PostgreSQL tuning](../postgresql-tuning.md);
- на Linux для OpenSearch: `vm.max_map_count=262144`.

PHP и Composer на host не обязательны: приложение собирается и проверяется в
контейнере. Не запускайте development-команды через `sudo`.

## Получить Совместимую Workspace

Приложение `v0.1.0` использует Composer path repositories, поэтому три Git
release должны лежать в фиксированной соседней структуре:

```shell
mkdir -p geo-registry-workspace/library
cd geo-registry-workspace

git clone --branch v0.1.0 \
  https://github.com/wolfcharaa/geo-registry.git geo-registry
git clone --branch v0.1.0 \
  https://github.com/wolfcharaa/message-bus-async.git library/MessageBusAsync
git clone --branch v0.1.0 \
  https://github.com/wolfcharaa/message-bus-async-worker.git library/MessageBusAsyncWorker

cd geo-registry
```

Не смешивайте произвольные ветки: `app/composer.lock` фиксирует точные commits
обеих библиотек, а production build проверяет их совпадение с соседними
checkout.

## Настроить Окружение

```shell
make config
```

Команда создаёт ignored `config/.env`, не перезаписывая существующий файл.
Development defaults включают PostgreSQL, OpenSearch и тестовый Nominatim с
Monaco extract. Это проверка интеграций, а не российский адресный dataset.

Если внешние интеграции пока не нужны, явно выключите их в `config/.env`:

```dotenv
NOMINATIM_ENABLED=0
OPENSEARCH_ENABLED=0
OPENSEARCH_SEARCH_ENABLED=0
OPENSEARCH_COORDINATE_UPDATES_ENABLED=0
```

Отключённые сервисы не попадают в runtime graph и не задерживают readiness.
Не переносите development credentials в production.

## Собрать И Запустить

```shell
make doctor
make build
make install
make up
make migrate
make worker-up
```

`make up` не запускает worker profile. `make worker-up` повторно проверяет
migrations и запускает два durable worker процесса. Первый импорт Nominatim
может занимать несколько минут; до его готовности required PostgreSQL уже
работает, а readiness сообщает состояние компонентов.

## Проверить Runtime

```shell
make ps
make smoke
curl --fail http://127.0.0.1:8080/health/live
curl --fail http://127.0.0.1:8080/health/ready
docker/compose/dev.sh run --rm --no-deps app bin/app list
```

После одних migrations поиск будет пустым — это корректно. Схема приложения,
Nominatim DB и OpenSearch index имеют разные lifecycle и не наполняют друг друга
автоматически.

## Следующие Шаги

- [Загрузить GAR и построить проекции](data-lifecycle.md).
- [Проверить HTTP API](../api/openapi.yaml).
- [Настроить MessageBus workers](../migration/worker-cutover.md).
- [Подготовить production Compose](../deployment/production-compose.md).

Остановить development runtime без удаления volumes:

```shell
make down
```

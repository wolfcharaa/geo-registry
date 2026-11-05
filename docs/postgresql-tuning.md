# Профили PostgreSQL

Application PostgreSQL запускается с одним из двух versioned-профилей:

| Профиль | CPU min/recommended | RAM min/recommended | Свободный диск min/recommended | Назначение |
|---|---:|---:|---:|---|
| `dev` | 2 / 4 | 4 / 8 GiB | 16 / 32 GiB | Разработка и небольшие fixtures |
| `prod` | 8 / 16 | 32 / 48 GiB | 192 / 384 GiB | Data server, GAR COPY/import и рабочая нагрузка |

Порог `min` является обязательным: `docker/compose/dev.sh up`, `start` и
`restart` завершаются до изменения runtime, если Docker предоставляет меньше
CPU/RAM или на filesystem Docker data root недостаточно места. Значение
`recommended` выводит warning, но не блокирует запуск. Для server preflight
проверяется `SERVER_STORAGE_PATH`, потому что оператор обязан указать
filesystem фактического размещения данных.

## Выбор И Переопределение

Локальный `config/.env` выбирает профиль:

```dotenv
POSTGRES_TUNING_PROFILE=dev
```

На data server допустим только:

```dotenv
POSTGRES_TUNING_PROFILE=prod
```

Versioned defaults находятся в `config/postgres/dev.env` и
`config/postgres/prod.env`. Любой параметр из выбранного профиля можно
переопределить в ignored `config/.env`; этот файл имеет более высокий
приоритет. Например, для сервера с отдельным быстрым volume:

```dotenv
POSTGRES_MAX_WAL_SIZE=96GB
POSTGRES_MIN_WAL_SIZE=12GB
POSTGRES_MIN_FREE_DISK_GB=256
POSTGRES_RECOMMENDED_FREE_DISK_GB=512
```

Переопределение проходит те же проверки связности. Минимальный свободный диск
должен быть не меньше двойного `max_wal_size`, поскольку этот параметр является
мягким пределом, а не квотой WAL.

## Что Настраивается

Профиль управляет:

- `shared_buffers`, planner hint `effective_cache_size`, `work_mem` и memory
  autovacuum/maintenance;
- `wal_buffers`, `min_wal_size`, `max_wal_size`, WAL compression и checkpoint
  cadence;
- общим и параллельным worker budget;
- I/O concurrency, huge pages и I/O/WAL timing;
- размером `/dev/shm` контейнера.

Проверка не разрешает `shared_buffers` выше 40% минимальной RAM. Также она
считает базовую сумму `shared_buffers + work_mem * max_connections +
maintenance_work_mem + autovacuum_work_mem * workers + wal_buffers` и требует
оставить не менее 25% минимальной RAM процессам, ядру и page cache. Реальный
расход `work_mem` может быть выше этой оценки: память выделяется на операцию
плана и параллельный worker, поэтому увеличивать её глобально без query plans
нельзя.

Настройки durability (`fsync`, `full_page_writes`, `synchronous_commit`) профили
не ослабляют. Container memory/CPU limits намеренно не задаются: PostgreSQL
может использовать свободный page cache, но его основные собственные бюджеты
остаются явными и проверяемыми.

## Проверка И Применение

```shell
make doctor             # оба versioned-профиля и Compose interpolation
make postgres-doctor    # выбранный профиль плюс фактическая capacity Docker host
docker/compose/dev.sh config
```

Параметры `postgres -c` применяются только при создании нового container
process. Изменение файлов само по себе не меняет работающую БД. После завершения
и фиксации текущих data operations профиль можно применить управляемым recreate:

```shell
docker/compose/dev.sh up --detach --no-build --force-recreate postgres
```

Перед recreate нужно убедиться, что импортеры и workers корректно остановлены;
команда не должна запускаться поверх активного GAR import. Named volume с
данными не удаляется. После старта эффективные значения проверяются через
`SHOW` и `pg_settings`, а checkpoint/WAL эффект — через
`pg_stat_checkpointer` и `pg_stat_wal`.

Использовать для запуска следует wrapper `docker/compose/dev.sh`: он загружает
сначала выбранный профиль, затем operator overrides. Прямой вызов `docker
compose -f ...` сохраняет dev defaults из Compose, но не даёт profile selection
и обязательный capacity gate.

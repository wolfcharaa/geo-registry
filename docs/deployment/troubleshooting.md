# Диагностика Развёртывания

Этот раздел отвечает на вопросы, которые уже возникали при реальном переносе
Geo Registry на новый сервер. Главный принцип: если длительная команда
прервалась со стороны SSH-клиента, сначала выясните состояние server-side
операции и только потом что-либо повторяйте.

## Команда Работает Или Зависла

`data-backup` и `data-restore` печатают heartbeat каждые 30 секунд. В нём есть:

- сколько секунд прошло;
- сколько bytes уже записано в backup либо каков текущий размер БД;
- число появившихся relations при restore;
- состояние, wait event и текущая PostgreSQL operation, если она видна.

Интервал можно уменьшить для диагностики без изменения Compose:

```shell
RESTORE_PROGRESS_INTERVAL_SECONDS=10 make data-restore
BACKUP_PROGRESS_INTERVAL_SECONDS=10 make data-backup
```

Процент намеренно не вычисляется: custom dump сжимается, а при restore данные,
индексы и WAL имеют разные размеры. Сравнение bytes архива с размером БД
показывало бы ложный процент.

Нагрузка PostgreSQL в `docker stats` на уровне 10–15% не означает зависание.
Операция может ждать storage, читать сжатый stream, строить один большой индекс
или валидировать constraint. Ориентируйтесь на heartbeat и состояние БД.

Для GAR import/rebuild откройте вторую сессию и выполните:

```shell
make data-status
```

Команда показывает durable checkpoints, количество обработанных файлов/tasks,
активные SQL statements и COPY workers. Она read-only и не перезапускает
операцию.

## Оборвалась SSH-сессия Во Время Restore

Не запускайте `make data-restore` повторно. `docker exec` может продолжать
`pg_restore`, даже если управляющий terminal исчез. Подключитесь снова и
выполните:

```shell
make data-restore-status
```

| `State` | Что это значит | Следующий шаг |
| --- | --- | --- |
| `postgres-not-running` | Managed PostgreSQL не запущен | `make data-restore` либо `make data-init` |
| `database-absent` | БД ещё не была создана | `make data-restore` |
| `restore-active` | `pg_restore` продолжает работу | ждать; restore не повторять и PostgreSQL не останавливать |
| `present-needs-application-validation` | `pg_restore` уже не работает, БД сохранена | `make deploy-start` |
| `present-incomplete-or-invalid` | Остались invalid indexes либо unvalidated constraints | сохранить БД и логи; не повторять restore и не активировать сервис |
| `owner-security-gate-failed` | Owner/privileges не вернулись в безопасное состояние | сохранить БД и проверить restore cleanup; не активировать сервис |

Статус также печатает размер БД, owner, число relations, invalid indexes,
unvalidated constraints и privilege flags owner role. Это заменяет ручной набор
SQL-запросов из runbook.

Длительные операции лучше изначально запускать в `tmux` или `screen`, но это
только защита terminal. Источником истины всё равно остаётся состояние
PostgreSQL.

## Повторный Restore Пишет, Что БД Уже Существует

Это защитное поведение, а не просьба удалить БД. Restore создаёт только новую
БД и никогда не пишет поверх существующей. Сначала выполните:

```shell
make data-restore-status
```

Если state — `restore-active`, ждите. Если process уже исчез, но invalid indexes
и unvalidated constraints равны нулю, выполните application gates:

```shell
make deploy-start
```

Повторный `data-restore` теперь останавливается до временного повышения owner
role. Нельзя делать `DROP DATABASE`, удалять volume или запускать
`docker compose down -v` только ради повторной попытки: сначала сохраните
результат и причину сбоя. Автоматического удаления частично восстановленной БД
нет именно для того, чтобы не уничтожить диагностические данные.

## Как Понять, Что Restore Полностью Завершён

Успешная управляющая сессия заканчивается строками `Shadow restore completed`
и `Release data is restored`. После потерянной сессии используйте два gate:

```shell
make data-restore-status
make deploy-start
```

Первый gate проверяет физическое состояние БД. Второй проверяет frozen image,
migration history, GAR integrity, точное покрытие регионов 50/77, запускает
application processes и выполняет HTTP smoke. Только успешный `deploy-start`
означает, что сервис активирован.

## Появилось Предупреждение Об Orphan Containers

Compose может увидеть Nominatim или OpenSearch от предыдущего контура. Во время
restore не добавляйте `--remove-orphans`: старый container или volume может
быть нужен для rollback. Сначала завершите `deploy-start`, проверьте сервис и
закройте rollback window; cleanup выполняется отдельным осознанным действием.

## Ошибка `bind: address already in use` Возникла В Конце Запуска

В актуальном deployment-контракте этот конфликт проверяется до migration/GAR
gates и повторно непосредственно перед activation:

```shell
make deploy-port-preflight
```

Команда читает итоговый `services.app.ports`, показывает host binding,
listeners и Docker containers, публикующие тот же port. Контейнер текущего
managed приложения распознаётся как допустимый владелец; посторонний listener
останавливает запуск.

Если владельца порта нельзя остановить, задайте другой несекретный binding в
ignored YAML:

```yaml
services:
  app:
    ports: !override
      - 127.0.0.1:18080:8080
```

Повторите `make deploy-port-preflight`, затем `make deploy-start`. Финальный
`make deploy-smoke` автоматически использует `18080`; передавать `APP_PORT` в
shell session не требуется.

## `make release-download`: Нет Такого Target

Команды являются частью конкретного Git tag. Не переносите отдельный Makefile
или scripts из `main` в старый checkout. В native deployment используется:

```shell
git fetch --tags origin
git switch --detach <release-tag>
make deploy-init
make deploy-image DOCKER_PLATFORM=linux/amd64
```

`deploy-image` сам выбирает verified offline archive либо public registry
image. Если target отсутствует в checkout, значит открыт tag, в котором этот
контракт ещё не существовал.

## Ошибка Manifest Tag Или Exact Image Digest

Обычно смешаны Git tag, release manifest и image archive разных версий.
Проверьте checkout и не вызывайте низкоуровневый `change-release.sh` вручную:

```shell
test "$(git rev-parse HEAD)" = "$(git rev-parse 'refs/tags/<release-tag>^{commit}')"
git status --short
make deploy-image DOCKER_PLATFORM=linux/amd64
```

`git status --short` не должен ничего печатать. Для offline media рядом должны
лежать оба файла — image TAR и его manifest. Частичный комплект не используется
как fallback.

## Где Взять Registry Token

Для pull публичного `ghcr.io/wolfcharaa/geo-registry/app` token не нужен.
`make deploy-image` выполняет anonymous pull. Token нужен только publisher-у
для push либо оператору private registry; в этом случае его создаёт владелец
registry и выполняет `docker login` до deployment.

## Где Должен Лежать Backup И Как Его Перенести

Переносится весь каталог, а не один `.dump`:

```text
application-postgres-<identity>/
├── application-postgres.dump
└── manifest.txt
```

На target положите его в `docker/data/backups/`. Если там ровно один backup,
достаточно:

```shell
make data-restore
```

Manifest хранит bytes, SHA-256, source PostgreSQL major и application revision.
Без него dump не считается переносимым release artifact. Для нового экспорта с
managed сервера используйте:

```shell
make data-backup-preflight
make data-backup
```

Путь опубликованного каталога будет напечатан после checksum/TOC verification.

## Что Хранится В YAML, А Что В Env-file

Несекретные production defaults находятся в tracked `compose.yaml` и
`docker/compose/prod/compose.yaml`. Host-specific несекретные настройки
помещаются в ignored `compose.override.yaml`. `config/prod.secrets.env` содержит
только пароли и API keys; его создаёт `make deploy-init`. Session `export` для
обычного deploy/restore не требуется.

Копируйте из руководства только строки внутри shell-блоков. Поясняющие слова
вроде «затем» не являются shell-командами.

## Нужно Ли Отдельно Запускать PostgreSQL

Нет. `data-restore` и `data-init` сами поднимают managed PostgreSQL и ждут его
healthcheck. Обычный путь нового сервера остаётся линейным:

```shell
make deploy-init
make deploy-image DOCKER_PLATFORM=linux/amd64
make data-restore
make deploy-start
```

Альтернатива без готового backup — `data-init`, официальный GAR download/import,
`data-address-rebuild`, optional `data-opensearch`, затем `deploy-start`.

Эти пути вынесены в два самостоятельных документа:

- [развёртывание с готовым PostgreSQL backup](deploy-with-backup.md);
- [наполнение нового сервера из официальных источников](deploy-from-sources.md).

# Переключение Durable Workers

- Статус: `Target lifecycle verified; production cutover requires release approval`
- Обновлено: `2026-11-07`
- Владелец решения: application release operator

Документ задаёт проверяемый переход со старых RoadRunner/BoltDB queues на
application-owned PostgreSQL queue. Он не является разрешением менять production
state и не подтверждает, что legacy queues уже пусты.

## Контракты Переключения

| Контур | Старый runtime | Новый runtime | Правило |
| --- | --- | --- | --- |
| Coordinate enrichment | RoadRunner `runtime` | PostgreSQL `runtime_async` → `runtime` | Alias `gar.address_coordinates.resolved` и binding `gar.address_coordinates.update` сохраняются |
| GAR/background jobs | RoadRunner `gar` | CLI-only operations; `address` зарезервирована для будущих bindings | Jobs не переносятся автоматически между хранилищами |

Legacy storage находится в файлах, заданных `RR_JOBS_RUNTIME_FILE` и
`RR_JOBS_GAR_FILE`; проверенная конфигурация по умолчанию использует
`app/runtime/rr/jobs-runtime.db` и `app/runtime/rr/jobs-gar.db`. Эти файлы нельзя
удалять, обнулять или переиспользовать как PostgreSQL queue.

## Preconditions

Перед release window должны быть зафиксированы:

1. одна revision приложения для HTTP producer и worker;
2. успешные `make check`, `make contract`, `make test-integration`;
3. применённая migration с `message_bus__queue_jobs` и worker-control tables;
4. существующий OpenSearch alias/target, если включается
   `OPENSEARCH_COORDINATE_UPDATES_ENABLED=1`;
5. snapshot/backup application PostgreSQL и сохранённые legacy BoltDB files;
6. способ получить counts/status старых `runtime` и `gar` queues средствами
   старого deployment. Новый репозиторий намеренно не открывает их напрямую.

## Cutover

1. Остановить или перевести в maintenance старые HTTP producers, чтобы после
   начала drain в RoadRunner queues не появлялись новые jobs.
2. Записать timestamp, revision, status и остаток обеих legacy queues.
3. Оставить старые RoadRunner consumers включёнными до нулевого остатка.
   Неуспешные jobs разобрать явно: завершить, отменить с причиной либо оставить
   rollback evidence. `gar` jobs должны завершиться в старом runtime и не
   копируются в новый `address` transport.
4. Повторно подтвердить нулевой остаток `runtime` и `gar`, остановить legacy
   consumers и сохранить read-only копию BoltDB files.
5. Применить target migrations командой `make migrate`, затем запустить workers
   через `make worker-up` и проверить `make worker-status`.
6. Переключить AMPHP HTTP producer той же revision. Только после готовности
   OpenSearch alias включать optional coordinate updater.
7. Выполнить reverse-geocode probe для адреса без координат и подтвердить:
   queue job `succeeded`, PostgreSQL заполнен, OpenSearch либо обновлён, либо
   зафиксирован bounded warning без retry canonical effect.
8. Выписать точный active instance из `make worker-status`, выполнить
   `make worker-restart WORKER_INSTANCE_ID=<exact-id>`, затем убедиться, что
   новый instance снова принимает jobs и старый отмечен stopped.

Шаг 4 закрывает последний W2 release gate. До появления evidence checkbox
legacy queue drain в roadmap остаётся незавершённым.

## Drain И Planned Restart

- `make worker-pause` прекращает новые claims, но не прерывает текущий job.
- `make worker-resume` возвращает paused workers к обработке.
- `make worker-drain WORKER_INSTANCE_ID=<exact-id>` завершает текущий job и
  останавливает ровно указанный process до supervisor restart.
- `make worker-restart WORKER_INSTANCE_ID=<exact-id>` создаёт persisted restart
  request для одного process; supervisor должен поднять новый instance после
  специального exit code.
- `make worker-status` и `make worker-logs` являются обязательной проверкой
  результата каждой control operation.

One-shot `drain`, `stop`, `kill` и `restart` на application CLI требуют
`--worker-instance-id`; group/name/all targets отклоняются до записи command.
Причина — command TTL библиотеки: широкая one-shot команда остаётся видимой
replacement process и может создать restart loop. `pause`/`resume` остаются
desired-state operations и поддерживают group target.

SIGTERM использует cooperative graceful stop. Принудительное завершение до
истечения grace period разрешено только как incident action: lease recovery
вернёт stale job в pending, поэтому handler effects обязаны оставаться
идемпотентными.

Development full-stack evidence от `2026-11-07` подтвердил group pause/resume
и exact-instance drain/restart: оба replacement process зарегистрировались как
`running/idle`, GAR queue осталась пустой. Небезопасный broad drain был погашен
по точной command identity, worker восстановлен, а воспроизводивший его путь
закрыт CLI guard-ом. Real-PostgreSQL integration отдельно подтверждает возврат
stale `running` job в `pending` после истечения heartbeat lease.

Свежий pre-cutover snapshot `2026-11-07 10:25 UTC` снят штатной legacy-командой
`php app.php rr:jobs:list`: pipelines `gar` и `runtime` активны, у обеих
`active=0`, `delayed=0`, `reserved=0`. Текущие mutable BoltDB files:

- `jobs-gar.db`: `32768` bytes,
  SHA-256 `1f90450d68e12e7f0a5093eda62168b0f6a652100bc447e0578711d4c869a508`;
- `jobs-runtime.db`: `65536` bytes,
  SHA-256 `00da206d67bb49ae5f0bccbbd0d889beaa7f04a56a3d6d929a8c657bdf7f460a`.

Это не cutover backup: legacy producers/consumers продолжают работать, поэтому
files могут измениться после snapshot. Финальные counts, копии и checksums
снимаются только после запрета новых publications в release window.

## Rollback

1. Остановить новый HTTP producer, чтобы PostgreSQL queue перестала расти.
2. Для каждого active instance из `make worker-status` отдельно выполнить
   `make worker-drain WORKER_INSTANCE_ID=<exact-id>`; не удалять
   pending/running/failed queue rows и не выполнять migration down.
3. Если возвращается старый HTTP runtime, восстановить его вместе с сохранёнными
   BoltDB files. Новые PostgreSQL jobs остаются замороженными до повторного
   forward cutover: wire-level перенос между transports не выполняется.
4. OpenSearch можно отключить независимо через
   `OPENSEARCH_COORDINATE_UPDATES_ENABLED=0`; PostgreSQL coordinates остаются
   canonical и не откатываются.
5. После устранения причины снова развернуть одну target revision, запустить
   workers и дать им обработать сохранённые PostgreSQL jobs.

Schema rollback допустим только после отдельного data-retention решения и
подтверждения отсутствия нужных queue/control records.

## Evidence Закрытия

- release/revision и границы maintenance window;
- before/after counts старых `runtime` и `gar` queues;
- checksum или immutable location сохранённых BoltDB copies;
- `make worker-status` до и после restart;
- counts PostgreSQL queue по status и отсутствие неожиданного retry роста;
- результат reverse-geocode probe и canonical coordinates;
- состояние OpenSearch projection либо зафиксированный optional outage;
- решение о конце rollback window и только затем — отдельное разрешение на
  удаление legacy storage.

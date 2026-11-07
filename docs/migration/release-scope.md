# Scope первого release

- Статус: `Accepted implementation scope; release evidence pending`
- Обновлено: `2026-11-07`
- Связанный gate: [release-parity.md](release-parity.md)

## HTTP Surface

Первый AMPHP release поддерживает только семь операций со статусом `current` в
OpenAPI 3.1:

| Группа | Method и path | Назначение |
| --- | --- | --- |
| Health | `GET /health/live` | Локальная жизнеспособность process |
| Health | `GET /health/ready` | Готовность required и optional integrations |
| Health | `GET /health/startup` | Завершение composition/startup |
| Address | `GET /api/addresses/search` | Региональный поиск |
| Address | `POST /api/addresses/suggest` | Staged city/district/street suggest |
| Address | `POST /api/addresses/find-by-id` | Поиск GAR address по identifier |
| Address | `GET /api/addresses/reverse-geocode` | Nominatim lookup с GAR match |

Transitional demo routes `/geocode` и `/search` не входят в release contract.
Они сохраняются только как migration bridges до R2 cutover и удаляются после
readiness/parity approval.

## Application CLI Surface

Все operations запускаются через единый executable `app/bin/app`; без аргументов
он выполняет default `server:run`. Release procedure поддерживает следующие
application commands:

| Команда | Release role |
| --- | --- |
| `message-bus:registry:check` | Fail-fast compilation registry/service/codec graph |
| `migrate:up` | Применение schema history |
| `migrate:down` | Контролируемый rollback по runbook |
| `migrate:history` | Evidence текущей schema revision |
| `gar:import` | Blocking CLI-only import canonical GAR XML |
| `gar:address-search:rebuild` | Regional partitioned read-model rebuild и cutover |
| `gar:status` | Import/rebuild manifests и региональные coverage counters |
| `gar:integrity` | Fail-closed quick/deep release gate для выбранных регионов |
| `gar:activity` | Read-only active SQL и durable GAR/worker activity snapshot |
| `opensearch:reindex` | Blue-green projection build и alias switch |
| `opensearch:status` | Fail-closed cluster/alias/source-target parity и inactive index inventory |
| `opensearch:index:cleanup` | Явное удаление подтверждённого inactive index |

Framework commands `migrate:create`, `migrate:new`, `migrate:redo` и особенно
`migrate:mark` остаются доступными tooling-ом, но не являются шагами production
release procedure. `completion`, `help` и `list` — стандартная Symfony Console
surface, а не application contract.

`gar:releases` также доступен как optional внешний metadata lookup перед
выбором GAR source. Он не скачивает данные и не входит в release gate: после
фиксации source manifest и checksum недоступность endpoint ФНС не должна
останавливать import/parity sequence.

## Worker CLI Surface

Worker package входит в общую совместимую `0.1.x` release line. Его commands
также зарегистрированы в `app/bin/app`, но при выполнении открывают отдельный
managed worker runtime. Поддерживаются:

- `worker:run` и `worker:status`;
- общий `worker:control`;
- aliases `worker:pause`, `worker:resume`, `worker:drain`, `worker:stop`,
  `worker:kill` и `worker:restart`.

`worker:schema:postgres` остаётся package utility для генерации reference SQL.
Geo Registry production schema применяет versioned application migrations и не
подменяет их runtime-генерацией схемы.

## Намеренно Deferred

В первый release не входят Data.Mos import/enrichment и импорт OSM aliases. Они
остаются отдельным E1 optional scope и требуют нового подтверждения до начала
implementation. Это не относится к Nominatim: его OSM dataset является внешней
reverse-geocode integration, а не переносом legacy OSM aliases в canonical GAR
model.

Также не входят multi-process/fork orchestration старого RoadRunner, outbox и
новые raw source-specific поля core contracts. Старые RoadRunner/BoltDB queues
должны быть drained либо явно списаны в release window; само наличие нового
PostgreSQL worker не закрывает этот gate.

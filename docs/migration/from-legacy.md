# Переход С Legacy `phpfiasgeocoder`

`geo-registry` сохраняет публичные address-сценарии legacy, но заменяет runtime,
очередь и data operations. Это migration с проверяемой semantic parity, а не
побайтовое продолжение старого deployment.

## Карта Замены

| Legacy | Geo Registry `v0.1.x` | Практическое следствие |
| --- | --- | --- |
| Spiral + RoadRunner | AMPHP HTTP server | один `bin/app`, non-blocking request handlers |
| RoadRunner/BoltDB jobs | PostgreSQL durable queue + worker package | старые queue-файлы не импортируются; их нужно drain/approve |
| единый legacy MessageBus v6 | `message-bus-async` + `message-bus-async-worker` | явный compiled registry и scopes |
| mutable full-data rebuild | checkpointed GAR import + partitioned shadow rebuild | partial active projection не видна online |
| `address_search.dump` как отдельный runtime artifact | application PostgreSQL logical backup | schema, canonical GAR, projection и queues переносятся согласованно |
| OpenSearch index/snapshot | rebuildable projection | reindex из PostgreSQL после restore |
| application-owned Nominatim calls | отдельный Nominatim lifecycle | отдельный PBF provenance и backup |
| `app.php` и разные console roots | `app/bin/app` | HTTP, migrations, GAR, OpenSearch и worker CLI в одном registry |

## Что Можно Переиспользовать

- утверждённые GAR XML, Data.Mos, OSM JSONL и Nominatim PBF при сохранённых
  provenance/checksum;
- HTTP representative values и legacy Postman collection как comparison
  evidence;
- domain identity GAR/FIAS и принятые semantic contracts;
- PostgreSQL данные только через отдельно доказанную restore/conversion
  процедуру.

## Что Нельзя Подключать Напрямую

- legacy `address_search.dump`: в нём нет явного `region_code`, необходимого
  target LIST partitions;
- BoltDB queue files: новый worker использует другую durable schema и lifecycle;
- legacy OpenSearch volume/index как target alias;
- Nominatim PostgreSQL volume одновременно к двум PostgreSQL processes;
- старые `.env`, secrets, RoadRunner config и deployment-specific image names.

Поддержанный первый data path — импорт canonical GAR XML, rebuild региональной
projection и затем optional enrichment. Альтернатива — проверенный logical
backup уже подготовленного target PostgreSQL, но не legacy dump без conversion.

## Команды С Похожим Именем, Но Новым Контрактом

`gar:releases`, `gar:import`, `gar:address-search:rebuild`, `gar:status`,
`gar:integrity`, Data.Mos/OSM import и `opensearch:reindex` существуют в обоих
проектах, однако их options и recovery semantics различаются. Не переносите
legacy shell-команды дословно; используйте `bin/app <command> --help` и
[актуальный data guide](../guides/data-lifecycle.md).

В target `gar:import` имеет fail-closed `--regions=50,77`, но не имеет
`--truncate` или `--insert-only`. Фильтр сохраняет общие root-справочники,
выбирает только числовые региональные каталоги и включается в
manifest/checkpoints. Target rebuild не принимает
legacy `--workers/--truncate`: он строит shadow table и переключает её только
после parity.

## Cutover

1. Оставить legacy доступным только для read comparison.
2. Подготовить target dataset и пройти GAR/OpenSearch/Nominatim parity.
3. Сохранить strict Postman artifacts и принятые semantic differences.
4. Повторно доказать пустые legacy queues и запретить новые публикации.
5. Сделать target data backup и restore rehearsal.
6. Переключить только application traffic; data services имеют отдельный
   lifecycle.
7. Сохранить legacy и предыдущие data targets до конца rollback window.

Точные gates находятся в [release parity](release-parity.md),
[worker cutover](worker-cutover.md) и
[backup/restore](../deployment/data-backup-restore.md). Полный инженерный журнал
решений остаётся в [Spiral to AMPHP roadmap](spiral-to-amphp.md), но не заменяет
эту operator-инструкцию.

Актуальное performance-сравнение на 30 exact/alias/fuzzy запросах находится в
[OpenSearch benchmark](opensearch-benchmark.md). Оно отдельно показывает
target OpenSearch path и legacy hot-cache path: cached legacy latency нельзя
интерпретировать как скорость его поискового backend-а.

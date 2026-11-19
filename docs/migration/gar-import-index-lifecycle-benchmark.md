# GAR Import: Secondary Index Lifecycle Benchmark

- Дата evidence: `2026-11-10`
- Ветка: `feature/gar-initial-load`
- Dataset: GAR `2026.09.01`, `v.288`, регион `50` и общие справочники
- Scope: `28` XML-файлов, `24 975 511 491` bytes source, `122 809 172` rows
- Решение: принять `--initial-load` для новой пустой offline database и
  продолжить эксперименты с direct COPY и bounded parse/write pipeline

Benchmark проверяет полный durable lifecycle: выгодно ли на пустой БД снять
только вторичные неуникальные индексы, выполнить существующий COPY/UPSERT import,
а затем восстановить индексы и статистику. В отличие от UNLOGGED-гипотезы,
candidate не ослабляет crash durability canonical tables.

## Условия

Оба прогона выполнены последовательно на одном host и storage в свежих базах с
одинаковыми `27` migrations:

- PostgreSQL `17.11`;
- `fsync=on`, `synchronous_commit=on`, `full_page_writes=on`;
- `max_wal_size=64GB`, `maintenance_work_mem=2GB`;
- `max_parallel_maintenance_workers=4`;
- `gar:import --regions=50 --batch-size=50000 --workers=4`;
- baseline сохранял все canonical indexes;
- candidate использовал `--initial-load`, снял `181` secondary index и сохранил
  PK, unique/exclusion, constraint-owned indexes и все FK constraints;
- два index workers не строили одновременно два индекса одной таблицы.

Перед каждым прогоном source pages удалялись через `POSIX_FADV_DONTNEED`.
`fincore` подтвердил `0` resident bytes из `24 975 511 491` bytes. Baseline БД
была удалена до создания candidate, чтобы не занимать диск одновременно.

## Результаты

| Этап | Baseline | `--initial-load` | Delta |
| --- | ---: | ---: | ---: |
| Prepare + raw import | `3651.120 s` | `2357.031 s` | `-35.44%` |
| Raw throughput | `33 636 rows/s` | `52 103 rows/s` | `+54.90%` |
| Index build | incremental внутри import | `349.908 s` | отдельная finalization |
| `ANALYZE` | не выполнялся importer-ом | `1.875 s` | отдельная finalization |
| Internal integrity | не выполнялся importer-ом | `0.002 s` | отдельная finalization |
| End-to-end до `ready` | `3651.120 s` (`60:51.1`) | `2708.870 s` (`45:08.9`) | `-25.81%` (`-15:42.3`) |
| End-to-end throughput | `33 636 rows/s` | `45 336 rows/s` | `+34.78%` |
| WAL delta | `172 626 765 328 B` | `46 679 319 864 B` | `-72.96%` |
| Database size | `35 359 086 259 B` | `32 918 582 963 B` | `-6.90%` |
| Canonical heap size | `14 215 913 472 B` | `14 216 118 272 B` | практически одинаково |
| Canonical index size | `21 119 574 016 B` | `18 678 538 240 B` | `-11.56%` |

Candidate finalization заняла `351.785 s` (`5:51.8`). Она вернула все
secondary indexes, выполнила `ANALYZE`, проверила lifecycle state и только после
этого перевела operation в `ready`. Даже с этой стоимостью полный путь остался
на `942.25 s` быстрее baseline.

WAL измерялся как cluster-wide LSN delta и может включать небольшой объём
фоновой активности других database. Разница достаточно велика, чтобы этот шум
не объяснял наблюдаемый эффект.

## Bottleneck Profile

На baseline во время `change_history` один 10-секундный срез показал:

- один активный PostgreSQL backend без wait event и около одного занятого CPU;
- PHP importer около `2.3%` CPU, то есть XML parser ждал БД;
- `250 000` source rows, но `500 000` inserted tuples: staging + canonical;
- около `17.2 MB/s` WAL, `5.4 MB/s` роста database;
- `8.59 млн` buffer hits, около `34` page touches на source row.

Причина недостижимости `100k rows/s` текущим write path двойная: incremental
index maintenance и whole-file scheduler. На больших dependency-фазах один XML
оставляет только один активный writer/backend. Candidate поднял raw throughput
до `52.1k rows/s`, но не устранил последовательный single-file tail.

## Проверка Корректности

Оба запуска завершили:

- `28/28` файлов;
- `122 809 172` processed и merged rows;
- одинаковый file/counter digest:
  `6c5d212b983cbcab57ae9ce322643bc6`.

После candidate:

- точный `count(*)` по 23 canonical tables: `122 809 172`;
- table-count digest: `d309fca782a378b32a6fb0dd4c0fad50`;
- canonical indexes: `206`, как в baseline;
- invalid/unready indexes: `0`;
- unvalidated constraints: `0`;
- `gar_import_index_snapshots` backlog: `0`;
- operation: `completed`, phase `ready`, indexes `181/181`.

Integration test дополнительно доказывает fail-closed empty-database gate,
восстановление index definitions двумя процессами и запрет обычного import при
незавершённом snapshot backlog.

## Положительные И Отрицательные Стороны

Положительные:

- end-to-end ускорение `25.81%`, заметно выше принятого `10%` gate;
- WAL меньше на `72.96%` без отказа от durability;
- заново построенные indexes компактнее на `11.56%`;
- phase/snapshot recovery закрывает сбой import, index build и analyze;
- обычный import не может обойти незавершённое восстановление индексов.

Ограничения:

- режим допустим только для новой пустой выделенной offline database;
- `--restart` намеренно запрещён: незавершённую operation нужно продолжить или
  пересоздать disposable database;
- finalization требует до `2 × maintenance_work_mem` для двух index workers;
- обычный `CREATE INDEX` блокирует writes, что приемлемо только до запуска app;
- whole-file scheduling и staging → UPSERT сохраняют CPU bottleneck;
- текущий `amphp/postgres 2.2.2` не поддерживает COPY, поэтому native
  `ext-pgsql` остаётся внутри child writer processes.

## Решение И Следующий Шаг

Первую ступень принять как initial-load path. Следующие гипотезы измерять
поочерёдно относительно этого candidate:

1. direct COPY в canonical tables без temporary staging и `ON CONFLICT`;
2. bounded PHP parser/writer pipeline через `amphp/parallel` и IPC backpressure;
3. только затем отдельный A/B отложенной FK validation;
4. объединить лишь варианты, прошедшие end-to-end и recovery gates.

Полная лестница и scorecard: [план оптимизации GAR import](gar-import-optimization-plan.md).

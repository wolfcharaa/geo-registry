# GAR Import: Direct COPY Benchmark

- Дата evidence: `2026-11-11`
- Ветка: `feature/gar-direct-copy`
- Основа candidate: `5d55e70` (`--initial-load` с lifecycle вторичных индексов)
- Dataset: GAR `2026.09.01`, `v.288`, регион `50` и общие справочники
- Scope: `28` XML-файлов, `24 975 511 491` bytes source,
  `122 809 172` rows
- Решение: принять `--direct-copy` как opt-in ветку `--initial-load` для новой
  пустой offline database; следующий bottleneck проверять через bounded
  parse/write fan-out одного большого XML

Эксперимент изолирует стоимость temporary staging и
`INSERT ... ON CONFLICT`: candidate пишет каждый batch native `COPY` сразу в
canonical table и фиксирует checkpoint той же транзакцией. Lifecycle вторичных
индексов, все ограничения и durability settings не менялись.

## Условия

Candidate выполнен на том же host, storage и source, что и принятый
secondary-index baseline:

- PostgreSQL `17.11`;
- `fsync=on`, `synchronous_commit=on`, `full_page_writes=on`;
- `max_wal_size=64GB`, `maintenance_work_mem=2GB`;
- `max_parallel_maintenance_workers=4`;
- `gar:import --regions=50 --batch-size=50000 --workers=4 --initial-load`;
- baseline использовал staging `COPY` и canonical UPSERT;
- candidate дополнительно использовал `--direct-copy`;
- обе ветки снимали `181` вторичный индекс и сохраняли PK,
  unique/exclusion, constraint-owned indexes, CHECK и FK;
- source pages перед candidate удалены через `POSIX_FADV_DONTNEED`, а `fincore`
  подтвердил `0` resident bytes из `24 975 511 491` bytes;
- candidate стартовал в отдельной свежей БД с пустыми 23 canonical tables.

Candidate содержал `28` migrations против `27` у baseline: дополнительная
migration добавляет только `gar_import_operations.write_mode` и не меняет
canonical schema или write indexes.

## Результаты

| Этап | `--initial-load` merge baseline | `--direct-copy` | Delta |
| --- | ---: | ---: | ---: |
| Prepare + raw import | `2357.031 s` | `1852.535 s` | `-21.40%` (`-8:24.496`) |
| Raw throughput | `52 103 rows/s` | `66 292 rows/s` | `+27.23%` |
| Source throughput | `10.596 MB/s` | `13.482 MB/s` | `+27.23%` |
| Index build | `349.908 s` | `353.008 s` | `+0.89%` |
| `ANALYZE` | `1.875 s` | `1.931 s` | `+2.99%` |
| Internal integrity | `0.002 s` | `0.003 s` | `+0.001 s` |
| End-to-end до `ready` | `2708.870 s` (`45:08.9`) | `2207.477 s` (`36:47.5`) | `-18.51%` (`-8:21.393`) |
| End-to-end throughput | `45 336 rows/s` | `55 633 rows/s` | `+22.71%` |
| WAL delta | `46 679 319 864 B` | `33 254 503 288 B` | `-28.76%` |
| Database size | `32 918 582 963 B` | `32 922 154 675 B` | `+0.011%` |
| Canonical heap size | `14 216 118 272 B` | `14 222 884 864 B` | `+0.048%` |
| Canonical index size | `18 678 538 240 B` | `18 675 400 704 B` | `-0.017%` |

Candidate finalization заняла `354.942 s`; она практически совпала с baseline.
Весь end-to-end выигрыш получен в raw import, поэтому результат объясняется
удалением staging tuple write, conflict lookup и merge executor, а не более
быстрой сборкой индексов.

Source throughput — объём выбранных XML, делённый на raw duration; это не
physical storage write rate. WAL измерялся как cluster-wide LSN delta и может
включать небольшой фон других database. Средний WAL rate по полному candidate
составил `15.064 MB/s`.

## Bottleneck Profile

Direct COPY улучшил средний raw throughput с `52.1k` до `66.3k rows/s`, но не
довёл весь прогон до `100k rows/s`:

- в фазах с тремя-четырьмя независимыми XML/writer процессы кратковременно
  превышали `100k rows/s`;
- на хвосте одного большого XML оставался один PostgreSQL backend примерно на
  одном CPU core; наблюдалось около `36–50k rows/s`;
- в одном четырёхписательном срезе PostgreSQL использовал около `286%` CPU,
  importer — `42%`, RSS importer был около `113 MiB`;
- тот же срез показывал около `14 MB/s` physical I/O и `4.4%` NVMe utilization;
  wait events отсутствовали;
- на single-file хвосте backend использовал около `101%` CPU, а importer почти
  всё время ожидал завершения database write.

Следовательно, средний предел всё ещё задаёт whole-file scheduler: крупный XML
нельзя распределить между имеющимися writer-процессами. Storage не был насыщен,
а увеличение `--workers` само по себе не создаст дополнительные работы внутри
одного файла.

## Проверка Корректности И Recovery

Candidate завершил:

- `28/28` файлов;
- `122 809 172` processed и merged rows;
- file/counter digest `6c5d212b983cbcab57ae9ce322643bc6`, как baseline;
- точный `count(*)` по 23 canonical tables: `122 809 172`;
- table-count digest `d309fca782a378b32a6fb0dd4c0fad50`, как baseline;
- canonical indexes: `206`;
- invalid/unready indexes: `0`;
- unvalidated constraints: `0`;
- dangling `address_objects.addr_type_id`: `0`;
- incomplete files и index snapshot backlog: `0`;
- operation: `completed`, `write_mode=direct-copy`, phase `ready`, indexes
  `181/181`.

Сохранённые PK/unique indexes доказывают отсутствие duplicate identity в
выбранном manifest: direct COPY не мог бы завершиться при конфликте. Integration
test дополнительно прерывает malformed XML после одного committed batch и
проверяет, что повтор использует ту же operation, увеличивает attempt, пропускает
ровно сохранённое число XML rows и не создаёт вторую canonical строку.

`write_mode` входит в durable identity operation. Повтор нельзя незаметно
переключить между merge и direct COPY. Для `address_objects` PHP mapper заранее
разрешает `addr_type_id` тем же правилом минимального id; неизвестная пара
level/type отклоняется до COPY.

## Положительные И Отрицательные Стороны

Положительные:

- end-to-end ускорение `18.51%` и `8:21.393` проходит gates `>=10%` и `>=5 min`;
- raw import быстрее на `21.40%`, throughput выше на `27.23%`;
- WAL меньше на `28.76%` без `UNLOGGED` и без ослабления durability;
- batch write и checkpoint остаются одной транзакцией;
- restart/resume state и final index lifecycle остаются наблюдаемыми;
- обычный staging/UPSERT import сохранён для непустой БД.

Ограничения:

- режим допустим только вместе с `--initial-load` на новой пустой выделенной БД;
- duplicate или FK violation теперь fail-closed останавливает batch вместо
  update через `ON CONFLICT`;
- отдельный PHP mapper должен существовать для descriptor, где canonical columns
  вычисляются из справочника;
- средний raw throughput всё ещё `66.3k`, потому что один большой XML занимает
  только один writer;
- candidate не делает full dataset/один XML resident в PHP: это нарушило бы
  memory budget и не дало бы durable backpressure.

## Решение И Следующий Шаг

Direct COPY принять как opt-in initial-load write mode. Delta находится выше
диапазона `5–15%`, для которого план требует обратный повтор, и одновременно
проходит абсолютный gate пяти минут. Перед production release остаётся полный
dataset gate, но region-50 гипотеза закрыта положительно.

Следующий изолированный эксперимент:

1. оставить direct COPY и все constraints без изменений;
2. разделить один большой XML на нумерованные encoded batches;
3. один parser передаёт bounded окно batches через `amphp/parallel`/IPC двум,
   затем четырём writer-процессам с отдельными PostgreSQL connections;
4. coordinator продвигает только contiguous committed checkpoint;
5. измерить `1/2/4` writers, in-flight bytes, CPU, I/O и recovery на interruption;
6. только если PostgreSQL после fan-out останется CPU-bound, отдельно тестировать
   drop/`NOT VALID`/`VALIDATE` lifecycle foreign keys.

После сбора evidence временные container и database удалены. Именованный Docker
volume для candidate не создавался; свободное место `/srv` после очистки — около
`278 GiB`.

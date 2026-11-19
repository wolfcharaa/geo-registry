# Benchmark Deferred Foreign Keys Для Initial GAR Import

- Статус: `accept` как opt-in `--defer-foreign-keys` для direct initial-load;
  W4+LPT принят, persistent FK process/connection reuse отклонён q86
- Дата: `2026-11-14`
- Цель: перенести referential-integrity checks из горячего COPY path в
  наблюдаемую bounded validation phase без ослабления готовой database

Эксперимент меняет только lifecycle canonical foreign keys. PK, unique, CHECK,
`NOT NULL`, secondary-index lifecycle, pipeline target, process budget,
PostgreSQL tuning и durability между прогонами не менялись.

## Условия

Оба прогона выполнены последовательно на одном host, каждый в новой пустой
database с anonymous disposable storage:

- GAR `2026-09-01 v.288`, регион `50` плюс общие справочники;
- manifest `6ac1fa709834d629852939a2f6576a6db5d8ae1de47fd1438fc1e24b82953229`;
- `28` XML, `24 975 511 491` source bytes, `122 809 172` rows;
- target
  `50/AS_HOUSES_PARAMS_20260831_dc096bf8-6bce-4d35-8158-61fe7bdf77e1.XML`;
- `--regions=50 --batch-size=50000 --workers=4 --initial-load --direct-copy`;
- один и тот же target с `--pipeline-writers=4`;
- candidate добавляет только `--defer-foreign-keys`;
- PostgreSQL `17.11`, `max_wal_size=64GB`, `maintenance_work_mem=2GB`,
  `max_parallel_maintenance_workers=4`;
- `fsync=on`, `synchronous_commit=on`, `full_page_writes=on`;
- перед каждым запуском `POSIX_FADV_DONTNEED` и `fincore` подтвердили
  `0 / 24 975 511 491` resident source bytes;
- application revision: HEAD `dc52076`, application diff SHA-256
  `b2de7e8f63e0d3491f4179f31e5b0e4ed76c5247ece6564df833a13e051e430c`.

Baseline использует принятый W4 с активными FK. Candidate snapshot-ит и снимает
только `34` FK на `11` imported tables, после index build восстанавливает их как
`NOT VALID` и валидирует двумя процессами. Два ограничения одной таблицы не
проверяются одновременно. Acceptance gate: end-to-end быстрее минимум на `10%`
и пять минут при exact parity; delta `5–15%` требует reverse-order repeat.

## Результат

| Метрика | W4, active FK | W4, deferred FK | Delta |
| --- | ---: | ---: | ---: |
| Raw import | `1487.163852 s` | `527.337663 s` | `-64.541%` (`-15:59.826`) |
| Raw throughput | `82 579 rows/s` / `16.794 MB/s` | `232 885 rows/s` / `47.362 MB/s` | выше |
| Houses target | `396.587349 s` | `108.985249 s` | `-72.519%` (`-4:47.602`) |
| Houses target throughput | `73 953 rows/s` | `269 110 rows/s` | выше |
| Index build | `361.796605 s` | `354.755386 s` | `-1.946%`, diagnostic |
| FK restore | — | `0.001479 s` | diagnostic |
| FK validation | active during COPY | `35.859536 s` | deferred cost |
| `ANALYZE` | `1.928544 s` | `1.978331 s` | diagnostic |
| Internal integrity | `0.004108 s` | `0.002835 s` | diagnostic |
| End-to-end до `ready` | `1850.893109 s` | `919.935230 s` | `-50.298%` (`-15:30.958`) |
| End-to-end throughput | `66 351 rows/s` | `133 498 rows/s` | выше |
| WAL, LSN delta | `33 175 239 880 B` | `29 704 555 072 B` | `-10.462%` |
| Database size | `32 927 332 019 B` | `32 928 986 803 B` | `+0.0050%` |

Candidate проходит относительный и абсолютный gates с большим запасом. Delta
выше `15%`, поэтому reverse-order repeat по принятому правилу не требуется.
Основной выигрыш действительно относится к referential checks: index build и
`ANALYZE` практически не изменились, а полная restore/validation цена составила
только `35.861 s`.

`object_params` содержит `49 147 843` строк и три FK. Перенос этих проверок из
каждого insert в bulk scans объясняет одновременно ускорение всего raw import и
houses target. Это измеренное объяснение для данного schema/workload, а не
универсальная гарантия для произвольной database.

## WAL, Размер И Ресурсы

Candidate создал на `3 470 684 808 B` меньше WAL по LSN. Размер готовой database
различается на `0.0050%`; canonical heap уменьшился на `0.0048%`, indexes выросли
на `0.0118%`. Эти малые различия не являются отдельным performance effect.

| Process resource, avg / p95 / max | Active FK | Deferred FK |
| --- | ---: | ---: |
| PostgreSQL CPU | `187.26 / 397.73 / 819.05%` | `136.72 / 459.82 / 605.89%` |
| PostgreSQL memory | `6619 / 11192 / 13568 MiB` | `8279 / 13773 / 16026 MiB` |
| Importer CPU | `29.70 / 117.85 / 142.59%` | `56.03 / 214.87 / 296.55%` |
| Importer memory | `104.82 / 196.00 / 220.70 MiB` | `110.42 / 202.60 / 222.50 MiB` |

У candidate выше importer CPU: после снятия FK PostgreSQL быстрее принимает
batches, поэтому parser/writers меньше ждут. PostgreSQL peak memory остаётся
ниже `16 GiB`; вместе с `8 GiB shared_buffers` и двумя bounded finalization
workers прогон уложился в host budget.

## Physical I/O

Timestamped `iostat` собирался для logical `/srv` device и physical `nvme0n1`.
Для решения используется physical device, а не cumulative Docker BlockIO.

| Physical NVMe p95 | Active FK | Deferred FK |
| --- | ---: | ---: |
| Full-run utilization | `29.92%` | `35.28%` |
| Full-run write await | `4.01 ms` | `5.29 ms` |
| Full-run queue depth | `5.76` | `7.51` |
| Target utilization | `26.48%` | `21.84%` |
| Target write await | `3.67 ms` | `2.30 ms` |
| Target queue depth | `2.91` | `3.83` |

FK validation длилась `35.86 s`, поэтому её окно содержит только семь
пятисекундных samples: p95 utilization `47.68%`, write await `7.60 ms`, queue
`6.18`. Это диагностический короткий burst, а не evidence sustained saturation.
На полном пути и target physical utilization остаётся далёкой от насыщения;
candidate ускоряет target на `72.52%` при меньшем target p95 utilization.

Wait sampler в обоих прогонах преимущественно видел CPU-active backends.
Отдельные `DataFileRead`, `WalSync`, `WalInit*`, `BuffileWrite` и short lock
events не образовали доминирующего wait profile.

## q86: Проверка Persistent FK Workers

После принятия W4+LPT q86 отдельно проверил цену запуска `34` CLI-процессов и
соединений. Candidate переиспользовал четыре Amp context worker и по одному
ext-pgsql connection на процесс; ordering, запрет параллельных validation одной
referencing table, durable snapshots и recovery semantics не менялись.

| Run | Executor | FK validation | E2E |
| --- | --- | ---: | ---: |
| q86a | persistent | `28.129849 s` | `225.136601 s` |
| q86b | process control | `32.056584 s` | `239.456712 s` |
| q86c | reverse process control | `28.836726 s` | `245.062584 s` |
| q86d | persistent repeat | `28.715524 s` | `243.764494 s` |

Первая пара дала FK/E2E `-12.249% / -5.980%`, но reverse-пара только
`-0.420% / -0.530%`. Формальный mean gain (`6.648% / 3.223%`) не принимается,
потому что обязательный соседний reverse gate `2%` провален; первый E2E delta
также включал более удачный raw/index schedule. Во второй паре разница самой FK
фазы была лишь `0.121 s`.

Все четыре full runs сохранили exact `122 809 172` rows, data/file/FK digests,
`102` valid indexes, `34/34` validated FK и нулевые backlogs. Нового swap-out
не было. q86 отклонён именно по производительности: persistent task/option и
tests удалены, process-per-FK W4/LPT остаётся production baseline. Значит
framework/connection bootstrap не объясняет server-side FK validation floor;
следующая FK-гипотеза должна сокращать саму validation work, а не process
lifetime.

## Correctness И Recovery

Оба прогона завершили:

- operation `completed`, phase `ready`, attempt `1`;
- `28/28` files и `122 809 172` processed/merged rows;
- file digest `6c5d212b983cbcab57ae9ce322643bc6`;
- exact table rows/digest
  `122 809 172 / d309fca782a378b32a6fb0dd4c0fad50`;
- `206` canonical indexes, invalid/unready `0`;
- `34` canonical FK, unvalidated `0`, definition digest
  `21e41661a1e2fd637b80356a2ed28d7e`;
- pipeline `587` batches / `2 363 492 345 B`, peak `4` /
  `16 137 618 B`;
- range ledger, index snapshot, FK snapshot, incomplete files и dangling
  `address_objects.addr_type_id`: `0/0/0/0/0`.

Real-process integration tests независимо проверяют два новых recovery cases:

- SIGTERM supervisor во время import оставляет operation в `import`, durable
  definitions всех снятых FK и ноль установленных canonical FK; совместимый
  повтор завершает тот же operation и восстанавливает constraints;
- SIGTERM validation worker оставляет durable `validating`; повтор принимает и
  rollback statement, и validation, успевшую завершиться server-side, затем
  проверяет exact definition/`convalidated` и только после этого разрешает
  `ready`.

## Решение И Ограничения

`--defer-foreign-keys` принят как явный opt-in для новой пустой выделенной
database вместе с `--initial-load --direct-copy`. Для измеренного region-50 W4
он уменьшил end-to-end вдвое без расхождения целостности или durability.

Ограничения решения:

- режим запрещён для standard/incremental import и непустой database;
- FK отсутствуют во время raw import, поэтому application traffic запрещён до
  `phase=ready`;
- FK flag входит в durable operation identity; незавершённый lifecycle нельзя
  продолжить без option или обойти обычным import;
- accepted count `34` относится к этой schema revision; runtime snapshot
  каталогизирует фактический набор, а не полагается на константу;
- два validation workers и измеренный memory/I/O profile остаются
  host-dependent; на другом schema или storage нужен собственный A/B;
- post-import `address_search` rebuild и release `gar:integrity` по-прежнему
  обязательны.

Следующая оптимизация не должна смешивать изменение batch, global workers,
второй pipeline XML или multi-parser с этим принятым результатом.

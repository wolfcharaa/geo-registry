# Benchmark Bounded Pipeline Для Одного GAR XML

- Статус: `accept` как opt-in для direct initial-load; матрица выбрала `4`
  pipeline writers, канонический парный A/B с physical iostat подтвердил gate
- Дата: `2026-11-11`
- Цель: убрать single-file tail принятого direct-COPY import, сохранив global
  worker budget, ограниченное окно batches, точный resume и durability

Эксперимент добавляет fan-out только для одного явно выбранного
object-parameters XML. Остальные файлы, lifecycle вторичных индексов,
ограничения PostgreSQL и post-import gates не менялись.

## Условия

Все варианты выполнены на одном host и storage, каждый — в свежей пустой
database:

- GAR `2026-09-01 v.288`, регион `50` плюс общие справочники;
- `28` XML, `24 975 511 491` source bytes, `122 809 172` summed
  source/canonical rows across `23` table families;
- target:
  `50/AS_HOUSES_PARAMS_20260831_dc096bf8-6bce-4d35-8158-61fe7bdf77e1.XML`;
- PostgreSQL `17.11`;
- `fsync=on`, `synchronous_commit=on`, `full_page_writes=on`;
- `max_wal_size=64GB`, `maintenance_work_mem=2GB`;
- `max_parallel_maintenance_workers=4`;
- `--regions=50 --batch-size=50000 --workers=4 --initial-load
  --direct-copy`;
- candidate дополнительно задавал target через `--pipeline-file` и менял только
  `--pipeline-writers=1`, `2`, `4`;
- перед каждым запуском source pages удалялись через `POSIX_FADV_DONTNEED`, а
  `fincore` подтверждал `0` resident bytes из `24 975 511 491`;
- durability не ослаблялась, named Docker volume для временной database не
  создавался.

Baseline матрицы — ранее принятый direct-COPY прогон без single-file pipeline:
`1852.535 s` raw и `2207.477 s` до durable `ready`. После выбора W4 выполнен
новый фиксированный парный direct/W4 A/B на двух свежих database с теми же
durability settings, cold source cache и timestamped physical iostat.
Acceptance gate: end-to-end быстрее минимум на `10%` и минимум на пять минут
при полном совпадении correctness evidence.

## Реализация

Pipeline сохраняет whole-document parser, но отделяет parse от batch write:

1. Supervisor последовательно читает target одним `XMLReader`, кодирует
   manifest rows и формирует contiguous batches.
2. `ContextWorkerPool` передаёт serializable batch tasks отдельным PHP
   процессам. Каждый process лениво открывает и затем переиспользует собственное
   `ext-pgsql` connection.
3. Ordinary file workers и pipeline batch writers делят один `--workers`
   budget. Capacity равна свободным global slots, но не превышает
   `--pipeline-writers`; она пересчитывается после завершения соседних XML.
4. Writer одной транзакцией выполняет canonical COPY, проверяет running
   operation/target, отклоняет overlap, записывает committed row range,
   складывает доступный contiguous prefix в file checkpoint и обновляет точные
   counters.
5. `gar_import_file_batches` хранит committed ranges впереди checkpoint. Resume
   пропускает и contiguous prefix, и эти ranges; после folding они удаляются.
6. При первой ошибке новые tasks не принимаются. Supervisor ограничивает cleanup
   пятью секундами, затем убивает pool; незакоммиченный COPY откатывается вместе
   с транзакцией.

Pipeline target входит в durable identity operation. Writer limit и batch size
можно изменить при resume, потому что identity строк и overlap защищены ledger,
а не размером предыдущего batch.

## Канонический Парный A/B С Physical iostat

Парный прогон повторяет один и тот же manifest сначала direct COPY без pipeline,
затем W4. Перед каждым запуском `fincore` подтвердил нулевую резидентность всех
`28` source XML. Database создавалась заново; PostgreSQL `17.11`, schema,
настройки WAL/durability, batch `50000`, global workers `4` и index lifecycle
совпадали.

| Метрика | Direct COPY | Pipeline W4 | Delta |
| --- | ---: | ---: | ---: |
| Raw import | `2027.231553 s` | `1541.173447 s` | `-23.976%` (`-8:06.058`) |
| Raw throughput | `60 580 rows/s` / `12.320 MB/s` | `79 685 rows/s` / `16.206 MB/s` | выше |
| Target XML | `890.746525 s` | `423.173943 s` | `-52.492%` (`-7:47.573`) |
| Target throughput | `32 926 rows/s` | `69 307 rows/s` | выше |
| Index build | `392.651148 s` | `382.598536 s` | diagnostic |
| `ANALYZE` | `2.036617 s` | `2.016302 s` | diagnostic |
| Internal integrity | `0.002048 s` | `0.002047 s` | diagnostic |
| End-to-end до `ready` | `2421.921366 s` | `1925.790332 s` | `-20.485%` (`-8:16.131`) |
| End-to-end throughput | `50 707 rows/s` | `63 771 rows/s` | выше |
| WAL, LSN delta | `33 848 028 640 B` | `33 173 172 904 B` | `-1.994%` |
| Database size | `32 926 250 675 B` | `32 926 430 899 B` | `+0.000547%` |

Target содержит `29 329 019` rows. W4 выполнил `587` batches, передал
`2 363 492 345` encoded bytes и достиг bounded peak `4` batches /
`16 142 491 B`; к завершению pending ranges/rows были равны нулю.

Timestamped iostat собирался отдельно для полного прогона и окна target на
physical NVMe device, а не по cumulative container `BlockIO`:

| p95 physical I/O | Direct COPY | Pipeline W4 |
| --- | ---: | ---: |
| Full run device utilization | `19.44%` | `16.80%` |
| Full run write await | `3.25 ms` | `4.59 ms` |
| Full run queue depth | `4.38` | `5.65` |
| Target device utilization | `18.24%` | `13.60%` |
| Target write await | `4.04 ms` | `4.28 ms` |
| Target queue depth | `1.90` | `2.35` |

Physical device не был насыщен ни на полном пути, ни в target window. Поэтому
результат не доказывает storage-bound режим; рост PostgreSQL/importer CPU при W4
и ускорение target поддерживают гипотезу о compute, constraint/WAL и file
scheduling limit, но следующий bottleneck требует отдельного профиля.

| Process resource | Direct COPY | Pipeline W4 |
| --- | ---: | ---: |
| PostgreSQL CPU avg / p95 / max | `131.98 / 323.52 / 785.47%` | `182.74 / 397.92 / 863.01%` |
| Importer CPU avg / p95 / max | `22.68 / 77.81 / 146.34%` | `29.29 / 119.09 / 148.79%` |
| PostgreSQL memory avg / p95 / max | `6543.76 / 11417.60 / 15749.12 MiB` | `6629.17 / 12185.60 / 13916.16 MiB` |
| Importer memory avg / p95 / max | `73.01 / 124.80 / 146.90 MiB` | `112.27 / 210.80 / 231.00 MiB` |

Delta `-20.485%` находится вне диапазона `5-15%`, поэтому обратный повтор по
правилу scorecard не требовался.

## Результаты Матрицы

| Вариант | Raw import | Raw rows/s | Target XML | Target rows/s | Finalization | End-to-end | Delta к baseline |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Direct COPY baseline | `1852.535 s` | `66 292` | — | — | `354.942 s` | `2207.477 s` | — |
| Pipeline, `1` writer | `1910.588 s` | `64 278` | `834.364 s` | `35 167` | `367.775 s` | `2278.364 s` | `+3.21%` |
| Pipeline, `2` writers | `1545.809 s` | `79 447` | `466.676 s` | `62 847` | `357.391 s` | `1903.199 s` | `-13.78%` (`-5:04.278`) |
| Pipeline, `4` writers | `1425.304 s` | `86 163` | `372.640 s` | `78 706` | `358.410 s` | `1783.740 s` | `-19.20%` (`-7:03.737`) |

Finalization — сумма index build, `ANALYZE` и internal integrity. У лучшего
варианта это `356.578 + 1.830 + 0.002 s`; она соответствует baseline, поэтому
выигрыш относится к raw import, а не к более быстрому post-processing.

Четыре writers дополнительно улучшили end-to-end на `119.459 s` (`6.28%`)
относительно двух. Target ускорился ещё на `94.036 s` (`20.15%`). В реальном
W4-прогоне target сначала получил один slot рядом с тремя ordinary file workers,
затем два и после их завершения четыре. Наблюдавшийся peak ни разу не превысил
глобальные четыре process slots.

Один writer медленнее прежнего direct-COPY path: IPC serialization, worker task
и range ledger добавляют цену, но не создают parallelism. Поэтому pipeline
остаётся явным opt-in, а не скрытой заменой каждого direct-COPY файла.

## Ресурсы И Write Amplification

| Метрика | `1` writer | `2` writers | `4` writers |
| --- | ---: | ---: | ---: |
| Pipeline batches | `587` | `587` | `587` |
| Encoded batch bytes | `2 363 492 345` | `2 363 492 345` | `2 363 492 345` |
| Peak in-flight batches | `1` | `2` | `4` |
| Peak in-flight encoded bytes | `4 056 841 B` | `8 085 451 B` | `16 137 618 B` |
| WAL delta | `34 744 080 832 B` | `33 163 756 920 B` | `33 168 934 848 B` |
| Database size | `32 927 143 603 B` | `32 922 457 779 B` | `32 922 531 507 B` |
| Canonical heap | `14 223 228 928 B` | `14 223 015 936 B` | `14 222 999 552 B` |
| Canonical indexes | `18 676 719 616 B` | `18 675 539 968 B` | `18 675 646 464 B` |

Peak encoded bytes — durable operation metric отправленных, но ещё не собранных
batches. Это не полный RSS: PHP arrays, serialization buffers, XMLReader и
PostgreSQL имеют собственный overhead. Метрика доказывает bounded encoded
window, но не заменяет host-level memory monitoring.

W4 создал `33 168 934 848 B` WAL, на `0.26%` меньше direct-COPY baseline
`33 254 503 288 B`. WAL измерялся как cluster-wide LSN delta и может включать
небольшой фон других database; разница не рассматривается как отдельный выигрыш.
Размеры database/heap/index совпадают в пределах `0.002%`.

## Проверка Корректности И Recovery

Все три pipeline-прогона завершили:

- operation `completed`, phase `ready`, indexes `181/181`;
- `28/28` файлов, `122 809 172` processed и merged rows;
- file/counter digest `6c5d212b983cbcab57ae9ce322643bc6`;
- точный `count(*)` по 23 canonical tables: `122 809 172`;
- table-count digest `d309fca782a378b32a6fb0dd4c0fad50`;
- canonical indexes `206`, invalid/unready `0`;
- unvalidated constraints `0`;
- `gar_import_file_batches` backlog `0`;
- index snapshot backlog `0`;
- dangling `address_objects.addr_type_id` `0`.

Оба digest совпали с принятым direct-COPY baseline. W4 выполнил `587` batches и
записал ровно `2 363 492 345` encoded bytes, как W1/W2.

Канонический парный повтор воспроизвёл те же digest, точные per-table counts,
`206` canonical indexes без invalid/unready, нулевые unvalidated constraints,
range/snapshot/dangling backlogs и database size в пределах `0.001%`. Поэтому
новый speed gate не опирается на ослабленную проверку или иной набор данных.

Integration tests отдельно проверяют:

- полный pipeline на малом manifest и пустой ledger после completion;
- out-of-order commit: range `2..2` не продвигает checkpoint, затем `1..1`
  складывает его до `2`;
- overlap отклоняется, а выполненный перед проверкой COPY откатывается;
- один process переиспользует PostgreSQL connection между tasks;
- после остановки pool не остаётся orphan connection;
- SIGTERM supervisor и завершение PostgreSQL backend оставляют attempt 1 в
  `failed`, после чего resume с batch `10 → 7` и writers `2 → 3` завершает тот
  же operation без duplicate/skip и без orphan writer;
- SIGKILL одного child writer восстанавливается pool-ом и позволяет attempt 1
  завершиться без ручного resume.

Unit tests покрывают folding contiguous ranges, gaps/overlaps, global capacity,
выделение target из dependency-фазы и deferred sibling files при
`--workers=1`.

## Решение И Ограничения

Матрица выбрала `--pipeline-writers=4`, а канонический парный region-50 A/B
подтвердил решение: относительный gate `20.485%`, абсолютный gate `8:16.131` и
все correctness/recovery gates пройдены. Исторический W2 также проходил
минимальные пороги, но уступил W4 по raw, target и end-to-end; его результат не
подменяет свежий direct/W4 acceptance run.

Ограничения принятого решения:

- только новая пустая выделенная database с `--initial-load --direct-copy`;
- только один явно выбранный object-parameters XML на operation;
- имя должно точно совпадать с manifest-relative path выбранного release;
- writer count зависит от CPU, storage и состава dependency-фазы, поэтому `4`
  не становится default для неизвестного host;
- parser остаётся один; безопасного multi-parser byte seek без XML boundary
  index нет;
- pipeline не изменяет обязательные post-import `address_search` rebuild и
  release integrity checks.

Следующая ступень — не автоматическое увеличение writers. Новый профиль W4
показал CPU-active backends почти во всех target samples при низком physical
device pressure, поэтому opt-in lifecycle FK через `NOT VALID`/`VALIDATE` уже
реализован и прошёл correctness/recovery gates. Отдельный свежий W4/W4+FK A/B
затем сократил end-to-end `1850.893 → 919.935 s` (`-50.30%`) и принял его как
opt-in: [benchmark deferred FK](gar-import-foreign-key-lifecycle-benchmark.md).
Для неизвестного host/schema он не становится default.
После сбора evidence временные benchmark database/container удаляются, source
release не изменяется.

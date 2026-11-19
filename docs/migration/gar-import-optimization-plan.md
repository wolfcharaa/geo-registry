# План Оптимизации Initial GAR Import

- Статус: рабочий план; A/B `secondary indexes lifecycle`, `direct COPY` и
  `bounded single-file pipeline` приняты; deferred FK lifecycle также принят
  отдельным region-50 A/B как opt-in; resource series приняла четыре index
  workers, largest-heap-first scheduling и удаление неиспользуемых raw-value и
  hierarchy path/canonical trigram GIN, redundant unit lifecycle и detached
  version-link B-tree, standalone object-parameter lifecycle B-tree и четыре
  неиспользуемых `reestr_objects` metadata B-tree;
  process-isolated table-ready index overlap с одним worker принят, а его
  admission на measured host отложен до `50%` завершённых source bytes;
  EventLoop COPY, два ранних index workers, global W8 и Amp `PosixSemaphore`
  для межпроцессного admission отклонены
- Дата: `2026-11-14`
- Цель: последовательно перенести предел initial import с одного CPU core на
  контролируемую пропускную способность storage/WAL, не потеряв resume и
  финальную целостность

План относится только к новой пустой выделенной PostgreSQL database. Он не
изменяет update/import поверх существующих canonical данных и не разрешает
ослаблять durability готовой базы.

## Наблюдаемый Предел

В baseline региона 50 одна длинная dependency-фаза выполняла один
`INSERT ... SELECT ... ON CONFLICT` в `change_history`. Из четырёх XML workers
активным оставался один PostgreSQL backend; он использовал около одного CPU
core без wait event, тогда как PHP importer в основном ждал БД.

10-секундный срез дал:

- `25 000` source rows/s;
- две tuple-записи на source row: temporary COPY и canonical UPSERT;
- около `17.2 MB/s` WAL и `5.4 MB/s` роста database;
- около `34` buffer-page touches на source row.

Поэтому рост только `--workers` не устраняет bottleneck: планировщик назначает
целые XML-файлы, а одна большая фаза не создаёт достаточно независимых работ.
Асинхронный supervisor также не сделает один SQL statement параллельным.

## Порядок Экспериментов

Каждая ступень сравнивается с предыдущим подтверждённым вариантом на одном
dataset региона 50, свежей БД и холодном source cache. Эффекты не объединяются,
пока не измерены отдельно.

### 1. Lifecycle Вторичных Индексов

Текущий эксперимент:

- сохранить PK, unique/exclusion и constraint-owned indexes;
- перед import снять остальные secondary indexes;
- после import восстановить их двумя bounded workers без одновременной сборки
  двух индексов одной таблицы;
- выполнить `ANALYZE`, index/checkpoint integrity gate и перейти в `ready`.

Эта ступень показывает верхнюю границу выигрыша только от index maintenance.

### 2. Прямой Fresh COPY

Реализован отдельный путь, который разрешён только после empty-database gate:

- писать batches непосредственно в canonical table;
- не создавать temporary staging table и не выполнять `ON CONFLICT`;
- фиксировать COPY и file checkpoint одной транзакцией;
- сохранить resume с точного числа committed XML rows;
- сохранять write mode в operation и fail-closed отклонять несовместимый resume;
- сохранять PK/unique/FK/CHECK, поэтому duplicate identity или нарушенная ссылка
  останавливают batch до продвижения checkpoint.

Это устраняет вторую tuple-запись, conflict lookup и merge executor. Обычный
возобновляемый UPSERT остаётся fallback для непустой БД.

Region-50 A/B принят: end-to-end `2708.870 → 2207.477 s` (`-18.51%`),
throughput `45 336 → 55 633 rows/s` (`+22.71%`), WAL
`46 679 319 864 → 33 254 503 288 B` (`-28.76%`). Оба digest и все integrity
gates совпали с baseline.

### 3. Bounded Parse/Write Pipeline Для Больших XML

Реализован opt-in pipeline для одного manifest-relative object-parameters XML:

1. Supervisor одним `XMLReader` последовательно формирует нумерованные encoded
   batches; полный XML или dataset в памяти не накапливается.
2. `amphp/parallel` передаёт serializable batch payload процессам writer pool;
   каждый процесс открывает и переиспользует собственное PostgreSQL-соединение.
3. Обычные file workers и batch writers делят глобальный `--workers` budget.
   Доступная ёмкость pipeline растёт по мере завершения соседних XML, но не выше
   `--pipeline-writers`.
4. Writer одной транзакцией выполняет direct COPY, записывает непересекающийся
   committed range, складывает непрерывный префикс в file checkpoint и обновляет
   operation counters.
5. При ошибке новые batches не отправляются, cleanup ограничен пятью секундами,
   затем pool принудительно завершается. Resume пропускает contiguous checkpoint
   и committed ranges впереди него.

Матрица `1/2/4` writers при batch `50k` выбрала вариант `4`; один writer
оказался на `3.21%` медленнее baseline из-за IPC/ledger overhead. После матрицы
фиксированный cold-cache A/B с physical iostat подтвердил решение на свежей
паре database: raw import `2027.232 → 1541.173 s` (`-23.98%`), target XML
`890.747 → 423.174 s` (`-52.49%`), end-to-end
`2421.921 → 1925.790 s` (`-20.49%`, `-8:16.131`). Пиковое окно W4 составило
`4` batches и `16 142 491` encoded bytes. Pipeline не включён по умолчанию.

Pipeline target входит в durable operation identity, а writer limit можно
изменить при resume. Несколько parser processes и byte seek внутрь XML не
реализованы: для этого сначала нужен доказанный parser bottleneck и безопасный
boundary index.

Read-only `gar:import:plan` теперь использует тот же manifest/filter и выдаёт
отсортированные совместимые target-файлы, но не выбирает и не включает pipeline.
`gar:status`/`gar:activity` показывают attempt, progress age, bounded-window и
range backlog; quick integrity отклоняет последний import с непустым ledger.
Real-process tests различают failed supervisor/backend attempt с точным resume и
самовосстановление отдельного child writer в том же attempt.

### 4. Отложенная Проверка Foreign Keys

Принят более рискованный empty-database opt-in:

- `--defer-foreign-keys` доступен только вместе с
  `--initial-load --direct-copy` и входит в durable operation identity;
- exact catalog definition/hash фиксируется только для canonical FK; `NOT NULL`,
  CHECK, PK и unique остаются активными;
- после index build все FK восстанавливаются как `NOT VALID` одной транзакцией;
- bounded pool `1..4` выполняет `VALIDATE CONSTRAINT`, начиная с крупнейших
  referencing tables и не запуская два FK одной таблицы одновременно;
  status/attempt каждого ограничения checkpointed;
- `ready` запрещён, пока snapshot definitions, `convalidated`, operation
  counters и quick integrity checks не совпали;
- real-process tests подтверждают resume после SIGTERM во время import со
  снятыми FK и после interruption validation worker без premature `ready`.

Свежий W4/W4+FK A/B на region 50 дал raw
`1487.164 → 527.338 s` (`-64.54%`) и end-to-end
`1850.893 → 919.935 s` (`-50.30%`, `-15:30.958`). Restore/validation всех `34`
FK заняли `35.861 s`; exact rows, file/table/FK digests, indexes и backlog gates
совпали. Option принят для измеренного host, но не включён по умолчанию.

На использованном q59 schema baseline отдельная q60/q61 серия приняла для measured
host `--foreign-key-workers=4`: средняя validation-фаза сократилась
`44.155 → 34.995 s`, а largest-table-first queue — ещё до `30.959 s`.
Reverse-order W4→W2 пара дала фактический E2E `295.610 → 307.686 s`; итоговый
W4+LPT против W2 — `292.742 → 307.686 s` (`-4.86%`), либо `-3.62%` после
вычитания независимого raw drift. CLI default остаётся `2` для неизвестного
shared host; `4` — измеренный execution profile этого dedicated сервера.

### 5. Оставшаяся Concurrency И Batch Matrix

Writer matrix `1/2/4` для pipeline и batch `50k` завершена. Для нового host или
если профиль изменится, прогнать оставшуюся матрицу:

- writers: `1`, `2`, `4`, затем больше только при отсутствии storage pressure;
- batch: `25k`, `50k`, `100k`;
- index builders: `1` и `2`;
- memory budget: encoded batch bytes × in-flight batches × writers плюс
  `maintenance_work_mem` каждого index build.

Stop condition — дальнейший writer увеличивает latency/WAL stalls или снижает
общий rows/s. Цель не максимальное число processes, а устойчивое насыщение
storage при ограниченной памяти и предсказуемом recovery.

### 6. Комбинированный Candidate

Принятый candidate уже объединяет lifecycle вторичных индексов, direct COPY и
bounded pipeline. Отложенную проверку FK добавлять только после отдельного A/B и
затем повторять полный end-to-end путь:

`prepare → direct import → index build → FK restore → FK validation → ANALYZE → integrity → ready`.

## Журнал Delta И Решений

Этот раздел обновляется после каждого прогона и остаётся единым оценочным
scorecard. Для duration отрицательная delta означает ускорение; для throughput
положительная delta означает ускорение. Решение принимается только по полному
пути до durable `ready`, а raw phase хранится как диагностическая метрика.

| Режим | Baseline | Candidate | Delta | Положительные стороны | Отрицательные стороны | Решение |
| --- | --- | --- | --- | --- | --- | --- |
| `UNLOGGED` raw import | `3702.854 s`, `33 166 rows/s` | `3325.352 s`, `36 931 rows/s` | duration `-10.19%`; throughput `+11.35%`; WAL `-84.28%` | Меньше WAL, raw import быстрее на `6:17.5` | Нет crash safety/replication до возврата durability | Не оценивать отдельно от `SET LOGGED` |
| `UNLOGGED → LOGGED` end-to-end | `3702.854 s` | `3987.874 s` | duration `+7.70%` (`+4:45.0`) | WAL всё ещё существенно меньше | `SET LOGGED` rewrite `662.522 s`; полный путь медленнее | `reject` для штатного canonical import |
| Secondary-index lifecycle raw import | `3651.120 s`, `33 636 rows/s` | `2357.031 s`, `52 103 rows/s` | duration `-35.44%`; throughput `+54.90%` | Нет incremental maintenance 181 secondary index | Всё ещё один CPU-bound backend на больших XML | diagnostic |
| Secondary-index lifecycle end-to-end | `3651.120 s`, `33 636 rows/s`, WAL `172 626 765 328 B` | `2708.870 s`, `45 336 rows/s`, WAL `46 679 319 864 B` | duration `-25.81%` (`-15:42.3`); throughput `+34.78%`; WAL `-72.96%` | Полный durable путь быстрее; indexes компактнее на `11.56%`; backlog/invalid/unvalidated `0` | Только empty offline DB; index build добавляет `349.908 s`; single-file tail остаётся | `accept` для initial-load |
| Direct COPY raw import | `2357.031 s`, `52 103 rows/s` | `1852.535 s`, `66 292 rows/s` | duration `-21.40%` (`-8:24.496`); throughput `+27.23%` | Нет staging tuple, conflict lookup и merge executor; checkpoint остаётся atomic с COPY | Duplicate/FK error останавливает batch; только empty initial-load | diagnostic |
| Direct COPY end-to-end | `2708.870 s`, `45 336 rows/s`, WAL `46 679 319 864 B` | `2207.477 s`, `55 633 rows/s`, WAL `33 254 503 288 B` | duration `-18.51%` (`-8:21.393`); throughput `+22.71%`; WAL `-28.76%` | Durable path прошёл оба digest; indexes/constraints/backlog без ошибок; размеры `±0.05%` | Single-file tail сохраняет средний raw throughput ниже `100k`; требуется отдельный mapper вычисляемых columns | `accept` как opt-in `--direct-copy` |
| Bounded pipeline raw import | `2027.232 s`, `60 580 rows/s` | `1541.173 s`, `79 685 rows/s` | duration `-23.98%` (`-8:06.058`); throughput `+31.54%` | Target XML распределён между четырьмя batch writers; global worker cap сохранён | Только один выбранный object-parameters XML; serializable IPC и больший RSS остаются ценой fan-out | diagnostic |
| Bounded pipeline end-to-end | `2421.921 s`, `50 707 rows/s`, WAL `33 848 028 640 B` | `1925.790 s`, `63 771 rows/s`, WAL `33 173 172 904 B` | duration `-20.49%` (`-8:16.131`); throughput `+25.76%`; WAL `-1.99%` | Оба digest и exact counts совпали; ledger/backlog/invalid/unvalidated `0`; encoded peak `4` batches / `16 142 491 B` | Physical device не насыщен; следующий bottleneck нельзя объявить storage-bound; pipeline остаётся opt-in | `accept` с `--pipeline-writers=4` для измеренного стенда |
| Deferred FK raw import | `1487.164 s`, `82 579 rows/s` | `527.338 s`, `232 885 rows/s` | duration `-64.54%` (`-15:59.826`); throughput `+181.99%` | Referencing checks перенесены из каждого COPY row в bulk validation | FK временно отсутствуют; только exclusive empty initial-load | diagnostic |
| Deferred FK end-to-end | `1850.893 s`, `66 351 rows/s`, WAL `33 175 239 880 B` | `919.935 s`, `133 498 rows/s`, WAL `29 704 555 072 B` | duration `-50.30%` (`-15:30.958`); throughput `+101.20%`; WAL `-10.46%` | `34` exact FK restored/validated за `35.861 s`; file/table/FK digests совпали; backlog/invalid/unvalidated `0` | Опция не для incremental import; validation memory/I/O host-dependent | `accept` как opt-in `--defer-foreign-keys` |
| FK validation W4 + LPT | q60 W2 repeat FK/E2E `43.753 / 307.686 s` | q61 W4+LPT FK/E2E `30.428 / 292.742 s` | FK `-30.45%`; E2E `-4.86%`; normalized E2E `-3.62%` | Два LPT прогона дали `30.428 / 31.490 s`; exact digests, `34/34` FK и нулевой backlog | W4 — профиль measured host, CLI default остаётся W2 | `accept` для measured host |
| Multi-file fragment G16/M64/B100 | q63c G8/M32 raw/E2E `233.994 / 294.413 s` | q63d pairs mean raw/E2E `217.707 / 280.505 s` | mean raw `-8.13%`; E2E `-5.54%`; batches `2979 → 1527` | Общий R8/P4 admission, exact digests, `34/34` FK, `106` indexes и нулевые backlogs | Importer max RSS вырос до `702 MiB`; G32/M128 E2E plateau | `accept` G16/M64/B100 |
| Table-aware F2 | q63g reverse broad F2 tail/E2E `27.041 / 287.105 s` | q63g table-aware tail/E2E `10.756 / 274.152 s` | tail `-60.22%`; E2E `-4.51%`; raw `+2.26%` | Same-table XML сохраняют F2, разные tables закрываются раньше для index admission | Не максимизирует raw отдельно; scheduling требует overlap indexes | `accept` для measured host |
| Удаление четырёх `reestr_objects` metadata B-tree | q64 controls mean raw/index/E2E `226.261 / 240.743 / 275.489 s` | q64 candidates mean `212.113 / 226.165 / 262.367 s` | raw `-6.25%`; index `-6.06%`; E2E `-4.76%`; index bytes `-333 185 024 B` | `is_active=true` измерен у `95.1924%` registry rows; остальные metadata consumers отсутствуют; exact digests, `34/34` FK и нулевые backlogs | Будущий inactive/metadata consumer должен вернуть собственный measured access path | `accept`; `102` indexes / `77` snapshots |
| Late I1 после 50% source bytes | q65 controls mean raw/tail/E2E `223.289 / 13.692 / 274.433 s` | q65 candidates mean `197.497 / 24.072 / 256.882 s` | raw `-11.55%`; E2E `-6.40%`; tail `+75.81%` | Две crossover-пары: E2E `-8.56% / -4.24%`; exact digests, `102` indexes, `34/34` FK и нулевые backlogs | Порог source bytes дискретен по завершённым файлам; unknown host требует собственного A/B | `accept`; measured-host execution profile |
| Edge-triggered pipeline peak | q66 eager controls mean raw/E2E `182.437 / 237.687 s` | q66 edge mean `180.609 / 240.349 s` | updates `-43.1%`; raw `-1.00%`; E2E `+1.12%` | Durable `GREATEST()` и exact recovery/correctness сохранены | HOT operation updates не являются значимым WAL/bottleneck path | `reject`; сохранить eager peak checkpoint |
| Global CLI OPcache | q67 OFF mean raw/E2E `175.856 / 235.965 s` | q67 ON mean `188.586 / 245.783 s` | raw `+7.24%`; E2E `+4.16%`; обе E2E пары хуже | Effective INI подтверждён у всей process tree; assertions уже zero-cost при `zend.assertions=-1` | q67a был быстрым выбросом, поэтому размер regression не обобщается | `reject` для GAR fan-out |
| Global tracing JIT | q68 JIT-OFF mean raw/E2E `189.578 / 247.843 s` | q68 tracing mean `184.641 / 246.504 s` | raw `-2.60%`; E2E `-0.54%`; tail `+17.42%` | JIT фактически active; raw выиграл обе пары | Whole-run gate не пройден; короткие index processes платят bootstrap/warm-up | `reject` global; проверить parser-only |
| Debian/glibc runtime | q69 Alpine mean raw/E2E `180.472 / 239.451 s` | q69 Debian mean `184.121 / 240.050 s` | raw `+2.02%`; E2E `+0.25%`; RSS `+3.55%` | PHP/ext/libuv/libpq/INI parity подтверждена | Пары меняют знак; distro profile не изолирует только allocator | `reject`; оставить Alpine baseline |
| Native-only jemalloc | q70 musl controls mean raw/E2E `180.535 / 241.872 s` | q70 jemalloc mean `178.778 / 236.282 s` | raw `-0.97%`; E2E `-2.31%`; RSS `+4.74%` | Zend MM и enforceable `memory_limit` сохранены; exact parity прошла | Пары меняют знак на `~15%` raw; mean E2E без reproducibility недостаточен | `reject`; не preload в production |
| Direct jemalloc без Zend MM | q71 Zend controls mean raw/E2E `177.640 / 234.967 s` | q71 direct mean `191.295 / 245.081 s` | raw `+7.69%`; E2E `+4.30%`; RSS `+31.63%` | Hard cgroup `2 GiB`, no swap и exact parity подтверждены | Обе E2E пары хуже; PHP `memory_limit` больше не ограничивает heap | `reject`; сохранить Zend MM |
| Selective tracing JIT | q72 controls mean raw/E2E `193.236 / 243.737 s` | q72 selective mean `182.865 / 241.112 s` | raw `-5.37%`; E2E `-1.08%`; tail `+37.16%`; RSS `+8.78%` | Parent/index/FK остались JIT-off; child args и exact parity подтверждены | E2E pairs `-4.273% / +1.940%`, reverse не воспроизвёл gain | `reject`; experimental option удалён |
| Persistent index workers | q73 CLI controls mean raw/index/E2E `188.291 / 124.095 / 245.233 s` | q73 persistent mean `182.290 / 119.967 / 237.915 s` | raw `-3.19%`; index `-3.33%`; E2E `-2.98%`; RSS `-3.81%` | Две crossover-пары `-3.559% / -2.416%`; четыре стабильных Amp PID вместо `77` CLI bootstraps; exact parity | Execution option только для initial-load; unknown/shared host требует собственного A/B | `accept` для measured host |
| COPY metadata CTE | q74 controls mean raw/E2E `197.391 / 250.137 s` | q74 CTE mean `184.219 / 244.432 s` | raw `-6.67%`; E2E `-2.28%`; tail `+31.19%`; index `+0.03%` | Exact parity на четырёх прогонах; statements/batch `7 → 3` | E2E pairs `-10.322% / +6.502%`, reverse сменил знак | `reject`; experimental path удалён |
| Cooperative range planner | q76 blocking controls mean raw/E2E `189.908 / 241.709 s` | q76 cooperative mean `185.224 / 238.434 s` | raw `-2.47%`; E2E `-1.36%`; tail `+10.62%` | Exact parity на четырёх прогонах; q75 отдельно доказал EventLoop lag blocking planner | E2E pairs `-2.510% / -0.224%`, raw сменил знак | `reject`; yields удалены, проверить non-blocking file source |
| Async file planner | q77 blocking controls mean raw/E2E `185.059 / 244.066 s` | q77 `UvFilesystemDriver` mean `186.332 / 244.415 s` | raw `+0.69%`; E2E `+0.14%`; tail `-3.08%` | Exact parity; runtime driver подтверждён, fallback pool не использовался | pairs raw `-10.619% / +12.230%`, E2E `-5.589% / +5.842%` | `reject`; dependency и implementation удалены |
| One-read source fragments | q78 offset controls mean raw/tail/E2E `186.354 / 21.791 / 240.395 s` | q78 inline mean `200.528 / 14.726 / 248.329 s` | raw `+7.61%`; tail `-32.42%`; E2E `+3.30%`; RSS `+27.5%` | Exact parity и bounded memory; повторный worker read действительно исключён | E2E pairs `-1.878% / +8.523%`; IPC payload удлинил raw critical path | `reject`; experimental path удалён |
| Three-file source tmpfs | q79 cold NVMe controls mean raw/tail/E2E `188.828 / 23.132 / 244.051 s` | q79 tmpfs import-only mean `173.361 / 30.548 / 235.433 s`; staged `246.293 s` | raw `-8.19%`; import E2E `-3.53%`; staged E2E `+0.92%`; tail `+32.06%` | Обе import-only пары положительны; `AS_ADM_HIERARCHY` быстрее на `36.8% / 27.4%`; exact parity | staging `10.86 s`; RSS `~13.5 GiB`; q79a swap-out `~514 MiB` | `reject` three-file profile; проверить hierarchy-only tmpfs |
| Hierarchy-only source tmpfs | q80 cold NVMe controls mean raw/tail/E2E `195.442 / 21.395 / 249.920 s` | q80 tmpfs mean raw/tail/import E2E `171.530 / 30.105 / 233.397 s`; staged `237.017 s` | raw `-12.23%`; import E2E `-6.61%`; staged E2E `-5.16%`; tail `+40.71%` | Staged crossover `-4.227% / -6.038%`; exact digests, `102` indexes, `34/34` FK и нулевые backlogs | staging `3.62 s`; peak container memory `~4.77 GiB`; no swap-out | `accept` optional для measured host; PostgreSQL остаётся на NVMe |
| Bounded shared-memory fragments | q81 offset controls mean raw/tail/E2E `188.287 / 20.515 / 241.890 s` | q81 shared-memory mean `182.890 / 25.670 / 241.033 s` | raw `-2.87%`; E2E `-0.35%`; tail `+25.13%`; RSS `+14.3%` | E2E pairs `-3.148% / +2.465%`; exact digests, `102` indexes, `34/34` FK, zero backlogs and clean normal IPC teardown | Reverse pair changed sign; crash cleanup not pursued after failed performance gate | `reject`; implementation/extensions removed |
| Hierarchy stage diagnostic | q73 mean raw/tail/E2E `182.290 / 23.214 / 237.915 s` | q82 instrumented `165.159 / 34.704 / 231.937 s` | context only: raw `-9.40%`, tail `+49.50%`, E2E `-2.51%`; hierarchy pair `49.589 s` | Exact parity; planner/parse/permit/COPY separated for both final files; R8 parser demand has room, shared COPY demand exceeds P4 | Diagnostic hot-path timing and no adjacent control forbid acceptance | `diagnostic`; next selective priority-80 pair test |
| Selective hierarchy F2 | q82 sequential pair/raw/tail/E2E `49.589 / 165.159 / 34.704 / 231.937 s` | q83 concurrent pair/raw/tail/E2E `50.706 / 166.282 / 34.443 / 234.167 s` | pair `+2.25%`; raw `+0.68%`; tail `-0.75%`; E2E `+0.96%` | Exact parity; only priority-80 pair changed; both files truly overlapped under shared R8/P4 | Combined permit wait `+12.0%`; no local recovery, so full crossover unjustified | `reject`; experimental grouping removed |
| Post-raw same-table index width 2 | q84 controls mean tail/E2E `21.980 / 238.619 s` | q84 width-2 mean `18.714 / 240.279 s` | tail `-14.86%`; E2E `+0.70%`; E2E pairs `-6.186% / +7.717%` | Same-table builds actually overlapped; exact parity and resource gates passed; early I1 stayed width 1 | Reverse E2E changed sign; candidate FK mean `+16.3%` | `reject`; one-index-per-table restored |
| Оставшиеся 26 B-tree | q84b E2E `241.022 s` | q85 measured serial build upper bound `2.950 s` | невозможный максимум E2E `-1.224%`, ниже gate | Source/query/constraint audit назначил все 26 schema-cleanup candidates | I4 overlap делает реальный эффект ещё меньше; full crossover нерационален | performance frontier закрыт; cleanup отдельно |
| Persistent FK workers | q86 process controls mean FK/E2E `30.447 / 242.260 s` | q86 persistent mean `28.423 / 234.451 s` | mean FK `-6.65%`, E2E `-3.22%`; pairs E2E `-5.980% / -0.530%` | Exact parity; bounded four-process reuse и recovery прошли | Reverse pair ниже `2%`; локальный FK delta только `0.121 s` | `reject`; task/option удалены, сохранить W4/LPT process pool |
| Bounded COPY-text spool | q73/q65 fused R8/P4 | q87 R8 parser → private tmpfs → P4 COPY | E2E отсутствует: full candidate остановлен на неизменных `27/28`, `122 616 995` rows | Single-fixture transaction/checksum/cleanup прошли; `152` поздних ranges последнего файла committed | Три ранних ready chunks застряли при idle R8/P4 и PostgreSQL; F2 liveness нарушена | `reject`; implementation удалена, повтор только с global dispatcher |
| Fresh baseline profile | q73 means raw/tail/FK/E2E `182.290 / 23.214 / 28.662 / 237.915 s` | q88 single diagnostic `163.473 / 30.032 / 29.131 / 225.737 s` | Не A/B: raw короче, tail длиннее; administrative index chain завершилась на `+29.985 s` | Exact parity; per-CPU, PSI, NVMe, PG waits и index history собраны | Один прогон не меняет baseline; CPU tail idle `75.02%`, NVMe avg/max `33.61/78%` | `diagnostic`; q89 targeted administrative width 2 |
| Targeted administrative index width 2 | q88 width 1 tail/E2E `30.032 / 225.737 s` | q89a width 2 `27.647 / 231.309 s` | tail `-7.94%`; E2E `+2.47%`; raw drift `+4.91%` до candidate phase | Exact parity; two target indexes actually overlapped; swap unchanged | paired builds inflated to ~`6.0 s` vs serial `3.977 / 3.474 s`; local saving only `2.384 s` | `reject` after negative first run; implementation removed |
| FK validation profile | W4/LPT floor около `28–30 s` | q90a FK `29.499 s`; `object_params` chain `18.157 + 8.162 + 2.758 s` | diagnostic; почти вся FK phase принадлежит одной table chain | Exact parity; CPU idle `85.06%`, NVMe avg/max `16.99/32.72%` | same-table workers сериализуются self-conflicting lock; combined `ALTER TABLE` всё равно вызывает отдельный `RI_Initial_Check()` на FK | `diagnostic`; FK knobs exhausted, no q91 same-table run |
| Global COPY-text dispatcher | q90 text fused path | q91a остановлен на `27/28`, `122 552 903` rows | Валидного E2E нет | Fixture/recovery прошли; `151` late ranges committed | Четыре early chunks stranded при idle R8/P4/PostgreSQL | `reject`; второй liveness failure, implementation удалена |
| Binary COPY | q90 raw/tail/E2E `164.107 / 35.961 / 233.343 s`, `13.188 GB` encoded | q92a `172.457 / 30.806 / 235.886 s`, `15.198 GB` encoded | raw `+5.09%`; tail `-14.34%`; E2E `+1.09%`; bytes `+15.24%` | Exact rows/digests, `102` indexes, `34/34` FK, recovery passed | Four-byte field lengths и PHP type conversion дороже compact text GAR values | `reject`; no A/B, implementation удалена |
| Pre-normalized COPY-text artifacts | q90 fused raw `164.107 s` | q93a normalize W8 `252.006 s`; q93b P4 load `123.460 s`; q93c P8 load `127.706 s` | sequential W8+P4 `375.467 s`; P8 load `+3.44%`; ideal 24-CPU floor `180.331 s` (`+9.89%`) | Exact `122 613 810` compatible rows, `1 527` ranges, `13.188 GB` and per-table load counts | Materialization serializes normalize and COPY; wider load increases primary-index/WAL contention | `reject`; no production artifact/recovery contract |
| Planned-range streaming COPY | q101 controls mean raw/index-complete/E2E `192.949 / 211.647 / 245.939 s` | q101 candidates `177.571 / 201.721 / 234.886 s` | raw `-7.97%`; index complete `-4.69%`; E2E `-4.49%`; tail relative to raw `+29.16%` | Две exact crossover-пары, `1 527` checkpoints, WAL mean `+0.028%`; process-local generator удерживает только `~1 MiB` prefetch на writer | Largest target mean `+10.06%`; per-file effect неоднороден и требует отдельного causal profile | `accept`; standard fused transport, benchmark flag удалён |
| Post-raw index/FK overlap | q103 controls median raw/post-raw/E2E `192.282 / 52.460 / 244.742 s` | q103 candidates `178.185 / 40.801 / 218.986 s` | post-raw `-22.22%`; E2E `-10.52%`; соседние E2E-пары `-14.41% / -6.52%` | Четыре cold points сохранили `122 809 172` rows, exact digests, `102` indexes, `34/34` FK и zero backlogs | Actual index/FK spans выросли `19.997 → 33.189 s` и `29.207 → 37.012 s`; FK завершались на `3.634–7.874 s` позже indexes | `accept`; после atomic `NOT VALID` restore выполнять final indexes и FK validation одновременно |
| Index-first table admission | q103 candidate median post-raw/E2E `40.801 / 218.986 s` | q104a post-raw/E2E `42.350 / 215.273 s`; actual index/FK `27.314 / 38.875 s` | E2E `-1.695%` ниже gate; post-raw `+3.797%` | Exact `122 809 172` rows/digests, `102` indexes, `34/34` FK, zero backlogs | Indexes завершились на `13.233 s` раньше FK; barrier усилил serial `object_params` critical chain | `reject` без crossover; implementation/option удалены |
| Native FK plan diagnostic | q104 serial `object_params` chain | q105 actual plans `23.374 + 8.583 + 3.019 s` | diagnostic only | Exact parity; accurate row estimates; `max_parallel_workers=12` | `RI_Initial_Check()` использует `SPI_prepare()` без `CURSOR_OPT_PARALLEL_OK`; планы структурно serial | `diagnostic`; parallel limits и pre-ANALYZE закрыты |
| FK visibility map | q106 fresh heap validation `32.354 s` | component `12.49–12.66 s`; q107 post-raw, q108 before-index, q109 after-index, q110 async after-index | q109 reverse E2E `+6.05%`; q110 E2E `+0.385%` despite post-raw `-6.11%` | Все full points exact; q109/q110 подтверждают реальное сокращение FK | До indexes VM не возникает; после indexes VACUUM конкурирует с remaining CREATE INDEX даже в отдельном OS process | `reject`; все три placement исчерпаны, q103 остаётся baseline |
| Dynamic remaining-index-work LPT | q103 candidate median raw/post-raw/E2E `178.185 / 40.801 / 218.986 s` | q111a `173.811 / 50.852 / 224.663 s` | raw `-2.45%`, post-raw `+24.63%`, E2E `+2.59%` | Exact rows/digests, `102` indexes, `34/34` FK, zero backlogs | Recomputed weight abandoned fourth `object_params` index; same-table index/FK overlap inflated it `~12 → 34.348 s` | `reject` without crossover; preserve table-chain completion |
| Deferred primary keys | active-PK raw/E2E `197.216 / 242.409 s` mean | deferred-PK raw/E2E `145.158 / 205.660 s` mean | raw `-26.40%`, E2E `-15.16%`, WAL `-32.63%` | q112 component duplicate gates; q113 exact rows/digests, `102` indexes, `34/34` FK, zero backlogs | Post-raw work grows, but is dominated by removal of per-row B-tree/WAL maintenance | `accept`; explicit direct initial-load option with durable PK snapshots |
| Primary-first early queue | q113 E2E `205.660 s` mean | q114a raw/post-raw/E2E `144.684 / 56.193 / 213.629 s` | raw `-0.33%`, E2E `+3.87%` | All `23` PK complete at raw boundary; exact final parity | Ready PK displace heavier secondary work from overlap window | `reject` without crossover; heap-size-first I1 restored |
| Work-orchestrator raw topology `1×8` | q115/q117 production-control raw mean `142.900 s` | q116/q117 raw mean `102.896 s` | raw `-27.99%`; `1 193 524 rows/s`; source `242.73 MB/s` | Два порядка дали `-28.07% / -27.92%`; exact `122 809 172` rows и оба digest; IPC `0.01347%` source bytes; backlog/swap `0` | Raw-only boundary не выполняет index/FK finalization; q116 потребовал benchmark-only schema override, q117 подтвердил forward migration без override | `accept`; raw default `1×8`, schema capacity `8`; legacy `gar:import` остаётся `1..4` |
| Raw `1×8` → production resume E2E | q117b/q119b production-control mean `238.048 s` | q120 prompt resume `213.255 s`; q118 с искусственной `17.835 s` harness-паузой `219.220 s` | q120 `-10.42%`, `-24.794 s`; observed candidate mean `-9.16%`; active-stage mean `-13.13%` | Control drift `0.37%`; exact `122 809 172` rows/digests, `102` valid indexes, `34/34` FK, zero backlogs; cgroup swap `0` | Lifecycle остаётся двумя CLI-командами; q118 показывает, что внешняя задержка между ними входит в operation E2E | `validated`; production `gar:import` resume завершает принятую raw operation без повторного XML import |
| Global file workers W8 | W4 raw/E2E `527.338 / 919.935 s` | W8 raw/E2E `534.108 / 934.536 s` | duration `+1.28% / +1.59%` | Exact parity и resource gates прошли | Dependency barriers оставляют лишние workers idle | `reject`; оставить W4 |
| Index pool I4 + LPT | I2 index/E2E `354.755 / 919.935 s` | I4+LPT index/E2E `208.455 / 804.030 s` | index `-41.24%`; E2E `-12.60%` (`-1:55.905`) | Exact parity; max PostgreSQL RSS `14.51 GiB`; NVMe index p95 util `61.12%` | Последний `idx_object_params_value` остаётся serial tail | `accept` для измеренного host |
| Удаление неиспользуемого raw-value GIN | q38 index/E2E `228.471 / 458.953 s`, `206` indexes | q39 index/E2E `183.966 / 397.210 s`, `205` indexes | index `-19.48%`; E2E `-13.45%` (`-1:01.743`); index bytes `-759 857 152 B` | Application query inventory и `EXPLAIN` подтверждают denorm composite index; data/file/FK digests и backlogs совпали | Прямой fuzzy search по raw `object_params.value` больше не индексирован и потребует отдельной migration | `accept`; новый baseline |
| Удаление четырёх hierarchy path GIN | q39 index/E2E `183.966 / 397.210 s`, `205` indexes | q41 index/E2E `149.424 / 366.205 s`, `201` indexes | index `-18.78%`; E2E `-7.81%` (`-0:31.005`); index bytes `-2 466 037 760 B` | `ANY(path_arr)` не поддерживается array GIN; path trigram consumer отсутствует; exact digests/FK/backlogs и integration parity совпали | Будущий `@>`/fuzzy hierarchy consumer должен вернуть минимальный индекс собственной migration | `accept`; новый baseline |
| Удаление оставшихся canonical trigram GIN | q41 index/E2E `149.424 / 366.205 s`, `201` indexes | q42 index/E2E `145.245 / 362.674 s`, `173` indexes | index `-2.80%`; E2E `-0.96%` diagnostic; index bytes `-323 084 288 B` | `28` raw fuzzy indexes не имеют используемого access path; populated suggestion plan сохраняет denorm composite; exact digests/FK/backlogs и integration parity совпали | Новый raw fuzzy consumer должен вернуть только свой predicate-compatible index и измерить read/write trade-off | `accept`; новый baseline |
| Удаление redundant unit lifecycle B-tree | q42 index/E2E `145.245 / 362.674 s`, `173` indexes | q43 repeat index/E2E `136.046 / 370.127 s`, `143` indexes | index `-6.33%`; E2E `+2.06%` из-за raw variance; index bytes `-367 616 000 B` | Шесть populated plans сохраняют partial active/current composites; две q43 index-фазы дали `-3.40/-6.33%`; exact parity совпала | Broad date/flag removal вне шести unit tables не разрешён этим A/B | `accept` по scoped index gate; новый schema baseline |
| Удаление detached version-link B-tree | q43 repeat index/E2E `136.046 / 370.127 s`, `143` indexes | q44 index/E2E `114.900 / 358.321 s`, `127` indexes | index `-15.54%`; E2E `-3.19%`; index bytes `-732 004 352 B` | Все `16` self-FK уже сняты из-за неполных GAR links; source consumers отсутствуют; exact digests/FK/backlogs и integration parity совпали | Будущий lineage traversal должен вернуть только нужный индекс и измерить read/write trade-off | `accept`; новый schema baseline |
| Удаление object-parameter lifecycle B-tree | q44 index/E2E `114.900 / 358.321 s`, `127` indexes | q45 repeat index/E2E `108.691 / 345.505 s`, `124` indexes | index `-5.40%`; E2E `-3.58%`; index bytes `-1 030 930 432 B` | Реальные single-object/path планы сохраняют partial denorm composite; две q45 index-фазы дали `-3.84/-5.40%`; exact parity совпала | Broad date-only parameter query больше не имеет standalone access path | `accept`; новый schema baseline |
| Удаление object-parameter change-end B-tree | q45 repeat index/E2E `108.691 / 345.505 s`, `124` indexes | q46 index/E2E `98.366 / 336.922 s`, `123` indexes | index `-9.50%`; E2E `-2.48%`; index bytes `-496 091 136 B` | `change_id_end` не имеет FK или query consumer; raw value, denorm composite и три FK paths сохранены; exact parity совпала | Будущий change-history traversal должен вернуть минимальный индекс и измерить trade-off | `accept`; новый schema baseline |
| Удаление duplicated unit-GUID B-tree | q46 index/E2E `98.366 / 336.922 s`, `123` indexes | q47 repeat index/E2E `85.301 / 323.592 s`, `117` indexes | index `-13.28%`; E2E `-3.96%`; paired control index `-12.47%`; index bytes `-281 010 176 B` | UUID берётся из unique registry row, unit join использует numeric FK; reverse control воспроизвёл q46; exact parity совпала | Первый q47 был storage-noisy; будущий direct unit-GUID path должен доказать преимущество над registry→FK route | `accept`; новый schema baseline |
| Удаление detached hierarchy cross-link B-tree | q47 schema: `117` indexes / `92` snapshots | q48 repeat index/E2E `78.046 / 319.045 s`, `113` indexes / `88` snapshots | две reverse-order crossover-пары: index `-3.55% / -11.44%`; paired index bytes `-691 732 480 B`; unpaired median `-0.28%` diagnostic | `parent_obj_id`/`change_id` FK ранее сняты; raw values и используемые `object_id`/`region_code` indexes сохранены; exact parity совпала | Host drift велик, поэтому заявляется только парный index effect; будущий cross-link traversal должен вернуть минимальный индекс со своим планом | `accept` по двум scoped paired gates; новый schema baseline |
| Удаление hierarchy classifier-code B-tree | q48 physical schema: `113` indexes / `88` snapshots | q49 A1 index/E2E `88.416 / 324.783 s`, `107` indexes / `82` snapshots | две пары против соседнего q48 control: index `+11.91% / +14.84%`; index bytes `-630 988 800 B` | Projection значений не требует source index, но обе пары воспроизводимо ухудшили изменяемую фазу; exact parity совпала | Экономия storage без performance gate недостаточна; шесть индексов остаются до нового policy/query evidence | `reject`; schema остаётся q48 |
| Удаление hierarchy start/update B-tree | q48 physical schema: `113` indexes / `88` snapshots | q50 repeat index/E2E `76.990 / 329.456 s`, `109` indexes / `84` snapshots | две reverse-order пары: index `-26.05% / -4.43%`; paired index bytes `-358 817 792 B`; E2E diagnostic | `start_date`/`update_date` не имеют consumer; оба используемых `end_date` индекса и raw values сохранены; exact parity совпала | Gain заявляется только для парной index-фазы; будущий start/update consumer должен вернуть минимальный индекс с populated plan | `accept` по двум scoped paired gates; новый schema baseline |

Secondary index добавляется только для конкретного production query или
constraint path с проверенным планом, а не «на будущее». Новый consumer должен
принести свой предикат, `EXPLAIN`/query benchmark и сравнение read gain с
ценой import, WAL, storage и recovery. Нулевой `idx_scan` служит началом
аудита, но не доказательством удаления без source inventory редких
integrity/recovery/referential путей.
Raw reference/lineage column после осознанного снятия FK также не получает
индекс автоматически: для него нужен фактический join/traversal consumer.
Это правило применено к `object_params.change_id_end`: значение остаётся в
canonical data, но standalone B-tree отсутствует до появления такого consumer.
То же относится к дублирующим UUID unit tables: authoritative unique UUID и
numeric FK indexes сохраняются, а отдельный direct index требует своего query.
Для hierarchy lifecycle колонок правило применяется по фактическому access
path: `start_date`/`update_date` не индексируются без predicate/order consumer,
но `end_date` сохраняет B-tree, пока current rebuild, integrity и DataMos SQL
реально фильтруют по нему. Экономия storage отдельно не заменяет performance
gate: q49 сохранил classifier-code indexes после двух регрессий index-фазы.
То же правило применяется к hierarchy `parent_obj_id`/`change_id`: после
намеренного снятия cross-link FK исходные значения сохраняются без отдельного
B-tree, пока production traversal не докажет свой predicate и read/write
trade-off. Используемые hierarchy lookup indexes этим решением не затронуты.

Полные протоколы:

- [benchmark lifecycle вторичных индексов](gar-import-index-lifecycle-benchmark.md);
- [benchmark direct COPY](gar-import-direct-copy-benchmark.md);
- [benchmark bounded single-file pipeline](gar-import-pipeline-benchmark.md);
- [benchmark deferred foreign keys](gar-import-foreign-key-lifecycle-benchmark.md);
- [benchmark resource saturation](gar-import-resource-saturation-benchmark.md);
- [журнал перемещения bottleneck](gar-import-bottleneck-ledger.md).

Для каждого нового режима добавляются две строки, если есть отдельная
finalization: raw import и end-to-end. Минимальный набор полей:

| Метрика | Baseline | Candidate | Delta | Gate |
| --- | ---: | ---: | ---: | --- |
| Import duration | — | — | — | diagnostic |
| Finalization duration | — | — | — | diagnostic |
| End-to-end duration | — | — | — | `>=2%` faster |
| End-to-end rows/s | — | — | — | рост согласован с duration |
| WAL bytes | — | — | — | без отказа от durability |
| Database / index bytes | — | — | — | объяснить write amplification |
| Peak parser/writer/PostgreSQL CPU | — | — | — | показать новый bottleneck |
| Physical I/O MB/s, utilization, await | — | — | — | доказать storage-bound |
| Peak RSS / in-flight bytes | — | — | — | уложиться в memory budget |
| Files, rows, digest, invalid objects, backlog | — | — | — | точное совпадение, нули ошибок |

Если end-to-end delta находится в диапазоне `2–5%`, прогон повторяется в
обратном порядке; при заметном host drift reverse repeat требуется и для более
крупного эффекта. Любое ухудшение correctness/recovery даёт `reject` независимо
от скорости. Плюсы и минусы сохраняются и для отклонённых вариантов, чтобы не
повторять уже проверенные гипотезы без новых условий.

## AMP И PostgreSQL Boundary

`amphp/parallel` подходит для настоящего process/thread parallelism и bounded
IPC, а `Amp\async()` — для конкурентного ожидания. Это orchestration layer, а
не замена PostgreSQL executor.

Текущий `amphp/postgres 2.2.2` явно отклоняет `COPY` как unsupported. Поэтому
первые direct-COPY experiments сохраняют native `ext-pgsql` в изолированных
writer processes.

q51 подтвердил, что `pg_put_copy_data()`/`pg_flush()` можно связать с
readiness watchers и освободить EventLoop на backpressure. На синтетическом
`32 MiB` COPY bounded-вариант дал `1 716` тиков цикла, но полная транзакция не
ускорилась: `4.443 s` blocking против `4.448 s` bounded nonblocking. При этом
PHP 8.4.27 не предоставляет public setter постоянного libpq nonblocking mode:
рабочий probe опирался на противоположную семантику
`stream_set_blocking($socket, true)`, потому что ext-pgsql напрямую передаёт
флаг в `PQsetnonblocking()`. Полный q55 подтвердил отсутствие пользы:
EventLoop-only E2E `328.411 s` против blocking q56 `318.773 s` (`+3.023%`).
Prototype и CLI option удалены; blocking `pg_copy_from()` остаётся transport
fused range worker.

Полезная работа появилась на lifecycle-уровне: после закрытия canonical table
для raw-записи отдельный scheduler строит её индексы параллельно импорту других
таблиц. Загрязнённые q52/q53 сначала подтвердили exact correctness и
`11–12 s` post-raw tail. Чистые blocking q54/q54r с ранним I1 затем дали E2E
`301.497 / 306.072 s` против q56 `318.773 s` (`-5.419% / -3.984%`).
Same-process Fiber prototype в одном repeat потерял завершение одного range и
завис на `27/28`, поэтому production scheduler вынесен в скрытый отдельный CLI
процесс; PostgreSQL остаётся durable coordination boundary. Process-isolated
repeat прошёл exact gate. q57 с ранним I2 замедлил raw и дал только `-1.327%`
к q56, поэтому принят I1 во время raw и I4 после raw.

q58 проверил Amp `PosixSemaphore` в disposable PHP 8.4 image с
`ext-sysvmsg`. Штатный release возвращает permit, но `SIGKILL` владельца
оставляет capacity `0`, а `SIGKILL` initializer оставляет orphan SysV queue.
Такой admission не имеет lease/`SEM_UNDO` и не усиливает recovery текущего
process pool, поэтому extension и semaphore не добавляются в production image.

Durable MessageBus event мог бы разбудить отдельный GAR worker почти в тот же
момент, что текущий polling с интервалом `50 ms`, но сам по себе не заменяет
scheduler. Нужны атомарный outbox между file checkpoint и enqueue, отдельный
GAR flow/worker, idempotent claim, cancellation при падении raw и barrier перед
финальной index phase. Без этого edge-triggered event слабее текущего
level-triggered reconciliation по PostgreSQL state; на текущем этапе MessageBus
вариант не принимается.

Это не временная нехватка реализации, а принятое архитектурное решение для
single-host initial-load. MessageBus оставляет тот же `CREATE INDEX`, не даёт
PostgreSQL дополнительной параллельности и выигрывает у polling максимум
десятки миллисекунд. Взамен появляются outbox, routing/codec, отдельный worker
lifecycle, lease/idempotency, error cancellation и reconciliation. Текущий
дочерний процесс уже владеет PID lifecycle, а durable PostgreSQL state
level-triggered: пропущенная готовность будет обнаружена следующим poll или
после restart. Если когда-нибудь понадобится multi-host finalization, в bus
следует отправлять idempotent command, а не полагаться на одноразовый event.

Следующая очередь не включает MessageBus, новый EventLoop transport или SysV
semaphore. Приоритеты такие:

1. q60/q61 принял для measured host FK validation W4 и автоматическую
   largest-referencing-table-first queue; unknown/shared host сохраняет default
   W2 до собственного A/B.
2. q62/q63 приняли общий multi-file admission, G16/M64/B100 и table-aware F2.
   R12, P6/M96, F3 и G32/M128 не прошли gate; их caps не входят в production
   contract.
3. q64 принял удаление четырёх `reestr_objects` metadata B-tree по двум
   crossover-парам; PK, unique UUID и `level_id` index сохранены.
4. q65 принял late I1 admission после `50%` source bytes: обе crossover-пары
   прошли whole-run gate, средний E2E улучшился на `6.395%`.
5. q66 edge peak, q67 global CLI OPcache, q68 global JIT, q69 Debian/glibc,
   q70 native-only jemalloc, q71 direct jemalloc и q72 selective parser JIT не
   прошли whole-run gate.
   q71 дополнительно увеличил средний peak RSS на `31.63%` и отключил защиту
   PHP `memory_limit`; q72 закрыл scoped JIT hypothesis после E2E
   `-4.273% / +1.940%` и удаления experimental option.
6. Delta lifecycle вынесен в отдельную будущую задачу. Текущая серия сохраняет
   immutable full fixture; смена данных требует нового benchmark baseline.
7. Allocator/JIT frontier закрыт q70–q72. q73 принял persistent index workers:
   обе crossover-пары прошли `2%` gate, средний E2E улучшился на `2.984%`.
8. q74 отклонил metadata CTE consolidation: средний E2E был лучше на `2.281%`,
   но crossover-пары дали `-10.322% / +6.502%`. Experimental path удалён.
9. q75 измерил EventLoop lag blocking range planner; q76 cooperative yields
   дал E2E `-2.510% / -0.224%` и raw со сменой знака, поэтому удалён.
10. q77 проверил настоящий `amphp/file`/`UvFilesystemDriver`: первая пара
    выиграла, reverse дала raw `+12.230%` и E2E `+5.842%`; средний effect
    нейтрален. Dependency и implementation удалены.
11. q78 проверил one-read PHP fragment payload. Средний tail сократился на
    `32.421%`, но raw/E2E ухудшились на `7.606% / 3.300%`, а peak RSS вырос на
    `27.5%`; implementation удалена.
12. q79 подтвердил raw gain partial critical-path tmpfs, но three-file profile
    проиграл staged E2E и вызвал swap pressure.
13. q80 принял hierarchy-only tmpfs для measured host: staged E2E выиграл
    `4.227% / 6.038%`, средний полный эффект `5.163%`, нового swap pressure нет.
14. q81 проверил bounded fragment через
    `SharedMemoryParcel + PassthroughSerializer` против fused worker. Средний
    raw улучшился на `2.867%`, но E2E только на `0.354%`, reverse-пара сменила
    знак, а RSS вырос примерно на `14.3%`; implementation/extensions удалены.
    Redis bulk payload не входит в дальнейшую очередь.
15. q82 локализовал последовательную
    `AS_ADM_HIERARCHY → AS_MUN_HIERARCHY` group: `49.589 s`, то есть `30.0%`
    raw. Совместный parser demand укладывается в R8, COPY demand должен остаться
    под P4.
16. q83 объединил только эту priority-80 пару, но pair span ухудшился на
    `2.254%`, raw — на `0.680%`, E2E — на `0.961%`; grouping удалён без
    crossover. One-second history локализовал новый post-raw bottleneck:
    последние administrative hierarchy indexes сериализованы правилом одного
    active build на table.
17. q84 проверил same-table width `2` только после raw. Средний tail улучшился
    на `14.858%`, но E2E пары дали `-6.186% / +7.717%`, а средний E2E стал хуже
    на `0.695%`; one-per-table восстановлен.
18. q85 закрыл оставшиеся `26` B-tree измеренной верхней границей: весь их
    serial build занимает `2.950007 s`, или максимум `1.224%` q84b E2E.
    Schema cleanup остаётся, но removal A/B больше нет в performance queue.
19. q86 проверил persistent FK process/connection reuse. Первая E2E-пара дала
    `-5.980%`, reverse только `-0.530%`; implementation удалена, W4/LPT
    process-per-FK остаётся baseline.
20. q87 проверил terminal bounded COPY-text spool без full-release barrier.
    Focused path прошёл, но полный F2 оставил три ready chunks без dispatch при
    полностью idle R8/P4 и PostgreSQL. Implementation удалена как liveness
    reject. Возврат возможен только с одним global dispatcher состояний
    `parse -> ready -> COPY -> committed`; дополнительные mutex/semaphore над
    прежними file fibers запрещены.
21. q90 измерил FK critical chain. Три `object_params` constraints заняли
    `18.157 + 8.162 + 2.758 s` из `29.499 s`; host CPU/NVMe имели большой
    резерв. PostgreSQL `SHARE UPDATE EXCLUSIVE` сериализует same-table
    validations, а source выполняет отдельный `RI_Initial_Check()` на каждую
    FK даже в одном `ALTER TABLE`. Поэтому W4/LPT остаётся потолком текущей
    схемы, а q91 same-table/batched validation не запускается.
22. q91 реализовал единый global dispatcher для COPY-text spool, но full-scale
    снова потерял early ranges при idle workers. Второй liveness reject закрыл
    эту scheduler topology; implementation удалена без timing/A/B.
23. q92 реализовал type-correct binary COPY в существующем fused worker.
    Correctness/recovery прошли, но raw ухудшился на `5.09%`, E2E на `1.09%`,
    а stream вырос на `15.24%`. Candidate удалён без A/B.
24. q93 материализовал exact COPY-text payload в tmpfs и отдельно измерил
    PostgreSQL load. W8 normalize занял `252.006 s`, лучший P4 load —
    `123.460 s`; P8 ухудшил load на `3.44%`. Даже идеальный 24-CPU lower bound
    суммы равен `180.331 s`, на `9.89%` хуже q90 raw до recovery overhead.
    Архитектура отклонена без production implementation и A/B.
25. q101 сохранил `1 MiB` chunks, но вернул одну transaction на planned range.
    Две exact crossover-пары дали raw `-7.970%` и E2E `-4.494%` при тех же
    `1 527` checkpoints и WAL `+0.028%`. Accepted transport стал стандартной
    fused implementation; отдельный CLI benchmark flag и array branch удалены.
26. q102 исключил descriptor-specific transport. На одинаковых ranges stream
    выиграл у array на `4.412–8.296%` для всех четырёх representative XML.
    Full trace показал, что houses владел в среднем `2.84` из P4, поскольку
    весь wall-span делил writers с params siblings; hierarchy без file-overlap
    владел `3.77 / 3.64`. Неоднородность q101 объяснена shared admission, а не
    XML shape. Fairness не является global throughput optimization.
27. q103 снял глобальный барьер `all indexes -> all FK validation`. После
    завершения raw и активной early-index задачи все FK атомарно
    восстанавливаются как `NOT VALID`, а оставшиеся indexes и validation идут
    одновременно. Median post-raw сократился `52.460 → 40.801 s` (`-22.22%`),
    E2E `244.742 → 218.986 s` (`-10.52%`). Обе соседние пары прошли gate.
    Исправленная snapshot telemetry показала, что indexes завершались на
    `7.874 / 3.634 s` раньше FK; прежний вывод о разнице `<=44 ms` был
    артефактом joined timestamp.
28. q104 добавил index-first table admission, но post-raw ухудшился на
    `3.797%`, а E2E выиграл только `1.695%` за счёт raw drift. Последняя
    `object_params` FK завершилась на `13.233 s` позже indexes при свободных
    CPU/NVMe. Candidate удалён; общий table barrier закрыт.
29. q105 показал три accurate serial Hash Anti Join по всем `49 147 843`
    строкам `object_params`. PostgreSQL 17 не передаёт parallel cursor option
    из `RI_Initial_Check()`, поэтому worker limits и pre-validation `ANALYZE`
    не являются кандидатами.
30. q106 подтвердил component margin visibility map: fresh `32.354 s` против
    `12.49–12.66 s` вместе с полным VACUUM. q107 cold crossover отклонил
    post-raw placement: обратная пара дала E2E `+0.09%` и post-raw `+24.67%`,
    а медианный выигрыш происходил из unrelated raw drift.
31. q107 telemetry оставила проверяемую q108-гипотезу: writers
    `object_params` заканчиваются за `76.837 s` до raw, а её early indexes —
    за `11.947 s`. Table-local VACUUM перед индексами может скрыть весь cost
    внутри raw и подготовить index-only native validation без нового barrier.
32. q108 опроверг before-index placement: VACUUM занял `2.591 s`, но target
    FK остались `35.861 s`, а post-raw `42.318 s` был хуже q103. Открытые
    transaction horizons не дали установить полезную VM.
33. q109 после table indexes сократил target FK до `19.057 / 22.482 s`, но
    reverse E2E ухудшился на `6.05%`, а index tail вырос
    `31.209 → 36.544 s`. Механизм работает, placement не проходит gate.
34. q110 вынес VACUUM в отдельный AMP process: target FK `24.760 s`, post-raw
    `-6.11%`, но E2E `+0.385%`; index tail был на `1.55%` хуже synchronous
    q109d. Значит, bottleneck — PostgreSQL resource competition, не PHP
    scheduler. Implementation удалена без crossover.
35. q111 пересчитывал `heap bytes × pending index count` после каждого index.
    После третьего `object_params` index scheduler переключился на hierarchy,
    а последний same-table index совпал с FK и вырос `~12 → 34.348 s`.
    Post-raw/E2E ухудшились на `24.63 / 2.59%`; dynamic LPT удалён.
36. q112 на точном `object_params` artifact сократил COPY+PK build почти вдвое.
    q113 перенёс результат в resumable production lifecycle и дал
    raw/E2E `-26.40 / -15.16%` при exact schema parity. q114 завершил
    scheduling frontier: primary-first вытеснил secondary work и ухудшил E2E
    на `3.87%`; принят heap-first I1.
37. q115 отклонил исходный work-orchestrator default `4×2`: raw занял
    `208.225 s` против production-control `142.571 s`, потому что один
    manifest-файл мог использовать только два child workers своего primary.
    Диагностический q116 `1×8` одновременно обнаружил пропуск невыданных
    siblings в `GroupedWorkQueue`; после исправления он дал `102.547 s`.
    Forward migration расширила persisted raw capacity до уже публичных
    `1..8`, а production-valid reverse q117 воспроизвёл
    `103.246 s` против control `143.229 s` (`-27.92%`). Средний эффект двух
    порядков равен `-27.99%`; default raw topology принят как `1×8`.
38. q118–q120 проверили полный lifecycle той же durable operation. После raw
    success production `gar:import` получил `resumed=yes`, не перечитывал
    завершённые XML и восстановил все PK/index/FK snapshots с последующими
    `ANALYZE` и integrity gate. Два свежих control дали `238.492 / 237.605 s`.
    q120 с переходом к finalization не позднее одной секунды завершился за
    `213.255 s` (`-10.42%` к среднему control); консервативный q118 с
    `17.835 s` задержки harness всё равно дал `219.220 s`. Во всех точках
    совпали `122 809 172` строк и оба digest, `102` indexes valid, `34/34` FK
    validated, backlog и cgroup swap равны нулю.

Из исходных `41` q40 B-tree больше нет непроверенного performance frontier:
шесть hierarchy classifier-code indexes отклонены q49, два hierarchy
`end_date` сохранены как используемые predicate paths, оставшиеся `26` закрыты
q85 upper bound и перенесены в schema cleanup. Binary COPY закрыт q92;
`COPY FREEZE` и `wal_level=minimal` остаются изменениями иной
transport/transaction topology, а не configuration knobs. После
q87 текущая очередь исчерпана: q88 должен быть новым diagnostic profile
принятого baseline, а не заранее выбранной настройкой. q88 выполнен и выбрал
q89: final-pool width `2` только для `administrative_reestr_hierarchy`. q89
сократил tail на `7.94%`, но ухудшил E2E на `2.47%`; implementation удалена,
same-table concurrency закрыта совместным evidence q84/q89. q90 затем закрыл
оставшийся FK scheduling frontier: свободные CPU/NVMe нельзя использовать
параллельными validation одной таблицы из-за PostgreSQL lock/execution
semantics. q91 затем закрыл global-dispatcher redesign повторным liveness
failure, q92 — binary COPY измеренным regression, q93 — двухфазный
bulk-load/pre-normalization измеренным и теоретическим lower bound. q102
закрыл последний per-file Strategy frontier. q103 открыл и принял новую
архитектурную ось: final indexes и FK validation теперь перекрываются после
raw. q104 закрыл общий table-aware barrier отрицательным full run. q105/q106
локализовали serial FK scan и visibility-map mechanism, q107–q110 исчерпали
post-raw, before-index и after-index placements. Повтор локальных index/FK
width knobs, VACUUM placement и dynamic queue weights запрещены. Текущий
configuration/scheduling frontier исчерпан. q112/q113 затем приняли временное
снятие PK на empty-database path, а q115–q117 проверили следующую topology axis:
raw work-orchestrator `1×8` принят после exact reverse A/B; `4×2` отклонён.
Нового performance candidate в очереди нет. Raw-only команда по-прежнему
останавливается до index/FK finalization. q118–q120 подтвердили существующий
операционный контракт: совместимый production `gar:import` возобновляет ту же
operation и завершает indexes/FK/ANALYZE/integrity без повторного XML import.
Автоматическая однокомандная orchestration этих стадий остаётся отдельным
архитектурным решением.

Документация API: [AMPHP parallel](https://amphp.org/parallel),
[PostgreSQL COPY](https://www.postgresql.org/docs/17/sql-copy.html),
[PostgreSQL asynchronous commands](https://www.postgresql.org/docs/17/libpq-async.html),
[PHP pg_put_copy_data](https://www.php.net/pg-put-copy-data),
[ext-pgsql socket handler](https://github.com/php/php-src/blob/PHP-8.4.27/ext/pgsql/pgsql.c#L4161-L4167).

## Метрики И Gate

Для каждой ступени фиксируются:

- end-to-end и длительность каждой lifecycle phase;
- source rows/s и bytes/s;
- CPU каждого parser/writer и PostgreSQL backend;
- PostgreSQL wait events, buffer hits/reads, WAL MB/s;
- physical read/write MB/s, device utilization и await;
- database/index size, peak RSS и число active connections;
- files/rows digest, index/constraint definitions, invalid objects и backlog;
- interruption/resume test на import и finalization.

Вариант считается storage-bound только при подтверждённых I/O wait/device
pressure; высокий cumulative `BlockIO` сам по себе этого не доказывает.
В каноническом direct/W4 A/B p95 utilization physical NVMe составил
`19.44%/16.80%` на полном прогоне и `18.24%/13.60%` в target window; p95 write
await оставался `3.25-4.59 ms`. Storage-bound состояние не подтверждено, поэтому
следующий эксперимент должен начинаться с нового CPU/wait/constraint профиля,
а не с автоматического увеличения writers или снятия FK.
На region-50 dataset ступень принимается при end-to-end улучшении не менее
`2%`, нулевых integrity issues и сохранённых recovery invariants. Delta
`2–5%` требует обратного повтора, как указано в scorecard gate; более крупный
эффект также повторяется при заметном host drift. Целевые `100 000 rows/s` —
направление, а не причина пропустить correctness gate.

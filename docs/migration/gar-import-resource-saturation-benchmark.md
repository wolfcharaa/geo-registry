# Benchmark Масштабирования Ресурсов GAR Initial-Load

- Статус: `accept` для `--index-workers=4`, largest-heap-first scheduling,
  all-compatible `--pipeline-file=auto` и fused parallel XML parsing R8 на
  измеренном host, table-aware F2 с fragment `16 MiB`, writer window `64 MiB`
  и batch `100000`, FK validation W4 с largest-referencing-table-first queue,
  а также для удаления неиспользуемых raw-value, hierarchy
  path/path-array, canonical trigram GIN, redundant unit lifecycle B-tree и
  detached GAR version-link/object-parameter lifecycle/change-end/duplicated
  unit-GUID/detached hierarchy cross-link/hierarchy start-update,
  `change_history` metadata и `reestr_objects` metadata B-tree;
  ранний index overlap теперь допускается после `50%` завершённых source bytes;
  standalone W8/P8, two-stage parser R8, fused parser R12, изолированный старый
  batch `100000`, multi-file parser R12, writer P6, broad F3 и fragment
  `32 MiB`, дополнительные PostgreSQL worker/memory/I/O-concurrency настройки,
  удаление hierarchy classifier-code B-tree, EventLoop COPY и два ранних index
  workers, Amp `PosixSemaphore` для межпроцессного admission, one-read
  PHP fragment payload, bounded `SharedMemoryParcel` transport и three-file
  source tmpfs, global COPY-text dispatcher, binary COPY и двухфазные
  pre-normalized COPY-text artifacts отклонены;
  hierarchy-only tmpfs принят как optional source-placement profile только для
  измеренного host с достаточным RAM headroom;
  process-isolated table-ready index overlap с одним поздно допускаемым worker
  принят и заменяет q64 как E2E execution baseline
- Дата: `2026-11-15`
- Цель: после принятого deferred-FK пути найти следующий предел CPU/NVMe без
  ослабления durability, resume и exact correctness

Перемещение критического пути между raw, overlap, post-raw tail и finalization
ведётся отдельно в [журнале bottleneck](gar-import-bottleneck-ledger.md).

## Условия

Все точки используют тот же workload и production PostgreSQL profile, что
[benchmark deferred FK](gar-import-foreign-key-lifecycle-benchmark.md):

- GAR `2026-09-01 v.288`, region `50` плюс shared dictionaries;
- `28` XML, `24 975 511 491` source bytes и `122 809 172` rows;
- manifest `6ac1fa709834d629852939a2f6576a6db5d8ae1de47fd1438fc1e24b82953229`;
- cold source перед каждым прогоном;
- accepted q63 point использует
  `--batch-size=100000 --initial-load --direct-copy --defer-foreign-keys`;
- accepted q50 использует `--workers=12 --pipeline-writers=4
  --pipeline-parsers=8 --pipeline-fused-ranges`;
- accepted q54r дополнительно использует
  `--overlap-table-indexes --overlap-index-workers=1`;
- accepted q61 дополнительно использует `--foreign-key-workers=4`; CLI default
  остаётся `2` для неизвестного/shared host;
- accepted q63g дополнительно использует
  `--pipeline-file-concurrency=2 --pipeline-buffer-mib=64
  --pipeline-fragment-mib=16 --pipeline-table-aware-files`;
- `fsync=on`, `synchronous_commit=on`, `full_page_writes=on`;
- PostgreSQL `17.11`, `shared_buffers=8GB`, `maintenance_work_mem=2GB`,
  `max_parallel_maintenance_workers=4`, `max_wal_size=64GB`;
- disposable empty database и полная финализация до durable `ready`.
- base revision `c9f8985`; q23 application/test diff SHA-256
  `adc703a2eb1637fd6b974986bb52a0d0f66414a747e1b8b10ffde51564723c67`.
- measured q24 run source/test/dev-image diff SHA-256
  `474f9f098774dc9549f02987113c9a4de92652aad0f159466f09af1782f56f27`.
  После замера byte guard уточнён pre-flush проверкой границы `8 MiB`; это не
  меняет измеренную selection/concurrency точку и отдельно прошло unit и
  integration gates.
- Финальная q33 реализация PHP source/test имеет staged diff SHA-256
  `8ce8abe3e8c7261db3b8ee6d8869e9eb8b91ab4fcadf736ca63f0586aca8e526`.
  От измеренного q33 она отличается только удалением повторного чтения identity
  attribute в прежнем single-XMLReader пути; unit/integration/full project gates
  повторены после исправления.
- Финальная q36 реализация source/test имеет staged diff SHA-256
  `e1506333731e3308953435b158ab74eed4baedb4259f895f92f58f81047fdc60`;
  после измерения добавлены только fail-closed R9 unit boundary и документация.
- Финальная q38 реализация source/test имеет staged diff SHA-256
  `d33505c45b8312238d5c9afc159183a5b94723dea4067f3fa05d2d273221d88a`.

Baseline — принятый W4/P4 с двумя index workers: raw `527.337663 s`, index
build `354.755386 s`, E2E `919.935230 s`. Серия меняет по одному фактору:

1. q20: global file workers `4 → 8`;
2. q21: index workers `2 → 3`, возвращая file workers к `4`;
3. q22: index workers `3 → 4`;
4. q23: при I4 queue tables упорядочены по фактическому heap size descending.
5. q24: bounded pipeline автоматически применяется к одному крупнейшему
   direct-compatible XML каждой data dependency phase.
6. q25: общий/pipeline budget увеличен `W4/P4 → W8/P8` при неизменном I4,
   auto selection и byte-bounded очереди.
7. q26: batch `50000 → 100000` при принятом `16 MiB` in-flight window.
8. q27: корректирующий B100 repeat с `32 MiB` window, восстанавливающим
   фактический peak P4.
9. q28: PostgreSQL worker ceilings `16/12/4 → 24/20/8` при неизменном I4.
10. q29: `effective_io_concurrency/maintenance_io_concurrency 200/100 → 32/16`.
11. q30/q31: парная проверка `maintenance_work_mem 2 → 4GB`.
12. q32: `shared_buffers 8 → 12GB` при возвращённом `maintenance_work_mem=2GB`.
13. q33: opt-in two-stage byte-range parser R4 и прежний durable COPY pool P4
    под общим W8 budget.
14. q34/q35: parser-only scaling R8; сначала штатный PHP `512M`, затем
    диагностический repeat с `1G`.
15. q36: fused R8 удерживает parsed rows в range worker до bounded P4 COPY,
    исключая оба больших IPC payload при штатном PHP `512M`.
16. q37: после cold parser-only R10/R12 probe fused width увеличен
    `W12/R8 → W16/R12` при неизменных P4/I4 и PHP `512M`.
17. q38: `auto` выбирает все direct-compatible XML выше priority
    `10`; внутри фазы они последовательно используют общий R8/P4
    fused importer, а incompatible siblings остаются ordinary workers.
18. q39: forward migration удаляет `idx_object_params_value`, потому что raw
    `object_params` служит входом нормализации, а приложение не выполняет
    trigram/`LIKE`/`ILIKE` поиск по `value`.
19. q40: read-only source/constraint/plan audit классифицирует все `205`
    canonical indexes: `68` обязательных и `137` removal candidates.
20. q41: отдельная forward migration удаляет только четыре hierarchy
    `path`/`path_arr` GIN, для которых текущий SQL не содержит совместимого
    indexable operator.
21. q42: отдельная forward migration удаляет оставшиеся `28` canonical
    `gin_trgm_ops`; normalized `address_search`, aliases, DataMos, OpenSearch и
    metadata `gar_versions` не входят в этот removal set.
22. q43: отдельная forward migration удаляет `30` standalone lifecycle B-tree
    на шести object/unit tables, сохраняя их используемые partial
    active/current composites.
23. q44: отдельная forward migration удаляет `16` `prev_id`/`next_id` B-tree
    на восьми canonical tables после ранее принятого снятия их self-FK.
24. q45: отдельная forward migration удаляет три standalone
    `object_params` date B-tree, сохраняя partial denorm composite и FK indexes.
25. q46: отдельная forward migration удаляет
    `idx_object_params_change_id_end`; raw-колонка сохраняется, а обязательные
    PK, denorm composite и FK indexes остаются без изменений.
26. q47: отдельная forward migration удаляет шесть B-tree по дублирующим
    unit UUID; authoritative unique registry UUID и numeric FK indexes
    сохраняются. После шумной первой точки выполнены unchanged repeat и
    reverse-order physical-schema control.
27. q48: отдельная forward migration удаляет четыре B-tree по detached
    `parent_obj_id`/`change_id` обеих hierarchy tables. После шумной первой
    точки выполнены две соседние crossover-пары с обратным A/B порядком.
28. q49: шесть hierarchy classifier-code B-tree удалены только в эксперименте;
    обе пары ухудшили index-фазу, поэтому migration и schema change отклонены.
29. q50: отдельная forward migration удаляет четыре B-tree по hierarchy
    `start_date`/`update_date`, сохраняя оба используемых `end_date` индекса;
    выполнены две соседние пары с обратным A/B порядком.
30. q51: disposable ext-pgsql probe сравнивает blocking, unbounded и bounded
    EventLoop COPY с socket readiness, backpressure и атомарным checkpoint.
31. q52: exact-runtime EventLoop COPY совмещён с table-ready index scheduler;
    во время raw разрешены четыре ранних index workers.
32. q53: ранний index budget уменьшен `4 → 1`, а после raw scheduler снова
    использует принятые четыре index workers.
33. q54/q54r: blocking COPY отделён от EventLoop transport, ранний I1 overlap
    повторён в чистом storage window; после обнаруженного IPC hang scheduler
    вынесен в отдельный CLI-процесс и повторён без изменения SQL workload.
34. q55: EventLoop COPY измерен без overlap.
35. q56: чистый blocking COPY без overlap служит разделяющим control.
36. q57: ранний index budget увеличен `1 → 2` при process isolation.
37. q58: disposable PHP 8.4 image с `ext-sysvmsg` проверяет Amp
    `PosixSemaphore` при штатном release, `SIGKILL` владельца permit и
    `SIGKILL` процесса-инициализатора.
38. q59: три неиспользуемых `change_history` B-tree удалены с reverse-order
    crossover; принят storage/WAL effect, но не заявлено E2E-ускорение.
39. q60: FK validation width сравнен на `2/3/4` процессах и подтверждён
    обратной W4→W2 парой.
40. q61: поверх W4 очередь стала largest-referencing-table-first и повторена
    без изменения immutable fixture.
41. q62: общий Amp Pipeline запускает до двух XML одной dependency phase на
    едином R/P/byte admission; reverse F1 отделяет raw overlap от host drift.
42. q63a–q63c: отдельно проверены R12, writer window M32 и B100 при прежнем
    fragment G8.
43. q63d: fragment/window увеличены G8/M32 → G16/M64 и подтверждены
    repeat/reverse crossover.
44. q63e/q63f: отдельно проверены COPY width P6/M96 и file concurrency F3.
45. q63g: table-aware F2 сравнен с соседним broad-F2 reverse control.
46. q63h: последний fragment step G32/M128 проверен на table-aware профиле.
47. q64: четыре standalone `reestr_objects` metadata B-tree удалены в двух
    соседних crossover-парах candidate/control и control/candidate.
48. q65: admission раннего I1 отложен до `50%` завершённых source bytes и
    подтверждён crossover-парами late/control и control/late.
49. q66: запись pipeline peak сделана edge-triggered только на новом
    process-local high-water и проверена двумя crossover-парами.
50. q67: `opcache.enable_cli=1` применён ко всей process tree через отдельный
    INI profile и сравнен с текущим CLI default.
51. q68: tracing JIT сравнен с JIT-off при включённом CLI OPcache; raw gate и
    whole-run gate оценены отдельно.
52. q69: Alpine/musl сравнен с Debian/glibc на PHP `8.4.27`, libuv `1.52.1`
    и libpq `18.6` с одинаковыми extensions и effective INI.
53. q70: Alpine native allocations перенаправлены в jemalloc `5.3.0` через
    `LD_PRELOAD`, сохраняя Zend Memory Manager и enforceable `memory_limit`.
54. q71: PHP heap передан напрямую jemalloc через `USE_ZEND_ALLOC=0`; обе
    стороны crossover ограничены одинаковым hard cgroup `2 GiB` без swap.
55. q72: tracing JIT включён только в Amp fused parser/COPY workers; parent,
    ordinary file, index и FK processes сохранили CLI OPcache/JIT off.
56. q73: отдельный CLI bootstrap на каждый из `77` index snapshots заменён
    opt-in persistent Amp pool; две crossover-пары проверили E2E и recovery.
57. q74: семь metadata statements после каждого fused COPY batch сокращены до
    трёх через CTE; crossover показал смену знака и вариант удалён.
58. q75: scoped EventLoop probe отделил scheduling lag от active time
    blocking range planner и обосновал эксперимент с кооперативным чтением.
59. q76: yield после каждого `8 MiB` planner chunk проверен полным crossover;
    reverse-пара осталась нейтральной, поэтому вариант удалён.
60. q77: настоящий async file source через `amphp/file 3.2.0` и
    `UvFilesystemDriver` дал сильную первую пару, но reverse полностью сменил
    знак; dependency и implementation удалены.
61. q78: one-read PHP fragment payload проверен полным crossover; меньший tail
    не компенсировал более длинный raw и рост RSS, вариант удалён.
62. q79: три critical-path XML помещены на tmpfs; raw ускорился, но staging,
    swap pressure и post-raw tail сделали полный профиль неприемлемым.
63. q80: только `AS_ADM_HIERARCHY` помещён на tmpfs; обе staged E2E-пары
    прошли gate при неизменной correctness и без нового swap pressure.
64. q81: bounded G16 fragments переданы fused worker через
    `SharedMemoryParcel + PassthroughSerializer`; первая пара выиграла, но
    reverse-пара сменила знак, поэтому runtime path и extensions удалены.

Начиная с q36, подтверждённое улучшение whole raw, изменяемой index-фазы или
E2E на `>=2%` считается существенным при обязательных exact, recovery и
resource gates. Ускорение только одного target XML не принимается.

`--index-workers` является execution knob и не входит в durable operation
identity: при resume число процессов можно изменить. Scheduler на всех точках
запрещает одновременно строить два индекса одной таблицы.

## Результаты

| Точка | Raw import | Index build | FK validate | End-to-end | Решение |
| --- | ---: | ---: | ---: | ---: | --- |
| W4/P4, I2 baseline | `527.338 s` | `354.755 s` | `35.860 s` | `919.935 s` | baseline |
| W8/P4, I2 | `534.108 s` | `365.111 s` | `33.385 s` | `934.536 s` | reject |
| W4/P4, I3 | `534.358 s` | `279.378 s` | `35.145 s` | `851.179 s` | diagnostic accept |
| W4/P4, I4 | `544.573 s` | `246.193 s` | `33.536 s` | `826.464 s` | accept pool width |
| W4/P4, I4 + LPT | `558.872 s` | `208.455 s` | `34.783 s` | `804.030 s` | accept scheduling |
| W4/P4, I4 + LPT + auto | `370.380 s` | `230.770 s` | `35.736 s` | `638.827 s` | accept multi-file pipeline |
| W8/P8, I4 + LPT + auto | `367.423 s` | `229.439 s` | `35.339 s` | `634.160 s` | reject: pipeline-width plateau |
| B100/W16, W4/P4, I4 + auto | `406.637 s` | `224.574 s` | `36.215 s` | `669.391 s` | reject: effective peak P2 |
| B100/W32, W4/P4, I4 + auto | `407.183 s` | `241.921 s` | `36.701 s` | `687.782 s` | reject: batch-size regression |
| PG workers 24/20/8 | `371.499 s` | `233.028 s` | `37.647 s` | `644.151 s` | reject: parallel-worker plateau |
| PG I/O concurrency 32/16 | `425.982 s` | `241.026 s` | `38.451 s` | `707.513 s` | reject: regression |
| maintenance memory 4GB, q30 | `404.556 s` | `225.827 s` | `35.590 s` | `667.819 s` | diagnostic repeat required |
| maintenance memory 4GB, q31 | `414.029 s` | `235.079 s` | `36.035 s` | `687.110 s` | reject: no reproduced gain |
| shared buffers 12GB | `430.082 s` | `229.457 s` | `38.207 s` | `699.630 s` | reject: memory cost without gain |
| W8/R4/P4, I4 + auto | `273.849 s` | `223.243 s` | `54.332 s` | `553.456 s` | accept parallel parser |
| W12/R8/P4, PHP 512M | — | — | — | — | reject: PHP OOM at 53.3M rows |
| W12/R8/P4, PHP 1G | `274.150 s` | `209.641 s` | `47.713 s` | `533.361 s` | reject: raw plateau, memory cost |
| W12/R8-fused/P4, PHP 512M | `258.664 s` | `212.934 s` | `39.446 s` | `513.304 s` | accept: raw and E2E gain, lower RSS |
| W16/R12-fused/P4, PHP 512M | `260.274 s` | `221.061 s` | `50.729 s` | `534.105 s` | reject: target-only gain, raw plateau |
| W12/R8-fused/P4, all-compatible auto | `190.187 s` | `228.471 s` | `36.293 s` | `458.953 s` | accept: raw `-26.473%`, E2E `-10.588%` |
| q39 без raw-value GIN | `174.759 s` | `183.966 s` | `36.546 s` | `397.210 s` | accept: index `-19.479%`, E2E `-13.453%` |
| q41 без hierarchy path GIN | `178.113 s` | `149.424 s` | `36.648 s` | `366.205 s` | accept: index `-18.776%`, E2E `-7.806%` |
| q42 без canonical trigram GIN | `181.758 s` | `145.245 s` | `33.745 s` | `362.674 s` | accept: index `-2.797%`; E2E `-0.964%` diagnostic |
| q43 без unit lifecycle B-tree | `197.127 s` | `136.046 s` | `34.964 s` | `370.127 s` | accept: repeat index `-6.333%`; E2E `+2.055%` diagnostic |
| q44 без detached version-link B-tree | `205.658 s` | `114.900 s` | `35.763 s` | `358.321 s` | accept: index `-15.544%`; E2E `-3.190%` |
| q45 без object-parameter lifecycle B-tree | `199.644 s` | `108.691 s` | `35.246 s` | `345.505 s` | accept: repeat index `-5.404%`; E2E `-3.577%` |
| q46 без object-parameter change-end B-tree | `202.446 s` | `98.366 s` | `34.151 s` | `336.922 s` | accept: index `-9.500%`; E2E `-2.484%` |
| q47 без duplicated unit-GUID B-tree | `200.360 s` | `85.301 s` | `35.749 s` | `323.592 s` | accept: repeat index `-13.282%`; paired control `-12.472%` |
| q48 без detached hierarchy cross-link B-tree | `205.219 s` | `78.046 s` | `33.792 s` | `319.045 s` | accept: crossover pairs index `-3.553% / -11.442%`; unpaired median `-0.281%` diagnostic |
| q49 без hierarchy classifier-code B-tree | `200.273 s` | `88.416 s` | `34.038 s` | `324.783 s` | reject: paired index `+11.913% / +14.837%` despite lower storage |
| q50 без hierarchy start/update B-tree | `215.620 s` | `76.990 s` | `34.890 s` | `329.456 s` | accept: crossover pairs index `-26.053% / -4.427%`; E2E diagnostic |
| q52 async COPY + overlap I4 | `248.147 / 312.599 s` | `11.201 / 10.766 s` после raw | `71.414 / 64.796 s` | `332.845 / 391.323 s` | correctness accept; performance invalid: external NVMe load |
| q53 async COPY + overlap I1 | `300.331 s` | `12.146 s` после raw | `58.385 s` | `373.005 s` | correctness accept; performance invalid: external NVMe load |
| q54/q54r blocking COPY + overlap I1 | `238.671 / 235.907 s` | `11.465 / 11.244 s` после raw | `48.192 / 56.925 s` | `301.497 / 306.072 s` | accept: E2E `-5.419% / -3.984%` к q56 |
| q55 EventLoop COPY без overlap | `193.571 s` | `90.357 s` | `40.397 s` | `328.411 s` | reject: E2E `+3.023%` к blocking control |
| q56 blocking COPY без overlap | `184.097 s` | `92.151 s` | `39.501 s` | `318.773 s` | clean control |
| q57 blocking COPY + overlap I2 | `255.398 s` | `10.684 s` после raw | `46.509 s` | `314.543 s` | reject: только `-1.327%` к q56, `+2.768%` к I1 repeat |
| q61 W4 + LPT FK repeat | `250.473 s` | `10.492 s` после raw | `31.490 s` | `294.339 s` | accept: measured-host FK baseline |
| q63d broad F2, B100/M64/G16 | `207.175 / 228.240 s` | `27.745 / 29.090 s` после raw | `31.445 / 32.391 s` | `269.217 / 291.793 s` | accept fragment: crossover mean E2E `-5.535%` к G8/M32 |
| q63e P6/M96/G16 | `214.712 s` | `26.630 s` после raw | `33.688 s` | `276.896 s` | reject: к среднему P4 E2E только `-1.29%` |
| q63f broad F3 | `222.518 s` | `26.738 s` после raw | `30.879 s` | `281.972 s` | reject: E2E `+0.52%` к среднему F2 |
| q63g table-aware F2/G16 | `229.370 s` | `10.756 s` после raw | `32.163 s` | `274.152 s` | accept: соседний broad control E2E `287.105 s`, delta `-4.512%` |
| q63h table-aware F2/G32 | `226.602 s` | `11.159 s` после raw | `34.701 s` | `274.368 s` | reject: batches `-19.12%`, E2E `+0.08%` |
| q64 без четырёх `reestr_objects` metadata B-tree | `199.462 / 224.763 s` | `17.078 / 11.453 s` после raw | `35.340 / 32.925 s` | `253.748 / 270.987 s` | accept: crossover E2E `-5.820% / -3.752%` |
| q65 late I1 после 50% source bytes | `186.764 / 208.229 s` | `29.437 / 18.707 s` после raw | `30.330 / 34.695 s` | `250.338 / 263.426 s` | accept: crossover E2E `-8.559% / -4.241%` |
| q66 edge-triggered peak | `178.169 / 183.049 s` | `27.985 / 27.604 s` после raw | `28.220 / 28.063 s` | `238.168 / 242.531 s` | reject: E2E pairs `+2.066% / +0.209%` |
| q67 global CLI OPcache | `190.900 / 186.271 s` | `19.001 / 30.297 s` после raw | `28.483 / 28.970 s` | `242.171 / 249.394 s` | reject: E2E pairs `+6.784% / +1.733%` |
| q68 global tracing JIT | `185.885 / 183.397 s` | `32.743 / 25.379 s` после raw | `31.033 / 29.030 s` | `251.467 / 241.542 s` | reject global: raw mean `-2.604%`, E2E mean only `-0.540%` |
| q69 Debian/glibc PHP runtime | `188.266 / 179.975 s` | `19.707 / 26.506 s` после raw | `28.927 / 31.202 s` | `240.693 / 239.408 s` | reject: pair signs differ; mean raw `+2.022%` |
| q70 native-only jemalloc | `192.128 / 165.428 s` | `21.662 / 29.875 s` после raw | `28.780 / 29.534 s` | `244.266 / 228.299 s` | reject: pair signs differ; mean raw `-0.973%`, RSS `+4.74%` |
| q71 direct jemalloc без Zend MM | `190.618 / 191.972 s` | `22.218 / 21.113 s` после raw | `28.484 / 28.174 s` | `245.129 / 245.032 s` | reject: E2E `+5.834% / +2.818%`, RSS mean `+31.63%` |
| q72 selective tracing JIT | `161.933 / 203.797 s` | `31.930 / 18.595 s` после raw | `28.972 / 29.480 s` | `226.590 / 255.634 s` | reject: E2E pairs `-4.273% / +1.940%`, mean gain `1.077%` |
| q73 persistent index workers | `173.787 / 190.792 s` | `29.060 / 17.368 s` после raw | `28.595 / 28.728 s` | `235.217 / 240.613 s` | accept: crossover E2E `-3.559% / -2.416%`, mean `-2.984%` |
| q74 metadata CTE | `166.592 / 201.846 s` | `36.283 / 21.177 s` после raw | `27.585 / 28.830 s` | `234.196 / 254.669 s` | reject: crossover E2E `-10.322% / +6.502%`, mean `-2.281%` без воспроизводимости |
| q76 cooperative range planner | `176.645 / 193.804 s` | `24.623 / 18.491 s` после raw | `28.136 / 28.592 s` | `233.229 / 243.638 s` | reject: crossover E2E `-2.510% / -0.224%`, raw сменил знак |
| q77 async file planner | `167.113 / 205.550 s` | `31.051 / 20.454 s` после raw | `27.833 / 29.291 s` | `229.775 / 259.054 s` | reject: crossover E2E `-5.589% / +5.842%`, mean `+0.143%` |
| q80 hierarchy-only tmpfs | `163.051 / 180.010 s` | `33.422 / 26.788 s` после raw | `28.480 / 28.538 s` | staged `231.349 / 242.684 s` | accept optional measured-host profile: staged crossover `-4.227% / -6.038%` |
| q81 bounded shared-memory fragments | `172.369 / 193.411 s` | `31.010 / 20.329 s` после raw | `28.193 / 29.140 s` | `235.350 / 246.715 s` | reject: E2E pairs `-3.148% / +2.465%`, mean gain only `0.354%`, RSS `+14.3%` |

Global W8 не увеличил фактический fan-out: raw стал медленнее на `1.284%`, а
E2E — на `1.587%`. Per-file timestamps объясняют результат dependency
barriers. Critical path последовательно проходит примерно через:

- `reestr_objects` — `59.8 s`;
- `normative_docs` — `10.0 s`;
- `change_history` — `145.8 s`;
- houses parameters pipeline — `109.0 s`;
- houses — `48.3 s`;
- `administrative_reestr_hierarchy` — `158.7 s`.

Внутри этих фаз больше четырёх независимых крупных файлов обычно нет. Поэтому
`--workers=4` уже покрывает межфайловую параллельность, а W8 простаивает на
barriers. Следующий raw gain требует intra-file fan-out для нескольких типов
XML, а не дальнейшего роста global workers.

I3 сократил index phase на `21.248%`, I4 — на `30.602%` относительно I2.
Largest-heap-first scheduling дополнительно дал `-15.329%` к I4 и довёл полный
index gain до `41.240%` (`-146.301 s`). Несмотря на raw variance `+31.535 s`,
q23 сократил E2E на `12.599%` (`-115.905 s`) относительно baseline.

q24 выбирает шесть pipeline-файлов в priorities `20/30/40/50/70/80` и
сократил raw на `33.727%` (`-188.492 s`), а E2E — на `20.547%`
(`-165.203 s`) относительно q23. Houses parameters уже использовал P4 и
остался практически неизменным: `109.126 → 109.400 s`. Выигрыш пришёл из
ранее последовательных critical-path файлов:

- `reestr_objects`: `64.556 → 23.221 s` (`-64.03%`);
- `normative_docs`: `10.315 → 7.094 s` (`-31.22%`);
- `change_history`: `149.842 → 73.817 s` (`-50.74%`);
- `houses`: `49.930 → 30.466 s` (`-38.98%`);
- administrative hierarchy: `172.447 → 112.936 s` (`-34.51%`).

Municipal hierarchy остался ordinary sibling, но выполнялся одновременно с
pipeline administrative hierarchy и сократился до `101.663 s`. Raw pipeline
записал `1 785` batches / `9 037 232 057` encoded bytes при peak `4` batches /
`16 760 581 B`.

Первый q24 diagnostic run обнаружил отдельный runtime limit: wide
`normative_docs` исчерпал development PHP default `128 MiB` при `46.3 MiB`
encoded in-flight. Development image выровнен с уже существующим production
limit `512M`. Дополнительно pipeline ограничивает batch `8 MiB`, а суммарные
in-flight encoded bytes — `16 MiB`; row limit `50000` остаётся верхней
границей. Принятый повтор достиг importer RSS max `405.9 MiB` для всего
контейнера с supervisor, ordinary workers и batch writers, не одного PHP
process.

q25 подтвердил, что writer pool больше четырёх уже не является raw bottleneck:
raw улучшился только на `0.798%` (`-2.957 s`), а E2E — на `0.731%`
(`-4.668 s`) к q24. Это ниже заранее установленного `<3%` plateau gate.
Pipeline действительно достиг peak `8` batches / `33 554 377 B`, но крупнейший
houses-parameters XML остался неизменным: `109.400 → 109.372 s` (`-0.026%`).
Остальные pipeline-файлы дали смешанную variance: administrative hierarchy
`-3.811%`, change history `+1.379%`, houses `+2.343%`, normative docs
`+2.737%`, reestr objects `+2.073%`. Основная видимая экономия пришла от
ordinary municipal hierarchy (`-8.369%`), а не от P8.

q26 сократил число pipeline tasks `1785 → 1119`, но два близких к `8 MiB`
batch заполнили accepted `16 MiB` window, поэтому фактический peak упал P4 →
P2. Raw ухудшился на `9.789%` (`+36.257 s`), E2E — на `4.784%`
(`+30.564 s`) к q24. Корректирующий q27 расширил только window до `32 MiB` и
вернул peak P4 (`4` batches / `33 554 232 B`), но raw всё равно ухудшился на
`9.937%` (`+36.803 s`), E2E — на `7.663%` (`+48.955 s`). Houses parameters
вырос до `116.095 s` (`+6.120%`). Следовательно, B100 отклонён независимо от
writer concurrency: меньшая task count не компенсирует более крупные latency
boundaries и burstier writes.

q28 фактически использовал расширенный PostgreSQL pool: число parallel workers
достигало нового global limit `20`. Однако index build ухудшился на `0.979%`,
E2E — на `0.833%`, а index-window p95 write await вырос `20.52 → 26.34 ms`.
Поэтому профиль сохраняет `max_worker_processes=16`,
`max_parallel_workers=12`, `max_parallel_maintenance_workers=4`.

q29 проверил `effective_io_concurrency=32` и
`maintenance_io_concurrency=16`. Это не увеличение относительно принятого
`200/100`, а снижение. В PostgreSQL 17 первый параметр управляет асинхронным
prefetch bitmap heap scans, второй — аналогичным I/O для maintenance operations;
они не задают число одновременно записывающих COPY backends
([PostgreSQL resource consumption](https://www.postgresql.org/docs/17/runtime-config-resource.html)).
Index build ухудшился на `4.444%`, E2E — на `10.752%`, index-window p95 write
await вырос `20.52 → 32.30 ms`. Значения `200/100` сохранены.

q30 показал `-2.142%` index time при `maintenance_work_mem=4GB`, но контрольный
q31 дал `+1.868%`. Среднее двух index runs — `230.453 s` против q24
`230.770 s`, выигрыш всего `0.137%`; 4GB отклонены. q32 с
`shared_buffers=12GB` улучшил index только на `0.569%`, одновременно ухудшив
raw на `16.119%` и E2E на `9.518%`. PostgreSQL max RSS достиг `17 438.72 MiB`,
а live host available memory падала примерно до `5 GiB`; профиль остаётся на
`shared_buffers=8GB`, `maintenance_work_mem=2GB`.

q33 распараллелил только producer-side XML decode: parent быстро планирует
непересекающиеся byte ranges по точному depth-1 element token, R4 worker pool
повторно разбирает каждый fragment через libxml и передаёт готовые batches в
неизменённый P4 durable COPY ledger. Raw сократился `370.380 → 273.849 s`
(`-26.063%`), houses-parameters `109.400 → 49.613 s` (`-54.650%`), E2E
`638.827 → 553.456 s` (`-13.364%`). Pipeline записал `2 107` batches и те же
`9 037 232 057` encoded bytes; writer peak остался `4` batches /
`16 775 862 B`. Unit, integration, SIGTERM/resume и полный exact gate прошли.

Изолированный parser продолжал масштабироваться: R4/R6/R8 дали
`73.79/55.70/45.71 s` при одинаковых `29 329 019` rows и
`2 363 492 345` encoded bytes. Но q34 с R8 исчерпал штатные PHP `512M` в
`NativeSerializer`; завершённая часть остановилась на `53 256 919` rows, а
disposable database после диагностики была удалена. q35 с PHP `1G` прошёл
exact gate и ускорил target до `38.355 s`, однако whole raw остался
`274.150 s` (`+0.110%` к q33), а importer max RSS вырос `800.70 → 1 242.11
MiB`. Более удачные index/FK timings улучшили E2E, но не доказывают raw gain от
R8. Поэтому two-stage R8/1G отклонён; q35 сохранил для этого пути parser limit
`1..4` и открыл проверку другой transport topology в q36.

q36 сохранил parsed rows внутри range worker: child отправляет parent только
`firstRow/lastRow/encodedBytes`, ждёт P4/`16 MiB` permit и затем выполняет
неизменённый atomic COPY/checkpoint. Это устранило OOM q34 без увеличения PHP
limit. К q33 raw сократился `273.849 → 258.664 s` (`-5.545%`), target
`49.613 → 32.826 s` (`-33.835%`), E2E `553.456 → 513.304 s` (`-7.255%`).
Raw самостоятельно проходит `>=2%` gate, поэтому решение не зависит от более
быстрых index/FK фаз. Exact gate и SIGTERM/resume integration прошли.

Перед q37 cold parser-only R10/R12 сохранил exact `29 329 019` rows /
`2 363 492 345` encoded bytes и дал `39.80/36.95 s`; R12 был на `19.17%`
быстрее R8 `45.71 s`. Полный workload не подтвердил перенос этого gain: target
улучшился `32.826 → 32.077 s` (`-2.280%`), но raw ухудшился
`258.664 → 260.274 s` (`+0.622%`), E2E — `513.304 → 534.105 s` (`+4.052%`).
Address-object parameters, reestr objects и change history регрессировали на
`11.97/5.23/2.35%`; dependency scheduling и COPY contention перекрыли локальный
parser gain. q37 отклонён, public fused limit остаётся R8.

q38 убрал ordinary single-reader tails из data phases, не меняя R8/P4
транспорт. К q36 raw сократился `258.664 → 190.187 s`
(`-26.473%`), target houses parameters — `32.826 → 30.846 s` (`-6.030%`),
E2E — `513.304 → 458.953 s` (`-10.588%`). Крупнейшие выигрыши:

- municipal hierarchy: `98.027 → 35.318 s` (`-63.97%`);
- steads parameters: `70.126 → 11.251 s` (`-83.96%`);
- apartments parameters: `66.815 → 11.159 s` (`-83.30%`);
- apartments: `42.844 → 8.012 s` (`-81.30%`).

Index build одного q38 вырос `212.934 → 228.471 s` (`+7.297%`), но
raw и E2E с запасом прошли предобъявленный whole-run gate. Pipeline
обработал `3 043` batches / `13 188 317 637` encoded bytes с peak P4 /
`16 775 862 B`; следовательно, выигрыш получен из scheduling большей
доли известного workload, а не из ослабления bounds.

q39 проверил необходимость длинного index tail до изменения scheduler. Raw
GAR-таблицы являются входом нормализации; все application reads
`object_params` фильтруют по `object_id`, `type_id`, датам и порядку обновления.
`value` только читается, а `LIKE`/`ILIKE`, regexp и similarity к нему не
применяются. На наполненной базе план нормализации использует
`idx_object_params_denorm_type_object_update`; прежний
`idx_object_params_value` выбирался только искусственным trigram-предикатом.

Forward migration удалила этот GIN, сохранив exact rollback definition. К q38
index build сократился `228.471 → 183.966 s` (`-19.479%`), E2E —
`458.953 → 397.210 s` (`-13.453%`). Raw также оказался быстрее на `8.112%`,
но removal не может влиять на фазу, где secondary indexes в обеих точках уже
сняты; эта delta считается host variance, а не эффектом q39. Самостоятельный
index gate с запасом проходит порог `2%`.

q40 затем проверил не только statistics counters, а весь canonical schema:
production SQL, normalization, integrity/recovery paths, PK/unique и все `34`
FK. Из `205` индексов `68` имеют конкретный контракт, `137` стали кандидатами
на поэтапное удаление. Первым отдельным набором выбраны четыре GIN на
`administrative_reestr_hierarchy.path/path_arr` и
`municipal_reestr_hierarchy.path/path_arr`. Согласно
[матрице GIN PostgreSQL 17](https://www.postgresql.org/docs/17/gin.html#GIN-BUILTIN-OPCLASSES),
array GIN обслуживает `&&`, `@>`, `<@` и array equality; текущий
`value = ANY(path_arr)` даёт parallel seq scan. Trigram `path` в production SQL
не фильтруется вовсе.

q41 удалил только этот набор обратимой concurrent migration. К q39 index build
сократился `183.966 → 149.424 s` (`-18.776%`), E2E —
`397.210 → 366.205 s` (`-7.806%`). Raw вырос на `1.920%`; secondary indexes в
обоих прогонах сняты до raw-фазы, поэтому delta ниже принятого `2%` gate
считается host variance. Крупнейший target XML был медленнее на `4.492%`, но
whole raw остался внутри gate, а target-only delta не определяет решение.

q42 удалил оставшиеся `28` trigram GIN на canonical import tables одной
обратимой concurrent migration. Первый cold run сохранил exact parity и сократил
index build до `132.425 s`, но его raw/E2E исключены из сравнения из-за
подтверждённого пользователем подвисания host и swap activity. Идентичный cold
repeat без изменения параметров дал raw `181.758 s`, index `145.245 s`, FK
validation `33.745 s` и E2E `362.674 s`. К q41 index-фаза улучшилась на
`2.797%` (`-4.179 s`) и прошла gate; E2E `-0.964%` остаётся диагностическим.
Raw variance `+2.046%` не приписывается schema change: в обеих точках secondary
indexes сняты до COPY, а target XML в q42 был быстрее на `2.899%`.

Populated `EXPLAIN` текущего suggestion predicate даже при доступном
`idx_address_objects_name` выбирает
`idx_address_objects_denorm_active_reestr`, после чего фильтрует объединённые
`lower`/`ILIKE`/`similarity` условия. Остальные `27` колонок не имеют raw fuzzy
consumer. Normalized search GIN в `address_search`, aliases и DataMos этой
migration не затронуты.

q43 проверил отдельный B-tree replacement contract: на `address_objects`,
`houses`, `apartments`, `rooms`, `steads` и `car_places` текущие unit lookups
начинаются с object identity, требуют active/actual row и сортируют по
update/id. Все шесть populated plans используют retained partial
`*_denorm_active_*` composites; `end_date` применяется как post-lookup filter.
Поэтому отдельная reversible migration удалила по пять standalone
`start_date/end_date/update_date/is_active/is_actual` indexes с каждой таблицы.

Первый q43 сохранил exact parity и улучшил index build на `3.399%`. Неизменный
cold repeat подтвердил результат сильнее: `145.245 → 136.046 s` (`-6.333%`),
при target XML `+0.280%`. Raw `+8.455%` и вызванный им E2E `+2.055%` считаются
host variance, потому что secondary indexes отсутствуют в raw-фазе обеих точек.
Обе q43 index-фазы независимо прошли `2%` gate; решение не распространяется на
standalone date/flag indexes других query families.

q44 проверил единый контракт raw version lineage. Миграция
`M260902100000RelaxGarVersionLinks` уже сняла все self-FK по `prev_id` и
`next_id`: GAR может ссылаться на версию, отсутствующую в текущей выгрузке.
Полный source inventory не нашёл traversal, join, integrity или recovery
consumer этих колонок. Все `16` индексов имели `idx_scan=0` и занимали на
наполненной dev-базе `1 292 771 328 B`; счётчик служил только дополнительным
сигналом к отсутствующему query/constraint contract.

Cold q44 сократил index build `136.046 → 114.900 s` (`-15.544%`) и E2E
`370.127 → 358.321 s` (`-3.190%`). Target XML изменился лишь на `+0.851%`.
Raw `+4.328%` не приписывается schema change, потому что secondary indexes в
обеих точках отсутствуют до index-build. Большой причинный выигрыш index-фазы
не требует repeat по `2%` gate. Колонки lineage и их значения сохранены;
будущий traversal consumer должен вернуть только нужный access path.

q45 изолировал три standalone date B-tree на `object_params`. Production SQL
использует типы `5/6/7/16`, object id, `end_date` filter и порядок
`update_date/id`. Populated `EXPLAIN` single-object official-name и path-wide
tax/postal lookups в обоих случаях выбирает retained partial
`idx_object_params_denorm_type_object_update`; отдельные date indexes не
становятся access path.

Первый q45 сохранил exact parity и улучшил index build на `3.841%`. Неизменный
cold repeat подтвердил `114.900 → 108.691 s` (`-5.404%`) и E2E
`358.321 → 345.505 s` (`-3.577%`). Target XML изменился на `+0.480%`; raw
`-2.925%` не приписывается индексам, отсутствующим во время COPY. Обе
index-фазы независимо проходят `2%` gate. `change_id_end`, denorm composite и
FK access paths в q45 не затронуты.

q46 отдельно проверил `idx_object_params_change_id_end`. Колонка хранит raw
GAR reference, которая может указывать на change-history вне загруженного
среза, поэтому FK для неё намеренно отсутствует. Полный source inventory не
нашёл production join/traversal, constraint, integrity или recovery consumer;
на заполненной dev-базе индекс занимал `734 076 928 B` и имел `idx_scan=0`.
Счётчик использовался только как дополнительный сигнал после проверки
контрактов.

Cold q46 сократил index build `108.691 → 98.366 s` (`-9.500%`) и E2E
`345.505 → 336.922 s` (`-2.484%`). Raw изменился на `+1.404%`, target XML —
на `+3.808%`; эти фазы не используют secondary index и не считаются эффектом
schema change. В q45 обязательный `change_id` index, затем удалённый
`change_id_end` удерживали очередь `object_params` около `50.04 s` до старта
denorm composite. В q46 после единственного `change_id` denorm composite
стартовал через `25.05 s` от начала index-фазы. Общий makespan уменьшился на
`10.325 s`, несмотря на параллельную работу остальных трёх table queues.
Результат значительно выше `2%` gate и наблюдавшейся q45 repeat variance,
поэтому дополнительный repeat не требуется. Raw-значение `change_id_end`
сохранено; будущий lineage consumer должен вернуть свой минимальный индекс.

q47 проверил шесть B-tree по дублирующим UUID в `address_objects`,
`apartments`, `car_places`, `houses`, `rooms` и `steads`. Нормализация получает
stable UUID через unique `reestr_objects.object_guid`, а unit rows связывает по
числовым `reestr_object_id`/`object_id` FK indexes. Production source не
содержит прямого предиката, constraint, integrity или recovery path по шести
UUID-копиям. На populated dev-базе индексы занимали `664 739 840 B` и имели
`idx_scan=0`; будущий GUID lookup уже может пройти через registry UUID и
сохранённый unit FK index.

Первый cold q47 попал в storage-noisy окно: index build `102.826 s`, p95
write await `60.69 ms`, queue `45.96`. Поэтому он не был принят. Unchanged
repeat дал index `85.301 s` (`-13.282%` к исходному q46) и E2E `323.592 s`
(`-3.957%`). Для причинной проверки reverse-order control на том же текущем
коде восстановил ровно шесть UUID-индексов в disposable empty DB: физический
q46 set дал `97.456 s`, в пределах `-0.925%` от исходных `98.366 s`.
Соседний q47 repeat быстрее control на `12.472% / 12.155 s`. Control E2E
оказался ниже из-за независимой raw variance `183.241 → 200.360 s`; secondary
indexes отсутствуют в raw-фазе, поэтому решение принимается по заранее
объявленному scoped index gate.

q48 проверил четыре B-tree по `parent_obj_id`/`change_id` обеих hierarchy
tables. Их cross-link FK ранее сняты: региональный GAR-срез может не содержать
родительский объект или change-history row. Production source не содержит
predicate/join/integrity/recovery consumer по этим полям; raw values при этом
сохранены. Индексы `object_id` обеих иерархий и административный
`region_code`, используемые нормализацией и integrity, остались без изменений.

Три q48 index-фазы дали `93.034 / 78.046 / 93.580 s`, два controls с явным
возвратом четырёх индексов — `80.921 / 105.671 s`. Unpaired median
`93.034 → 93.296 s` отличается лишь на `0.281%` и не считается выигрышем:
host заметно дрейфовал, что видно и по raw. Поэтому причинный gate проверен
двумя соседними crossover-парами с обратным порядком. A/B pair дала
`80.921 → 78.046 s` (`-3.553%`), B/A pair — `105.671 → 93.580 s`
(`-11.442%`). Оба знака совпали и превысили `2%`; representative repeat имеет
index `78.046 s` и E2E `319.045 s`. Решение не приписывает q48 непарный
медианный или raw/E2E gain.

q49 проверил шесть hierarchy classifier-code B-tree: пять административных
code columns и municipal `oktmo`. Source inventory не нашёл raw predicate;
normalized online OKTMO lookup сохраняет собственный `address_search.oktmo`
index. Удаление сократило index storage на `630 988 800 B`, но A1 и A2
ухудшили изменяемую index-фазу относительно соседнего q48 physical-schema
control на `11.913%` и `14.837%`. Более быстрый E2E объясняется raw variance.
Обе пары совпали по знаку, поэтому q49 отклонён, а шесть индексов возвращены в
schema: storage benefit сам по себе не заменяет performance gate.

q50 затем изолировал четыре hierarchy B-tree по `start_date`/`update_date`.
Repository-wide inventory не нашёл для них predicate, join, ordering,
constraint, integrity или recovery consumer; на populated dev-базе они имели
нулевые scans и занимали `606 855 168 B`. Оба `end_date` индекса исключены из
removal set: rebuild, integrity и DataMos paths фильтруют по ним, а populated
counters показывают `29/11` scans.

Две соседние q50/control пары с обратным порядком дали
`115.707 → 85.562 s` (`-26.053%`) и `80.556 → 76.990 s` (`-4.427%`). Обе
превысили scoped gate при противоположном raw drift. Representative repeat
имеет index `76.990 s`, E2E `329.456 s`, `109` indexes и `84` snapshots; E2E
не заявляется как ускорение, потому что secondary indexes отсутствуют во время
raw COPY. Первый repeat bootstrap не вошёл в серию: Docker hostname превысил
DNS label limit до migration/import, после чего неизменный вариант повторён с
коротким именем.

Normalization/search parity подтверждена integration-сценариями rebuild,
suggestion, integrity и recovery, а populated `EXPLAIN` сохраняет те же PK,
`object_id`, regional и denorm composite plans; q42 отдельно сохранил
`idx_address_objects_denorm_active_reestr` в populated suggestion plan. Полный
region-50 normalization
не включён в initial-load timer: на текущей dev-базе такой rebuild занимает
около `93` минут и ни один из удалённых GIN в его плане не участвует.

## q51: EventLoop COPY

q51 не менял region-50 приложение и не является новой строкой performance
таблицы. Одноразовый PHP 8.4.27/libpq 18.6 probe передавал `4 096` строк /
`32 MiB` через намеренно замедленный trigger и выполнял полный
`BEGIN → COPY → checkpoint → COMMIT`.

| Режим | Send | Transaction | EventLoop ticks during send | Flush waits |
| --- | ---: | ---: | ---: | ---: |
| Blocking control | `3.992 s` | `4.443 s` | `0` | `0` |
| Nonblocking, unbounded queue | `0.006 s` | `4.456 s` | `0` | `19` final |
| Nonblocking, flush each chunk | `3.529 s` | `4.448 s` | `1 716` | `19` streaming |

Все варианты записали ровно `4 096` rows и один checkpoint в той же
транзакции. Bounded backpressure действительно отдаёт управление Revolt loop,
но не сокращает PostgreSQL critical path. Unbounded-вариант только переносит
`32 MiB` в очередь libpq и поэтому не подходит для production streaming.

Главный admission failure — API mode control. В установленном ext-pgsql нет
public аналога `PQsetnonblocking()`: `pg_connection_busy()` возвращает
соединение в blocking mode, а async-connect-only control после handshake также
дал zero send ticks. Рабочий probe использовал инвертированное поведение
pg-socket stream handler: `stream_set_blocking($socket, true)` напрямую
передаёт `true` в `PQsetnonblocking()` и фактически включает nonblocking.
Это противоречит PHP stream contract и является нестабильной implementation
detail, а не production API.

q55 затем изолировал exact-runtime adapter на полном region-50 workload без
index overlap. EventLoop-вариант дал raw `193.571 s`, index `90.357 s` и E2E
`328.411 s`; соседний blocking q56 — `184.097 / 92.151 / 318.773 s`.
EventLoop COPY оказался медленнее на `3.023%` E2E и не прошёл `2%` gate.
Экспериментальный adapter и CLI option удалены: нестабильный инвертированный
pg-socket mode switch не оправдан ни throughput, ни lifecycle orchestration.
Production transport остаётся blocking `pg_copy_from()` в изолированных fused
range workers.
Первичные контракты: [PostgreSQL async](https://www.postgresql.org/docs/17/libpq-async.html),
[libpq COPY](https://www.postgresql.org/docs/current/libpq-copy.html),
[PHP COPY data](https://www.php.net/pg-put-copy-data),
[ext-pgsql stream mode](https://github.com/php/php-src/blob/PHP-8.4.27/ext/pgsql/pgsql.c#L4161-L4167).

## q52–q57: Table-Ready Index Overlap

Индекс допускается только когда в durable manifest не осталось незавершённых
файлов его таблицы; `startIndex()` повторяет тот же predicate под
транзакционной блокировкой. После raw обычная index phase строит оставшийся
backlog с полным `--index-workers`.

Механизм доказан на region 50: в q52 при `12/28` raw-файлах уже были завершены
`31/84` индекса, при `26/28` — `72/84`; последний raw-файл оставил только
`11.201 s` index tail. Неизменный I4 repeat оставил `10.766 s`, а q53 с одним
ранним worker — `12.146 s`. Все три точки дали точные `28/28` файлов,
`122 809 172` rows, matching table/file/FK digests, `109` valid indexes,
`34/34` FK и пять нулевых backlog.

Числа q52/q53 нельзя использовать как performance A/B. Все эти прогоны
совпали с внешней активностью `mvd_cake_database`; после q53, уже без нашего
benchmark-контейнера, NVMe сохранял `58–86%` utilization, читал до
`227 MiB/s` и писал до `379 MiB/s`. Поэтому raw `248.147–312.599 s`, FK
validation `58.385–71.414 s` и E2E `332.845–391.323 s` помечены contaminated.

Чистые q54/q54r оставили blocking COPY и один ранний index worker. Raw занял
`238.671 / 235.907 s`, а после закрытия последнего XML осталось только
`11.465 / 11.244 s` index tail. E2E `301.497 / 306.072 s` быстрее чистого
blocking q56 `318.773 s` на `5.419% / 3.984%`; среднее двух overlap-точек —
`303.784 s`, или `-4.702%`. Все точки дали точные `122 809 172` rows,
`109` valid indexes, `34/34` FK и нулевые backlog.

Первый q54 использовал два coordinator Fiber одного CLI process. q54 repeat
обнаружил редкий, но неприемлемый IPC hang: операция остановилась на `27/28`,
в `municipal_reestr_hierarchy` было merged `9 850 787` строк при contiguous
checkpoint `288 256`; отсутствовал ровно диапазон `288257..320291`
(`32 035` строк), все PostgreSQL backends и восемь Amp workers уже ждали
клиента/event loop. Причиной стала общая process-watcher boundary двух
coordinator-ов, а не COPY или lock PostgreSQL.

Финальная реализация запускает скрытый `gar:import:index-scheduler` отдельным
OS-процессом. Raw coordinator и index coordinator больше не делят event loop;
готовность таблиц и окончание raw наблюдаются через PostgreSQL. После последнего
файла scheduler прекращает admission, дожидается активного index worker и
только затем основной процесс переходит в обычную index phase. Process-isolated
q54r завершился без потерянного диапазона с точным checkpoint/digest.

q57 увеличил ранний budget до двух workers. Он успел построить `73/84` индекса
уже на `26/28` файлов, но замедлил raw до `255.398 s`; post-raw tail уменьшился
лишь на `0.560 s`, а E2E вырос к I1 repeat на `2.768%`. Выигрыш к q56 всего
`1.327%`, ниже gate. Поэтому accepted execution profile — ранний I1 и финальный
I4; дальнейшие I3/I4 не запускаются, так как observed curve уже ограничена
конкуренцией raw/index I/O, а не backlog после raw.

## q58: Amp PosixSemaphore Failure Probe

Одноразовый image на том же PHP `8.4.27` получил `ext-sysvmsg`; tracked
Dockerfile и production image не менялись. Amp `PosixSemaphore(1)` корректно
ограничил межпроцессный admission и после штатного `Lock::release()` вернул
очередь к одному permit.

Failure gate не пройден. После `SIGKILL` процесса, удерживавшего lock, число
permit осталось `0`, а следующий процесс не получил lock за `500 ms`.
Реализация использует SysV message queue как очередь токенов и не имеет
`SEM_UNDO`, lease или owner recovery. После отдельного `SIGKILL` процесса,
создавшего semaphore, message queue также осталась orphan-объектом ядра и была
удалена probe вручную.

Поэтому `PosixSemaphore` не добавляется в GAR pipeline: штатный concurrency
limit уже принадлежит process pool, а crash recovery надёжнее выражен через
durable PostgreSQL snapshots/checkpoints и parent-owned PID lifecycle. Для
будущего distributed scheduler нужен lease/outbox/reconciliation protocol, а
не SysV permit.

## Архитектурное Решение: Не Добавлять MessageBus В Index Overlap

Текущий scheduler намеренно остаётся локальным дочерним CLI-процессом. Он
каждые `50 ms` сверяет level-triggered состояние файлов и index snapshots в
PostgreSQL, допускает индекс только после закрытия всей canonical table и перед
переходом в финальную index phase дожидается активной работы. Потерянного
одноразового сигнала здесь нет: после рестарта то же состояние снова будет
обнаружено и обработано.

MessageBus не ускоряет `CREATE INDEX` и не создаёт дополнительного PostgreSQL
parallelism. Его единственный потенциальный выигрыш в текущей схеме — заменить
не более `50 ms` polling latency на доставку сообщения, тогда как сами builds
занимают секунды и десятки секунд. Для корректной доставки пришлось бы добавить
атомарный outbox между file checkpoint и публикацией, отдельный GAR
flow/worker, codec и routing, idempotent claim, lease/reconciliation после
падения consumer, отмену при ошибке raw и тот же финальный barrier. Edge-only
event без этого слабее уже работающего PostgreSQL reconciliation.

Поэтому попытка диверсифицировать index scheduler через MessageBus отклонена:
она переносит тот же SQL workload в дополнительную инфраструктурную обвязку
без подтверждаемого performance gain. Если задача всё же должна храниться в
очереди, это была бы idempotent command «восстановить индексы таблицы», а
событие «таблица импортирована» оставалось бы только уведомлением. Решение
можно пересмотреть лишь при появлении отдельного требования распределять GAR
finalization между несколькими hosts или независимо масштабировать её
lifecycle; для single-host initial-load такого требования нет.

Связанные отрицательные решения образуют одну границу:

| Не использовать | Почему | Условие пересмотра |
| --- | --- | --- |
| MessageBus для table-ready index build | Та же работа и почти та же готовность, но нужны outbox, worker lifecycle, idempotency, cancellation и reconciliation | Multi-host scheduler или независимый distributed lifecycle |
| Amp `PosixSemaphore` / `ext-sysvmsg` | `SIGKILL` владельца теряет permit, initializer оставляет orphan queue; recovery слабее PostgreSQL state | Реализация с lease/owner recovery, дающая новый измеримый concurrency contract |
| EventLoop COPY поверх ext-pgsql implementation detail | q55 дал E2E `+3.023%`, public nonblocking mode control отсутствует | Поддерживаемый API и отдельный A/B с выигрышем whole E2E |

## q59: Три Неиспользуемых Индекса `change_history`

q59 изолировал `idx_change_history_addr_object_guid`,
`idx_change_history_change_date` и `idx_change_history_ndoc_id`. Source
inventory не нашёл ни одного runtime, normalization, integrity или recovery
query, использующего эти access paths; `ndoc_id` уже является soft reference.
Migration сохраняет PK и два индекса исходящих FK на `reestr_object_id` и
`oper_type_id`.

Reverse-order crossover не подтвердил однозначное E2E-ускорение:

| Порядок | Без трёх B-tree | Control | E2E delta candidate |
| --- | ---: | ---: | ---: |
| candidate → control | `282.867 s` | `314.663 s` | `-10.105%` |
| control → candidate | `305.751 s` | `293.128 s` | `+4.307%` |

Unpaired median дала `-3.155%`, но знак crossover изменился, поэтому это не
performance proof. Physical NVMe также не объясняет расхождение одним явным
внешним saturation event: средний utilization составил `32.36–40.49%`, p95
`67.60–85.20%`.

Независимый storage/write effect воспроизвёлся в обоих порядках. Candidate
сократил canonical index footprint примерно на `1.28 GB` и cluster-wide WAL
LSN delta примерно на `1.04 GB`. Все четыре прогона получили точные
`122 809 172` rows, одинаковые table/file digests, `34/34` FK, нулевые
backlog, `106` valid indexes у candidate и `109` у control. Поэтому migration
принимается как удаление доказанно неиспользуемого storage/write overhead, но
не объявляется самостоятельным E2E-ускорением.

## q60/q61: Ширина И Порядок FK Validation

q60 сделал прежнюю константу `2` bounded execution knob
`--foreign-key-workers=1..4`. Ограничения одной referencing table по-прежнему
не валидируются одновременно. q61 заменил алфавитную очередь на
largest-referencing-table-first по `pg_relation_size()`, чтобы крупная таблица
не начиналась в конце и не оставалась одиночным tail.

| Run | FK workers / order | Raw | Index tail | FK validation | E2E |
| --- | --- | ---: | ---: | ---: | ---: |
| q60a control | `2 / name` | `223.506 s` | `11.847 s` | `44.556 s` | `281.830 s` |
| q60b | `3 / name` | `246.502 s` | `11.293 s` | `43.605 s` | `303.298 s` |
| q60c | `4 / name` | `267.905 s` | `10.786 s` | `34.638 s` | `315.247 s` |
| q60d reverse W4 | `4 / name` | `247.767 s` | `10.484 s` | `35.352 s` | `295.610 s` |
| q60e reverse W2 | `2 / name` | `251.593 s` | `10.485 s` | `43.753 s` | `307.686 s` |
| q61a | `4 / LPT` | `247.794 s` | `10.700 s` | `30.428 s` | `292.742 s` |
| q61b repeat | `4 / LPT` | `250.473 s` | `10.492 s` | `31.490 s` | `294.339 s` |

W3 сократил FK меньше чем на секунду и не принимается. W4 воспроизвёл
validation `34.638 / 35.352 s` против W2 `44.556 / 43.753 s`; reverse-order
пара дала фактический E2E `-3.92%`, а после вычитания независимого raw drift —
`-2.68%`. LPT воспроизвёл `30.428 / 31.490 s`; самостоятельно это лишь около
`1.4%` normalized E2E и не объявляется отдельным gate, но в общем W4+LPT
изменении итог против reverse W2 равен `-4.86%` фактического и `-3.62%`
normalized E2E.

Все семь прогонов получили `122 809 172` rows, одинаковые table/file/FK
digests, `34/34` validated FK, `106` valid indexes и нулевые backlog. Поэтому
для measured dedicated host принят W4+LPT; безопасный CLI default остаётся W2.

## q62/q63: Общий Multi-File Pipeline, Fragment И Table-Aware F2

q62 заменил последовательный обход compatible XML внутри dependency phase на
`Pipeline::fromIterable()->concurrent(F)->unordered()`. Все file Fibers делят
один `ContextWorkerPool`, общий parser-task admission, P writer permits и
encoded byte window. Первая реализация обнаружила admission deadlock: оба
producer Fiber блокировались на девятом `submit()`, пока восемь children ждали
COPY permit, а parent не читал readiness. Финальный вариант использует
non-blocking token admission и `DeferredFuture` notification; counting
semaphore не заменяет byte window и не блокирует producer до readiness drain.

| Run | Профиль | Raw | Post-raw indexes | E2E | Решение |
| --- | --- | ---: | ---: | ---: | --- |
| q62a | F1 R8/P4/B50/M16/G8 | `223.498 s` | `11.647 s` | `274.864 s` | control |
| q62b | broad F2 R8/P4/B50/M16/G8 | `220.966 s` | `31.267 s` | `288.476 s` | raw overlap есть, table-ready tail хуже |
| q62c | F1 reverse | `256.296 s` | `10.803 s` | `304.087 s` | control drift |
| q63a | broad F2 R12/P4/B50/M16/G8 | `240.587 s` | `28.445 s` | `309.501 s` | reject R12 |
| q63b | broad F2 R8/P4/B50/M32/G8 | `228.723 s` | `27.235 s` | `291.487 s` | reject M32 alone |
| q63c | broad F2 R8/P4/B100/M32/G8 | `233.994 s` | `25.681 s` | `294.413 s` | reject: batches только `3043 → 2979` |
| q63d | broad F2 R8/P4/B100/M64/G16 | `207.175 s` | `27.745 s` | `269.217 s` | candidate |
| q63d repeat | тот же профиль | `228.240 s` | `29.090 s` | `291.793 s` | repeat |
| q63c reverse | G8/M32 control | `239.932 s` | `25.829 s` | `299.466 s` | crossover control |
| q63e | P6/M96/G16 | `214.712 s` | `26.630 s` | `276.896 s` | reject below gate |
| q63f | broad F3/P4/G16 | `222.518 s` | `26.738 s` | `281.972 s` | reject below gate |
| q63g | table-aware F2/P4/G16 | `229.370 s` | `10.756 s` | `274.152 s` | candidate |
| q63g reverse | broad F2/P4/G16 | `224.308 s` | `27.041 s` | `287.105 s` | crossover control |
| q63h | table-aware F2/P4/G32/M128 | `226.602 s` | `11.159 s` | `274.368 s` | reject E2E plateau |

G16/M64 сократил batches `2979 → 1527`. Две G16/G8 пары дали средний raw
gain `8.13%` и E2E gain `5.54%`; importer max RSS для принятого G16 оставался
не выше `702 MiB`. G32 дополнительно сократил batches до `1235`, но E2E
изменился на `+0.08%`, а max RSS вырос до `884 MiB`: row limit и последующие
фазы уже скрывают оставшуюся экономию COPY calls.

Broad F2 ускоряет raw, но одновременно заканчивает разные таблицы поздно и
оставляет `27–31 s` index tail. Table-aware F2 параллелит только XML одной
canonical table, затем переходит к следующей table group. В соседней паре он
пожертвовал `2.26%` raw, сократил tail на `60.22%` и улучшил E2E на `4.51%`.
R12, P6/M96, F3 и G32/M128 не прошли `2–3%` whole-run gate, поэтому публичные
caps оставлены на измеренных R8/P4/F2/G16/M64.

Все q62/q63 завершённые точки получили exact `122 809 172` rows, одинаковые
table/file/FK digests, `106` valid indexes, `34/34` FK и нулевые range/index/FK
backlogs. Unit/scenario, strict PHPStan и full integration recovery gates
повторены после финальной реализации.

## q64: Четыре Metadata-Индекса `reestr_objects`

q64 изолировал `idx_reestr_objects_change_id`,
`idx_reestr_objects_create_date`, `idx_reestr_objects_is_active` и
`idx_reestr_objects_update_date`. Source inventory не нашёл runtime,
normalization, integrity или recovery filter/order по трём metadata columns.
`is_active` встречается в suggestion SQL только после точечного join по
`reestr_objects.id`; PK уже задаёт access path. `level_id`, PK и unique
`object_guid` indexes сохранены.

Распределение измерено на самом импортированном fixture, а не предположено:
из `7 673 256` registry rows активны `7 304 357`, то есть `95.1924%`.
Standalone boolean B-tree поэтому не селективен для текущего `true` predicate.
Редкие `false` rows (`4.8076%`) могли бы оправдать partial index только вместе
с фактическим production consumer, которого сейчас нет.

| Run | Schema | Raw | Full index window | Post-raw tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q64a | без четырёх B-tree | `199.462 s` | `216.326 s` | `17.078 s` | `35.340 s` | `253.748 s` |
| q64b | control с четырьмя B-tree | `221.768 s` | `233.900 s` | `12.370 s` | `33.440 s` | `269.428 s` |
| q64c | reverse control | `230.754 s` | `247.586 s` | `17.088 s` | `31.835 s` | `281.551 s` |
| q64d | reverse без четырёх B-tree | `224.763 s` | `236.004 s` | `11.453 s` | `32.925 s` | `270.987 s` |

Обе crossover-пары прошли whole-run gate: E2E улучшился на `5.820%` и
`3.752%`; средние E2E/raw/full-index deltas равны `-4.763%`, `-6.253%` и
`-6.056%`. Эффект проявляется во время raw, потому что table-ready index build
перекрывается с импортом и четыре лишних builds конкурируют за тот же storage.

Четыре control indexes занимали вместе `333 185 024 B`. Средний cluster-wide
WAL LSN delta сократился на `174 920 656 B` (`-0.710%`), а наблюдаемый общий
index footprint — примерно на `332.4 MB`. Все четыре точки получили exact
`122 809 172` rows, одинаковые table/file/FK digests, `34/34` FK и нулевые
backlogs. Candidate inventory — `102` valid indexes против `106` у control;
migration и rollback дополнительно прошли full integration gate `48/48`.

## q65: Поздний Admission Раннего I1

q65 изолировал только момент допуска раннего index worker. Immediate control
сохранял прежнюю семантику: первый table-ready index запускался сразу. Candidate
ждал, пока сумма `file_size` завершённых manifest-файлов достигнет `50%` общей
суммы. Это не wall-clock delay и не привязка к имени таблицы; на неизменном
fixture barrier открылся после `houses_params`, когда было завершено
`13 065 347 966 / 24 975 511 491 B` (`52.313%`) из-за дискретной границы файла.
Table-ready проверка осталась обязательной для каждого индекса.

| Run | Admission | Raw | Index overlap | Post-raw tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q65a | late `50%` | `186.764 s` | `99.022 s` | `29.437 s` | `30.330 s` | `250.338 s` |
| q65b | immediate control | `225.339 s` | `225.092 s` | `13.765 s` | `32.878 s` | `273.771 s` |
| q65c | reverse immediate control | `221.239 s` | `221.016 s` | `13.619 s` | `38.384 s` | `275.094 s` |
| q65d | reverse late `50%` | `208.229 s` | `118.772 s` | `18.707 s` | `34.695 s` | `263.426 s` |

Обе crossover-пары прошли whole-run gate: E2E улучшился на `8.559%` и
`4.241%`, raw — на `17.118%` и `5.881%`. Средний candidate/control E2E равен
`256.882 / 274.433 s` (`-6.395%`), raw — `197.497 / 223.289 s`
(`-11.551%`). Цена меньшей конкуренции во время COPY — рост среднего post-raw
tail с `13.692` до `24.072 s`; экономия raw перекрыла его в обеих парах.

Все четыре точки получили exact `122 809 172` rows, одинаковые
table/file/FK digests, `102` valid indexes, `34/34` FK и нулевые backlogs.
Опция `--overlap-index-start-percent=50` принята для measured dedicated host;
default `0` сохраняет совместимость и immediate scheduler для окружений без
собственного A/B.

## q66: Edge-Triggered Pipeline Peak

q66 проверил, стоит ли сохранять durable pipeline peak после каждого
завершённого batch. Candidate выполнял SQL update только при новом
process-local high-water; `GREATEST()` оставался durable resume guard.
Количество operation updates сократилось с `3293` до `1874` (`-43.1%`), но
WAL практически не изменился, потому payload обновлялся HOT и не был значимой
частью общего write path.

| Run | Peak policy | Raw | Post-raw tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: |
| q66a | eager control | `175.629 s` | `25.314 s` | `28.643 s` | `233.348 s` |
| q66b | edge-triggered | `178.169 s` | `27.985 s` | `28.220 s` | `238.168 s` |
| q66c | reverse eager control | `189.244 s` | `19.748 s` | `29.270 s` | `242.025 s` |
| q66d | reverse edge-triggered | `183.049 s` | `27.604 s` | `28.063 s` | `242.531 s` |

Edge-triggered E2E ухудшился на `2.066%` и `0.209%`; среднее ухудшение равно
`1.120%`. Raw в среднем улучшился только на `1.002%`, ниже gate. Candidate
отклонён и код возвращён к eager checkpoint: меньшего числа HOT updates
недостаточно без whole-run выигрыша.

## q67–q68: OPcache И JIT Для CLI Process Tree

Фактический development runtime загружает OPcache, но оставляет
`opcache.enable_cli=0`. Это относится и к `bin/app server:run`, и к GAR CLI,
потому что оба используют CLI SAPI. Production image уже задаёт
`opcache.enable_cli=1` и `opcache.validate_timestamps=0`. В обоих образах
`zend.assertions=-1`, поэтому assertion bytecode не генерируется и отдельного
runtime overhead нет.

q67 подключал CLI OPcache через INI, видимый главному importer и всем дочерним
parser/index/FK процессам. Две пары не дали выигрыша:

| Run | CLI OPcache | Raw | Post-raw tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: |
| q67a | off control | `165.790 s` | `29.073 s` | `29.087 s` | `226.786 s` |
| q67b | on | `190.900 s` | `19.001 s` | `28.483 s` | `242.171 s` |
| q67c | on repeat | `186.271 s` | `30.297 s` | `28.970 s` | `249.394 s` |
| q67d | off reverse control | `185.921 s` | `27.568 s` | `27.884 s` | `245.144 s` |

E2E regressions равны `+6.784%` и `+1.733%`; средний peak importer RSS вырос
примерно с `659.5` до `719.5 MiB`. Одиночный быстрый q67a не повторился,
поэтому причинность размера regression не заявляется, но глобальный CLI
OPcache не прошёл ни одну пару и отклонён для GAR fan-out.

q68 оставил CLI OPcache включённым и изолировал `opcache.jit=tracing` против
`disable`. JIT активировался фактически, а не только в INI:

| Run | JIT | Raw | Post-raw tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: |
| q68a | off control | `191.434 s` | `25.176 s` | `29.480 s` | `249.859 s` |
| q68b | tracing | `185.885 s` | `32.743 s` | `31.033 s` | `251.467 s` |
| q68c | tracing repeat | `183.397 s` | `25.379 s` | `29.030 s` | `241.542 s` |
| q68d | off reverse control | `187.721 s` | `24.326 s` | `30.049 s` | `245.826 s` |

Tracing улучшил raw в обеих парах на `2.899%` и `2.304%`; средний raw gain
`2.604%` проходит scoped gate. Но E2E изменился на `+0.644%` и `-1.743%`, а
средний gain составил только `0.540%`. Глобальный JIT отклонён. Результат
оставляет отдельную архитектурную гипотезу: JIT только для долгоживущего
parser pool может сохранить raw gain без bootstrap/прогрева `77` коротких
index subprocesses.

## q69: Alpine/musl Против Debian/glibc

q69 сравнил runtime profiles без изменения importer/PostgreSQL workload.
Оба образа использовали PHP `8.4.27` NTS, одинаковый список extensions,
libuv `1.52.1`, libpq `18.6`, `zend.assertions=-1` и OPcache/JIT OFF. Control —
Alpine `3.24` с musl `1.2.6`; candidate — Debian Bookworm с glibc `2.36`.
Это distro/runtime A/B, а не чистый allocator microbenchmark: системные libxml,
OpenSSL и compiler build также принадлежат base image.

| Run | Runtime | Raw | Post-raw tail | FK | E2E | Peak RSS |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q69a | Alpine/musl control | `169.006 s` | `32.637 s` | `28.419 s` | `233.853 s` | `661.5 MiB` |
| q69b | Debian/glibc | `188.266 s` | `19.707 s` | `28.927 s` | `240.693 s` | `680.8 MiB` |
| q69c | Debian/glibc repeat | `179.975 s` | `26.506 s` | `31.202 s` | `239.408 s` | `671.0 MiB` |
| q69d | Alpine/musl reverse control | `191.939 s` | `22.260 s` | `29.077 s` | `245.049 s` | `644.0 MiB` |

Пары меняют знак: Debian raw `+11.396% / -6.233%`, E2E
`+2.925% / -2.302%`. Средний Debian raw хуже на `2.022%`, E2E — на `0.250%`,
а peak RSS — примерно на `3.55%`. Debian/glibc отклонён; Alpine остаётся
runtime baseline. Следующая allocator-гипотеза остаётся внутри Alpine:
сначала `jemalloc` только для native allocations, затем отдельный
`USE_ZEND_ALLOC=0` A/B лишь при сохранении enforceable memory ceiling.

## q70: Native-Only jemalloc В Alpine

q70 добавил jemalloc `5.3.0` в неизменный Alpine image и передал
`LD_PRELOAD=/usr/lib/libjemalloc.so.2` всей process tree. Zend Memory Manager
остался включённым; smoke-test с `memory_limit=16M` корректно остановил
allocation `32 MiB`, поэтому q70 не ослаблял PHP memory safety. Candidate
затрагивал native allocations libxml/libpq/libuv и системных библиотек, но не
заменял основной PHP heap allocator.

| Run | Native allocator | Raw | Post-raw tail | FK | E2E | Peak RSS |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q70a | musl control | `167.208 s` | `37.600 s` | `29.372 s` | `238.013 s` | `680.2 MiB` |
| q70b | jemalloc | `192.128 s` | `21.662 s` | `28.780 s` | `244.266 s` | `729.7 MiB` |
| q70c | jemalloc repeat | `165.428 s` | `29.875 s` | `29.534 s` | `228.299 s` | `714.4 MiB` |
| q70d | musl reverse control | `193.863 s` | `19.199 s` | `28.932 s` | `245.730 s` | `698.6 MiB` |

Пары полностью меняют знак: jemalloc raw `+14.903% / -14.668%`, E2E
`+2.627% / -7.094%`. Средний raw gain только `0.973%`, ниже gate; средний E2E
gain `2.311%` не принимается без парной воспроизводимости. Peak RSS candidate
в среднем выше примерно на `4.74%`. Exact `122 809 172` rows, digests,
`102` indexes, `34/34` FK и нулевые backlogs совпали. Native-only jemalloc
отклонён. `USE_ZEND_ALLOC=0` является отдельным экспериментом: он переносит
PHP heap на внешний allocator и требует container OOM gate, поскольку обычный
`memory_limit` больше нельзя считать достаточной защитой.

## q71: Direct jemalloc Без Zend Memory Manager

q71 изолировал второй режим jemalloc: тот же Alpine image и
`LD_PRELOAD=/usr/lib/libjemalloc.so.2`, но PHP heap передан напрямую jemalloc
через `USE_ZEND_ALLOC=0`. Все четыре точки выполнялись с одинаковым hard cgroup
`2 GiB` и `memory.swap.max=0`. Safety smoke подтвердил риск: при PHP
`memory_limit=16M` direct allocator успешно выделил `64 MiB`, тогда как cgroup
`128 MiB` остановил попытку выделить `256 MiB` с exit `137`.

| Run | PHP heap | Raw | Post-raw tail | FK | E2E | Peak RSS |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q71a | Zend MM control | `166.101 s` | `33.236 s` | `28.531 s` | `231.616 s` | `766.7 MiB` |
| q71b | direct jemalloc | `190.618 s` | `22.218 s` | `28.484 s` | `245.129 s` | `980.7 MiB` |
| q71c | direct jemalloc repeat | `191.972 s` | `21.113 s` | `28.174 s` | `245.032 s` | `949.9 MiB` |
| q71d | Zend MM reverse control | `189.178 s` | `18.426 s` | `27.923 s` | `238.317 s` | `700.0 MiB` |

E2E ухудшился в обеих crossover-парах на `5.834%` и `2.818%`, raw — на
`14.760%` и `1.477%`. Среднее ухудшение E2E/raw равно `4.304% / 7.687%`, а
средний peak RSS вырос `733.35 → 965.30 MiB` (`+31.63%`). Tail менял знак и не
компенсировал raw regression. Exact `122 809 172` rows, digests, `102` indexes,
`34/34` FK и нулевые backlogs совпали. `USE_ZEND_ALLOC=0` отклонён; Zend MM и
его `memory_limit` остаются частью production safety profile.

## q72: Selective Tracing JIT В Fused Workers

q72 проверил оставшуюся после q68 гипотезу без глобального изменения process
tree. Parent importer сохранил `opcache.enable_cli=0`; только Amp fused
parser/COPY workers запускались с `opcache.enable_cli=1`,
`opcache.jit=tracing` и JIT buffer `64M`. Ordinary file workers, index
scheduler/subprocesses и FK workers не менялись. Process-args sampler подтвердил
фактическую передачу этих параметров дочернему `process-runner.php`.

| Run | Fused worker runtime | Raw | Post-raw tail | FK | E2E | Peak RSS |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q72a | selective JIT | `161.933 s` | `31.930 s` | `28.972 s` | `226.590 s` | `683.1 MiB` |
| q72b | control | `182.497 s` | `22.299 s` | `28.179 s` | `236.704 s` | `680.2 MiB` |
| q72c | reverse control | `203.975 s` | `14.537 s` | `28.418 s` | `250.769 s` | `619.6 MiB` |
| q72d | selective JIT repeat | `203.797 s` | `18.595 s` | `29.480 s` | `255.634 s` | `730.8 MiB` |

Первый candidate был быстрее соседнего control: raw `-11.268%`, E2E
`-4.273%`. Reverse-пара не воспроизвела эффект: raw `-0.087%`, E2E
`+1.940%`. Средний raw улучшился на `5.367%`, но tail ухудшился на `37.16%`,
средний E2E gain составил только `1.077%`, а peak RSS вырос на `8.78%`.
Exact `122 809 172` rows, digests, `102` indexes, `34/34` FK и нулевые
backlogs совпали. Из-за смены знака E2E и результата ниже gate selective JIT
отклонён; экспериментальный CLI option и ProcessContext override удалены.

## q73: Persistent Index Worker Pool

До q73 каждый из `77` index snapshots запускал скрытую Symfony-команду
`gar:import:index-worker`, повторно собирал CLI graph и открывал новое
PostgreSQL-соединение. Candidate сохранил внешний early-index scheduler
отдельным OS-процессом, но внутри него и финальной index phase использовал
bounded Amp process pool. Каждый worker последовательно выполняет index tasks,
переиспользует ext-pgsql connection и возвращает index identity/backend PID.
Parent по-прежнему запрещает два активных индекса одной таблицы и при первой
ошибке убивает весь pool; `GarInitialLoadStore` сохраняет прежние
`pending/building/completed/failed` checkpoints.

| Run | Index executor | Raw | Index window | Post-raw tail | FK | E2E | Peak RSS |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| q73a | persistent candidate | `173.787 s` | `117.957 s` | `29.060 s` | `28.595 s` | `235.217 s` | `676.2 MiB` |
| q73b | CLI-per-index control | `188.274 s` | `121.557 s` | `22.759 s` | `29.039 s` | `243.897 s` | `718.7 MiB` |
| q73c | reverse control | `188.308 s` | `126.633 s` | `28.316 s` | `28.200 s` | `246.569 s` | `668.8 MiB` |
| q73d | persistent repeat | `190.792 s` | `121.977 s` | `17.368 s` | `28.728 s` | `240.613 s` | `658.4 MiB` |

Обе соседние пары прошли whole-run gate: E2E улучшился на `3.559%` и
`2.416%`. Средние raw/index-window/post-raw/E2E улучшились соответственно на
`3.187% / 3.327% / 9.099% / 2.984%`; средний peak RSS снизился на `3.813%`.
Односекундный process sampler увидел `37/39` уникальных CLI index-worker PID в
controls и ни одного в candidates. В устойчивом финальном index window оба
candidate-прогона держали ровно четыре одинаковых Amp worker PID.

Все четыре прогона завершили exact `122 809 172` rows, одинаковые file/table/FK
digests, `102` valid indexes, `34/34` FK и нулевые backlogs. Опция
`--persistent-index-workers` принята для measured dedicated host. Она остаётся
execution knob: не входит в durable identity и может меняться при resume.

## q74: COPY Metadata CTE Consolidation

До q74 каждый из `1 527` fused COPY batches после `COPY` выполнял семь
metadata statements: lock/validation, overlap check, ledger insert, range
read, file update, operation update и удаление поглощённых ranges. Candidate
сохранил отдельный `FOR UPDATE` statement из-за snapshot semantics
`READ COMMITTED`, но объединил overlap+insert+range read и затем оба
update+delete. Таким образом, на один batch осталось три metadata statements
без изменения COPY payload, checkpoint folding и durable ledger.

| Run | Metadata path | Raw | Index window | Post-raw tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q74a | CTE candidate | `166.592 s` | `118.301 s` | `36.283 s` | `27.585 s` | `234.196 s` |
| q74b | seven-statement control | `207.633 s` | `130.761 s` | `23.900 s` | `27.788 s` | `261.152 s` |
| q74c | reverse control | `187.149 s` | `119.302 s` | `19.898 s` | `28.337 s` | `239.122 s` |
| q74d | CTE repeat | `201.846 s` | `131.826 s` | `21.177 s` | `28.830 s` | `254.669 s` |

Первая пара выглядела убедительно: raw/E2E улучшились на
`19.766% / 10.322%`. Reverse-пара сменила знак: raw/E2E ухудшились на
`7.853% / 6.502%`. Средние candidate/control дали raw
`184.219 / 197.391 s` (`-6.673%`) и E2E `244.432 / 250.137 s`
(`-2.281%`), но index window остался нейтральным (`+0.025%`), а post-raw tail
ухудшился на `31.193%`. Mean выше gate не компенсирует противоположные знаки
соседних пар: воспроизводимый эффект не доказан.

Все четыре прогона сохранили exact `122 809 172` rows, file digest
`6c5d212b983cbcab57ae9ce322643bc6`, table digest
`d309fca782a378b32a6fb0dd4c0fad50`, `102` valid indexes, `34/34` FK и нулевые
backlogs. Экспериментальная опция и CTE path удалены; accepted baseline
остаётся q73.

## q75–q76: EventLoop Lag И Cooperative Range Planner

q75 измерил только fused planner phases и поэтому не использовался как
performance baseline. На `17` XML и `1 544` planner slices blocking
`fread`/scan заняли `50.715 s`; `1 516` slices превысили `10 ms`, а их excess
над `10 ms` составил `35.520 s`. Scoped EventLoop probe зафиксировал
`51.754 s` excess lag над `10 ms` и максимум `2 869.460 ms`. Диагностика
подтвердила scheduling reserve, но не доказала, что частые yield ускорят E2E.

q76 поэтому добавлял ровно один cooperative turn после каждого существующего
`8 MiB` planner chunk. Crossover сравнивал candidate с исходным blocking
reader на одном q73 profile:

| Run | Planner | Raw | Index window | Post-raw tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q76a | cooperative candidate | `176.645 s` | `119.274 s` | `24.623 s` | `28.136 s` | `233.229 s` |
| q76b | blocking control | `186.862 s` | `119.878 s` | `20.059 s` | `28.443 s` | `239.233 s` |
| q76c | reverse control | `192.953 s` | `124.568 s` | `18.918 s` | `28.457 s` | `244.185 s` |
| q76d | cooperative repeat | `193.804 s` | `129.356 s` | `18.491 s` | `28.592 s` | `243.638 s` |

Первая E2E-пара улучшилась на `2.510%`, reverse — только на `0.224%`;
raw-пары дали `-5.468% / +0.441%`. Средние candidate/control E2E
`238.434 / 241.709 s` означают лишь `-1.355%`, а post-raw tail ухудшился на
`10.615%`. Все четыре прогона сохранили exact rows, оба immutable digest,
`102` valid indexes, `34/34` FK и нулевые backlogs. Вариант отклонён и удалён:
таймерные turns не преобразовали измеренный lag в воспроизводимый `2%` gain.
Это стало основанием q77 проверить настоящий non-blocking file source вместо
дополнительных zero-delay yields.

## q77: Async File Source Через `ext-uv`

`amphp/file` v4 несовместим с locked `amphp/cluster v2.0.1`, который явно
конфликтует с `amphp/file <3 || >=4`. Для эксперимента напрямую объявлен
совместимый `amphp/file 3.2.0`; runtime evidence подтвердил
`Revolt\\EventLoop\\Driver\\UvDriver` и
`Amp\\File\\Driver\\UvFilesystemDriver`, а не fallback process pool.
Candidate заменял только coordinator `fopen`/`fread`/seek; scanner, chunk
`8 MiB`, ranges, parser workers, COPY и recovery не менялись. Controls тем же
кодом выбирали исходный blocking handle.

| Run | Planner source | Raw | Index window | Post-raw tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q77a | async uv | `167.113 s` | `112.997 s` | `31.051 s` | `27.833 s` | `229.775 s` |
| q77b | blocking control | `186.967 s` | `124.019 s` | `24.129 s` | `28.541 s` | `243.377 s` |
| q77c | reverse control | `183.151 s` | `116.554 s` | `29.013 s` | `28.840 s` | `244.756 s` |
| q77d | async uv repeat | `205.550 s` | `124.249 s` | `20.454 s` | `29.291 s` | `259.054 s` |

Первая пара выглядела сильной: raw/E2E `-10.619% / -5.589%`. Reverse-пара
полностью сменила знак: raw/E2E `+12.230% / +5.842%`. Средние async/control
raw равны `186.332 / 185.059 s` (`+0.688%`), E2E —
`244.415 / 244.066 s` (`+0.143%`). Средний post-raw tail async был ниже на
`3.082%`, но это не компенсирует отсутствие воспроизводимого raw/E2E effect.
q77d одновременно получил средний device write await `7.561 ms` против
`4.427 ms` у q77a, что подтверждает чувствительность whole pipeline к
storage-phase drift, но не доказывает пользу async source.

Все четыре прогона сохранили exact `122 809 172` rows, оба immutable digest,
`102` valid indexes, `34/34` FK и нулевые backlogs. `amphp/file`, архитектурный
layer, async reader и unit path удалены; q73 остаётся production baseline.
Отдельный вывод q77a о сокращении времени overlap не принимается как новый
baseline, но учитывается при будущей оптимизации index tail после любого
реально воспроизводимого raw gain.

## q78: One-Read PHP Source Fragments

Candidate сохранял каждый точный G16 XML fragment в coordinator во время уже
необходимого boundary scan и передавал bytes fused parser/COPY worker-у вместо
offset-only task с повторным `fopen`/`fseek`/`fread`. Recovery identity,
`XMLReader`, encoder, COPY payload, batch count `1 527` и PostgreSQL profile не
менялись. Память оставалась bounded текущими range/byte admission limits; весь
архив в PHP heap не загружался.

| Run | Source transport | Raw | Index window | Post-raw tail | FK | E2E | Peak RSS |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| q78a | inline fragments | `186.198 s` | `114.068 s` | `17.887 s` | `28.978 s` | `236.881 s` | `815.9 MiB` |
| q78b | offset/read control | `189.713 s` | `118.867 s` | `19.706 s` | `28.124 s` | `241.415 s` | `641.2 MiB` |
| q78c | reverse control | `182.995 s` | `120.386 s` | `23.877 s` | `28.742 s` | `239.376 s` | `638.3 MiB` |
| q78d | inline repeat | `214.858 s` | `132.359 s` | `11.565 s` | `29.597 s` | `259.777 s` | `815.7 MiB` |

Первая пара дала raw/E2E `-1.853% / -1.878%`: направление было положительным,
но E2E уже находился ниже gate. Reverse-пара сменила знак на raw
`+17.412%` и E2E `+8.523%`. Средние candidate/control raw равны
`200.528 / 186.354 s` (`+7.606%`), E2E — `248.329 / 240.395 s`
(`+3.300%`). Candidate сократил средний post-raw tail на `32.421%`, но это не
компенсировало более длинный raw critical path. Средний peak importer RSS вырос
с `639.8` до `815.8 MiB` (`+27.5%`); swap counters во всех четырёх точках не
изменились.

Все точки сохранили exact `122 809 172` rows, file/table/FK digests, `102`
valid indexes, `34/34` FK и нулевые backlogs. One-read payload отклонён и
удалён. Результат согласуется с тем, что на текущем NVMe повторное range-read
в значительной мере обслуживается page cache: перенос source bytes через
process boundary добавил PHP serialization/IPC/RSS и нестабильно изменил
расписание raw/index work. q73 остаётся production baseline.

## q79: Critical-Path XML На tmpfs

Candidate размещал три region-50 XML на `tmpfs`: `AS_CHANGE_HISTORY`,
`AS_HOUSES_PARAMS` и `AS_ADM_HIERARCHY`, суммарно `13 846 408 000 B`.
Остальные `25` XML были отдельными read-only bind mounts непосредственно под
тем же `/gar-source`, поэтому secure source-root guard не ослаблялся. После
staging исходные страницы этих трёх файлов повторно удалялись из page cache;
`fincore` подтверждал original resident `0`. PostgreSQL оставался durable на
NVMe. Staging воспроизводимо занял `10.86 s`.

| Run | Source placement | Raw | Post-raw tail | FK | Import E2E | Staging + E2E | Peak importer RSS |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| q79a | three-file tmpfs | `167.663 s` | `34.617 s` | `28.136 s` | `234.215 s` | `245.075 s` | `13 916 MiB` |
| q79b | cold NVMe control | `184.710 s` | `22.335 s` | `29.178 s` | `240.000 s` | `240.000 s` | `670 MiB` |
| q79c | reverse control | `192.946 s` | `23.928 s` | `28.881 s` | `248.102 s` | `248.102 s` | `670 MiB` |
| q79d | three-file tmpfs repeat | `179.058 s` | `26.479 s` | `27.327 s` | `236.650 s` | `247.510 s` | `13 834 MiB` |

Import-only raw улучшился в обеих crossover-парах на `9.229% / 7.198%`, а
E2E — на `2.410% / 4.616%`. Однако более раннее завершение raw перенесло
работу в post-raw tail: он вырос на `54.988% / 10.660%`. После обязательного
staging E2E пары составили `+2.115% / -0.239%`; средний candidate
`246.293 s` хуже среднего control `244.051 s` на `0.919%`.

Resource gate также не пройден. В q79a `pswpout` вырос на `131 492` страницы
(около `514 MiB`), `pswpin` — на `8`; swap заполнился практически полностью.
Controls не меняли swap counters. q79d стартовал уже после заполнения swap,
поэтому отсутствие нового delta не отменяет q79a pressure evidence. Все четыре
полные точки сохранили exact `122 809 172` rows, immutable digests, `102`
valid indexes, `34/34` FK и нулевые backlogs.

Three-file tmpfs отклонён как production profile: import-only gain не пережил
staging и потребовал слишком большого shared-memory working set. При этом raw
гипотеза подтверждена. Почти весь локальный эффект пришёлся на
`AS_ADM_HIERARCHY`: его duration сократился на `36.808% / 27.381%`, тогда как
`AS_CHANGE_HISTORY` и `AS_HOUSES_PARAMS` изменились лишь на несколько
процентов. Следующий q80 проверяет только `AS_ADM_HIERARCHY` (`4 418 790 670 B`)
на tmpfs, чтобы сохранить raw gain при меньших staging/RSS и без swap pressure.

## q80: Только `AS_ADM_HIERARCHY` На tmpfs

Candidate помещал на tmpfs только region-50 `AS_ADM_HIERARCHY`
(`4 418 790 670 B`), а остальные `27` XML оставлял отдельными read-only bind
mounts на cold NVMe. Исходный hierarchy-файл после staging повторно
эвиктировался; `fincore` подтверждал resident `0`. Secure source-root guard не
ослаблялся, PostgreSQL и его durability profile не менялись. Staging в обоих
candidate-прогонах занял `3.62 s`.

| Run | Source placement | Raw | Post-raw tail | FK | Import E2E | Staging + E2E | Peak container memory |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| q80a | hierarchy-only tmpfs | `163.051 s` | `33.422 s` | `28.480 s` | `227.729 s` | `231.349 s` | `4.772 GiB` |
| q80b | cold NVMe control | `187.992 s` | `20.004 s` | `29.777 s` | `241.561 s` | `241.561 s` | — |
| q80c | reverse control | `202.892 s` | `22.787 s` | `28.829 s` | `258.278 s` | `258.278 s` | — |
| q80d | hierarchy-only tmpfs repeat | `180.010 s` | `26.788 s` | `28.538 s` | `239.064 s` | `242.684 s` | `4.770 GiB` |

Обе пары прошли обязательный staged-E2E gate: `4.227% / 6.038%`. Средний raw
сократился с `195.442` до `171.530 s` (`12.234%`), import E2E — с `249.920`
до `233.397 s` (`6.611%`), а полный staging-inclusive E2E составил
`237.017 s` (`5.163%` выигрыша). При этом средний post-raw tail вырос с
`21.395` до `30.105 s` (`40.710%`): source placement ускоряет именно raw
critical path и переносит следующую проблему в завершение индексов.

Все четыре точки сохранили exact `122 809 172` rows, file/table/FK digests,
`102` valid indexes, `34/34` FK и нулевые backlogs. q80a изменил swap counters
только на `pswpin +2` страницы при `pswpout +0`; q80d — на `+0/+0`. Peak
container memory включает charge tmpfs и составил около `4.77 GiB`, вместо
`~13.5 GiB` у q79. Поэтому hierarchy-only tmpfs принимается как optional
initialization profile измеренного dedicated host. Для unknown/shared host он
не становится default: staging включается в E2E, PostgreSQL остаётся на NVMe,
а перед запуском требуется memory headroom сверх размера файла и runtime/page
cache.

## q81: Bounded Shared-Memory Fragments

q81 не использовал q80 tmpfs. Все `28` XML перед каждым прогоном оставались
на cold NVMe с resident `0`. Parent сохранял точный G16 fragment во время уже
существующего boundary scan, помещал только текущие admitted fragments в
`Amp\Sync\SharedMemoryParcel` с `PassthroughSerializer`, а fused worker получал
ключи parcel/semaphore. Рабочий набор зависел от R8/G16, а не от числа регионов
или полного размера архива. Это не zero-copy: оставались копии parent buffer →
shared segment и shared segment → worker PHP string.

| Run | Transport | Raw | Post-raw tail | FK | E2E | Peak importer memory |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| q81a | shared memory | `172.369 s` | `31.010 s` | `28.193 s` | `235.350 s` | `744.6 MiB` |
| q81b | offset/read control | `190.741 s` | `19.396 s` | `29.139 s` | `242.998 s` | `671.9 MiB` |
| q81c | reverse control | `185.834 s` | `21.634 s` | `29.493 s` | `240.781 s` | `660.1 MiB` |
| q81d | shared-memory repeat | `193.411 s` | `20.329 s` | `29.140 s` | `246.715 s` | `777.8 MiB` |

Первая пара улучшила raw/E2E на `9.631% / 3.148%`, но reverse-пара ухудшила
их на `4.077% / 2.465%`. Средний raw всё ещё короче на `2.867%`, однако
средний E2E улучшился лишь на `0.354%`; post-raw tail вырос на `25.128%`, а
peak importer memory — примерно на `14.3%`. Все точки сохранили exact
`122 809 172` rows, file/table/FK digests, `102` valid indexes, `34/34` FK и
нулевые backlogs. После штатного завершения candidates не осталось SysV
shared-memory segments/message queues; swap pressure не возникло.

q81 отклонён: crossover сменил знак, и вторая E2E-пара не прошла `2%` gate.
Crash cleanup после `SIGKILL` не исследуется дальше, потому что performance
gate уже закрыт.
Экспериментальный transport, прямые dependency contracts и обязательные
`ext-shmop`/`ext-sysvmsg` удалены. `Amp\Cache\LocalCache` по-прежнему годится
только для малых immutable metadata одного процесса. Redis не рассматривается
как transport больших XML/COPY payload из-за дополнительной socket copy,
отдельного memory lifecycle и инфраструктуры; он остаётся вариантом для малых
metadata, lease и multi-host coordination. См. [AMPHP sync](https://amphp.org/sync),
[AMPHP cache](https://amphp.org/cache) и [AMPHP Redis](https://github.com/amphp/redis).

Во всех четырёх q81-прогонах последними последовательно завершались
`AS_ADM_HIERARCHY`, затем `AS_MUN_HIERARCHY`. Это не dependency данных: оба
descriptor имеют priority `80` и пишут в разные таблицы. Последовательность
создаёт принятый table-aware scheduler: он запускает table groups по очереди,
чтобы раньше открыть индексы готовой таблицы. Для нескольких регионов каждая
группа остаётся bounded F2 и обрабатывает региональные файлы этой таблицы по
два, но municipal group ждёт завершения всей administrative group. Простое
отключение table-aware уже проверено broad F2 control q63g: raw был немного
короче, однако post-raw tail и E2E стали хуже. Следующий diagnostic должен
разделить planner/XML parse/COPY/commit именно для этой hierarchy-пары, а не
повторять generic cross-table parallelism.

## q82: Диагностика Последней Hierarchy-Группы

q82 не менял table-aware scheduler и выполнил один instrumented cold-NVMe
прогон на q73 execution profile. Каждый fused worker вернул parent-процессу
наносекундные границы parse/encode, channel send, permit wait, connection и
`COPY+commit`; parent отдельно измерил только active resume time блокирующего
range planner. По одному JSON event записано на каждый из `17` fused XML.

| Файл | Wall | Planner | Parse/encode sum | Permit wait sum | COPY+commit sum | Max task |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `AS_ADM_HIERARCHY` | `25.258 s` | `9.150 s` | `119.979 s` | `9.153 s` | `60.901 s` | `1.053 s` |
| `AS_MUN_HIERARCHY` | `24.331 s` | `9.292 s` | `62.038 s` | `34.349 s` | `69.846 s` | `5.710 s` |

Worker sums отражают concurrent demand и не складываются с wall. Для
administrative они соответствуют примерно `4.75` parser и `2.41` COPY worker,
для municipal — `2.55`, `2.87` и ещё `1.41` permit-wait equivalents. Поэтому
совместный parser demand помещается в R8, но COPY должен оставаться под общим
P4/M64 admission. Последовательная пара заняла `49.589 s`, или `30.0%` raw.

| Raw | Raw/index overlap | Post-raw tail | FK | Finalization | E2E |
| ---: | ---: | ---: | ---: | ---: | ---: |
| `165.159 s` | `80.033 s` | `34.704 s` | `28.337 s` | `3.727 s` | `231.937 s` |

Точка сохранила `122 809 172` rows, exact digests, `102` valid indexes,
`34/34` FK и нулевые backlogs. Peak importer RSS был `662.6 MiB`, PostgreSQL —
около `15.9 GiB`; swap counters не изменились. Относительно q73 mean raw/E2E
выглядят лучше на `9.397% / 2.513%`, но tail хуже на `49.498%`. Это только
диагностика без adjacent control, а instrumentation меняет hot path, поэтому
production result не принят.

Первый q82 diagnostic JSON завышал собственное поле `processed_rows`, потому
что складывал cumulative checkpoint из каждого result. Поле не участвовало в
таймингах или импорте и исключено из анализа; после прогона collector исправлен
на `lastRow - firstRow + 1`. Correctness получен независимо из canonical SQL.

Следующий experiment не повторяет broad F2 q63g. Он может объединить только
финальные administrative/municipal descriptors priority `80` под общими
R8/P4/G16/M64, сохранив table-aware groups для всех предыдущих фаз. Сначала
проверяется локальный hierarchy critical path. Полный E2E crossover запускается
только при потенциальном выигрыше не меньше `2%`.

## q83: Selective Hierarchy F2

q83a сохранил q82 instrumentation и объединил только два descriptor priority
`80`; все ранние phases остались table-aware. Файлы стартовали с разницей
`44 ms`, использовали общий R8 parser pool и прежний P4/M64 COPY admission.

| Metric | q82 sequential | q83 selective F2 | Delta |
| --- | ---: | ---: | ---: |
| Hierarchy pair span | `49.589 s` | `50.706 s` | `+2.254%` |
| Raw | `165.159 s` | `166.282 s` | `+0.680%` |
| Post-raw tail | `34.704 s` | `34.443 s` | `-0.752%` |
| FK | `28.337 s` | `29.643 s` | `+4.609%` |
| E2E | `231.937 s` | `234.167 s` | `+0.961%` |

Administrative wall вырос до `50.706 s`, municipal — до `42.839 s`.
Совместный parse/encode sum почти не изменился (`182.256 s` против q82
`182.017 s`), но permit wait вырос `43.502 → 48.733 s`. То есть q82 верно
показал свободный parser budget, однако одновременный COPY demand упёрся в P4,
а снятие ограничения означало бы возврат уже отклонённого P6 и больший DB
contention.

Correctness снова сохранил `122 809 172` rows, exact digests, `102` indexes,
`34/34` FK и нулевые backlogs. Peak importer RSS был `664.7 MiB`, PostgreSQL —
около `14.43 GiB`; swap-out вырос только на одну страницу. Локальный gate не
пройден, поэтому production crossover не запускался, экспериментальная
группировка удалена, q73 table-aware scheduler сохранён.

One-second index history дополнительно показал, что только пять крупных
индексов завершились до raw, а `72` — после. Финальный хвост определили
`idx_administrative_reestr_hierarchy_plan_code`, `region_code` и `street_code`.
Они строились последовательно из-за правила scheduler «один active index на
table». Следующая изолированная гипотеза — width `2` только для post-raw pool;
во время raw overlap правило не меняется.

## q84: Post-Raw Same-Table Index Width 2

PostgreSQL 17 прямо разрешает одновременные regular index builds одной таблицы;
обычный `CREATE INDEX` использует совместимый `SHARE` lock. При этом
`maintenance_work_mem` ограничивает каждый build целиком, а не каждого его
parallel worker. q84 поэтому не увеличивал общий I4, память или parallel caps:
он допускал до двух index jobs одной таблицы только после raw. Early I1 overlap
сохранил прежний width `1`. См. [CREATE INDEX](https://www.postgresql.org/docs/17/sql-createindex.html),
[explicit locking](https://www.postgresql.org/docs/17/explicit-locking.html) и
[resource consumption](https://www.postgresql.org/docs/17/runtime-config-resource.html).

| Run | Width | Raw | Post-raw tail | FK | E2E |
| --- | ---: | ---: | ---: | ---: | ---: |
| q84a | `2` | `167.623 s` | `22.565 s` | `33.152 s` | `226.112 s` |
| q84b | `1` control | `184.926 s` | `22.715 s` | `29.588 s` | `241.022 s` |
| q84c | `1` reverse control | `181.315 s` | `21.245 s` | `29.882 s` | `236.217 s` |
| q84d | `2` repeat | `199.567 s` | `14.863 s` | `36.004 s` | `254.445 s` |

Exact history подтвердил одновременные administrative hierarchy builds.
Candidate tail улучшился в обеих парах на `0.660% / 30.032%`, средний — на
`14.858%`. Но E2E сменил знак: первая пара лучше на `6.186%`, reverse candidate
хуже на `7.717%`. Средний E2E candidate `240.279 s` против control
`238.619 s` (`+0.695%`), а средний FK вырос примерно на `16.3%`.

Все точки сохранили exact rows/digests, `102` valid indexes, `34/34` FK и
нулевые backlogs. Candidate importer peak был `667–671 MiB`, PostgreSQL около
`12.5–13.5 GiB`, swap не изменился. q84 отклонён по whole-run gate; исходное
one-index-per-table правило восстановлено.

## q85: Верхняя Граница Оставшихся B-tree

После q84 оставались `26` B-tree без текущего query/constraint consumer: три
даты `normative_docs`, два division link, пятнадцать dictionary lifecycle,
четыре dictionary `is_active` и два dictionary code/level. Source audit
подтвердил, что mapper `address_object_types` уже обслуживается retained unique
`(short_name, level, id)`; standalone `level` для него не нужен.

Точный q84b one-second history дал суммарное serial build time всех `26`
индексов только `2.950007 s`:

| Группа | Индексы | Полное serial build time |
| --- | ---: | ---: |
| `normative_docs` dates | 3 | `2.395420 s` |
| division parent/child | 2 | `0.011874 s` |
| dictionary dates | 15 | `0.459728 s` |
| dictionary flags | 4 | `0.045807 s` |
| dictionary code/level | 2 | `0.038178 s` |

Даже невозможный случай, в котором всё это время целиком исчезает из E2E,
равен лишь `1.224%` от q84b `241.021560 s`, ниже gate `2%`. Реальная экономия
ещё меньше из-за I4 overlap. Поэтому пять дорогих crossover не запускались:
performance frontier закрыт измеренной верхней границей. Все `26` остаются
кандидатами отдельной schema-cleanup migration по правилу «индекс по
потребности», но им не приписывается E2E-ускорение и они не смешиваются с
execution benchmark.

## q86: Persistent FK Worker Pool

q86 проверил, является ли запуск отдельного Symfony CLI и PostgreSQL connection
для каждого из `34` deferred FK устойчивой частью finalization floor. Candidate
использовал четыре постоянных Amp context worker с одним ext-pgsql connection
на процесс; LPT ordering, запрет двух FK одной referencing table, snapshot
recovery и все остальные параметры q73 оставались неизменными.

| Run | Executor | Raw | Tail | FK | E2E |
| --- | --- | ---: | ---: | ---: | ---: |
| q86a | persistent | `173.574 s` | `20.680 s` | `28.130 s` | `225.137 s` |
| q86b | process control | `183.348 s` | `20.349 s` | `32.057 s` | `239.457 s` |
| q86c | reverse control | `197.868 s` | `14.524 s` | `28.837 s` | `245.063 s` |
| q86d | persistent repeat | `188.192 s` | `23.069 s` | `28.716 s` | `243.764 s` |

Первая пара дала FK/E2E `-12.249% / -5.980%`, но reverse-пара только
`-0.420% / -0.530%`. Средние FK/E2E формально лучше на `6.648% / 3.223%`, но
не заменяют обязательную воспроизводимость обеих соседних пар: большая часть
первого E2E delta пришла из более короткого raw/index schedule. Во второй паре
чистая разница FK составила лишь `0.121 s`.

Все четыре точки сохранили `122 809 172` rows, exact digests, `102` valid
indexes, `34/34` FK и нулевые backlogs; нового swap-out не было. Это не
correctness/resource reject, а провал performance gate. Persistent FK task,
option и tests удалены; production сохраняет process-per-FK W4/LPT. Повторный
CLI/connection bootstrap не считается следующим материальным bottleneck.

## q87: Bounded COPY-Text Spool

Полный XML→CSV pre-stage не включён в очередь: он задержит table-ready/index
overlap и добавит полную запись/чтение. Текущий `GarCopyRowEncoder` уже выдаёт
PostgreSQL COPY text, а `pg_copy_from()` принимает массив этих строк. Если все
локальные loop исчерпаны, последним архитектурным экспериментом стал bounded
spool готовых COPY-text chunks с path/size/range handoff, checksum, recovery и
hard byte/file admission.

Текущая region-50 точка передаёт `13 188 317 637` encoded bytes в `1 527`
ranges. Полная materialization добавила бы минимум `26.38 GB` file I/O.
Server-side `COPY FROM filename` может читать файл без client hop, но требует
видимого PostgreSQL path и superuser/`pg_read_server_files`; client-side file
COPY не требует этой роли, зато данные всё равно проходят соединение. Поэтому
эти варианты разделяются и проходят отдельный security gate. См.
[PostgreSQL COPY](https://www.postgresql.org/docs/17/sql-copy.html) и
[Pdo\\Pgsql::copyFromFile](https://www.php.net/manual/en/pdo-pgsql.copyfromfile.php).

q87 сохранил client-side transaction boundary: R8 parser workers создавали
private `0600` chunks в bounded tmpfs, P4 writers проверяли size/SHA-256 и
передавали их через `pg_put_copy_data()` до записи checkpoint в той же
транзакции. Hard reservation составлял `M64 + R8 × G16 = 192 MiB`.

Focused `make check` прошёл `214/903`, selected integration — `1/1` и `20`
assertions. Полный F2 выявил нарушение liveness, которого малый fixture не
покрывал. После удаления двух ошибочных дублирующих admission counters третий
candidate снова застыл на `27/28`, `122 616 995` rows и `74/77` index
snapshots. Последний municipal-hierarchy файл уже имел `152` committed ranges
и `9 690 645` rows, но его первые три ready chunks (`192 177` rows, около
`33.5 MiB`) оставались в tmpfs. Все восемь parser и четыре writer process были
idle в `epoll`, application PostgreSQL sessions — `idle`, активного COPY не
было. Значит данные застряли в coordinator state, а не в resource limit.

Ни один q87 candidate не получил валидного E2E, q87b не запускался: после
провала correctness/liveness gate сравнение скорости не имеет смысла.
Implementation, option, tests и benchmark variants удалены. q87 отклонён.
Повтор разрешён только как отдельный redesign с одним global dispatcher,
который владеет всеми переходами `parse -> ready -> COPY -> committed` и
сначала проходит F2 interruption/resume/liveness stress gate.

## q88: Fresh Baseline Saturation Profile

После исчерпания прежней очереди q88 не менял приложение: cold-NVMe q73/q65
baseline повторён с per-CPU `mpstat`, PSI, прежними `iostat`, PostgreSQL waits
и one-second index history.

| Raw | Raw/index overlap | Post-raw tail | FK | ANALYZE | E2E |
| ---: | ---: | ---: | ---: | ---: | ---: |
| `163.473 s` | `78.615 s` | `30.032 s` | `29.131 s` | `3.093 s` | `225.737 s` |

Exact `122 809 172` rows, digests, `102` valid indexes, `34/34` FK и нулевые
backlogs совпали. PostgreSQL peak memory `14.62 GiB`, importer `684.9 MiB`;
swap counters не изменились.

В post-raw tail host в среднем имел `19.41% user`, `3.49% system`, `2.05%`
iowait и `75.02% idle`; CPU PSI оставался `0`. NVMe показывал
`39.30/253.63 MiB/s` read/write, `1.56/8.23 ms` await и `33.61%` average /
`78%` maximum utilization. Это не storage saturation, хотя I/O PSI `some
avg10` кратковременно достиг `7.28`.

Критический путь после raw изменился относительно средних q73: последний
`object_params` index завершился на `+1.503 s`, municipal hierarchy — на
`+14.993 s`, а восемь последовательных `administrative_reestr_hierarchy`
indexes тянулись до `+29.985 s`. Поэтому q89 проверяет узкий вариант width `2`
только для этой таблицы в final pool. Ранний overlap остаётся I1, общий лимит
I4 и все остальные per-table limits не меняются. Это новый scoped evidence,
а не повтор отклонённого q84 global width `2` без причины.

## q89: Targeted Administrative Index Width 2

q89 менял только final-pool policy для `administrative_reestr_hierarchy`:
разрешал два одновременных regular `CREATE INDEX`. Early I1, общий I4 и все
остальные таблицы сохранили width `1`.

| Run | Raw | Post-raw tail | FK | E2E |
| --- | ---: | ---: | ---: | ---: |
| q88 width-1 diagnostic | `163.473 s` | `30.032 s` | `29.131 s` | `225.737 s` |
| q89a targeted width 2 | `171.494 s` | `27.647 s` | `28.386 s` | `231.309 s` |

Target indexes действительно пересекались. Tail стал короче на `2.384 s` /
`7.94%`, но contention почти удвоил individual time первой пары: `area_code`
и `city_code` заняли около `6.0 s` каждый вместо q88 `3.977 / 3.474 s` serial.
E2E ухудшился на `5.572 s` / `2.47%`; raw отдельно вырос на `8.021 s` /
`4.91%` ещё до активации изменения.

Exact rows/digests, `102` indexes, `34/34` FK, zero backlogs и swap gate
сохранились. По заранее установленному negative first-run rule три следующих
crossover не запускались. Option, scheduler branch, tests и harness variants
удалены. q84 и q89 вместе закрывают same-table index concurrency для текущего
fixture: локальное сокращение tail не даёт достаточного whole-run выигрыша.

## q90: Foreign-Key Validation Profile

q90 не менял приложение и выполнил один диагностический прогон принятого
W4/LPT FK-пути. Snapshot sampler с интервалом `0.5 s` сохранил времена каждой
проверки до штатной очистки lifecycle rows.

| Raw | Post-raw tail | FK | ANALYZE | E2E |
| ---: | ---: | ---: | ---: | ---: |
| `164.107 s` | `35.961 s` | `29.499 s` | `3.771 s` | `233.343 s` |

Exact `122 809 172` rows, file/table/FK digests, `102` valid indexes, `34/34`
validated FK и нулевые backlogs совпали. FK-фазу определила одна serial chain
на `object_params`:

| Constraint | Duration |
| --- | ---: |
| `object_params_change_id_fkey` | `18.157 s` |
| `object_params_object_id_fkey` | `8.162 s` |
| `object_params_type_id_fkey` | `2.758 s` |

Другие длинные проверки перекрывались W4: `houses_change_id_fkey` занял
`9.983 s`, `change_history_reestr_object_id_fkey` — `9.410 s`,
`apartments_change_id_fkey` — `8.754 s`, `steads_change_id_fkey` — `7.870 s`.
При этом FK-window в среднем сохранял `85.06%` CPU idle; CPU PSI `some avg10`
не превышал `1.37`. NVMe имел `16.99%` average / `32.72%` peak utilization,
`112.30/85.98 MiB/s` read/write и `0.91/2.36 ms` await. Swap-out не изменился;
разовый swap-in `35` pages не является pressure signal.

Простое снятие запрета двух FK одной referencing table не создаёт
параллельность. `VALIDATE CONSTRAINT` берёт `SHARE UPDATE EXCLUSIVE`, а эта
блокировка конфликтует сама с собой. Один составной `ALTER TABLE` также не
объединяет три FK-проверки: PostgreSQL ставит каждую FK в отдельный queue item,
затем последовательно вызывает `validateForeignKeyConstraint()` и отдельный
`RI_Initial_Check()` для каждого ограничения. Поэтому q91 same-table width и
batch нескольких `VALIDATE` не запускаются: отсутствие выигрыша следует из
механики PostgreSQL, а не из нехватки CPU/NVMe.

Официальные основания:
[ALTER TABLE](https://www.postgresql.org/docs/17/sql-altertable.html),
[Explicit Locking](https://www.postgresql.org/docs/17/explicit-locking.html),
[PostgreSQL 17 `tablecmds.c`](https://github.com/postgres/postgres/blob/REL_17_STABLE/src/backend/commands/tablecmds.c#L5600-L5634).
После q60/q61, q86 и q90 безопасный FK scheduling/configuration frontier
исчерпан. Следующий существенный эксперимент должен менять raw transport или
bulk-load transaction topology, а не число/порядок/lifetime FK workers.

## q91: Global COPY-Text Dispatcher

q91 повторил идею q87 с одним владельцем всех переходов и отдельными R8 parser
и P4 writer pools. Unit/integration/recovery gates прошли, но полный run не
получил валидного timing: после более `13 min` состояние осталось `27/28`,
`122 552 903` rows и `74/77` indexes.

Municipal hierarchy имела `151` committed non-contiguous ranges и
`9 626 553` merged rows, но contiguous gap `1..256269` не закрылся. Четыре
ранних spool files (`45 704 KiB`) остались в tmpfs; все worker processes и все
восемь PostgreSQL sessions были idle. Это повторный scheduler-liveness reject,
а не XML, PostgreSQL, NVMe или tmpfs saturation. Implementation удалена, A/B
не запускался.

## q92: Binary COPY

q92 менял только формат внутри принятого fused worker: ordered types читались
из `pg_attribute`/`pg_type`, PHP формировал native header/tuples/trailer, а
`COPY FROM STDIN (FORMAT binary)` и checkpoint оставались одной транзакцией.
Все GAR types и interruption/resume прошли; полный run сохранил exact
`122 809 172` rows, digests, `102` indexes, `34/34` FK и нулевые backlogs.

| Run | Raw | Post-raw tail | FK | ANALYZE | E2E | Encoded bytes |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| q90 text control | `164.107 s` | `35.961 s` | `29.499 s` | `3.771 s` | `233.343 s` | `13 188 317 637` |
| q92a binary | `172.457 s` | `30.806 s` | `28.794 s` | `3.817 s` | `235.886 s` | `15 198 054 646` |

Binary raw хуже на `8.350 s` / `5.09%`, E2E — на `2.543 s` / `1.09%`.
Stream вырос на `2 009 737 009` bytes / `15.24%`, peak admitted bytes — на
`15.97%`. WAL, temp bytes и final database size materially не изменились.
Для коротких GAR integers/flags/dates text representation компактнее суммы
binary value и обязательного four-byte field length, а type conversion
переносится из PostgreSQL в PHP. q92 отклонён после первого negative run;
implementation и option удалены, A/B не запускался.

## q93: Pre-Normalized COPY-Text Artifacts

q93 проверил архитектуру с полной materialization между XML и PostgreSQL.
Одноразовый диагностический normalizer выбрал те же `17` automatic pipeline
files, спланировал B100/G16 ranges и восемью fork workers записал ordered
private `0600` COPY-text segments в tmpfs. Перед прогоном все `28`
region-50/shared XML (`24 975 511 491` bytes) имели zero resident source cache.

| Этап | Width | Wall | Rows | Ranges | COPY bytes |
| --- | ---: | ---: | ---: | ---: | ---: |
| q93a normalize | W8 | `252.006 s` | `122 613 810` | `1 527` | `13 188 317 637` |
| q93b artifact load | P4 | `123.460 s` | `122 613 810` | — | `13 188 317 637` |
| q93c artifact load | P8 | `127.706 s` | `122 613 810` | — | `13 188 317 637` |

Normalization exact совпал с q90 text payload, но ещё до PostgreSQL был на
`87.899 s` / `53.56%` длиннее всего q90 raw. GNU time зарегистрировал
`1 308.94 s` user и `55.96 s` system CPU, `541%` среднего process CPU и
`125 460 KiB` max RSS. Host в среднем оставался `76.30%` idle; source device
читал `89.38 MiB/s` average / `470.00 MiB/s` peak. Основные file stages:
houses params `52.852 s`, administrative hierarchy `46.181 s`, change history
`44.397 s`, municipal hierarchy `25.109 s`.

P4 load использовал свежую PostgreSQL 17 базу, q90 settings и существующий
initial-load lifecycle снятия indexes/FK. Exact counts сошлись по каждой
таблице. Финальная база занимала `18 300 122 803` bytes, WAL —
`21 580 324 997` bytes. Database device писал `441.32 MiB/s` average и
`2 185.38 MiB/s` peak с `87.20%` peak utilization. Result с повторным
`count(*)` занял `130.398 s`; сам deterministic P4 COPY schedule —
`123.460 s`.

P8 не использовал свободные CPU в полезную работу: COPY стал хуже на `3.44%`,
host iowait вырос `1.76% → 4.70%`, write await `2.46 → 6.76 ms`. Change
history замедлился `89.923 → 127.692 s`, houses params
`76.046 → 124.767 s`, reestr objects `47.414 → 88.401 s`. Это contention
primary indexes/WAL, а не нехватка writer width.

Измеренный normalization demand равен `1 364.90 CPU-s`. Даже недостижимая
идеальная утилизация всех 24 CPU даёт `56.871 s`; вместе с лучшим P4 load это
`180.331 s`, на `9.89%` хуже q90 raw `164.107 s` ещё до ordinary files,
artifact/checkpoint metadata и recovery. q93 поэтому отклонён без production
implementation и A/B. Принятый fused path принципиально лучше: он перекрывает
XML parse/encode с COPY вместо последовательной оплаты обеих фаз.

## Research: Роль `change_history` И Delta-Обновления

Официальный XSD определяет `AS_CHANGE_HISTORY` как сведения по истории
изменений: `CHANGEID`, изменённый `OBJECTID`/GUID, тип операции, optional
нормативный документ и дату. ФНС отдельно указывает, что сведения ГАР
историчны и позволяют прослеживать адрес в разные периоды. Это реальная
audit/lineage-семантика, даже если текущий search runtime её не показывает.

Source inventory приложения не нашёл чтения `change_history` за пределами
schema/import lifecycle. `address_search` rebuild и OpenSearch projection
используют сами versioned canonical rows, их lifecycle flags/dates и hierarchy,
но не журнал изменений. Значит таблица не ускоряет текущий поиск и не выбирает
строки для обновления.

Она, однако, остаётся частью корректного delta baseline. Официальный metadata
endpoint публикует `GarXMLDeltaURL`; настоящий delta release `2026-11-13`
имеет размер `46 970 039 B`, содержит `version.txt` (`v.309`), изменённые
`AS_*` entity XML и отдельный `AS_CHANGE_HISTORY`. Для региона 50 журнал delta
содержит `16 855` изменений, но не является самодостаточным справочником всех
`CHANGEID` в entity-файлах:

| Region-50 delta file | CHANGEID references | Нет в delta `AS_CHANGE_HISTORY` |
| --- | ---: | ---: |
| `AS_ADDR_OBJ` | `25` | `6` |
| `AS_APARTMENTS` | `2 076` | `921` |
| `AS_HOUSES` | `3 143` | `640` |
| `AS_STEADS` | `2 048` | `142` |
| `AS_ADM_HIERARCHY` | `5 608` | `1 565` |
| `AS_MUN_HIERARCHY` | `5 608` | `1 565` |

Это ожидаемые ссылки на более старые транзакции из full baseline. Текущие FK
поэтому требуют сначала полной истории, а затем каждого delta-журнала; priority
catalog уже ставит `change_history` перед parameters и entity tables. Само
применение delta выполняется не через журнал: changed entity rows уже находятся
в соответствующих XML и обычный importer обновляет их через `ON CONFLICT`.

Сквозной delta lifecycle пока не является поддержанным контрактом. Код умеет
показать `delta_xml_url`, а standard importer технически принимает те же XML,
но operator guide явно выбирает full archive, Makefile скачивает только full,
`gar_versions` importer-ом не заполняется, predecessor/gap не проверяется и
паритет цепочки delta с новым full snapshot не доказан. Для local full release
`2026-09-01` до `2026-11-13` официальный каталог содержит `21` последующий
delta releases; применение только последнего пропустило бы промежуточные
изменения.

Решение: не добавлять `--exclude=change-history` и не ослаблять восемь
оставшихся FK ради initial-load benchmark. Полная история сохраняет audit и
даёт корректный baseline для будущих incremental updates. Если когда-нибудь
появится отдельный search-only продуктовый профиль без delta/audit, он должен
быть явным data contract, а не произвольным table filter.

Безопасный delta path требует отдельной реализации и acceptance:

1. скачивать full/delta с checksum и immutable release provenance;
2. хранить applied release identity и тип архива, а не только file manifest;
3. проверять строгую последовательность delta и fail-closed отклонять gap;
4. импортировать каждый архив dependency phases текущего catalog;
5. перестраивать затронутые `address_search` partitions и OpenSearch;
6. сравнить последовательную delta-chain с fresh full release по canonical и
   projection digests.

Источники: [официальный набор и XSD ГАР](https://www.nalog.gov.ru/opendata/7707329152-fias/),
[описание историчности ГАР ФНС](https://www.nalog.gov.ru/rn77/news/activities_fts/13824755/).

## q103: Post-Raw Index/FK Overlap

q102 показал новый барьер: после raw оставшиеся indexes полностью завершались
до начала FK validation. q103 сохранил raw path и PostgreSQL profile, но после
завершения raw и активной early-index задачи сначала атомарно восстановил все
FK как `NOT VALID`, а затем запустил final index и FK pools одновременно.

| Серия | Raw median | Index tail median | FK median | Post-raw median | E2E median |
| --- | ---: | ---: | ---: | ---: | ---: |
| q103b/c sequential control | `192.282 s` | `19.997 s` | `29.207 s` | `52.460 s` | `244.742 s` |
| q103a/d overlap | `178.185 s` | `33.189 s` actual | `37.012 s` actual | `40.801 s` | `218.986 s` |

Обе соседние пары прошли gate: E2E `212.372` против `248.137 s`
(`-14.41%`) и `225.600` против `241.348 s` (`-6.52%`). Median post-raw
сократился на `22.22%`, median E2E — на `10.52%`. Во всех четырёх points
получены `122 809 172` rows, одинаковые file/table/FK digests, `102` valid
indexes, `34/34` validated FK и нулевые backlogs.

Локальные index и FK durations выросли из-за совместной нагрузки и relation
locks, но теперь перекрываются. Operation-level `index_build_completed_at`
первоначально ставился после joined await и не являлся временем окончания
index pool. Actual snapshot timestamps показывают, что indexes завершились на
`7.874 / 3.634 s` раньше FK; telemetry исправлена на
`max(snapshot.completed_at)`. Поэтому q103 принят, но residual bottleneck уже
находился в FK critical chain, а не в синхронном завершении двух pools.

## q104: Index-First Table Admission

q104 допускал FK конкретной referencing table только после завершения всех её
pending indexes; независимые tables продолжали выполняться параллельно.

| Raw | Actual index tail | Actual FK span | Post-raw | E2E |
| ---: | ---: | ---: | ---: | ---: |
| `172.923 s` | `27.314 s` | `38.875 s` | `42.350 s` | `215.273 s` |

Correctness полностью совпала: `122 809 172` rows, прежние file/table/FK
digests, `102` valid indexes, `34/34` validated FK и нулевые backlogs. Но E2E
лучше q103 candidate median лишь на `1.695%`, тогда как post-raw хуже на
`3.797%`. Actual index pool завершился в `16:38:29.942`, а последняя
`object_params` FK — в `16:38:43.175`, через `13.233 s`.

Отрицательный результат причинный: admission сократил index tail, но не serial
FK chain. В post-raw окне после завершения indexes host idle вырос до
`91–93%`, NVMe utilization упал до `3–5%`; `object_params` validation оставался
единственной длинной работой. q104 отклонён без crossover, implementation и
CLI option удалены. Общий table barrier больше не входит в очередь.

## q105: Native FK Validation Plans

`auto_explain` снял планы внутренних запросов `RI_Initial_Check()` на принятой
q103 topology. Diagnostic сохранил exact `122 809 172` rows/digests,
`102` valid indexes, `34/34` FK и zero backlogs; E2E составил `215.092 s`.

| `object_params` constraint | Duration | Referenced rows | Actual plan |
| --- | ---: | ---: | --- |
| `change_id` | `23.374 s` | `27 599 809` | serial Hash Anti Join |
| `object_id` | `8.583 s` | `7 673 256` | serial Hash Anti Join |
| `type_id` | `3.019 s` | `22` | serial Hash Anti Join |

Каждый запрос полностью прочитал `49 147 843` referencing rows. Estimates
были точны, но ни один план не содержал `Gather` или workers. Причина находится
в PostgreSQL 17: `RI_Initial_Check()` вызывает `SPI_prepare()`, тот передаёт
`cursorOptions = 0`, а parallel planner допускается только с
`CURSOR_OPT_PARALLEL_OK`. Поэтому дополнительные parallel-worker limits и
pre-validation `ANALYZE` отклонены без лишних full runs.

## q106: Visibility-Map Component Gate

На retained exact базе fresh native heap plans заняли
`20.408 + 8.866 + 3.080 = 32.354 s`. После установки visibility map все
`584 781` pages стали all-visible:

| Cycle | VACUUM | Three validations | Combined |
| --- | ---: | ---: | ---: |
| VM candidate A | `2.140 s` | `10.520 s` | `12.660 s` |
| VM candidate B | `2.120 s` | `10.370 s` | `12.490 s` |

VM-reset controls не использованы как production baseline: extension очистил
map, но не `pg_class.relallvisible`, и planner выбрал index-only scans с heap
fetches. Положительный component margin разрешил cold production crossover,
но сам по себе не являлся acceptance evidence.

## q107: Post-Raw Visibility Crossover

Candidate выполнял `VACUUM (ANALYZE) object_params` после raw и до atomic FK
restore. Все четыре cold точки сохранили полную correctness.

| Point | Raw | Post-raw | E2E | Decision signal |
| --- | ---: | ---: | ---: | --- |
| q107a candidate | `180.116 s` | `35.838 s` | `215.954 s` | VACUUM `3.825 s` |
| q107b control | `187.870 s` | `48.249 s` | `236.119 s` | adjacent gain `8.54%` |
| q107c control | `188.793 s` | `42.748 s` | `231.541 s` | stable control raw |
| q107d candidate | `178.465 s` | `53.295 s` | `231.760 s` | adjacent regression `0.09%` |

Candidate medians выглядят положительно (`E2E -4.264%`, post-raw `-2.048%`),
но это ложный aggregate: raw кандидатов быстрее controls на `4.801%`, хотя
VACUUM начинается только после raw. Соседний reverse pair сменил знак;
post-raw q107d ухудшился на `24.67%`. VACUUM занял `8.026 s`, а actual index
tail вырос до `49.447 s`, практически совпав с FK completion. q107 отклонён,
production implementation удалена.

Новый causal admission point найден в file telemetry: последний writer
`object_params` завершился за `76.837 s` до raw, а table-local index chain —
за `11.947 s` до raw. Следующий q108 проверяет VACUUM после завершения всех
writers таблицы, до её early indexes; тогда обслуживание VM должно быть
скрыто последующим XML/COPY вместо добавления post-raw latency.

## q108–q110: Early Visibility Admission

q108 запустил VACUUM после последнего writer `object_params`, но до четырёх
table-local indexes. Exact q108a дал raw/post-raw/E2E
`171.082 / 42.318 / 213.400 s`; VACUUM занял `2.591 s`, а три target FK —
`35.861 s`. Полезная VM не возникла, поэтому быстрый E2E был только raw drift,
и candidate отклонён без crossover.

q109 переставил VACUUM после завершения table-local indexes:

| Point | Raw | Post-raw | E2E | Three target FK |
| --- | ---: | ---: | ---: | ---: |
| q109a candidate | `176.012 s` | `34.907 s` | `210.919 s` | `19.057 s` |
| q109b control | `195.510 s` | `45.496 s` | `241.006 s` | `35.582 s` |
| q109c control | `174.973 s` | `41.559 s` | `216.533 s` | `34.292 s` |
| q109d candidate | `189.176 s` | `40.462 s` | `229.638 s` | `22.482 s` |

Mechanism стабилен, но reverse candidate ухудшил E2E на `6.05%`; post-raw
улучшился только на `2.64%`, а actual index tail вырос
`31.209 → 36.544 s`. Blocking VACUUM вытеснил remaining index work.

q110 устранил блокировку PHP scheduler отдельным bounded AMP process. Exact
first candidate дал raw/post-raw/E2E `178.347 / 39.020 / 217.367 s`, VACUUM
`4.351 s`, target FK `24.760 s`, actual index tail `37.109 s`. Относительно
adjacent q109c control post-raw улучшился на `6.11%`, но E2E ухудшился на
`0.385%`; относительно synchronous q109d index tail ухудшился на `1.55%`.
Следовательно, остаточная цена находится в PostgreSQL resource competition
между VACUUM и CREATE INDEX, а не в event-loop scheduling. q108–q110 rejected,
их implementation удалена; q103 остаётся production baseline.

## q111: Dynamic Remaining-Index-Work LPT

q111 заменил heap-size ordering на динамическую оценку
`heap bytes × pending index count`, сохранив early I1, final I4 и
one-index-per-table. Exact candidate дал raw/post-raw/E2E
`173.811 / 50.852 / 224.663 s`, actual index tail `36.995 s` и FK validation
`47.581 s`.

Формула нарушила table-chain completion. После третьего индекса
`object_params` её remaining score уменьшился, scheduler переключился на
hierarchy, а `idx_object_params_type_id` попал в final pool одновременно с FK
той же таблицы. Его duration вырос с обычных `~12 s` до `34.348 s`; target FK
заняли `23.724 + 18.599 + 2.723 = 45.046 s`. E2E ухудшился на `2.59%` к q103
candidate median и на `3.75%` к q109c control, несмотря на более быстрый raw.
q111 отклонён без crossover, implementation/test удалены. Heap-size ordering
сохраняется как механизм завершения уже начатой largest-table chain.

## q112–q114: Deferred Primary Keys

q112 изолировал `object_params` на точном нормализованном COPY-text artifact:
`49 147 843` строки и `4 072 151 024` bytes. COPY с активным PK занял в
среднем `102.193 s`; COPY в heap с последующей уникальной сборкой и
`ADD CONSTRAINT ... PRIMARY KEY USING INDEX` — `51.170 s`, то есть на
`49.93%` быстрее. WAL сократился примерно на `42.16%`, а bulk-built backing
index был на `23.77%` меньше. Duplicate row и duplicate bulk build были
отклонены, поэтому component gate разрешил production lifecycle.

q113 добавил явный `--defer-primary-keys`, доступный только вместе с
`--initial-load --direct-copy --defer-foreign-keys`. Snapshot хранит отдельно
точные определения и SHA-256 backing index и primary constraint. В `prepare`
сначала снимаются FK, затем PK; при завершении все PK обязаны пройти
create/attach/definition/ownership gate до восстановления FK. Recovery
идемпотентен как после create, так и между create и attach.

Полный region-50 control/candidate/control/candidate сохранил exact
`122 809 172` rows, оба digest, `102` valid indexes, `34/34` validated FK и
нулевые backlog. Candidate snapshot inventory равен `77` secondary плюс `23`
primary:

| Metric | Active PK mean | Deferred PK mean | Change |
|---|---:|---:|---:|
| raw | `197.216 s` | `145.158 s` | `-26.40%` |
| E2E | `242.409 s` | `205.660 s` | `-15.16%` |
| WAL (LSN) | `24.440 GB` | `16.465 GB` | `-32.63%` |
| final index bytes | `9.547 GB` | `8.526 GB` | `-10.69%` |

Соседние E2E-пары дали `-16.68%` и `-13.63%`; q113 принят. Post-raw work
увеличился, но существенно меньшие raw и WAL полностью перекрыли эту цену.

q114 глобально поднял готовые PK выше secondary indexes в early I1. Все `23`
PK действительно завершились к raw boundary, но тяжёлые secondary tasks ушли
в хвост: raw составил `144.684 s`, post-raw index `56.193 s`, E2E
`213.629 s`. Это на `3.87%` хуже q113 mean, поэтому candidate отклонён без
crossover и heap-size-first ordering восстановлен. Дополнительный ранний
worker также не возвращается: q57 уже показал I2 regression `2.768%` к I1
repeat. Измеренный PK scheduling/configuration frontier исчерпан.

## Резерв После q93

Оставшиеся варианты разделены по ожидаемой цене доказательства. Это очередь
гипотез, а не обещание суммарного ускорения:

Delta lifecycle вынесен в отдельную будущую задачу: текущая серия продолжает
только immutable full fixture, иначе весь benchmark потребует нового baseline.

1. q82/q83 уже разделили последовательную пару
   `AS_ADM_HIERARCHY → AS_MUN_HIERARCHY`; selective F2 ухудшил её wall time.
   Следующая raw-гипотеза должна менять transport/transaction topology, а не
   повторять parser-width или table-group scheduling.
2. q85 закрыл performance frontier оставшихся `26` B-tree измеренной верхней
   границей `1.224%`. Их удаление остаётся отдельной schema cleanup, но больше
   не входит в E2E optimization queue.
3. q86 закрыл reuse FK-процессов: reverse E2E выиграл только `0.530%`.
   Finalization не следует снова дробить на bootstrap knobs без нового профиля.
4. q87 закрыл текущую реализацию COPY-text spool по liveness: готовые tmpfs
   chunks остались без dispatch при idle worker pools. Локальных вариантов в
   прежней очереди больше нет.
5. q88 fresh profile выбрал q89: targeted final-pool width `2` только для
   `administrative_reestr_hierarchy`, чья serial chain заняла почти весь tail.
   q89 отклонён: tail `-7.94%`, E2E `+2.47%`; следующий вариант не должен
   возвращать same-table width без нового workload evidence.
6. q90 локализовал FK floor в трёх serial `object_params` constraints:
   `18.157 + 8.162 + 2.758 s`. Same-table parallel validation закрыта
   self-conflict блокировки `SHARE UPDATE EXCLUSIVE`, а PostgreSQL source
   подтвердил отдельный `RI_Initial_Check()` на каждую FK даже внутри одного
   `ALTER TABLE`.
7. q91 исчерпал global-dispatcher retry q87: четыре early chunks снова
   stranded при полностью idle parser/writer pools и PostgreSQL.
8. q92 исчерпал binary COPY: exact correctness прошла, но raw/E2E ухудшились,
   а encoded stream вырос на `15.24%`.
9. q93 исчерпал pre-normalized COPY-text: W8 preparation `252.006 s`, лучший
   P4 load `123.460 s`, P8 load хуже на `3.44%`. Даже идеальный 24-CPU floor
   суммы `180.331 s` не достигает q90 raw.
10. q103 принял post-raw overlap index/FK. q104 index-first table admission
    отклонён без crossover: post-raw ухудшился, а actual index/FK completion
    разошлись на `13.233 s`. Следующая гипотеза должна сокращать serial
    `object_params` FK chain или DB-side raw cost; отдельные I4/W4 knobs и
    общий table barrier исчерпаны.
11. q105 закрыл parallel/ANALYZE tuning native FK plans. q106 открыл
    visibility-map candidate, но q107–q110 исчерпали post-raw, before-index и
    after-index placements. Полезная VM требует момента после table indexes,
    где VACUUM уже конкурирует с remaining CREATE INDEX и не улучшает E2E.
12. q111 закрыл dynamic remaining-work LPT. Пересчёт веса после каждого index
    фрагментирует тяжёлую `object_params` chain и оставляет same-table index
    конкурировать с serial FK critical path.
13. q112/q113 приняли deferred PK lifecycle; q114 закрыл global primary-first
    scheduling. Дальнейшая перестановка early I1 не поддержана E2E evidence, а
    I2 уже отклонён q57.

Из исходных `41` q40 B-tree candidates больше нет непроверенного performance
frontier. Шесть hierarchy classifier-code indexes отклонены q49, два hierarchy
`end_date` сохранены как используемые predicate paths, а оставшиеся `26`
закрыты q85 верхней границей и перенесены в schema cleanup.

Binary COPY, pre-normalized artifacts, q104 table admission, q107–q110
visibility preparation и q111 dynamic scheduling больше не входят в очередь.
Локальные parser/index/FK configuration knobs исчерпаны. Следующий осмысленный
region-50 loop должен менять topology: сначала отдельный component A/B для
deferred PK/unique build на empty-database path, и только при положительной
сумме `COPY + constraint rebuild` проектировать production recovery contract.
`COPY FREEZE` и
`wal_level=minimal` не являются безопасными knobs текущего batch/checkpoint
пути, потому что их bulk-load преимущества требуют иной transaction/table
topology и, для minimal WAL, отдельного operational profile. PostgreSQL также
рекомендует bulk load через `COPY`, создание индексов после загрузки и
отложенное применение FK, что уже реализовано текущим baseline:
[COPY](https://www.postgresql.org/docs/17/sql-copy.html),
[Populating a Database](https://www.postgresql.org/docs/17/populate.html),
[CREATE INDEX](https://www.postgresql.org/docs/17/sql-createindex.html).

## Почему Помог Порядок Индексов И Удаление GIN

Алфавитная очередь q22 поздно начинала `object_params`. Последний
`idx_object_params_value` стартовал через `166.9 s` после начала index phase,
работал около `79 s` и остался единственным хвостом.

q23 начинает очереди четырёх крупнейших heaps сразу:

- `object_params`;
- `change_history`;
- `administrative_reestr_hierarchy`;
- `municipal_reestr_hierarchy`.

`idx_object_params_value` стартовал через `112.9 s`. Из-за конкуренции с
hierarchy indexes он работал дольше, но перекрытие всё равно уменьшило общий
makespan с `246.193` до `208.455 s`. Дальнейшее увеличение внешнего pool не
устраняло последний индекс: после исчерпания остальных table queues он снова
оставался единственным. q39 устранил сам неиспользуемый consumer-ом GIN. В
новом index tail последняя одиночная работа длилась лишь несколько секунд;
прежний эксперимент по same-table overlap больше не имеет достаточного
основания и уступает полному аудиту необходимости остальных secondary indexes.
q42 дополнительно устранил build/setup cost `28` мелких trigram GIN; суммарный
canonical index footprint уменьшился ещё на `323 084 288 B`.
q43 убрал ещё `30` standalone B-tree, уже заменённых шестью composite access
paths, и сократил повторную index-фазу ещё на `9.199 s`.
q44 убрал `16` B-tree, оставшихся после снятия необязательных self-FK
`prev_id`/`next_id`, и сократил index-фазу ещё на `21.146 s`. После этого
наблюдаемый одиночный tail снова принадлежит `object_params`; его обязательный
composite и FK indexes нельзя смешивать с отдельно проверяемыми lifecycle
candidates.
q45 убрал три крупных date B-tree из очереди `object_params`; повторная фаза
сократилась ещё на `6.209 s`. Новый tail — `idx_object_params_type_id`,
обязательный referencing-side FK index. q46 затем убрал более ранний
raw-reference `idx_object_params_change_id_end` без consumer и сократил фазу
ещё на `10.325 s`. Оставшаяся очередь `object_params` состоит из denorm
composite и трёх обязательных referencing-side FK indexes; её дальнейшее
сокращение удалением не рассматривается.
q47 убрал шесть поздних unit-GUID builds, которые дублировали authoritative
registry identity и не имели consumer. Reverse-order control подтвердил, что
они добавляли около `12.155 s` к общему four-queue makespan в сопоставимом
host-окне.
q48 убрал четыре ранних hierarchy cross-link builds без FK или consumer.
Из-за их конкуренции с другими индексами больших hierarchy queues абсолютный
эффект менялся вместе с storage window, но обе соседние crossover-пары с
обратным порядком сократили makespan на `2.876 s` и `12.091 s`. Unpaired
median не прошла gate и отдельно не используется как аргумент принятия.
q49 показал границу такого удаления: шесть classifier-code builds занимали
место, но их отсутствие не сократило four-queue makespan ни в одном порядке.
Они остаются в schema до нового query/policy evidence. q50 удалил только
четыре unqueried start/update builds, сохранив end-date queues; две crossover
пары сократили index makespan на `30.145 s` и `3.566 s`.

## Ресурсы

| Точка | PostgreSQL CPU avg / p95 / max | RSS p95 / max |
| --- | ---: | ---: |
| I2 baseline | `136.72 / 459.82 / 605.89%` | `13 773 / 16 026 MiB` |
| W8/P4 | `136.18 / 391.56 / 677.70%` | `13 199 / 14 930 MiB` |
| I3 | `156.03 / 576.58 / 957.93%` | `13 148 / 14 674 MiB` |
| I4 | `162.10 / 631.19 / 1246.02%` | `13 558 / 14 940 MiB` |
| I4 + LPT | `173.70 / 668.16 / 1592.12%` | `12 698 / 14 858 MiB` |
| I4 + LPT + auto | `211.41 / 761.39 / 1540.74%` | `13 148 / 14 582 MiB` |
| I4 + LPT + auto, P8 | `206.02 / 682.74 / 1341.07%` | `11 581 / 13 906 MiB` |
| PG workers 24/20/8 | `218.80 / 720.77 / 1570.38%` | `12 134 / 13 179 MiB` |
| PG I/O concurrency 32/16 | `196.40 / 700.12 / 1480.78%` | `12 196 / 13 189 MiB` |
| Maintenance memory 4GB, q30 | `202.58 / 702.15 / 1125.22%` | `13 005 / 15 299 MiB` |
| Maintenance memory 4GB, q31 | `200.53 / 719.67 / 1344.77%` | `13 445 / 15 370 MiB` |
| Shared buffers 12GB | `197.85 / 696.07 / 1291.78%` | `15 503 / 17 439 MiB` |
| Parallel parser R4 | `268.69 / 759.31 / 1389.49%` | `12 175 / 13 179 MiB` |
| Parallel parser R8, PHP 1G | `283.89 / 760.63 / 1296.33%` | `13 957 / 15 186 MiB` |
| Fused parallel parser R8 | `285.82 / 794.28 / 1322.64%` | `12 247 / 14 121 MiB` |
| Fused parallel parser R12 | `287.88 / 776.58 / 1310.74%` | `12 298 / 13 107 MiB` |
| All-compatible fused R8 | `327.89 / 772.96 / 1047.74%` | `12 093 / 13 261 MiB` |
| q39 без raw-value GIN | `332.95 / 798.90 / 1219.80%` | `12 237 / 13 066 MiB` |
| q41 без hierarchy path GIN | `321.95 / 899.09 / 1214.20%` | `11 244 / 12 687 MiB` |
| q42 без canonical trigram GIN | `307.69 / 871.13 / 1399.41%` | `11 551 / 12 339 MiB` |
| q43 без unit lifecycle B-tree | `287.30 / 858.89 / 1202.71%` | `11 561 / 12 626 MiB` |
| q44 без detached version-link B-tree | `310.70 / 839.47 / 1301.43%` | `13 793 / 14 305 MiB` |
| q45 без object-parameter lifecycle B-tree | `287.21 / 915.79 / 1239.93%` | `11 305 / 12 616 MiB` |
| q46 без object-parameter change-end B-tree | `277.56 / 835.24 / 1314.06%` | `11 469 / 12 636 MiB` |
| q47 без duplicated unit-GUID B-tree | `310.31 / 831.13 / 1362.80%` | `11 469 / 12 954 MiB` |
| q48 без hierarchy cross-link B-tree | `278.20 / 790.20 / 1043.69%` | `10 895 / 11 919 MiB` |
| q50 без hierarchy start/update B-tree | `255.68 / 842.78 / 1155.08%` | `11 633 / 12 892 MiB` |

На host `12C/24T, 58 GiB RAM` I4/LPT лучше использует CPU без роста peak RSS.
Physical NVMe q23 за полный путь имел average/p95 utilization
`23.58/54.40%`; в index window — `32.71/61.12%`. Index p95 write await и queue
выросли до `17.13 ms` и `21.42`, но utilization max был `66.24%`, throughput
продолжил расти, а фаза сократилась на `15.33%`. Sustained disk saturation не
доказан; это более плотная, но ещё эффективная загрузка NVMe.

В q24 physical NVMe за полный путь имел average/p95 utilization
`26.72/73.20%`. Raw window оставался свободным: `17.70/38.56%`, p95 write
await `8.91 ms`, queue `14.37`. Index window стал плотным:
average/p95/max util `40.19/85.68/95.60%`, p95 write await `20.52 ms`, queue
`36.57`; index phase при этом выросла на `10.705%` (`+22.315 s`) к q23.
Один прогон не отделяет конкуренцию соседних host workloads от предела NVMe,
но I4 нельзя повышать без отдельного подтверждения: следующая безопасная ось —
raw pipeline width при неизменном I4.

Importer q24 использовал CPU avg/p95/max `84.46/219.60/309.44%` и RSS
avg/p95/max `266.99/393.60/405.90 MiB`. Host raw-window busy CPU был в среднем
лишь `15.29%`, поэтому CPU запас для следующей writer-width точки сохраняется.

q25 не превратил этот запас в throughput: importer CPU avg/p95/max остался
`84.85/220.83/307.18%`, тогда как RSS avg/p95/max вырос до
`406.52/552.60/563.80 MiB`; max RSS увеличился на `38.9%`. Raw NVMe также не
был пределом: util avg/p95 `15.93/22.24%`, p95 write await `4.52 ms`, queue
`4.68`. Index window остался сопоставимым с q24: util avg/p95/max
`39.67/88.48/92.64%`, p95 write await `19.10 ms`, queue `38.86`.
Совокупность unchanged large-file duration, почти неизменного importer CPU и
роста памяти означает, что P4 уже успевает принимать поток single XMLReader /
encoder; дополнительные writers только расширяют очередь.

В q27 importer CPU avg/p95/max снизился до `78.92/228.21/330.64%`, но RSS
avg/p95/max вырос до `411.60/554.30/574.50 MiB`. Raw NVMe util avg/p95 стал
`23.36/51.52%`, p95 write await `22.91 ms`, queue `17.27`: B100 создал более
плотные write bursts без throughput gain. Возврат B50/W16 одновременно быстрее
и экономнее по памяти.

В q33 importer CPU avg/p95/max вырос до `136.00/501.34/555.77%`: свободный CPU
наконец превратился в raw throughput. RSS avg/p95/max составил
`471.80/761.10/800.70 MiB`. Raw NVMe util avg/p95 был лишь
`18.71/30.24%`, p95 write await `9.81 ms`, queue `9.58`; sustained disk
saturation снова не возник. В index window util avg/p95 `36.23/74.24%`, p95
write await `18.58 ms`, queue `26.04`. R8 поднял importer RSS до
`754.56/1 135.62/1 242.11 MiB`, не ускорив whole raw import.

В q36 importer CPU avg/p95/max составил `122.67/576.74/668.92%`, а RSS
снизился до `144.13/385.50/456.50 MiB`: max на `43.0%` ниже q33 и на `63.2%`
ниже q35. PostgreSQL p95/max RSS был `12 247/14 121 MiB`; после cleanup host
сохранил около `19 GiB` available memory. Physical NVMe за полный путь имел
average/p95 utilization `22.98/55.87%`, target — `28.95/31.04%`; sustained
disk saturation не возник.

В q37 importer CPU avg/p95/max остался `122.54/616.79/776.86%`, а RSS вырос
до `158.24/469.30/583.40 MiB`. Physical NVMe full-run p95 utilization вырос
`55.87 → 64.88%`, p95 write await — `6.12 → 17.46 ms`, но raw throughput не
улучшился. Это закрывает R12 как более дорогую ширину без whole-run gain.

В q38 importer CPU avg/p95/max составил `141.69/464.01/556.03%`, RSS —
`171.50/361.20/428.50 MiB`; PostgreSQL max RSS снизился до `13 260.80 MiB`.
Physical NVMe за весь путь имел average/p95 utilization `34.75/80.00%`,
в raw window — `30.80/58.96%` и p95 write await `19.29 ms`. Raw значительно
ускорился без sustained disk saturation; на host осталось около `19 GiB`
available memory, OOM и нового swap не было.

В q39 importer CPU avg/p95/max составил `163.10/476.62/571.11%`, RSS —
`180.56/360.60/435.80 MiB`; PostgreSQL max RSS — `13 066.24 MiB`. Index-window
NVMe util avg/p95/max вырос до `46.72/89.92/93.28%`, но p95 write await и
queue снизились относительно q38 с `28.67 ms / 50.79` до
`27.32 ms / 40.49`; более короткая фаза не создала sustained saturation. За
прогон не было OOM или swap-in; host записал только `17` swap pages и после
cleanup сохранял около `10.9 GiB` available memory при работающих соседних
сервисах.

В q41 importer CPU avg/p95/max составил `178.10/470.44/522.56%`, RSS —
`186.35/353.30/428.20 MiB`; PostgreSQL max RSS снизился до `12 687.36 MiB`.
Index-window NVMe util avg/p95/max был `53.99/92.40/93.44%`, p95 write await
`50.23 ms`, queue `49.16`. Более плотный оставшийся B-tree build создаёт bursts,
но max utilization не вырос materially, фаза стала короче на `34.542 s`, OOM
и swap-in не было. Host завершил прогон с примерно `10.9 GiB` available memory.

В принятом q42 repeat importer CPU avg/p95/max составил
`180.87/474.22/568.51%`, RSS — `197.16/377.20/407.40 MiB`; PostgreSQL max RSS
снизился до `12 339.20 MiB`. Index-window NVMe util avg/p95/max был
`57.18/92.40/93.36%`, p95 write await `19.34 ms`, queue `42.23`: max utilization
не вырос, а latency/queue снизились относительно q41. OOM не было; swap-in/out
составил лишь `212/759` pages. Первый q42 с системным подвисанием не используется
для E2E или resource baseline.

В принятом q43 repeat importer CPU avg/p95/max составил
`173.30/471.61/542.92%`, RSS — `191.51/338.00/399.00 MiB`; PostgreSQL RSS
p95/max — `11 560.96/12 625.92 MiB`. Index-window NVMe util avg/p95/max был
`52.04/96.40/97.92%`, p95 write await `47.92 ms`, queue `49.04`. Короткие
bursts приблизились к насыщению, но index phase сократилась на `6.333%`, OOM
не было, swap-in/out составил `2/0` pages.

В q44 importer CPU avg/p95/max составил `174.21/447.51/527.02%`, RSS —
`197.27/363.10/418.70 MiB`; PostgreSQL RSS p95/max —
`13 793.28/14 305.28 MiB`. Index-window physical NVMe util avg/p95/max был
`41.32/81.84/93.84%`, p95 write await `25.00 ms`, queue `39.57`. Фаза стала
короче на `15.544%` без sustained saturation; OOM не было, swap-in/out
составил `1/87` pages.

В принятом q45 repeat importer CPU avg/p95/max составил
`182.87/452.70/487.19%`, RSS — `206.99/345.50/392.40 MiB`; PostgreSQL RSS
p95/max — `11 304.96/12 615.68 MiB`. Index-window physical NVMe util
avg/p95/max был `55.68/89.52/90.24%`, p95 write await `23.31 ms`, queue
`43.93`. Первый q45 был плотнее (`65.52%` average util), но обе index-фазы
ускорились; OOM не было, repeat swap-in/out составил `17/47` pages.

В q46 importer CPU avg/p95/max составил `195.14/469.02/507.48%`, RSS —
`210.90/361.60/412.00 MiB`; PostgreSQL CPU —
`277.56/835.24/1314.06%`, RSS p95/max — `11 468.80/12 636.16 MiB`.
Index-window physical NVMe util avg/p95/max был `64.65/91.52/95.04%`, p95
write await `36.65 ms`, queue `39.04`. Краткие пики приблизились к пределу,
но utilization не был sustained, queue ниже q45, а index-фаза сократилась на
`9.500%`. OOM не было; swap-in/out составил `7/47` pages.

В принятом q47 repeat importer CPU avg/p95/max составил
`192.24/458.08/560.54%`, RSS — `212.95/358.90/402.30 MiB`; PostgreSQL CPU —
`310.31/831.13/1362.80%`, RSS p95/max — `11 468.80/12 953.60 MiB`.
Index-window physical NVMe util avg/p95/max был `48.08/82.96/82.96%`, p95
write await `34.09 ms`, queue `32.55`; sustained saturation и OOM не было,
swap-in/out составил `105/197` pages. Первый q47 не используется как resource
baseline из-за p95 write await `60.69 ms`; reverse control подтвердил
физический q46 index time `97.456 s`.

В representative q48 repeat importer CPU avg/p95/max составил
`197.04/470.18/569.40%`, RSS p95/max — `348.30/407.90 MiB`; PostgreSQL CPU —
`278.20/790.20/1043.69%`, RSS p95/max — `10 895.36/11 919.36 MiB`.
Swap-in/out не изменился. Из-за широкого разброса storage windows ресурсная
точка описывает capacity, но не используется вместо двух crossover index
comparisons как доказательство ускорения.

В принятом q50 repeat importer CPU avg/p95/max составил
`192.52/464.62/555.92%`, RSS p95/max — `325.40/382.50 MiB`; PostgreSQL CPU —
`255.68/842.78/1155.08%`, RSS p95/max — `11 632.64/12 892.16 MiB`.
Swap-in не происходил, swap-out не изменился. Как и для q48, capacity metrics
не заменяют две paired index comparisons: raw drift делает непарный E2E
непригодным для причинного вывода.

## Correctness, WAL И Размер

Все завершённые точки, включая отклонённые q25–q32, q35 и q37, а также принятые
q33/q36/q38/q39/q41/q42/q43/q44/q45/q46/q47/q48/q50, отклонённый q49 и
reverse controls, а также q59/q64 crossover, прошли
одинаковые data/FK/backlog gates:

- `122 809 172` processed/merged rows и `28/28` files;
- file digest `6c5d212b983cbcab57ae9ce322643bc6`;
- table digest `d309fca782a378b32a6fb0dd4c0fad50`;
- до q38 включительно `206` canonical indexes, invalid/unready `0`; q39 после
  первой намеренной schema migration — `205`, q41 после второй — `201`, q42
  после третьей — `173`, q43 после четвёртой — `143`; во всех точках
  invalid/unready `0`; q44 после пятой — `127`, q45 после шестой — `124`,
  q46 после седьмой — `123`, q47 после восьмой — `117`, q48 после девятой —
  `113`, q50 после десятой — `109`, invalid/unready `0`; q49 diagnostic
  временно имел `107`, после reject control восстановил `113`; q59 после
  одиннадцатой migration — `106`, q64 после двенадцатой — `102`;
- `34` canonical FK, unvalidated `0`, definition digest
  `21e41661a1e2fd637b80356a2ed28d7e`;
- range/index/FK/incomplete/dangling backlogs `0/0/0/0/0`.

До q38 WAL LSN delta оставался в диапазоне `29.697–29.719 GB`, database size —
`32.925–32.936 GB`. q39 снизил WAL на `612 401 632 B` (`-2.062%`), index bytes
на `759 857 152 B` (`-4.067%`, около `724.7 MiB`) и database size на
`760 594 432 B` (`-2.310%`). Почти полное совпадение последних двух deltas
локализует изменение в удалённом GIN, а неизменные table/file/FK digests
подтверждают сохранение содержимого и durability.

От q39 к q41 index bytes уменьшились ещё на `2 466 037 760 B` (`-13.759%`),
database size — на `2 465 357 824 B` (`-7.663%`), WAL LSN bytes — на
`975 037 464 B` (`-3.352%`). Совпадение storage deltas снова локализует эффект
в намеренно отсутствующих индексах; file/table/FK digests и все пять backlog
остались неизменными.

От q41 к принятому q42 repeat index bytes уменьшились ещё на `323 084 288 B`
(`-2.090%`), database size — на `324 280 320 B` (`-1.092%`), WAL LSN bytes —
на `182 506 880 B` (`-0.649%`). Exact file/table/FK digests и пять backlog
остались неизменными; финальный inventory — `173` valid/ready indexes.

От q42 к принятому q43 repeat index bytes уменьшились ещё на `367 616 000 B`
(`-2.429%`), database size — на `367 026 176 B` (`-1.249%`), WAL LSN bytes —
на `196 725 688 B` (`-0.704%`). Exact digests/backlogs снова совпали; финальный
inventory — `143` valid/ready indexes.

От принятого q43 repeat к q44 index bytes уменьшились ещё на `732 004 352 B`
(`-4.957%`), database size — на `731 832 320 B` (`-2.522%`), WAL LSN bytes —
на `372 028 456 B` (`-1.342%`). Exact digests/backlogs снова совпали; финальный
inventory — `127` valid/ready indexes и `102/102` восстановленных snapshots.

От q44 к принятому q45 repeat index bytes уменьшились ещё на
`1 030 930 432 B` (`-7.346%`), database size — на `1 030 701 056 B`
(`-3.644%`), WAL LSN bytes — на `575 137 800 B` (`-2.102%`). Exact
digests/backlogs совпали; финальный inventory — `124` valid/ready indexes и
`99/99` восстановленных snapshots.

От принятого q45 repeat к q46 index bytes уменьшились ещё на
`496 091 136 B` (`-3.815%`), database size — на `496 377 856 B`
(`-1.821%`), WAL LSN bytes — на `253 666 744 B` (`-0.947%`). Exact
digests/backlogs совпали; финальный inventory — `123` valid/ready indexes и
`98/98` восстановленных snapshots.

От q46 к принятому q47 repeat index bytes уменьшились ещё на
`281 010 176 B` (`-2.247%`), database size — на `280 911 872 B`
(`-1.050%`), WAL LSN bytes — на `249 762 024 B` (`-0.941%`). Exact
digests/backlogs совпали; финальный inventory — `117` valid/ready indexes и
`92/92` восстановленных snapshots. В парном reverse control storage deltas
составили `-282 714 112 B`, `-282 517 504 B` и `-239 663 280 B`.

В соседней q48 repeat/control паре index bytes уменьшились ещё на
`691 732 480 B` (`-5.658%`), database size — на `690 921 472 B`
(`-2.610%`), WAL LSN bytes — на `385 120 536 B` (`-1.466%`). Exact
digests/backlogs совпали; финальный inventory — `113` valid/ready indexes и
`88/88` восстановленных snapshots. Storage delta стабилен по q48 points и
не зависит от временного разброса index duration.

Отклонённый q49 сокращал index bytes на `630 988 800 B` (`-5.471%`), database
size на `631 005 184 B` (`-2.447%`) и WAL LSN bytes на `321 719 008 B`
(`-1.243%`), но не прошёл performance gate, поэтому эта экономия не вошла в
schema baseline.

В q50 repeat/control паре index bytes уменьшились на `358 817 792 B`
(`-3.112%`), database size — на `358 825 984 B` (`-1.392%`), WAL LSN bytes —
на `192 496 048 B` (`-0.744%`). Exact digests/backlogs совпали; финальный
inventory — `109` valid/ready indexes и `84/84` восстановленных snapshots.

В двух q64 crossover-парах четыре `reestr_objects` indexes занимали
`333 185 024 B`. Средние index bytes уменьшились примерно на `332.4 MB`, WAL
LSN bytes — на `174 920 656 B` (`-0.710%`). Exact digests/backlogs совпали;
финальный inventory — `102` valid/ready indexes против `106` у q64 control.

## Правило Проектирования Индексов

Secondary indexes создаются по потребности подтверждённого consumer, а не
профилактически «на будущее». Для каждого нового индекса должны быть названы
конкретный production query/constraint path, его предикат и ожидаемая
селективность; `EXPLAIN` или query benchmark подтверждает выбранный access
method. После этого read gain сравнивается с ценой initial-load, incremental
writes, WAL, storage и recovery.

Нулевой `pg_stat_user_indexes.idx_scan` является только сигналом для аудита:
редкий integrity, recovery, delete/update или referential path может быть
существенным. Удаление допускается после полного source inventory и проверки
планов materially distinct запросов. Если будущий сценарий действительно
потребует fuzzy search по любой raw canonical колонке либо containment/fuzzy
поиска по hierarchy path, он должен вернуть минимально подходящий index
отдельной migration вместе со своим query и A/B, а не сохранять постоянную
write/build cost до появления consumer. Индексы normalized read models
сохраняются, пока обслуживают их фактические search predicates.
Отдельный индекс по flag/date также не сохраняется только из-за имени колонки:
если используемый composite уже реализует object/predicate/order contract,
дублирующий standalone access path требует собственного подтверждённого query.
Raw lineage column также не получает индекс только потому, что хранит ссылочный
идентификатор: после намеренного снятия FK `prev_id`/`next_id` требуют
конкретного traversal/join consumer и измеренного плана. Сами значения при этом
остаются в canonical tables и могут быть проиндексированы отдельной migration,
когда такой consumer появится.
Для `object_params` наличие `end_date` filter и `update_date` ordering само по
себе не оправдывает standalone date indexes: если selective object/type lookup
и требуемый порядок уже обслуживает partial composite, broad date-only access
path появляется только вместе с отдельным production consumer и планом.
Аналогично raw `change_id_end` не получает B-tree только из-за ссылочного
смысла имени: без FK и фактического change-history traversal колонка остаётся
каноническим значением, а access path появляется вместе с consumer.
Дублирующий GUID в unit table также не требует собственного индекса, если
authoritative registry UUID уже уникально индексирован, numeric FK path
сохранён, а прямого unit-GUID consumer нет. Новый direct lookup должен
сравнить свой план с двухшаговым registry-identity path.
Hierarchy cross-link identifier также не получает B-tree только из-за имени:
после снятия FK с `parent_obj_id`/`change_id` raw values остаются canonical,
но индекс возвращается лишь вместе с фактическим traversal/join consumer и
его populated plan. Используемые `object_id`/`region_code` paths этим правилом
не затрагиваются.
Hierarchy lifecycle columns рассматриваются раздельно: `start_date` и
`update_date` не получают standalone B-tree без predicate/order consumer, но
`end_date` сохраняет индекс из-за текущих active-current predicates в rebuild,
integrity и DataMos paths. Даже значительная экономия storage не является
самостоятельным основанием удаления: q49 сохранён после двух регрессий
index-фазы.

## Профиль Инициализации Измеренного Сервера

Измеренный профиль относится к dedicated host `12C/24T`, `58 GiB RAM`, NVMe и
пустой application database. PHP CLI использует `memory_limit=512M`. PostgreSQL
сохраняет durability и запускается со следующими существенными параметрами:

```text
shared_buffers=8GB
effective_cache_size=24GB
work_mem=16MB
maintenance_work_mem=2GB
autovacuum_work_mem=1GB
wal_buffers=64MB
min_wal_size=8GB
max_wal_size=64GB
wal_compression=on
checkpoint_timeout=30min
checkpoint_completion_target=0.9
max_worker_processes=16
max_parallel_workers=12
max_parallel_workers_per_gather=4
max_parallel_maintenance_workers=4
effective_io_concurrency=200
maintenance_io_concurrency=100
fsync=on
synchronous_commit=on
full_page_writes=on
```

Последовательность инициализации: применить migrations к пустой dedicated БД,
проверить manifest через `gar:import:plan`, запустить import с профилем ниже,
дождаться durable `phase=ready`, выполнить integrity/status gates и только
после этого перестраивать OpenSearch из PostgreSQL source of truth.

| Условия | Workers / pipeline / indexes / FK | Правило |
| --- | --- | --- |
| Unknown или shared host | `2 / off+2 / 2 / 2` | безопасная отправная точка; parser включать только после замера |
| Этот dedicated host | `12 / table-aware F2, R8-fused+P4, B100/M64/G16 / 4 / 4+LPT` | измеренный optimum для region-50 workload |
| Больше CPU/RAM при том же XML | начать с `12 / R8-fused+P4 / 4 / 2` | FK W4 отдельно подтвердить на своём storage |

Для q63g измерен PostgreSQL RSS max около `13.56 GiB`; importer-контейнер при
принятом профиле достиг `651.9 MiB`. При переносе профиля нужен запас для page
cache, Docker и соседних сервисов. Для другого NVMe сначала сравнивать I2/I3/I4
по index duration вместе с physical-device await/queue; не переносить I4 как
универсальный default.

## Рекомендация

Для измеренного dedicated NVMe host использовать:

```text
--batch-size=100000
--workers=12
--pipeline-file=auto
--pipeline-writers=4
--pipeline-parsers=8
--pipeline-fused-ranges
--pipeline-file-concurrency=2
--pipeline-buffer-mib=64
--pipeline-fragment-mib=16
--pipeline-table-aware-files
--index-workers=4
--overlap-table-indexes
--overlap-index-workers=1
--overlap-index-start-percent=50
--overlap-index-foreign-keys
--defer-foreign-keys
--defer-primary-keys
--foreign-key-workers=4
```

Largest-heap-first index и largest-referencing-table-first FK scheduling
применяются автоматически. Для неизвестного
или shared host default `--index-workers=2` остаётся безопасной отправной
точкой: сначала измерить `2`, затем `3/4`, контролируя PostgreSQL RSS и physical
device await/queue. FK pool для неизвестного host оставляют на default `2`;
измеренный dedicated host использует `4`. W12 в принятой точке сохраняет полную ширину R8, пока рядом
работают до четырёх ordinary file workers; внутри этих восьми fused tasks parent
разрешает одновременно не более P4 COPY. F2 делит этот budget между двумя XML
одной canonical table, а table groups закрываются последовательно. На
измеренном host ранний I1 допускается только после `50%` завершённых source
bytes. Standalone W8 file workers, P8 и повторно проверенный P6 writers были
plateau.

Pipeline width выше четырёх отклонён q25: менее `1%` выигрыша при `+38.9%` max
importer RSS. Изолированный batch `100000` был отклонён q26/q27, но q63
показал, что ограничителем был fragment G8: связанный B100/G16/M64 профиль
прошёл crossover gate. q28–q32 дополнительно отклонили расширенный PostgreSQL
parallel-maintenance pool, I/O concurrency `32/16`, maintenance memory `4GB` и
shared buffers `12GB`. q33 реализовал bounded parallel XML parsing, а q36
устранил двойной IPC payload и стал новой принятой точкой. Two-stage R8/1G
оставался raw plateau с ростом памяти на `55%`; fused R8, напротив, ускорил
whole raw на `5.545%` и снизил max RSS. Рост parser width выше R8,
writer/index/FK pools выше принятого профиля сейчас не поддержан измерениями;
q37 напрямую отклонил R12 из-за raw plateau и роста importer RSS. q38 стал
новой принятой точкой: без роста width он применил тот же bounded fused
transport ко всем compatible data XML и сократил E2E до `458.953 s`. q39
удалил неиспользуемый raw-value GIN и стал новым baseline с index build
`183.966 s` и E2E `397.210 s`. q40 завершил полный source/constraint/plan
inventory, q41 удалил четыре неиспользуемых hierarchy path GIN, а q42 —
оставшиеся `28` canonical trigram GIN. q43 затем удалил `30` redundant unit
lifecycle B-tree, сохранив шесть active/current composites. q44 удалил `16`
detached version-link B-tree после ранее снятых self-FK. q45 удалил три
standalone object-parameter lifecycle B-tree, сохранив partial denorm composite.
q46 удалил отдельный `change_id_end` B-tree без query/constraint consumer.
q47 удалил шесть duplicated unit-GUID B-tree и подтвердил эффект reverse-order
control. q48 удалил четыре detached hierarchy cross-link B-tree; две
reverse-order crossover-пары подтвердили scoped index gain `3.553%` и
`11.442%`, хотя unpaired median `0.281%` отдельно не считается ускорением.
q49 проверил hierarchy classifier-code B-tree, но обе пары ухудшили index-фазу
на `11.913%` и `14.837%`, поэтому schema change отклонён. q50 удалил четыре
unqueried hierarchy start/update B-tree, сохранив оба используемых end-date
индекса; crossover-пары подтвердили index gain `26.053%` и `4.427%`.
Representative q50 schema/index point имел index build `76.990 s`, `109`
canonical indexes, `84` snapshots и diagnostic E2E `329.456 s`. q59 затем
удалил три неиспользуемых `change_history` B-tree, а q64 — четыре metadata
B-tree `reestr_objects`; текущий inventory равен `102` indexes / `77`
snapshots. q85 затем закрыл оставшиеся `26` измеренной performance upper bound
`1.224%`; они остаются schema-cleanup candidates без заявления об E2E gain.
Classifier-code и end-date индексы не возвращаются в removal queue без нового
evidence.
q51 подтвердил техническую отзывчивость bounded EventLoop COPY без
самостоятельного ускорения транзакции, а чистый q55 показал E2E-регрессию
`3.023%`; prototype и CLI option удалены. q52/q53 впервые доказали table-ready
index scheduler, но их timings загрязнены внешней NVMe-нагрузкой. Чистые
q54/q54r с blocking COPY и ранним I1 сократили E2E к q56 control на
`5.419% / 3.984%`; после обнаруженного IPC hang scheduler изолирован отдельным
CLI-процессом. q57 с I2 не прошёл gate и хуже I1 repeat на `2.768%`.
q58 подтвердил штатный cross-process admission Amp `PosixSemaphore`, но
отклонил его для recovery: `SIGKILL` владельца теряет permit, а `SIGKILL`
инициализатора оставляет orphan SysV queue.
q60/q61 приняли FK W4+LPT для measured host. q62/q63 добавили общий Amp
multi-file admission, приняли G16/M64/B100 и table-aware F2; R12, P6, F3 и G32
не прошли gate. q64 удалил четыре `reestr_objects` metadata B-tree: две пары
дали E2E `-5.820% / -3.752%`, а `is_active=true` фактически занимает
`95.1924%` registry rows. q65 отложил ранний I1 до `50%` source bytes: две
crossover-пары дали E2E `-8.559% / -4.241%`, несмотря на более длинный
post-raw tail. Production execution baseline теперь использует blocking P4
COPY, table-aware F2, late I1 и финальный I4.
q86 проверил persistent FK workers, но соседние E2E-пары дали
`-5.980% / -0.530%`; worker/connection reuse удалён, process-per-FK W4/LPT
остаётся baseline.
q112 component gate и q113 exact crossover приняли отложенную сборку `23` PK:
raw/E2E уменьшились на `26.40% / 15.16%`, WAL — на `32.63%`. q114 подтвердил,
что глобальный primary-first admission ухудшает E2E на `3.87%`; heap-first
early I1 сохраняется.
Хвост `object_params` теперь
содержит denorm composite и обязательные `change_id/object_id/type_id` FK
paths; их не следует удалять. Малые index/FK bootstrap knobs исчерпаны;
q87 затем проверил bounded COPY-text transport, но отклонён по F2 liveness до
получения валидного E2E. Поэтому следующий шаг — новый diagnostic profile
неизменного baseline; конкретный q88-кандидат выбирается только по свежим
CPU/wait/I/O/critical-path evidence.

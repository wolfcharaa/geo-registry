# Журнал Bottleneck GAR Initial-Load

- Статус: живой журнал; обновляется после каждого полного benchmark-прогона
- Дата среза: `2026-11-16`
- Production execution baseline: q73, persistent index workers поверх принятого
  q65/q63 profile; optional measured-host source placement: q80 hierarchy-only
  tmpfs
- Числовой протокол: [resource saturation benchmark](gar-import-resource-saturation-benchmark.md)
- Очередь гипотез: [optimization plan](gar-import-optimization-plan.md)
- Component split: [safe ranges, resume и COPY](gar-import-component-benchmarks.md)

## Назначение

Этот документ фиксирует не только итоговый E2E, но и то, куда перемещается
критический путь после каждого эксперимента. Он нужен, чтобы не оптимизировать
уже скрытую overlap-ом фазу и не принимать локальное ускорение одной стадии за
ускорение инициализации приложения.

После каждого полного прогона сюда добавляется одна строка. Для crossover
candidate/control/control/candidate добавляются четыре строки: разброс между
соседними точками является частью результата, а не усредняемой помехой.
Подробные SQL, resource и correctness evidence остаются в профильном benchmark;
этот журнал хранит диагноз и следующую проверяемую проблему.

## Модель Критического Пути

Полный путь до durable `ready` рассматривается как четыре связанных участка:

1. `raw` — от старта операции до завершения последнего исходного XML;
2. `overlap` — часть index window, уже выполненная параллельно raw;
3. `post-raw tail` — индексы, которые остаются после последнего XML;
4. `finalization` — FK restore/validation, `ANALYZE`, integrity и перевод в
   `ready`.

`index window` не складывается с raw целиком: его overlap скрыт raw-фазой.
Поэтому приближённая проверка E2E выглядит так:

```text
E2E = raw + post-raw index tail + FK + ANALYZE/integrity
```

Для каждого участка дополнительно проверяются CPU, physical I/O, wait events,
RSS, swap и готовность отдельных таблиц. Слово `storage-bound` используется
только при фактических device pressure/I/O waits, а не по cumulative BlockIO.

## Текущий Baseline И Оставшиеся Ограничения

Средние значения двух принятых q73 candidate-прогонов:

| Участок | Время | Доля E2E | Что уже скрыто overlap-ом | Оставшаяся проблема |
| --- | ---: | ---: | --- | --- |
| Raw | `182.290 s` | `76.62%` | В среднем `96.753 s` index work выполняется до конца raw | Последняя тяжёлая table/file group определяет момент открытия finalization |
| Post-raw index tail | `23.214 s` | `9.76%` | Большинство из `77` snapshots уже построено | Хвост чувствителен к table-ready order и конкуренции с raw |
| FK validation | `28.662 s` | `12.05%` | Не перекрывается с незавершёнными индексами | Стабильный отдельный floor после raw/index barrier |
| ANALYZE/integrity | около `3.75 s` | `1.58%` | — | Малый floor; пока не приоритет оптимизации |
| E2E | `237.915 s` | `100%` | — | Принимать только воспроизводимый выигрыш `>=2%` |

Главный bottleneck baseline — raw critical path, но он не независим от
индексов: слишком ранняя или широкая index-нагрузка замедляет raw, а слишком
поздняя оставляет post-raw tail. q65 уже подтвердил полезный компромисс — один
index worker допускается после `50%` завершённых source bytes. Следующая
гипотеза должна явно указывать, какой из четырёх участков она сокращает и какой
соседний участок может ухудшить.

## Как Перемещался Bottleneck

| Этап | Принятое изменение | Bottleneck до | Bottleneck после |
| --- | --- | --- | --- |
| Index lifecycle | Вторичные индексы сняты на время initial-load | Incremental index maintenance каждой строки | Один backend и крупные XML |
| Direct COPY | Staging/merge заменены direct COPY | Tuple/merge executor и WAL | XML parsing и single-file serialization |
| Bounded/fused pipeline | XML ranges распределены между R8/P4 | Один parser/COPY path | FK checks, затем table completion order |
| Deferred FK | `34` FK восстановлены и проверены после raw | Per-row referential checks | Index build и raw/index resource contention |
| I4 и index audit | Удалены лишние GIN/B-tree, принят I4/LPT | Серийный тяжёлый index tail | Raw critical path и overlap policy |
| Table-ready overlap | Индексы строятся после готовности своей таблицы | Полная index phase после raw | Баланс raw против post-raw tail |
| q63/q65 scheduling | G16/M64/B100, table-aware F2, late I1 | Fragment overhead и ранняя конкуренция | Последняя raw group плюс variable tail |
| q73 worker reuse | Persistent index worker pool | Bootstrap `77` CLI-процессов | Raw critical path; стабильный finalization floor |
| q74–q78 frontier | CTE, yields, async file source и one-read IPC отклонены | Предполагаемый planner/read overhead | Подтверждена необходимость оптимизировать фазы раздельно |
| q79/q80 source placement | Three-file tmpfs сужен до одного hierarchy XML | NVMe-чтение hierarchy на raw critical path | Raw короче; post-raw index tail вырос и стал следующей явной целью |
| q81 shared-memory boundary | Bounded G16 parcel transport отклонён | Повторный range-read и task serialization | Средний raw-сигнал не дал воспроизводимого E2E; RSS вырос |
| q85 index upper bound | Оставшиеся 26 B-tree вынесены в schema cleanup | Предполагаемая цена малых индексов | Полный serial upper bound только `1.224%` E2E; performance frontier закрыт |
| q86 FK process reuse | Persistent FK workers отклонены | Bootstrap 34 CLI/connection | Reverse FK/E2E gain лишь `0.420% / 0.530%`; finalization floor почти не изменился |
| q87 COPY-text spool | Per-range tmpfs handoff отклонён по liveness | Повторное чтение range и IPC rows | F2 coordinator оставил готовые chunks без writer dispatch; требуется единый глобальный dispatcher |
| q88 fresh profile | Неизменный q73/q65 измерен с per-CPU/PSI | Неизвестный frontier после q87 | Post-raw tail задают восемь serial administrative-hierarchy indexes; CPU/NVMe имеют резерв |
| q89 targeted table width | Administrative hierarchy width 2 отклонён | `29.985 s` serial administrative index chain | Tail `-7.94%`, но E2E `+2.47%`; paired builds почти удвоили individual duration |
| q90 FK profile | Неизменный W4/LPT измерен per-constraint | Неизвестная стоимость `29.5 s` FK floor | Три serial `object_params` FK занимают почти всю фазу; lock/source semantics закрывают same-table parallel и batched validation |
| q91 global COPY-text dispatcher | Единый dispatcher повторно отклонён по liveness | Несогласованность file-local coordinators q87 | Четыре ранних municipal chunks остались в spool при idle R8/P4/PostgreSQL; scheduler frontier закрыт |
| q92 binary COPY | Type-correct binary stream отклонён по performance | PostgreSQL text parsing и escaping | Raw `+5.09%`, payload `+15.24%`, E2E `+1.09%`; text COPY компактнее для коротких GAR scalar values |
| q94 async-parser ordinary files | Новый adapter оставлен как composition cleanup, performance claim отклонён | GAR-specific orchestration обычных файлов | Fused hot path не изменён; raw `+9.05%`, tail `-28.40%`, E2E `+1.38%` |
| q95 component split | Safe ranges, resume, PHP 8.5 iterable и chunked COPY измерены отдельно | Смешанная цена parse/planner/COPY | XML decode/encode доминирует; generator экономит allocation, async COPY даёт liveness с `+2.56%` protocol overhead |

## Подробный Журнал Последних Прогонов

`Raw`, `tail`, `FK` и `E2E` ниже взяты из immutable region-50 fixture. Все
полные q73–q86 точки сохранили `122 809 172` rows, file/table/FK digests,
`102` valid indexes, `34/34` FK и нулевые backlogs.

| Run | Вариант | Raw | Tail | FK | E2E | Наблюдаемый bottleneck / вывод |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| q73a | persistent index workers | `173.787 s` | `29.060 s` | `28.595 s` | `235.217 s` | Raw доминирует; worker bootstrap больше не критичен |
| q73b | CLI-per-index control | `188.274 s` | `22.759 s` | `29.039 s` | `243.897 s` | Меньший tail не компенсирует raw и bootstrap |
| q73c | reverse control | `188.308 s` | `28.316 s` | `28.200 s` | `246.569 s` | Control воспроизводит более длинный E2E |
| q73d | persistent repeat | `190.792 s` | `17.368 s` | `28.728 s` | `240.613 s` | Принят: обе E2E-пары быстрее `>=2%` |
| q74a | metadata CTE | `166.592 s` | `36.283 s` | `27.585 s` | `234.196 s` | Raw выигрыш частично превращён в больший tail |
| q74b | statement control | `207.633 s` | `23.900 s` | `27.788 s` | `261.152 s` | Первая пара положительна, но ещё не воспроизводима |
| q74c | reverse control | `187.149 s` | `19.898 s` | `28.337 s` | `239.122 s` | Быстрый control меняет вывод первой пары |
| q74d | metadata CTE repeat | `201.846 s` | `21.177 s` | `28.830 s` | `254.669 s` | Отклонён: reverse E2E хуже на `6.502%` |
| q75 | lag diagnostic | — | — | — | — | Planner active `50.715 s`, EventLoop excess lag `51.754 s`; наблюдаемость не доказала causality |
| q76a | cooperative planner | `176.645 s` | `24.623 s` | `28.136 s` | `233.229 s` | Первая пара прошла gate |
| q76b | blocking control | `186.862 s` | `20.059 s` | `28.443 s` | `239.233 s` | Yield улучшает raw, но увеличивает tail |
| q76c | reverse control | `192.953 s` | `18.918 s` | `28.457 s` | `244.185 s` | Разброс raw сопоставим с candidate effect |
| q76d | cooperative repeat | `193.804 s` | `18.491 s` | `28.592 s` | `243.638 s` | Отклонён: reverse E2E только `0.224%` |
| q77a | async uv file source | `167.113 s` | `31.051 s` | `27.833 s` | `229.775 s` | Сильный raw, но увеличенный tail |
| q77b | blocking control | `186.967 s` | `24.129 s` | `28.541 s` | `243.377 s` | Первая пара выглядела положительно |
| q77c | reverse control | `183.151 s` | `29.013 s` | `28.840 s` | `244.756 s` | Control raw оказался быстрее repeat candidate |
| q77d | async uv repeat | `205.550 s` | `20.454 s` | `29.291 s` | `259.054 s` | Отклонён: raw/E2E сменили знак |
| q78a | one-read source fragments | `186.198 s` | `17.887 s` | `28.978 s` | `236.881 s` | Tail сокращён, первая E2E-пара лучше на `1.878%` |
| q78b | offset/read control | `189.713 s` | `19.706 s` | `28.124 s` | `241.415 s` | Первая пара ниже gate, но направление положительное |
| q78c | reverse control | `182.995 s` | `23.877 s` | `28.742 s` | `239.376 s` | Control raw быстрее обоих первых points |
| q78d | one-read repeat | `214.858 s` | `11.565 s` | `29.597 s` | `259.777 s` | Отклонён: лучший tail ценой raw `+17.412%` и E2E `+8.523%` |
| q79a | three-file tmpfs | `167.663 s` | `34.617 s` | `28.136 s` | `234.215 s` | Raw быстрее, но tail `+55%`; staged E2E `245.075 s`, swap-out `~514 MiB` |
| q79b | cold NVMe control | `184.710 s` | `22.335 s` | `29.178 s` | `240.000 s` | Первая import-only пара положительна; staged pair отрицательна |
| q79c | reverse control | `192.946 s` | `23.928 s` | `28.881 s` | `248.102 s` | Подтверждает drift controls и длинный hierarchy file |
| q79d | three-file tmpfs repeat | `179.058 s` | `26.479 s` | `27.327 s` | `236.650 s` | Raw/E2E gain повторён, но staged gain только `0.239%` |
| q80a | hierarchy-only tmpfs | `163.051 s` | `33.422 s` | `28.480 s` | `227.729 s` | Staged E2E `231.349 s`; raw резко короче, tail длиннее |
| q80b | cold NVMe control | `187.992 s` | `20.004 s` | `29.777 s` | `241.561 s` | Первая staged-пара лучше на `4.227%` |
| q80c | reverse control | `202.892 s` | `22.787 s` | `28.829 s` | `258.278 s` | Воспроизводит длинный cold-source raw |
| q80d | hierarchy-only tmpfs repeat | `180.010 s` | `26.788 s` | `28.538 s` | `239.064 s` | Staged E2E `242.684 s`; reverse-пара лучше на `6.038%` |
| q81a | shared-memory fragments | `172.369 s` | `31.010 s` | `28.193 s` | `235.350 s` | Первая E2E-пара лучше на `3.148%` |
| q81b | offset/read control | `190.741 s` | `19.396 s` | `29.139 s` | `242.998 s` | Raw control длиннее, но tail заметно короче |
| q81c | reverse control | `185.834 s` | `21.634 s` | `29.493 s` | `240.781 s` | Стабильный control E2E перед reverse candidate |
| q81d | shared-memory repeat | `193.411 s` | `20.329 s` | `29.140 s` | `246.715 s` | Отклонён: raw/E2E хуже control на `4.077% / 2.465%` |
| q82a | hierarchy stage diagnostic | `165.159 s` | `34.704 s` | `28.337 s` | `231.937 s` | Diagnostic-only: hierarchy pair занимает `49.589 s`; быстрый raw снова переместил bottleneck в tail |
| q83a | selective hierarchy F2 diagnostic | `166.282 s` | `34.443 s` | `29.643 s` | `234.167 s` | Отклонён локально: concurrent pair span `50.706 s`, на `2.254%` хуже sequential |
| q84a | post-raw same-table index width 2 | `167.623 s` | `22.565 s` | `33.152 s` | `226.112 s` | Tail candidate; первая E2E-пара положительна |
| q84b | width 1 control | `184.926 s` | `22.715 s` | `29.588 s` | `241.022 s` | Медленный raw скрыл больше index work overlap-ом |
| q84c | reverse width 1 control | `181.315 s` | `21.245 s` | `29.882 s` | `236.217 s` | Соседний control для reverse-пары |
| q84d | width 2 repeat | `199.567 s` | `14.863 s` | `36.004 s` | `254.445 s` | Tail быстрее, но reverse E2E хуже на `7.717%` |
| q86a | persistent FK workers | `173.574 s` | `20.680 s` | `28.130 s` | `225.137 s` | Первая пара прошла gate, но raw/index schedule также был быстрее |
| q86b | process-per-FK control | `183.348 s` | `20.349 s` | `32.057 s` | `239.457 s` | Первый control создал сильный локальный FK-сигнал |
| q86c | reverse process control | `197.868 s` | `14.524 s` | `28.837 s` | `245.063 s` | FK bootstrap уже почти не отличался от persistent path |
| q86d | persistent FK repeat | `188.192 s` | `23.069 s` | `28.716 s` | `243.764 s` | Отклонён: reverse FK/E2E gain только `0.420% / 0.530%` |
| q90a | FK validation diagnostic | `164.107 s` | `35.961 s` | `29.499 s` | `233.343 s` | `object_params` chain `18.157 + 8.162 + 2.758 s`; PostgreSQL сериализует same-table validation |
| q91a | global COPY-text dispatcher | — | — | — | — | Liveness reject: `27/28`, `122 552 903` rows; четыре ранних spool chunks stranded при idle workers |
| q92a | binary COPY | `172.457 s` | `30.806 s` | `28.794 s` | `235.886 s` | Exact parity; raw `+5.09%`, E2E `+1.09%`, encoded bytes `+15.24%`; reject без A/B |
| q93a | pre-normalized COPY-text, W8 | `252.006 s` только normalize | — | — | — | Exact `122 613 810` rows / `13.188 GB`; уже `+53.56%` к полному q90 raw до PostgreSQL |
| q93b | artifact bulk-load P4 | `123.460 s` только COPY | — | — | — | Exact table rows; вместе с q93a `375.467 s`; лучший load width |
| q93c | artifact bulk-load P8 | `127.706 s` только COPY | — | — | — | `+3.44%` к P4, iowait/await и per-file contention выросли; reject |
| q94a | async-parser ordinary files | `178.957 s` | `25.748 s` | `28.046 s` | `236.573 s` | Exact parity; raw `+9.05%`, E2E `+1.38%`; performance reject без A/B, adapter оставлен как composition cleanup |
| q95a | largest-file component split | — | — | — | — | `5.33 GB`: linear parse `91.798 s`; safe plan `4.701 s`; sequential ranges `100.091 s`; 50% resume `66.813 s` |
| q95b | PHP 8.5 iterable COPY | — | — | — | — | Generator `0.4183 s` против array `0.5196 s`, memory `2.10` против `211.82 MB`; positive component signal |
| q95c | PHP 8.4 chunked COPY A/B | — | — | — | — | Nonblocking `0.3768 s`, blocking `0.3674 s`; EventLoop `6–7` против `0` ticks, protocol overhead `+2.56%` |
| q96a | XML parser alternatives | — | — | — | — | XMLReader scan `28.316 s`; SAX transform `94.519 s`, Sabre `89.259 s`, BenMorel `133.596 s`; alternatives rejected |
| q96b | fused XMLReader A/B | — | — | — | — | Control `92.739 s`, fused `87.630 s`; exact digest, component gain `5.508%` |
| q96c | full-memory XML | — | — | — | — | DOM/SimpleXML OOM при `32 GiB` на `5.33 GB`; на `288 MB` они `61.78–69.77%` медленнее streaming control |
| q97a | continuous blocking XML→COPY | — | — | — | — | Mean `91.220 s` против control `111.883 s`, exact component gain `18.469%`; recovery contract меняется |
| q97b | readiness-driven XML→COPY | — | — | — | — | `89.817 s`, только `1.54%` к blocking mean; `0` write waits, throughput claim rejected |
| q97c | bounded `4 MiB` XML→COPY | — | — | — | — | Mean `108.473 s`, exact gain `3.048–3.194%`; проходит component gate, нужен real ledger/E2E A/B |
| q98a | continuous transport chunk sweep | — | — | — | — | `1 MiB` лучший measured throughput `89.763 s`; `256 KiB–128 MiB` не дали ускорения, peak memory выросла до `392 MiB` |
| q98b | minimal atomic transaction curve | — | — | — | — | `4 MiB` дал `564` transaction/marker; A/B mean `91.861 s` против whole-file `90.386 s`, tax `+1.632%`, exact parity; production WAL/ledger gate остался |
| q99a | async-parser path-first API | — | — | — | — | Cold raw read W28 performance-neutral; NDJSON structural pipeline W8 `6.629035 → 0.255006 s` (`26.00×`) при exact counters/digest; bottleneck был в arbitrary-chunk framing/copies, не в NVMe |
| q99b | async filesystem driver crossover | — | — | — | — | Cold W28 native mean `7.720996 s`, UV `7.599922 s`; gain `1.568%` ниже gate при spread `12.26% / 11.15%`; UV исправен, factory остаётся benchmark-only |
| q99c | binary COPY component recheck | — | — | — | — | `100k`: binary `0.414149 s` против text `0.353935 s` (`+17.01%`), payload `+13.08%`, encode `+59.44%`; exact logical digest, reject без A/B |
| q99d | ordinary INSERT component sweep | — | — | — | — | `100k`: лучший VALUES-1000 `0.665667 s` (`+88.08%` к COPY); `1M`: `6.671476 s` против `3.590259 s` (`+85.82%`), exact logical digest; reject |
| q100a | production `1 MiB` stream / `4 MiB` transaction | `187.701 s` | `17.095 s` | `28.397 s` | `237.060 s` | Exact parity; raw `+4.89%` к q94, ledger batches `1 527 → 3 509`; reject без crossover |
| q101a-d | `1 MiB` stream / planned-range transaction | `177.571 s` mean | `24.150 s` mean | `29.399 s` mean | `234.886 s` mean | Exact crossover: raw `-7.97%`, E2E `-4.49%`; `1 527` batches, accept |
| q103b/c | sequential index then FK controls | `192.282 s` median | `19.997 s` | `29.207 s` | `244.742 s` | Post-raw median `52.460 s`; обе фазы быстры локально, но полностью последовательны |
| q103a/d | concurrent final indexes and FK | `178.185 s` median | `33.189 s` actual snapshot tail | `37.012 s` actual snapshot span | `218.986 s` | Post-raw `40.801 s` (`-22.22%`), E2E `-10.52%`; accept, FK завершались на `3.634–7.874 s` позже indexes |
| q104a | index-first table admission | `172.923 s` | `27.314 s` actual snapshot tail | `38.875 s` actual snapshot span | `215.273 s` | Exact parity; E2E только `-1.695%` к q103 median, post-raw `42.350 s` (`+3.797%`); reject без crossover |
| q105a | native FK plan diagnostic | `172.951 s` | — | `35.0 s` для трёх `object_params` FK | `215.092 s` | Три accurate serial Hash Anti Join; `SPI_prepare()` не разрешает parallel plan |
| q106a | retained visibility component | `174.158 s` | — | `32.354 → 12.49–12.66 s` вместе с VACUUM | `213.506 s` import | Index-only signal положительный; retained/VM-reset evidence допускает только cold crossover |
| q107a-d | post-raw visibility crossover | candidates `179.290 s` median | `41.726 s` median actual index tail | unstable | `223.857 s` median | Reject: соседние E2E-пары `-8.54% / +0.09%`, post-raw `-25.72% / +24.67%`; median искажён raw drift |
| q108a | visibility before table indexes | `171.082 s` | — | `35.861 s` target FK | `213.400 s` | Reject без A/B: VM не установлена, post-raw `42.318 s` хуже q103; красивый E2E получен быстрым raw |
| q109a-d | visibility after table indexes | candidates `182.594 s` median | `37.684 s` post-raw median | `19.057 / 22.482 s` target FK | `220.278 s` median | Mechanism real, но reverse E2E `+6.05%`; blocking VACUUM вытеснил index work, reject |
| q110a | async visibility maintenance | `178.347 s` | `37.109 s` actual index tail | `24.760 s` target FK | `217.367 s` | Post-raw `-6.11%`, но E2E `+0.385%` к adjacent control; index tail `+1.55%` к synchronous candidate, reject без A/B |
| q111a | dynamic remaining-index-work LPT | `173.811 s` | `36.995 s` actual index tail | `45.046 s` target FK | `224.663 s` | Reject: E2E `+2.59%` к q103 median; scheduler бросил незавершённую `object_params` chain, её последний index вырос `~12 → 34.348 s` |
| q112a-d | deferred `object_params` PK component | — | active/deferred `102.193 / 51.170 s` | — | — | Accept component: `-49.93%`, WAL `-42.16%`, exact duplicate/schema gates |
| q113a-d | deferred PK full lifecycle | active/deferred raw `197.216 / 145.158 s` | deferred post-raw `60.503 s` | — | active/deferred `242.409 / 205.660 s` | Accept: E2E `-15.16%`, WAL `-32.63%`, both adjacent pairs pass |
| q114a | primary-first early queue | `144.684 s` | `56.193 s` post-raw index | — | `213.629 s` | Reject: 23 PK hidden by raw, but displaced secondary work makes E2E `+3.87%` |

q78 особенно важен для интерпретации будущих результатов. Candidate в среднем
сократил post-raw tail на `32.421%`, но ухудшил raw на `7.606%` и E2E на
`3.300%`. Peak importer RSS вырос с control mean `639.8 MiB` до
`815.8 MiB` (`+27.5%`), swap не изменился. Передача уже прочитанных XML bytes
через IPC поэтому не принимается. Результат согласуется с тем, что на текущем
NVMe повторное range-read в значительной мере скрыто page cache, тогда как
сериализация payload меняет resource schedule и удлиняет raw critical path.

q79 подтвердил другой важный переход: RAM-backed source ускорил raw в обеих
парах на `9.229% / 7.198%`, но post-raw tail вырос на `54.988% / 10.660%`.
После staging средний E2E стал хуже control на `0.919%`, а первый candidate
вытеснил около `514 MiB` в swap. Поэтому three-file profile отклонён, но
bottleneck локализован дальше: `AS_ADM_HIERARCHY` один ускорился на
`36.808% / 27.381%`; q80 изолированно проверил только его.

q80 прошёл обе staged-E2E пары. Средний raw сократился на `12.234%`, import
E2E — на `6.611%`, а полный результат с `3.62 s` staging — на `5.163%`.
Peak container memory с tmpfs составил около `4.77 GiB`, нового swap-out нет.
Цена ускорения теперь видна явно: средний post-raw tail вырос на `40.710%` до
`30.105 s`. Поэтому следующий bottleneck — не общее чтение XML, а конкретная
последняя raw group и индексы, оставшиеся после её более раннего завершения.

q81 проверил более масштабируемую альтернативу whole-file tmpfs: только
активные G16 fragments передавались fused workers через SysV shared memory.
Первая пара дала raw/E2E `-9.631% / -3.148%`, но reverse-пара сменила знак на
`+4.077% / +2.465%`. Средний raw сократился на `2.867%`, тогда как E2E — лишь
на `0.354%`; tail вырос на `25.128%`, peak importer memory — примерно на
`14.3%`. Normal cleanup не оставил IPC objects, но performance gate уже
провален, поэтому crash-path не оправдывает дальнейшую production-сложность.
Последней raw group во всех четырёх точках была последовательность
`AS_ADM_HIERARCHY → AS_MUN_HIERARCHY`. Её создаёт не cross-table dependency,
а table-aware scheduler: разные target tables исполняются группами, чтобы
раньше допускать их индексы. Для many-region load внутри каждой группы уже
работает bounded F2, но municipal files ждут всю administrative group. Broad
cross-table F2 ранее проиграл q63g по tail/E2E, поэтому следующий шаг —
phase timing этой пары, а не повторное снятие table-aware boundary.

q82 разложил эту пару без изменения scheduler. Administrative hierarchy занял
`25.258 s`, municipal — `24.331 s`; вместе они потребовали `49.589 s`, то есть
`30.0%` всего raw. Active planner составил `9.150 / 9.292 s`. Worker sums для
parse/encode были `119.979 / 62.038 s`, для `COPY+commit` —
`60.901 / 69.846 s`. У municipal дополнительно появился `34.349 s` суммарного
permit wait и единичный максимум task `5.710 s`, тогда как administrative max
был `1.053 s`. Это не доказывает нехватку parser: совместный спрос укладывается
в R8, а shared P4 остаётся нужным ограничителем COPY.

В полном diagnostic raw оказался на `9.397%` короче q73 mean, но tail вырос на
`49.498%`; E2E был лучше на `2.513%`, однако точка instrumented и не имеет
adjacent control, поэтому ничего не принимает. Raw всё ещё занимает `71.21%`
E2E, tail уже `14.96%`, FK — `12.22%`, finalization — `1.61%`. Следующая узкая
гипотеза — совместно запускать только финальную priority-80 hierarchy-пару с
общими R8/P4, сохранив table-aware groups во всех ранних фазах. Теоретический
выигрыш сначала проверяется локально по паре, затем — только при достаточном
сигнале — полным crossover.

q82 также обнаружил пробел evidence: final integrity штатно удаляет index
snapshots, поэтому старый пятисекундный sampler сохранял только часть
`building` состояний. Со следующего прогона harness снимает все состояния раз
в секунду. В ledger будут попадать точные residual indexes и durations, а не
только общий post-raw tail. Завышенный `processed_rows` внутри q82 diagnostic
JSON был ошибкой наблюдаемости (суммировался cumulative checkpoint); счётчик
исправлен на длину range, а canonical correctness q82 не затронута.

q83 запустил только administrative/municipal hierarchy одновременно, не меняя
ранние table-aware groups. Оба файла стартовали с разницей `44 ms`, но общий
span вырос с q82 `49.589 s` до `50.706 s` (`+2.254%`). Parse/encode demand
почти не изменился (`182.256 s` против `182.017 s`), а permit wait вырос с
`43.502 s` до `48.733 s`: P4 правильно ограничил COPY, но parser headroom не
дал wall-time выигрыша. Raw стал хуже на `0.680%`, tail лучше только на
`0.752%`, E2E хуже на `0.961%`. Поэтому selective F2 отклонён без production
crossover, а q73 scheduler восстановлен.

Новый one-second index history сделал tail предметным. До raw завершились пять
крупных индексов: один `change_history` и четыре `object_params`; ещё `72`
snapshot завершились после barrier. Последними шли
`idx_administrative_reestr_hierarchy_plan_code`, затем `region_code`, затем
`street_code`; последний завершился практически вместе со всем index window.
Причина сериализации видна в scheduler: `nextRunnable()` допускает только один
active index на таблицу. Следующая отдельная гипотеза относится уже не к raw:
сохранить one-per-table во время overlap, но проверить width `2` для одной
таблицы только после raw.

q84 подтвердил локальный механизм, но отклонил его как production profile.
Same-table width `2` сократил tail в обеих парах на `0.660% / 30.032%`; средний
tail улучшился на `14.858%` (`21.980 → 18.714 s`). Однако E2E-пары дали
`-6.186% / +7.717%`, а средний candidate E2E был хуже control на `0.695%`.
Средний FK candidate дополнительно вырос примерно на `16.3%`. Exact parity и
resource gates прошли, поэтому это не correctness/resource failure, а перенос
времени между соседними lifecycle stages. Width `2` удалён и не комбинируется
с другой гипотезой без собственного повторного crossover.

q85 не запускал заведомо слабые full imports. One-second q84b history показал,
что все оставшиеся `26` unused B-tree вместе строились только `2.950007 s`.
Даже полное удаление этой работы дало бы максимум `1.224%` q84b E2E, до учёта
I4 overlap. Поэтому performance removal frontier закрыт; индексы остаются
отдельной schema-cleanup задачей, а не источником заявленного ускорения.

q86 проверил последний локальный finalization knob — повторное использование
четырёх FK процессов и соединений. Первая пара выглядела сильно (`-12.249%`
FK, `-5.980%` E2E), но reverse-пара дала лишь `-0.420% / -0.530%`. Средние
улучшения `6.648% / 3.223%` не заменяют pair gate: первый результат включал
более удачный raw/index schedule. Во второй паре чистая разница FK была всего
`0.121 s`. Exact correctness и resources прошли, implementation удалена.

Следовательно, finalization floor остаётся примерно `28–30 s`, но bootstrap
FK workers не является его материальной причиной. Новый кандидат должен менять
саму стоимость PostgreSQL validation либо более крупную raw/transport topology;
перестановка process lifetime больше не входит в очередь.

q87 дошёл до terminal transport loop: parser R8 записывал bounded COPY-text
chunks в private tmpfs, а writer P4 передавал их через `pg_put_copy_data()` в
той же транзакции, что и checkpoint. Focused integration прошёл, однако полный
F2-прогон трижды не завершился. В финальной попытке `152` поздних range
последнего файла уже были committed (`9 690 645` rows), а первые три готовых
range (`192 177` rows, около `33.5 MiB`) остались в spool. Все R8/P4 процессы
и PostgreSQL connections были idle, parent fiber ожидал completion; прогресс
застыл на `27/28`, `122 616 995` rows и `74/77` indexes. Это coordinator
livelock, а не CPU/NVMe/PostgreSQL saturation. Timing q87 не считается
benchmark evidence; implementation удалена. Повтор допустим только после
перехода к одному глобальному state dispatcher для всех файлов.

q88 повторно измерил неизменный baseline: raw `163.473 s`, tail `30.032 s`,
FK `29.131 s`, ANALYZE `3.093 s`, E2E `225.737 s`. Correctness полная. В tail
24 CPU оставались idle в среднем на `75.02%`, CPU PSI был нулевым, NVMe имел
`33.61%` average / `78%` peak utilization, swap не изменился. После raw
`object_params` закрылся за `1.503 s`, а восемь последовательных индексов
`administrative_reestr_hierarchy` заняли critical chain до `+29.985 s`.
Поэтому q89 сужает отклонённый q84: width `2` только для этой таблицы и только
в final pool; early I1, total I4 и остальные таблицы остаются неизменными.

q89 разрешил width `2` только для `administrative_reestr_hierarchy` после raw.
Параллельность фактически сработала, tail сократился `30.032 -> 27.647 s`
(`-7.94%`). Но первые `area_code`/`city_code` заняли около `6.0 s` каждый
против q88 serial `3.977 / 3.474 s`: contention поглотил большую часть
теоретической экономии. E2E ухудшился `225.737 -> 231.309 s` (`+2.47%`), при
этом raw независимо дрейфовал на `+4.91%` до включения candidate. По правилу
negative first run crossover не продолжался; implementation удалена. q84/q89
закрывают same-table concurrency frontier.

q90 отделил реальную серверную стоимость FK от process bootstrap. Три
ограничения `object_params` последовательно заняли `18.157`, `8.162` и
`2.758 s`, почти полностью определив `29.499 s` validation floor. При этом
CPU был idle на `85.06%`, а NVMe utilization составлял только
`16.99%` average / `32.72%` peak: свободные ресурсы есть, но текущая операция
не может использовать их безопасной настройкой пула.

PostgreSQL `VALIDATE CONSTRAINT` берёт self-conflicting
`SHARE UPDATE EXCLUSIVE`, поэтому два процесса на одной referencing table
будут ждать друг друга. Один `ALTER TABLE` с несколькими subcommands также не
объединяет FK scans: исходники PostgreSQL проходят очередь FK по одному и для
каждой вызывают отдельный `RI_Initial_Check()`. После принятых W4/LPT q60/q61,
отклонённого process reuse q86 и профиля q90 FK frontier исчерпан. Следующая
гипотеза должна менять raw transport/transaction topology; FK worker knobs
возвращаются только при новой capability PostgreSQL или другом workload.

q91 повторил COPY-text spool с одним глобальным dispatcher и отдельными R8/P4
pools. Fixture, recovery и cleanup прошли, но полный run снова потерял
прогресс: municipal hierarchy остановилась на `processed_rows=0` при
`9 626 553` committed rows вне contiguous prefix. Четыре первых chunks
остались в tmpfs, все worker processes и PostgreSQL sessions были idle.
Валидного timing нет; второй независимый coordinator воспроизвёл тот же класс
liveness, поэтому implementation удалена без A/B.

q92 изолировал binary COPY внутри принятого fused worker без новой очереди.
Все PostgreSQL типы и interruption/resume прошли integration, а полный run
сохранил exact parity. Однако raw вырос `164.107 -> 172.457 s` (`+5.09%`),
E2E — `233.343 -> 235.886 s` (`+1.09%`). Binary stream занял
`15 198 054 646` bytes против `13 188 317 637` у text (`+15.24%`):
четырёхбайтовые field lengths и PHP type conversion дороже компактного текста
для коротких GAR scalar values. Candidate удалён по negative first-run gate.

q93 проверил последний отложенный архитектурный вариант: сначала полностью
нормализовать `17` совместимых XML в private COPY-text segments на tmpfs, затем
отдельно загрузить их в чистый PostgreSQL. W8 normalization дал точные
`122 613 810` rows, `1 527` ranges и прежние `13 188 317 637` bytes, но занял
`252.006 s` — уже на `53.56%` дольше всего q90 raw без единого COPY. Процесс
потребил `1 308.94 s` user и `55.96 s` system CPU; host оставался idle на
`76.30%`, потому что последовательные file stages и range planning не могли
равномерно занять 24 CPU.

Отдельный P4 bulk-load на свежей базе с тем же q90 PostgreSQL profile занял
`123.460 s`; table counts сошлись для всех `122 613 810` rows. P8 не сократил
critical path: `127.706 s` (`+3.44%`), host iowait вырос `1.76% → 4.70%`,
write await `2.46 → 6.76 ms`, а крупные primary-index/WAL writers замедлились.
Даже недостижимый lower bound `1 364.90 CPU-s / 24 + 123.460 = 180.331 s`
хуже q90 raw на `9.89%` до ordinary files, checkpoints и recovery metadata.
Значит fused q90 выигрывает именно перекрытием normalize и COPY; полная
materialization платит обе стоимости последовательно. Production artifact
contract не добавляется, q93 закрыт без A/B.

q94 заменил GAR-specific запуск обычной dependency-safe file phase на
`Fedor\AsyncParser`, сохранив без изменений принятый fused path для `17`
крупных XML. Exact correctness полностью совпала: `122 809 172` rows, table и
file digests, `102` valid indexes, `34/34` FK, нулевые backlogs. Однако raw
вырос `164.107 → 178.957 s` (`+9.05%`), а более короткий tail
`35.961 → 25.748 s` не компенсировал его: E2E вырос
`233.343 → 236.573 s` (`+1.38%`). Финальной raw group снова была
`AS_ADM_HIERARCHY → AS_MUN_HIERARCHY`; последняя заняла `33.782 s`.

По negative first-run gate A/B со старым process adapter не выполнялся. Новый
adapter остаётся выбранным как более тестируемая и переиспользуемая композиция,
но не считается оптимизацией производительности. Этот прогон также ограничивает
ожидания: ordinary phase слишком мала, чтобы сдвинуть текущий critical path;
следующая performance-гипотеза должна менять fused hot path либо workload/
operational contract.

q100 перенёс component-кандидат `1 MiB` text chunks / `4 MiB` atomic
transaction в production range store. XML encode и COPY остались в одном child
process без row payload IPC; data и ledger фиксировались одной transaction.
Exact correctness и recovery прошли, но raw вырос до `187.701 s`: это
`+4.886%` к текущей q94 composition и `+14.377%` к q90. E2E `237.060 s` также
не улучшил ни одну baseline.

WAL к q90 вырос лишь на `0.037%`, поэтому regression не объясняется объёмом
данных. Реальная разница — `3 509` ledger transactions вместо `1 527`,
`3 577` file updates вместо `1 595` и `5 275` operation updates вместо
`3 293`. Несколько критических файлов стали медленнее, а ускоренный post-raw
tail `17.095 s` только скрыл часть raw regression. Вариант `4 MiB` отклонён без
crossover. Последний причинный transport check сохраняет `1 MiB` chunks, но
возвращает одну transaction на исходный planned range до `16 MiB`; это отделит
цену streaming transport от доказанно лишней частоты durable checkpoints.

q101 сохранил transport, но восстановил одну transaction на исходный planned
range. Две cold пары candidate/control/control/candidate дали raw mean
`177.571` против `192.949 s` (`-7.970%`) и E2E `234.886` против `245.939 s`
(`-4.494%`). Exact state и `1 527` checkpoints совпали, WAL mean изменился
только на `+0.028%`. Значит q100 regression действительно создавался частыми
ledger transactions, а process-local generator/chunk COPY сам по себе полезен.

Новый bottleneck неоднороден: largest object-parameters стал медленнее на
`10.057%`, но hierarchy-файлы ускорились примерно на `27%`. Post-raw tail вырос
на `29.160%` только относительно сдвинутого raw frontier: absolute index
completion от старта ускорился на `4.690%`. Следующий loop должен разделить
per-file parse/COPY scheduling; index concurrency уже закрыта q84/q89 и не
должна повторяться.

q102 разделил intrinsic transport и scheduler context. На `12` равномерных
ranges каждого representative XML process-local stream выиграл у array во всех
двухчастных crossover: `4.412–8.296%`, включая houses parameters. Exact
rows/bytes/digest совпали, admitted payload сократился до `~1.05 MB`.
Descriptor-specific array Strategy отклонена.

Полный opt-in trace подтвердил настоящий источник file-duration: houses
parameters все `43.546 s` делил общий P4 с другим params-файлом и владел в
среднем `2.84` writer slots. Administrative/municipal hierarchy выполнялись
без file-overlap и владели `3.77 / 3.64` slots. Их ускорение и houses outlier в
q101 не являются разной эффективностью XML transport. Текущий scheduler
work-conserving глобально; per-file fairness не является throughput
bottleneck. q102 exact point: raw `174.620 s`, E2E `230.936 s`, `122 809 172`
rows, `102` indexes, `34/34` FK, zero backlogs. Per-file Strategy frontier
закрыт; возвращаться к нему можно только для другого latency/workload contract
или при доказанном idle P4 с ready ranges.

q103 убрал следующий глобальный lifecycle barrier. После raw и завершения
активной early-index задачи все FK восстанавливаются одной транзакцией как
`NOT VALID`; затем final index pool и FK validation pool исполняются
одновременно. Candidate/control/control/candidate сохранили exact rows и
digests, `102` valid indexes, `34/34` validated FK и нулевые backlogs.

Обе пары прошли E2E gate: `212.372` против `248.137 s` (`-14.41%`) и
`225.600` против `241.348 s` (`-6.52%`). Median post-raw сократился
`52.460 → 40.801 s` (`-22.22%`). По actual snapshot timestamps median index
tail вырос `19.997 → 33.189 s`, а FK span — `29.207 → 37.012 s`: выигрыш
создаёт overlap, а не ускорение локальных работ. Первоначальная operation-level
telemetry ошибочно ставила `index_build_completed_at` после общего join. В
действительности FK завершались на `7.874 / 3.634 s` позже indexes.

q104 проверил index-first table admission. Exact `122 809 172` rows, digests,
`102` indexes, `34/34` FK и zero backlogs сохранились. E2E `215.273 s` лучше
q103 candidate median лишь на `1.695%`, ниже gate, а post-raw вырос
`40.801 → 42.350 s` (`+3.797%`). Actual indexes завершились через `27.314 s`
после raw, но FK — только через `38.875 s`; последняя serial
`object_params` chain закончилась на `13.233 s` позже indexes. Candidate и
публичный flag удалены без crossover. Следующий профиль должен исследовать
саму serial FK critical chain или DB-side raw cost, а не новый межпуловый
table barrier.

q105 снял actual plans скрытых PostgreSQL FK-проверок. Каждая из трёх
`object_params` FK выполняет отдельный serial Hash Anti Join и полностью
сканирует `49 147 843` referencing rows. Оценки строк точны, но `Gather`
отсутствует даже при `max_parallel_workers=12`: PostgreSQL 17 вызывает
`RI_Initial_Check()` через `SPI_prepare()` с нулевыми cursor options, поэтому
planner не получает `CURSOR_OPT_PARALLEL_OK`. Дополнительные worker limits и
ранний `ANALYZE` закрыты причинным evidence, а не предположением.

q106 доказал component-потенциал visibility map. На fresh exact базе три
heap-based validation заняли `20.408 + 8.866 + 3.080 = 32.354 s`. После полного
VACUUM все `584 781` heap pages стали all-visible; две VM-кандидатные точки
дали `vacuum + validation = 12.660 / 12.490 s`. Искусственные controls после
очистки VM не являются production baseline из-за сохранённого
`pg_class.relallvisible`; поэтому результат разрешил только cold q107.

q107 поставил `VACUUM (ANALYZE) object_params` после общего raw. Все четыре
точки сохранили exact `122 809 172` rows/digests, `102` indexes, `34/34` FK и
zero backlogs. Однако соседние пары разошлись: первый candidate сократил
post-raw `48.249 → 35.838 s`, второй увеличил `42.748 → 53.295 s`; E2E пары
составили `-8.54% / +0.09%`. Во втором candidate VACUUM вырос
`3.825 → 8.026 s`, а index tail — до `49.447 s`. Медианный E2E выигрыш
`4.264%` нельзя приписывать кандидату: candidate raw случайно был быстрее
controls на `4.801%`, хотя изменение начинается только после raw. Кандидат
отклонён и удалён.

q108–q110 проверили оба ранних admission point и устранили неоднозначность.
VACUUM перед четырьмя индексами `object_params` завершился за `2.591 s`, но
target FK остались на fresh heap floor `35.861 s`: открытые raw/index
transactions не позволили установить полезную visibility map. VACUUM после
этих индексов стабильно сократил target FK до `19.057 / 22.482 s`, однако
синхронное исполнение вытеснило remaining index work и дало reverse E2E
regression `6.05%`.

q110 вынес ту же подготовку в отдельный AMP OS process. Target FK сократились
до `24.760 s`, post-raw — на `6.11%` относительно adjacent q109c control, но
E2E вырос на `0.385%`. Actual index tail `37.109 s` оказался на `1.55%` хуже
синхронного q109d (`36.544 s`). Значит, bottleneck не в блокировке PHP
scheduler: `VACUUM` и `CREATE INDEX` конкурируют уже внутри PostgreSQL. Три
позиции — before-index, after-index и post-raw — исчерпаны; production остаётся
на q103 без visibility preparation.

q111 проверил новый remaining-work scheduler без изменения worker limits:
`pg_relation_size(table) × pending_index_count`. Он динамически понизил
приоритет `object_params` после третьего из четырёх indexes и переключил early
I1 на hierarchy chain. Последний `idx_object_params_type_id` ушёл в final pool,
совпал с FK scans той же таблицы и вырос с обычных `~12 s` до `34.348 s`.
Target FK заняли `45.046 s`; post-raw/E2E выросли до
`50.852 / 224.663 s`. Candidate удалён без crossover. Текущий heap-first
scheduler сохраняет важную инварианту: тяжёлая table chain заканчивается до
переключения, пока эта таблица остаётся крупнейшей eligible relation.

q112 локализовал крупнейшую оставшуюся raw write amplification в активном PK:
bulk COPY и последующая сборка `object_params_pkey` сократили component time на
`49.93%`. q113 подтвердил механизм полным crossover: raw уменьшился на
`26.40%`, E2E — на `15.16%`, WAL — на `32.63%`. Bottleneck сместился из
per-row B-tree/WAL maintenance в post-raw concurrent secondary-index/FK tail.

q114 показал границу этой оптимизации. Глобальный приоритет PK полностью скрыл
их за raw, но лишил тяжёлые secondary indexes раннего окна; E2E вырос на
`3.87%`. Поэтому accepted scheduler оставляет heap-size-first I1, а отдельный
primary lane не открывается: q57 уже доказал, что второй ранний worker ухудшает
E2E из-за конкуренции с raw.

## Правило Обновления После Следующего Прогона

Для новой точки фиксируются:

| Поле | Обязательный вопрос |
| --- | --- |
| Hypothesis | Какой участок критического пути должен стать короче? |
| Raw | Какая последняя file/table group завершила raw и почему? |
| Overlap | Сколько index work скрыто raw и когда допущен первый index? |
| Tail | Какие конкретные индексы остались после raw? |
| Finalization | FK, ANALYZE и integrity стабильны или стали новым bottleneck? |
| Resources | CPU, I/O waits, RSS или admission реально ограничили фазу? |
| Correctness | Совпали ли rows, digests, indexes, FK и backlogs? |
| Decision | Прошли ли обе соседние пары gate `>=2%`? |
| Next question | Какой один bottleneck проверяется дальше? |

Если локальная фаза ускорилась, а E2E нет, изменение не комбинируется с другим
кандидатом автоматически. Сначала устанавливается, какой участок поглотил
выигрыш. Комбинировать можно только независимо принятые изменения с повторным
полным crossover.

## Ближайшая Очередь

1. Для post-raw tail сохранять one-second history всех index snapshots и их
   durations, но не возвращать q84 width или q85 cleanup как performance claim
   без нового workload evidence.
2. FK scheduling/configuration frontier закрыт q60/q61, q86 и q90. Process
   reuse, same-table workers и несколько `VALIDATE` в одном `ALTER TABLE` не
   возвращать без новой PostgreSQL capability или другого workload.
3. COPY-text spool закрыт двумя full-scale liveness rejects: file-local q87 и
   global-dispatcher q91. Не добавлять третий scheduler layer без новой
   доказуемой модели worker-pool completion.
4. q88 fresh profile выполнен; выбранный q89 targeted width `2` отклонён по
   negative first-run gate. Не возвращать same-table concurrency без нового
   workload: q84 показал нестабильный global E2E, q89 — слабую scoped экономию
   из-за удвоения времени конкурирующих builds.
5. Binary COPY q92 закрыт full-scale reject и повторно подтверждён q99c на
   continuous topology: `+17.01%` component time, payload `+13.08%`. Не
   возвращать encoder без другого schema/data distribution.
6. Ordinary INSERT закрыт q99d: лучший VALUES-1000 проиграл text COPY на
   `88.08%` при `100k` и на `85.82%` при `1M`; single-row и другие batches хуже.
7. Bulk-load/pre-normalization q93 закрыт: W8 normalize `252.006 s`, лучший P4
   load `123.460 s`, а идеальный 24-CPU lower bound суммы `180.331 s` уже хуже
   q90 raw. P8 load дополнительно ухудшился на `3.44%`.
8. Production `1 MiB` text stream с planned-range transaction q101 принят:
   exact crossover дал raw `-7.97%` и E2E `-4.49%` при прежних `1 527`
   checkpoints. Следующий loop исследует per-file неоднородность; binary,
   INSERT, index width, chunk width и `4 MiB` checkpoints не возвращаются.
9. q94 не открывает новый performance frontier: reusable adapter полезен как
   composition boundary, но ordinary-file transport не является bottleneck.
   Не сравнивать его повторно без workload, где ordinary files занимают
   материальную долю raw.
10. q102 закрыл per-file transport Strategy: stream быстрее array на каждом
    representative XML, а wall-time различается из-за shared P4/F2 overlap.
    Не оптимизировать fairness отдельного файла без global raw/E2E hypothesis.
11. q103 принял overlap final indexes/FK. q104 index-first table admission
    отклонён: он ускорил actual index completion, но увеличил разрыв до
    завершающей `object_params` FK chain и не прошёл `2%` gate. Не возвращать
    общий table barrier; следующий candidate обязан сокращать serial FK scan
    либо измеренную DB-side часть raw.
12. q105 доказал, что native PostgreSQL 17 FK validation структурно serial;
    worker и ANALYZE knobs закрыты. q106 подтвердил index-only потенциал, но
    q107–q110 исчерпали все безопасные места для VACUUM: до indexes VM не
    возникает, после indexes и после raw он конкурирует с critical index tail.
    q111 также закрыл dynamic remaining-work scheduling: дробление
    `object_params` chain резко усиливает same-table index/FK contention.
    Следующий loop допускается только по новой PostgreSQL capability или иной
    transaction/index topology, а не по перестановке текущих очередей.

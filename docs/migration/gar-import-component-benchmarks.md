# Component Benchmarks Нового GAR Import Path

- Дата: `2026-11-16`
- Статус: safe-range/recovery cost, XML parser alternatives, COPY transport
  chunks, минимальная transaction/recovery curve и binary/INSERT alternatives
  измерены; production ledger, WAL profile и полный E2E ещё не проверены
- Full E2E baseline: q90 остаётся production control до отдельного полного
  прогона

## q99 Async-Parser Structural Ownership

До transport alternatives библиотека `app/library/async-parser` переведена с
core-owned arbitrary byte batches на прямой `FileReference`: parser открывает
path внутри worker и сам владеет XML/NDJSON/CSV/XLSX boundaries. `FileInput`,
`FileBatch`, `chunkBytes()`, `buffer()`, internal filesystem reader,
`amphp/file` и `amphp/pipeline` удалены из runtime core 0.2. После отдельной
проверки `amphp/file 4.0.0` возвращён только в package `require-dev`, а
`ext-uv` — в `suggest`; это не возвращает byte-reader в публичный API.

Контроль pure read на прежнем manifest `28 XML / 24 975 511 491 bytes` не
показал самостоятельного выигрыша. После eviction и `RES=0` крупнейшего файла:

| Mode | 0.2 points | Mean | Historical 0.1 | Вывод |
| --- | ---: | ---: | ---: | --- |
| Direct W28 | `8.283557 / 7.869466 s` | `8.076511 s` | `8.093600 s` | `-0.21%`, neutral |
| Handler W28 | `7.281357 / 8.193891 s` | `7.737624 s` | `7.952091 s` | signal `-2.70%`, но spread `11.79%` |

Warm `1.729–1.746 s` был page-cache diagnostic, не NVMe throughput и не
принимается как gain.

На deterministic NDJSON `8 × 250 000` records structural pipeline дал W1
`1.164175 s` и W8 `0.253837 / 0.256175 s` (mean `0.255006 s`). Historical 0.1
был W1 `35.413665 s`, W8 mean `6.629035 s`: улучшение W8 `26.00×` / elapsed
`-96.15%`. Во всех новых точках совпали `2 000 000` records, `400` writes,
`84 888 896` input/output bytes и output digest.

Локализованный bottleneck: старый NDJSON parser удерживал `1 MiB` carry, искал
newline и копировал весь остаток через `substr` на каждой строке. `fgets()`
сразу возвращает structural record; одновременно исчезли Amp file/Future и
async-file sink wrappers внутри отдельного OS worker. Это подтверждает новый
ownership contract, но не переносится автоматически на GAR XML: production
XML уже использует XMLReader и требует отдельного raw/E2E gate.

Следующее library extension записано, но не реализуется в q99: явный
structural `Strategy` seam для XML/SAX, NDJSON, CSV, JSON, XLSX и их собственных
buffer/checkpoint rules без возврата arbitrary byte batches в core.

### Filesystem driver factory

Benchmark-only factory повторяет принцип runtime selection: environment
`ASYNC_PARSER_BENCHMARK_FILE_DRIVER` имеет приоритет, затем `auto` выбирает
`uv → eio → native`. Standard process fallback `amphp/file` исключён из auto,
потому что file task уже выполняется в отдельном OS worker и вложенный pool
поменял бы topology. Доступны explicit `native`, `blocking`, `uv`, `eio`.

Cold W28 crossover на exact manifest дал native
`7.247626 / 8.194367 s` (mean `7.720996 s`) и UV
`7.176245 / 8.023598 s` (mean `7.599922 s`). UV был быстрее в обеих парах на
`0.985% / 2.084%`, но mean gain только `1.568%` при spread
`12.26% / 11.15%`. Single blocking diagnostic был `7.892898 s`. Все точки
прочитали `24 975 511 491` bytes за `23 840` calls после `resident=0`.

Решение: UV backend исправен и optional suggestion оправдан descriptor-heavy
runtime и будущим async Strategy, но pure read gain ниже `2–3%` gate. Factory
остаётся benchmark support; production/public Strategy появится только после
structural parser + waiting sink benchmark на реалистичном workload.

## Зачем Этот Срез Отделён От E2E

Полный GAR benchmark одновременно смешивает filesystem read, XML parsing,
COPY encoding, PostgreSQL transport, indexes, FK и finalization. Этот документ
разделяет первые четыре границы, чтобы локальное ускорение не выдавалось за
ускорение полной инициализации.

Исполняемые harness:

- `app/benchmarks/gar-xml-range-cost.php` — safe-range discovery, linear parse,
  range parse и два resume-сценария;
- `app/benchmarks/gar-xml-parser-comparison.php` — raw scan и эквивалентный
  XML→normalize→COPY encode→SHA-256 через native и third-party XML paths;
- `app/benchmarks/gar-xml-copy-pipeline.php` — production-style row batches,
  continuous chunked COPY, transport chunk sweep и transaction-sized COPY с
  минимальным atomic ledger marker на реальном XML;
- `app/benchmarks/gar-xml-transport-alternatives.php` — bounded binary COPY,
  prepared single-row INSERT и multi-row VALUES на одном нормализованном
  object-parameters prefix с deterministic PostgreSQL round-trip digest;
- `app/benchmarks/xml-parsers/composer.lock` — изолированные benchmark-only
  версии Sabre, BenMorel и Prewk; production dependencies не меняются;
- `app/benchmarks/pgsql-copy-iterable.php` — одинаковый `pg_copy_from()` с
  `array` и `Generator`;
- `app/benchmarks/pgsql-copy-nonblocking.php` — одинаковые `1 MiB` chunks через
  blocking и readiness-driven `pg_put_copy_data()`;
- `app/benchmarks/php85-pgsql/Dockerfile` — отдельный pinned PHP `8.5.11`
  image; production runtime проекта не меняется.

## XML-Safe Ranges И Resume

### Условия

- PHP `8.4.27`, image `geo-registry-amp-app:latest` на момент замера;
- один крупнейший region-50 XML:
  `AS_HOUSES_PARAMS_20260831_dc096bf8-6bce-4d35-8158-61fe7bdf77e1.XML`;
- source bytes: `5 334 695 811`;
- XML rows: `29 329 019`;
- range limit: `8 MiB`, row limit: `50 000`;
- PostgreSQL не участвует;
- parse modes дополнительно считают SHA-256 закодированных COPY rows, поэтому
  это correctness baseline, а не минимальное production parse time.

### Результаты

| Mode | Результат | Время | Peak memory | Вывод |
| --- | ---: | ---: | ---: | --- |
| Cold `plan` | `636` ranges / `29 329 019` rows | `7.729 s` | `31.47 MB` | Первый scan включает cold-source цену |
| Warm `plan` внутри range run | те же границы | `4.701 s` | — | Чистая прогретая цена discovery |
| `resume-plan`, 50% committed | осталось `318` ranges / `14 662 956` rows | `4.874 s` | `31.47 MB` | Planner всё равно сканирует файл целиком |
| Linear parse/encode/hash | `29 329 019` rows | `91.798 s` | `6.29 MB` | Однопроцессный correctness control |
| Linear resume, 50% rows | осталось `14 664 510` rows | `4.871 + 61.941 = 66.813 s` | `31.47 MB` | Экономия лишь `27.22%`, не `50%` |
| Sequential safe-range parse | `636` ranges, exact digest | `4.701 + 95.390 = 100.091 s` | `37.76 MB` | `+9.03%` к linear control до parallelism |

Linear и safe-range paths дали одинаковые encoded bytes
`2 363 492 345` и SHA-256
`482ba3689c86ba2959a200c38b7cff490969ce650c6996f2ce5e61d1f7220056`.

### Решение

Safe ranges имеют измеримую цену, но нужны прежде всего для распараллеливания
одного большого XML. Сам planner на прогретом source стоит около `5.12%`
linear parse baseline. Его нельзя целиком объявлять recovery tax.

Resume не является бесплатным «продолжением с позиции»: planner снова читает
весь файл, а обычный row checkpoint XMLReader снова проходит уже обработанные
elements. На половине файла полный resume component path экономит `27.22%`.
Механизм оправдан после process/container/host crash или потери PostgreSQL
connection, но не как отдельная оптимизация для намеренной остановки
администратором. Durable ledger tax внутри PostgreSQL остаётся следующим
изолированным измерением.

## XML Parser Alternatives

### Границы Сравнения

Проверены два разных вопроса:

1. `scan` — прочитать source и определить строки без normalize/COPY encode;
2. `transform` — получить ровно `29 329 019` rows, закодировать
   `2 363 492 345` COPY bytes и получить контрольный SHA-256
   `482ba3689c86ba2959a200c38b7cff490969ce650c6996f2ce5e61d1f7220056`.

Одинаковый digest обязателен для transform-кандидатов. `prewk-scan` является
только specialized string-extraction ceiling: библиотека ищет matching tags и
не доказывает эквивалентную XML validation/attribute normalization.

Runtime: pinned PHP `8.4.27`, libxml2 `2.13.9`. [Native XMLReader является
pull parser](https://www.php.net/manual/en/class.xmlreader.php), а native
[XML Parser API](https://www.php.net/manual/en/ref.xml.php) выдаёт SAX-style
callbacks. Из внешних вариантов проверены pinned
[`sabre/xml 4.1.0`](https://packagist.org/packages/sabre/xml),
[`benmorel/xml-streamer 0.6.0`](https://packagist.org/packages/benmorel/xml-streamer)
и [`prewk/xml-string-streamer 1.2.3`](https://packagist.org/packages/prewk/xml-string-streamer).
Sabre и BenMorel используют native XMLReader, поэтому это API/representation
alternatives, а не независимые C parser backends.

Воспроизведение из `app/`:

```bash
composer --working-dir=benchmarks/xml-parsers install --no-dev
composer benchmark:gar-xml-parser-comparison -- xmlreader-fused <xml-file>
```

### Scan Крупнейшего Файла

| Path | Время | Peak/RSS | Что фактически сделано |
| --- | ---: | ---: | --- |
| `fread(8 MiB)` + file SHA-256 | `12.142 s` | `23.08 MB` PHP peak | Прочитаны все `5.33 GB`, XML не разбирался |
| Native XMLReader | `28.316 s` | `6.29 MB` PHP peak | Проверен XML token stream, посчитаны depth-1 rows |
| Native SAX, лучший из `1/8/32 MiB` | `48.379 s` | `10.49–73.41 MB` PHP peak | Создан PHP callback и attributes array на каждую row |
| Prewk UniqueNode, tuned `16 KiB` | `59.859 s` | `6.29 MB` PHP peak | Выделены raw strings всех self-closing rows, XML correctness неэквивалентна |

SAX input chunk не является tuning lever: `1/8/32 MiB` дали
`48.386 / 48.656 / 48.379 s`. Цена находится в per-row callback/array, а не в
числе `fread()`.

### Эквивалентный Transform Крупнейшего Файла

| Path | Время | Относительно | Решение |
| --- | ---: | ---: | --- |
| Production `GarXmlRowReader → list → encoder` | `92.273 s` | control | Correctness baseline повторён |
| Direct native XMLReader | `88.007 s` | `-4.62%` | Положительный signal: убрать промежуточный generator/array handoff |
| Fused native XMLReader, A/B mean | `87.630 s` | `-5.51%` к A/B control `92.739 s` | Стабильный component candidate |
| Sabre Reader | `89.259 s` | `+1.42%` к direct native | Reject: тот же backend и дополнительная обвязка |
| Native SAX | `94.519 s` | `+2.43%` к production | Reject: callback/attributes allocation дороже |
| BenMorel XMLStreamer | `133.596 s` | `+51.80%` к direct native | Reject: `expand()` создаёт DOMElement на каждую row |

Interleaved A/B подтвердил fused point:

- control: `92.707 / 92.771 s`;
- fused: `87.445 / 87.816 s`;
- mean improvement: `5.508%`;
- rows, encoded bytes и digest во всех четырёх прогонах совпали.

Fused относительно отдельного direct run быстрее только примерно на `0.43%`.
Следовательно, основная экономия возникает от удаления лишнего
`yield list → acceptValues` boundary; ручная замена `array_map + implode` сама
по себе не проходит установленный `2–3%` gate.

### Full-Memory DOM И SimpleXML

На крупнейшем файле оба процесса достигли cgroup limit `32 GiB` RSS и были
остановлены до завершения load. Это контролируемый benchmark failure, а не
ошибка PHP memory limit: libxml tree allocations видны во внешнем RSS, но не в
`memory_get_peak_usage()`.

Дополнительный полный transform файла `288 414 378` bytes / `1 587 584` rows:

| Path | Load | Transform/total | Max RSS | Результат |
| --- | ---: | ---: | ---: | --- |
| Fused XMLReader | streaming | `4.803 s` | `26 MB` | control |
| DOM | `2.517 s` | `3.757 / 7.770 s` | `3.56 GiB` | `+61.78%`, около `13.27×` source bytes |
| SimpleXML | `2.577 s` | `4.103 / 8.154 s` | `3.56 GiB` | `+69.77%`, около `13.27×` source bytes |

Full-memory path поэтому не запрещён библиотечным контрактом, но отклонён для
данного GAR workload и сервера: он одновременно медленнее и требует порядка
`70 GiB` для крупнейшего файла при наблюдаемом коэффициенте. Для небольших XML
это остаётся usability choice, но не performance optimization.

### Решение

- Максимум диска не достигнут: raw read занимает только `12.142 s` из
  `92–93 s` production transform.
- Максимум libxml2 parsing также не достигнут как общий runtime bottleneck:
  XMLReader scan занимает `28.316 s`, а около `59–64 s` добавляет PHP
  attribute extraction, normalization, COPY escaping и hashing.
- Альтернативный SAX binding, XMLReader wrappers и full-memory tree не дали
  выигрыша. Native XMLReader остаётся лучшей основой.
- Кодовый максимум ещё не достигнут: fused/direct API убирает один лишний
  per-row boundary и стабильно даёт `5.51%` component gain.
- Следующий шаг — перенести fused row production в production-shaped
  XML→bounded COPY chunks path, после чего сравнить blocking и readiness-driven
  `pg_put_copy_data()` на полном raw/E2E contract.

## Production-Shaped XML→COPY

### Условия

- крупнейший XML `5 334 695 811` bytes / `29 329 019` rows;
- encoded payload `2 363 492 345` bytes, exact SHA-256 сохранён;
- PostgreSQL `17` с временным data directory в tmpfs;
- temp table использует production-shaped `bigint/integer/text/date` columns,
  но не production indexes/FK/WAL/checkpoint metadata;
- `pg-copy-batches` повторяет текущую форму: XMLReader формирует массивы по
  `50 000` encoded rows и вызывает `pg_copy_from()` `587` раз;
- continuous modes держат один COPY open и подают `1 MiB` chunks;
- bounded mode завершает отдельный COPY на каждые `4 MiB`, то есть даёт `564`
  recovery-sized boundaries, близких к текущим `587` row batches.

`encode_seconds` в harness считает только row normalization/encoding внутри
итератора. Полный XMLReader traversal, generator dispatch и COPY coordination
входят в `seconds`; поэтому только `seconds` используется для acceptance.

### Результаты

| Path | Прогоны | Mean | Относительно control | EventLoop/backpressure |
| --- | ---: | ---: | ---: | --- |
| `pg_copy_from()` batches | `111.547 / 111.989 / 112.114 s` | `111.883 s` | control | `0` ticks |
| Continuous blocking chunks | `90.599 / 91.841 s` | `91.220 s` | `-18.469%` | `0` ticks |
| Continuous readiness-driven | `89.817 s` | — | `-19.64%` к control, но только `-1.54%` к blocking mean | `3` ticks, `2` read waits, `0` write waits |
| Bounded blocking, `4 MiB` | `108.143 / 108.802 s` | `108.473 s` | `-3.048%` ко всем controls; `-3.194%` к соседним controls | `0` ticks |

Все modes дали `29 329 019` rows, одинаковые encoded bytes/digest и одинаковую
сумму хранимых `value` bytes `305 426 607`. Peak PHP memory: `12.58 MB` у
control, `10.49 MB` у continuous chunks, `20.98 MB` у bounded `4 MiB`.

### Интерпретация И Решение

Текущий batch path сначала парсит до `50 000` строк, затем блокируется внутри
`pg_copy_from()`. Измеренные `~17.95 s` COPY складываются с XML path почти
последовательно. Continuous path оставляет COPY open: пока PHP формирует
следующий chunk, PostgreSQL уже потребляет ранее переданные bytes. Поэтому
transport почти полностью скрывается под XML transform, а не просто становится
дешевле.

Continuous result пока нельзя принять в production без изменения recovery
contract: падение до `pg_put_copy_end()` откатывает весь текущий file COPY и
теряет промежуточные checkpoints. Bounded `4 MiB` сохраняет близкую к текущей
replay granularity, но оставляет только `3.05–3.19%` component margin. Этот
q97 harness не включал `BEGIN + COPY + ledger update + COMMIT`;
q98 ниже изолирует минимальную transaction curve.

Readiness-driven mode сохраняет EventLoop liveness, но на локальном tmpfs
PostgreSQL не встретил write backpressure и не прошёл throughput gate против
blocking. Production prototype поэтому должен сначала использовать более
простой blocking chunk protocol. Async adapter остаётся отдельным выбором для
responsiveness, удалённого/медленного PostgreSQL или полезной работы других
Fibers во время readiness wait.

### q98: Transport Chunk И Transaction Boundary

q98 разделил две независимые настройки:

- transport chunk — сколько bytes передаётся одному `pg_put_copy_data()`;
- transaction boundary — сколько уже отправленных bytes допускается до
  `pg_put_copy_end()`, ledger marker и `COMMIT`.

Сначала один continuous COPY прошёл sweep chunk size. Все варианты сохранили
`29 329 019` rows, `2 363 492 345` encoded bytes, SHA-256 и
`305 426 607` stored value bytes.

| Transport chunk | Chunks | Время | Delta к `1 MiB` | PHP peak |
| ---: | ---: | ---: | ---: | ---: |
| `256 KiB` | `9 018` | `90.484 s` | `+0.803%` | `6 MiB` |
| `512 KiB` | `4 509` | `89.970 s` | `+0.230%` | `8 MiB` |
| `1 MiB` | `2 255` | `89.763 s` | control | `10 MiB` |
| `2 MiB` | `1 128` | `90.736 s` | `+1.084%` | `14.01 MiB` |
| `4 MiB` | `564` | `91.317 s` | `+1.730%` | `20.01 MiB` |
| `8 MiB` | `282` | `91.537 s` | `+1.976%` | `32.01 MiB` |
| `16 MiB` | `141` | `92.138 s` | `+2.645%` | `56.01 MiB` |
| `32 MiB` | `71` | `92.246 s` | `+2.765%` | `104.01 MiB` |
| `64 MiB` | `36` | `92.918 s` | `+3.515%` | `200.01 MiB` |
| `128 MiB` | `18` | `93.386 s` | `+4.035%` | `392 MiB` |

`1 MiB` остаётся throughput profile: larger chunks не ускорили transport и
монотонно увеличили retained memory. `512 KiB` допустим как lower-memory
profile, но не является ускорением.

Здесь `1 MiB` — это `1 048 576` encoded COPY-text bytes, а не
фрагмент source XML. В largest fixture средняя encoded row занимает
`80.585` bytes, поэтом полный chunk содержит около `13 012` rows;
наблюдаемое среднее по `2 255` chunks с учётом последнего
неполного — `13 006` rows. Source XML в среднем занимает `181.891`
bytes/row, то есть `1 MiB` source содержал бы около `5 765` rows.

Затем transport зафиксирован на `1 MiB`. Каждый transaction segment выполнял
`BEGIN -> COPY -> ledger insert -> COMMIT`; committed ledger bytes обязаны были
совпасть с encoded bytes. Это минимальный marker в temp table, а не полный
production ledger.

| Transaction boundary | Transactions | Время | Delta к whole file |
| ---: | ---: | ---: | ---: |
| whole file | `1` | `90.257 s` | control |
| `1 GiB` | `3` | `90.958 s` | `+0.777%` |
| `256 MiB` | `9` | `90.413 s` | `+0.173%` |
| `64 MiB` | `36` | `90.823 s` | `+0.627%` |
| `16 MiB` | `141` | `91.452 s` | `+1.324%` |
| `4 MiB` | `564` | `92.229 s` | `+2.185%` |
| `2 MiB` | `1 128` | `93.107 s` | `+3.158%` |
| `1 MiB` | `2 255` | `93.359 s` | `+3.437%` |

Interleaved confirmation минимального in-gate кандидата:

- whole-file control: `90.257 / 90.515 s`, mean `90.386 s`;
- `4 MiB`: `92.229 / 91.493 s`, mean `91.861 s`;
- observed component tax: `+1.632%`;
- `564` transactions, максимальный segment `4 194 264 bytes`, exact parity.

Это меняет интерпретацию q97. Его bounded path был медленным не из-за одного
лишь числа `COMMIT`: он сначала целиком готовил recovery-sized
chunk и только затем открывал COPY, теряя overlap внутри segment.
Continuous `1 MiB` streaming можно завершать атомарными `4 MiB`
segments, не возвращаясь к produce-then-send boundary, и с небольшим
component tax.

Явный transaction не нужен ради самого факта наполнения disposable пустой БД:
один COPY statement и без внешнего `BEGIN` атомарен. Он нужен, когда данные и
durable checkpoint обязаны фиксироваться вместе. Одна transaction на весь файл
при этом не обязательна — атомарной единицей может быть безопасный segment.

Ограничение вывода существенно: temp tables и PostgreSQL data directory в
tmpfs не воспроизводят durable WAL/fsync и полный production ledger. Поэтому
`1 MiB` transport + `4 MiB` transaction является component candidate, а не
production default. Production store/WAL и full E2E отложены. Следующий
component loop:

1. на identical normalized prefix дать q92 binary COPY один
   benchmark-only recheck с теми же `1 MiB / 4 MiB` boundaries;
2. сравнить text COPY с prepared single-row INSERT и multi-row VALUES
   batches `100/1 000/5 000`, начав с `100 000` rows и hard timeout;
3. отклонить медленный transport до полных `29M` rows; только
   положительный component signal получает A/B;
4. после этого вернуться к production range transaction и raw/WAL
   A/B; E2E остаётся ещё более поздним gate.

PostgreSQL 17 заранее даёт сильное counter-evidence: [COPY оптимизирован
для bulk load и почти всегда быстре INSERT](https://www.postgresql.org/docs/17/populate.html),
даже при prepared statements и одной transaction. Локальный benchmark
нужен для закрытия project-specific вопроса, а не потому, что
INSERT ожидается победителем.

### q99c: Binary COPY И Ordinary INSERT

Transport alternatives проверены в pinned `geo-registry-amp-app` на
PHP `8.4.27` / libpq `18.7` против PostgreSQL `17`. Input — первые
`100 000` строк крупнейшего object-parameters XML региона 50; text и binary
COPY использовали `1 MiB` transport chunks и `4 MiB` transaction boundaries.
Каждый режим имел внешний hard timeout `60 s`. Validation после timed section
сортировала PostgreSQL rows по всем девяти полям и считала обратный COPY-text
SHA-256, поэтому сравнивается сохранённая логическая строка, а не различающийся
wire format.

| Transport | Queries / transactions | Total | Delta к text COPY | Payload / parameters | PHP peak |
| --- | ---: | ---: | ---: | ---: | ---: |
| Text COPY | `2` transactions | `0.353935 s` | control | `8 053 846 B` | `10 MiB` |
| Binary COPY | `3` transactions | `0.414149 s` | `+17.013%` | `9 107 558 B` | `10 MiB` |
| Prepared INSERT × 1 | `100 000` queries | `1.267875 s` | `+258.222%` | `7 016 720 B` params | `6 MiB` |
| VALUES × 100 | `1 000` queries | `0.772568 s` | `+118.279%` | `7 016 720 B` params | `6 MiB` |
| VALUES × 1 000 | `100` queries | `0.665667 s` | `+88.076%` | `7 016 720 B` params | `6 MiB` |
| VALUES × 5 000 | `20` queries | `0.683019 s` | `+92.979%` | `7 016 720 B` params | `12 MiB` |

Binary корректен, но повторяет q92 на новой topology. Его payload вырос на
`13.083%`, encode time — с `0.103169` до `0.164487 s` (`+59.435%`), тогда как
собственно COPY time почти совпал: `0.007451` против `0.007651 s`. Выигрыша от
binary protocol нет; цену создают PHP type conversion, field-length framing,
больший payload и третья recovery transaction.

Лучший INSERT (`VALUES × 1 000`) прошёл предусмотренную scale-confirmation на
`1 000 000` строк: `6.671476 s` против `3.590259 s` text COPY, то есть
`+85.822%`. На обоих размерах совпали row count, stored value bytes и logical
SHA-256 (`2d8029...fe68d` для `100k`, `c22e98...61390` для `1M`). Ни binary,
ни INSERT не достигли положительного `2%` gate, поэтому interleaved A/B и полный
`29M` run не выполняются.

Transport alternatives закрыты. Следующий отдельный gate перенёс принятые
`1 MiB` text chunks / `4 MiB` recovery transaction в production range store;
его результат зафиксирован ниже.

### q100a: Production Ledger И WAL

Opt-in `--pipeline-streaming-copy` сохранил XML parsing, encoding и COPY внутри
одного child process. После `1 MiB` prefetch тот же worker продолжал generator
и отправлял row-aligned chunks через `pg_put_copy_data()`. Каждые `4 MiB`
закрывались COPY, ledger и transaction. Control `pg_copy_from(array)` остался
доступен без флага.

Полный cold-source region-50 run на PHP `8.4.27` и PostgreSQL `17` прошёл exact
gate: `122 809 172` rows, оба digest, `102` valid indexes, `34/34` FK и нулевые
backlogs совпали. Ошибочный COPY segment и supervisor interruption отдельно
прошли integration recovery.

| Метрика | q90 лучший control | q94 текущая composition | q100a `4 MiB` stream |
| --- | ---: | ---: | ---: |
| Raw | `164.107 s` | `178.957 s` | `187.701 s` |
| Крупнейший object-parameters XML | `41.131 s` | `47.047 s` | `46.106 s` |
| Post-raw index tail | `35.961 s` | `25.748 s` | `17.095 s` |
| FK validation | `29.499 s` | `28.046 s` | `28.397 s` |
| E2E | `233.343 s` | `236.573 s` | `237.060 s` |
| Ledger transactions | `1 527` | `1 527` | `3 509` |
| WAL LSN bytes | `24 418 745 288` | `24 426 194 568` | `24 427 880 416` |

Raw q100a хуже текущей composition на `4.886%` и q90 на `14.377%`; E2E хуже
q90 на `1.593%`. Увеличение WAL к q90 составило только `0.037%`, зато число
ledger transactions выросло на `129.797%`, file updates — `1 595 → 3 577`,
operation updates — `3 293 → 5 275`. В критических файлах также выросла
стоимость: apartments parameters `32.179 → 37.025 s`, administrative hierarchy
`27.667 → 33.999 s`, change history `27.331 → 30.299 s` относительно q94.

Таким образом, component tax `1.632%` недооценил production metadata/lock
стоимость частых checkpoints. q100a отклонён по negative first-run gate без
control crossover. Единственная оставшаяся transport-развилка должна отделить
chunking от transaction frequency: сохранить `1 MiB` transport, но вернуть
одну atomic transaction на исходный planned range (не больше `16 MiB`). Если и
она не даст положительного raw signal, low-level streaming path удаляется.

### q101: Planned-range Transaction

В q101 writer больше не владеет скрытой границей transaction. Fused task
передаёт ему собственный `maxEncodedBytes`: `1 MiB` row-aligned transport
chunks остаются, но весь заранее проверенный XML-safe range до `16 MiB`
фиксируется одним `COPY + ledger + COMMIT`. Тем самым q101 меняет только
transaction topology относительно q100, не discovery, scheduler или durability.

Cold crossover выполнен в порядке candidate/control/control/candidate. Все
четыре прогона дали `122 809 172` rows, одинаковые table/file digest, `102`
valid indexes, `34/34` FK и нулевые backlogs.

| Метрика | Candidate q101a/q101d | Control q101b/q101c | Изменение mean |
| --- | ---: | ---: | ---: |
| Raw | `172.654 / 182.488 s` | `193.962 / 191.935 s` | `-7.970%` |
| Post-raw index tail | `25.649 / 22.652 s` | `16.832 / 20.564 s` | `+29.160%` |
| Index complete from operation start | `198.303 / 205.140 s` | `210.794 / 212.499 s` | `-4.690%` |
| E2E | `232.277 / 237.496 s` | `243.328 / 248.550 s` | `-4.494%` |
| Largest object-parameters file | `49.990 / 44.557 s` | `42.998 / 42.908 s` | `+10.057%` |
| Ledger transactions | `1 527 / 1 527` | `1 527 / 1 527` | parity |
| WAL LSN bytes, mean | `24 439 882 904` | `24 433 160 848` | `+0.028%` |
| Peak admitted payload | `~4 MiB` | `~52 MiB` | `-91.9%` |

Даже худший candidate raw быстрее лучшего control примерно на `4.92%`.
Ускорение не сосредоточено в largest fixture: он регрессировал. Post-raw tail
вырос относительно более раннего raw frontier, но absolute index completion
от старта ускорился на `4.690%`; это оголившийся остаток, а не index regression.
Administrative hierarchy mean сократился на `27.485%`,
municipal hierarchy — на `26.748%`, address-object parameters — на `14.563%`.
Process-local generator не удерживает полный encoded range до writer permit и
лучше распределяет parse/COPY pressure между concurrent files. Минимальный WAL
delta и одинаковое число checkpoints исключают ослабление durability как
источник выигрыша.

q101 принят: low-level text streaming стал единственной fused implementation;
отдельные CLI flag и array branch удалены. Следующая работа должна сначала
причинно объяснить per-file неоднородность; index knobs уже закрыты q84/q89, а
возвращаться к `4 MiB` checkpoints, binary COPY, ordinary INSERT или очередному
chunk sweep не нужно.

### q102: Per-file Transport И Shared Admission

Сначала одинаковые production-safe ranges четырёх файлов были проверены в
компонентном crossover `array/stream/stream/array`. Для каждого файла один
planner pass выбрал `12` ranges, равномерно распределённых от начала до конца;
COPY шёл в временную таблицу с production PostgreSQL types, без canonical
indexes. Rows, encoded bytes и SHA-256 совпали во всех повторах.

| XML | Array mean | Stream mean | Stream delta |
| --- | ---: | ---: | ---: |
| address-object parameters | `3.849167 s` | `3.652286 s` | `-5.115%` |
| houses parameters | `4.068952 s` | `3.889431 s` | `-4.412%` |
| administrative hierarchy | `4.443911 s` | `4.136639 s` | `-6.914%` |
| municipal hierarchy | `4.088662 s` | `3.749465 s` | `-8.296%` |

Stream выиграл в обеих половинах crossover у всех четырёх XML, включая houses
outlier q101. Peak admitted encoded payload уменьшился с `7.4–12.5 MB` до
примерно `1.05 MB`. Следовательно, filename/descriptor Strategy не имеет
причинного основания.

Следующий cold q102a включил benchmark-only stage trace в полном region-50
pipeline. Он дал exact `122 809 172` rows, canonical digests, `102` valid
indexes, `34/34` FK, нулевые backlogs и прежние `1 527` ranges. Raw составил
`174.619942 s`, E2E `230.936098 s`; это diagnostic point внутри q101 spread, а
не новая A/B-кандидатура.

Houses parameters занимал в среднем только `2.84` из общего P4
(`123.796749 writer-s / 43.546021 wall-s`), потому что весь интервал делил
writers: сначала `22.416700 s` со steads parameters, затем `21.065207 s` с
apartments parameters. Administrative и municipal hierarchy не имели
file-overlap и использовали примерно `3.77 / 3.64` slots. Поэтому их file wall
нельзя сравнивать как intrinsic transport cost. q102 закрывает per-file
неоднородность как эффект shared work-conserving scheduler, сохраняет единый
streaming transport и не вводит fairness ради одной file-duration.

## PHP 8.5 Iterable `pg_copy_from()`

### Условия

- один PostgreSQL `17` container, data directory в tmpfs;
- `1 000 000` rows, payload `128 000 000` bytes;
- три прогона каждой точки после correctness warm-up;
- PHP `8.4.27` и отдельный PHP `8.5.11`, оба с libpq `18.7`;
- temp table без production indexes/FK/WAL profile: это transport/allocation
  component benchmark, не E2E claim.

| Runtime/input | Mean prepare | Mean COPY | Mean total | Memory после prepare |
| --- | ---: | ---: | ---: | ---: |
| PHP 8.4 array | `0.0837 s` | `0.4341 s` | `0.5178 s` | `213.91 MB` |
| PHP 8.5 array | `0.0861 s` | `0.4335 s` | `0.5196 s` | `211.82 MB` |
| PHP 8.5 generator | `~0 s` | `0.4183 s` | `0.4183 s` | `2.10 MB` |

Generator снизил mean total на `19.50%` относительно PHP 8.5 array и убрал
около `99.01%` PHP allocation этой входной коллекции. Версия PHP сама по себе
не ускорила array control. Положительный сигнал связан именно с iterable input.

Это не делает `pg_copy_from()` асинхронным: вызов остаётся blocking. Но PHP 8.5
позволяет не материализовать `array<string>` целиком, поэтому generator должен
быть проверен на реальном XML→COPY path с прежними transaction/checkpoint
гарантиями.

## PHP 8.4 Readiness-Driven COPY

### Подтверждённый API Path

В pinned PHP 8.4 доступны `pg_socket()`, `pg_socket_poll()`,
`pg_put_copy_data()`, `pg_put_copy_end()`, `pg_flush()`,
`pg_consume_input()` и `pg_connection_busy()`. Stream из `pg_socket()` принимает
`stream_set_blocking(false)`; текущая ext-pgsql implementation передаёт эту
настройку в `PQsetnonblocking()`.

`pg_connect_poll()` обслуживает только handshake соединения, созданного через
`pg_connect(..., PGSQL_CONNECT_ASYNC)`. Он не является COPY poller. После
handshake COPY state machine использует socket readiness, `pg_send_query()`,
`pg_put_copy_data()`, `pg_flush()` и final result consumption.

Установленный `amphp/postgres v2.2.2` уже применяет `pg_connect_poll()` и Revolt
read/write watchers для queries, но явно отклоняет `PGSQL_COPY_IN` и
`PGSQL_COPY_OUT`. Поэтому COPY требует отдельного adapter, а не вызова
существующего `PostgresConnection::query('COPY ...')`.

### Blocking/Nonblocking A/B На Одинаковых Chunks

Обе точки генерировали те же `1 MiB` chunks без массива: `1 000 000` rows,
`128 MB`, три interleaved прогона.

| Protocol | Mean | EventLoop ticks | Read waits | Write waits/backpressure | Peak memory |
| --- | ---: | ---: | ---: | ---: | ---: |
| Blocking `pg_put_copy_data` | `0.3674 s` | `0` | `0` | `0` | `10.49 MB` |
| Readiness-driven | `0.3768 s` | `6–7` | `2` | `0` | `10.49 MB` |

Readiness-driven COPY сохранил EventLoop liveness, но был на `2.56%` медленнее
blocking chunked control. На локальном tmpfs PostgreSQL даже payload `640 MB`
не создал write backpressure: измерены только ожидания server result. Поэтому
этот тест доказывает state machine и responsiveness, но ещё не поведение на
медленном/удалённом PostgreSQL.

Отдельно важно, что chunked blocking protocol быстрее array `pg_copy_from()`
component control. Это нельзя приписывать асинхронности: chunked path делает
около одного native call на `1 MiB`, тогда как `pg_copy_from()` передаёт строки
поэлементно. Следующий production-shaped benchmark должен раздельно сравнить:

1. прежний array `pg_copy_from()`;
2. PHP 8.5 generator `pg_copy_from()`;
3. blocking chunked `pg_put_copy_data()`;
4. readiness-driven chunked `pg_put_copy_data()`.

Принимать adapter можно только после exact raw/E2E и interruption/recovery
gate. Патчить ext-pgsql или писать C-extension для этого не требуется.

## Текущий Вывод

- Path-only parser contract и единичный manifest snapshot убирают лишнюю
  byte-batch capability и повторный per-file discovery, но ещё не являются
  самостоятельным performance claim.
- Safe-range planning — заметный, но не главный component bottleneck;
  последовательный XML decode/encode доминирует.
- Замена XMLReader на SAX, wrapper или full-memory tree отклонена; измеримый
  резерв находится в fused per-row transform API поверх XMLReader.
- PHP 8.5 generator даёт сильный memory/allocation signal.
- Ручной chunked COPY является более сильной transport-гипотезой, чем один
  только переход на async: async сохраняет полезную работу EventLoop, но сам по
  себе добавил `2.56%` в одинаковом A/B.
- Production q101 подтвердил правильную границу: `1 MiB` transport chunks и
  одна transaction на planned range дали `7.970%` raw и `4.494%` E2E gain в
  двух exact crossover-парах. `4 MiB` checkpoints отклонены; streaming path
  принят с сохранённым q90 checkpoint contract и без row payload IPC.

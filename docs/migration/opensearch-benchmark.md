# PostgreSQL И OpenSearch: Server Benchmark

- Дата evidence: `2026-11-06`
- Dataset: GAR, регионы `50` и `77`, затем полный Data.Mos/OSM enrichment
- Документы после enrichment: `28 649 238`
- Aliases после исправленного region-77 matching: `794`
- Решение: OpenSearch projection построена, online decorator пока выключен

Этот benchmark отвечает на узкий migration-вопрос: даёт ли OpenSearch пользу
поверх уже оптимизированной региональной PostgreSQL projection. Результаты не
следует переносить на другой dataset, mapping или workload без повторного
измерения.

## Конфигурация

- host: `24 CPU`, `58.5 GiB RAM`;
- PostgreSQL pool: `10` connections;
- `address_search`: LIST partitions регионов 50/77, `24 GiB` вместе с indexes;
- OpenSearch `3.9.0`: `2` primary shards, `0` replicas, heap `8 GiB`;
- OpenSearch primary store после merge: `5.3 GiB`;
- search limit: `10`, hierarchy: `adm`, регион задавался явно;
- каждый sequential результат — 10 прогретых запросов после отдельного warm-up.

## Baseline До Enrichment

Следующие исходные измерения выполнены на `28 584 562` GAR-документах без
Data.Mos и aliases. Они сохранены как baseline для сравнения mapping/workload,
а не как текущее состояние read model.

### Rebuild

Blue-green rebuild с `--batch-size=10000 --concurrency=16` завершился за
`361 570 ms`: source/index/target parity равна `28 584 562`, alias `places`
переключён атомарно. Средний end-to-end throughput составил около
`79 000 документов/с`.

Stress-профиль при стандартном PHP `memory_limit=128M` исчерпал память после
примерно `3.8 млн` документов. Alias при этом не переключился, partial target
был удалён guarded cleanup-командой. Для reindex введён отдельный
`OPENSEARCH_REINDEX_MEMORY_LIMIT=512M`; этот budget применяется только выбранной
CLI-командой и не меняет лимит HTTP/worker runtime.

### Последовательная Задержка

| Запрос | Регион | PostgreSQL avg | OpenSearch avg | PG / OS |
| --- | ---: | ---: | ---: | ---: |
| `Москва Тверская 1` | 77 | 178.5 ms | 67.2 ms | 2.65x |
| `Москва Арбат 10` | 77 | 28.7 ms | 60.4 ms | 0.48x |
| `Москва Ленинградский проспект 37` | 77 | 68.5 ms | 83.2 ms | 0.82x |
| `Московская область Химки Ленинградская 1` | 50 | 91.6 ms | 47.0 ms | 1.95x |
| `Балашиха Советская 10` | 50 | 38.0 ms | 73.0 ms | 0.52x |
| `Масква Тверская 1` | 77 | 65.6 ms, 0 hits | 64.9 ms, 10 hits | semantic gain |

OpenSearch выигрывает не на каждом одиночном запросе. Уже дешёвый региональный
PostgreSQL FTS быстрее сетевого OpenSearch path; OpenSearch окупается на более
тяжёлых запросах и даёт fuzzy recall для опечатки.

### Параллельная Нагрузка

Одинаковый запрос `Москва Тверская 1`, 100 requests, concurrency `16`:

| Backend | Average | p50 | p95 | p99 | Max |
| --- | ---: | ---: | ---: | ---: | ---: |
| PostgreSQL | 435.5 ms | 426.8 ms | 587.2 ms | 638.4 ms | 679.0 ms |
| OpenSearch | 66.4 ms | 70.5 ms | 78.1 ms | 87.9 ms | 89.1 ms |

При pool из 10 PostgreSQL connections OpenSearch дал примерно `6.6x` по
average и `7.5x` по p95. Это основное подтверждённое performance-преимущество:
вынос полнотекстового поиска разгружает PostgreSQL и лучше держит одновременные
тяжёлые запросы.

### Relevance

Top-10 overlap пяти корректно написанных запросов составил `29/50` (`58%`), а
первый результат совпал в трёх случаях из пяти. Fuzzy query полезен для опечаток,
но иногда ухудшает literal precision: для `Химки Ленинградская 1` OpenSearch
поставил `Ленинградское шоссе, влд. 1/1` выше документов точной улицы.

До включения online decorator были нужны:

1. representative relevance fixture с ожидаемыми top results;
2. boosts точной фразы, exact/prefix street и номера дома перед fuzzy score;
3. повторная проверка после импорта Data.Mos/OSM aliases;
4. mixed-load test, а не только один тяжёлый запрос;
5. отдельное решение о hybrid policy: OpenSearch primary, PostgreSQL primary с
   fuzzy fallback либо объединение candidates с application-side reranking.

## Повтор После Data.Mos/OSM И Ranking Tuning

Data.Mos добавил `64 676` datamos-only документов и обогатил `11 572 796` GAR
строк. OSM import добавил `794` aliases только для region 77. Mapping дополнен
`keyword` и `literal` subfields для alias/address; ranking применяет exact
term, literal alias, literal/russian phrase и strict `cross_fields` до fuzzy
fallback. Это устраняет смешение русских основ вроде «Ленинградская» и
«Ленинградское».

Промежуточный rebuild с concurrency `8` занял `514 811 ms`. Финальный rebuild
с literal mapping и concurrency `16` занял `497 450 ms`; source/index parity —
`28 649 238`, physical index — `places-v20261106173307122`, store — `6.5 GiB`.
Увеличение concurrency `8 -> 16` дало только около `3.4%`, поскольку основная
нагрузка уже лежала на OpenSearch segment/index work, а не на PHP event loop.

Финальный sequential benchmark выполнялся внутри app container через один
process-wide AMPHP HTTP pool и PostgreSQL pool: один warm-up, затем 10
чередующихся запросов к каждому backend.

| Запрос | OpenSearch avg | PostgreSQL avg | PG / OS |
| --- | ---: | ---: | ---: |
| `Москва Тверская 1` | 13.0 ms | 255.7 ms | 19.7x |
| `Москва Арбат 10` | 7.5 ms | 97.7 ms | 13.1x |
| `Москва Ленинградский проспект 37` | 20.2 ms | 166.6 ms | 8.2x |
| `Московская область Химки Ленинградская 1` | 25.8 ms | 109.0 ms | 4.2x |
| `Балашиха Советская 10` | 14.7 ms | 73.7 ms | 5.0x |
| `Масква Тверская 1` | 10.4 ms, 10 hits | 224.8 ms, 0 hits | semantic gain |
| `Москва Самаркандский бульвар 7/28` | 18.4 ms | 291.2 ms | 15.8x |

Последний запрос подтверждает пользу aliases: оба backend нашли canonical
`Улица Ферганская, д. 28/7` по альтернативному Самаркандскому адресу, а после
alias boost он стал первым результатом OpenSearch. Для Химок literal field
исправил исходный false positive: `Улица Ленинградская` теперь выше
`Ленинградского шоссе`.

Повторный concurrency test: 100 запросов `Москва Тверская 1`, concurrency `16`,
один persistent client/pool:

| Backend | Average | p50 | p95 | p99 | Max | Wall time |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| OpenSearch | 29.3 ms | 28.4 ms | 54.2 ms | 58.9 ms | 63.1 ms | 248.7 ms |
| PostgreSQL | 440.9 ms | 370.9 ms | 678.4 ms | 722.7 ms | 736.5 ms | 4381.2 ms |

OpenSearch дал примерно `15.1x` по average и `12.5x` по p95. Повтор также
фактически проверяет connection reuse: 100 запросов отправлены одним
`amphp/http-client`, который переиспользует keep-alive connections и открывает
дополнительные sockets только при конкурентной потребности.

Relevance gate улучшился по известным проблемам literal street и OSM alias, но
ещё не закрыт полностью. Top-10 overlap пяти корректных запросов после смены
dataset/ranking — `15/50` (`30%`), первый результат совпал в одном случае из
пяти. OpenSearch всё ещё иногда предпочитает помещение/квартиру точному дому,
когда номер встречается несколько раз. Нужны structured house/unit fields или
application-side reranking; один общий text score не кодирует эту семантику.

## Решение

PostgreSQL остаётся default backend: он уже достаточно быстр для большинства
региональных запросов, точнее следует literal input и не требует дополнительных
`~9.5 GiB` resident memory OpenSearch process. OpenSearch projection сохраняется
как rebuildable optional read model: она оправдана при высокой конкуренции,
fuzzy/alias search и когда снижение нагрузки на canonical PostgreSQL важнее
стоимости отдельного service.

`OPENSEARCH_SEARCH_ENABLED` остаётся `0`: performance benefit и alias/fuzzy
recall доказаны, но production cutover требует закрыть house/unit relevance
fixture. Наличие построенного alias само по себе не разрешает переключение.
